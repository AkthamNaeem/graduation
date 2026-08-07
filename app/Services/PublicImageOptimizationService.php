<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublicImageOptimizationService
{
    private const WEBP_QUALITY = 82;

    private const MAX_SOURCE_DIMENSION = 8000;

    private const MAX_SOURCE_PIXELS = 20_000_000;

    /**
     * Decode, orient, resize, encode, and store one optimized public image.
     */
    public function store(
        UploadedFile $image,
        string $directory,
        int $maxWidth,
        int $maxHeight,
        string $validationField = 'image',
    ): string {
        $this->assertRuntimeSupportsWebP($validationField);

        $sourcePath = $image->getRealPath();
        if (! $image->isValid() || ! is_string($sourcePath) || $sourcePath === '') {
            $this->fail($validationField, 'image_invalid');
        }

        $metadata = @getimagesize($sourcePath);
        if (! is_array($metadata) || ! isset($metadata[0], $metadata[1], $metadata[2])) {
            $this->fail($validationField, 'image_invalid');
        }

        [$sourceWidth, $sourceHeight, $sourceType] = $metadata;
        if (! in_array($sourceType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $this->fail($validationField, 'image_invalid');
        }

        if (
            $sourceWidth < 1
            || $sourceHeight < 1
            || $sourceWidth > self::MAX_SOURCE_DIMENSION
            || $sourceHeight > self::MAX_SOURCE_DIMENSION
            || $sourceWidth * $sourceHeight > self::MAX_SOURCE_PIXELS
        ) {
            $this->fail($validationField, 'image_dimensions_exceeded');
        }

        $source = $this->decode($sourcePath, $sourceType, $validationField);

        try {
            $source = $this->normalizeOrientation($source, $sourcePath, $sourceType, $validationField);
            $optimized = $this->resize($source, $maxWidth, $maxHeight, $validationField);

            try {
                $encoded = $this->encodeWebP($optimized, $validationField);
            } finally {
                imagedestroy($optimized);
            }
        } finally {
            imagedestroy($source);
        }

        $path = trim($directory, '/').'/'.Str::uuid().'.webp';

        try {
            $written = Storage::disk('public')->put($path, $encoded);
            if (! $written || ! Storage::disk('public')->exists($path) || Storage::disk('public')->size($path) < 1) {
                Storage::disk('public')->delete($path);
                $this->fail($validationField, 'image_storage_failed');
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            Storage::disk('public')->delete($path);
            $this->fail($validationField, 'image_storage_failed');
        }

        return $path;
    }

    private function assertRuntimeSupportsWebP(string $validationField): void
    {
        if (
            ! extension_loaded('gd')
            || ! function_exists('imagewebp')
            || ! function_exists('imagecreatefromjpeg')
            || ! function_exists('imagecreatefrompng')
            || ! function_exists('imagecreatefromwebp')
        ) {
            $this->fail($validationField, 'image_processing_unavailable');
        }
    }

    private function decode(string $path, int $type, string $validationField): GdImage
    {
        $decoded = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $decoded instanceof GdImage) {
            $this->fail($validationField, 'image_invalid');
        }

        return $decoded;
    }

    private function normalizeOrientation(
        GdImage $source,
        string $sourcePath,
        int $sourceType,
        string $validationField,
    ): GdImage {
        if ($sourceType !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $source;
        }

        $exif = @exif_read_data($sourcePath);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            $flipMode = in_array($orientation, [2, 5, 7], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL;
            if (! imageflip($source, $flipMode)) {
                $this->fail($validationField, 'image_processing_failed');
            }
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $source;
        }

        $rotated = @imagerotate($source, $angle, 0);
        if (! $rotated instanceof GdImage) {
            $this->fail($validationField, 'image_processing_failed');
        }

        imagedestroy($source);

        return $rotated;
    }

    private function resize(GdImage $source, int $maxWidth, int $maxHeight, string $validationField): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1);
        $targetWidth = max(1, (int) floor($sourceWidth * $scale));
        $targetHeight = max(1, (int) floor($sourceHeight * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $target instanceof GdImage) {
            $this->fail($validationField, 'image_processing_failed');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);

        if (! imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        )) {
            imagedestroy($target);
            $this->fail($validationField, 'image_processing_failed');
        }

        return $target;
    }

    private function encodeWebP(GdImage $image, string $validationField): string
    {
        $encodedSuccessfully = false;
        $encoded = false;
        ob_start();

        try {
            $encodedSuccessfully = @imagewebp($image, null, self::WEBP_QUALITY);
            $encoded = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (! $encodedSuccessfully || ! is_string($encoded) || $encoded === '') {
            $this->fail($validationField, 'image_processing_failed');
        }

        return $encoded;
    }

    private function fail(string $validationField, string $message): never
    {
        throw ValidationException::withMessages([
            $validationField => [(string) __("validation.custom_messages.{$message}")],
        ]);
    }
}
