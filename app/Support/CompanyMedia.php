<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

final class CompanyMedia
{
    /**
     * @return array{logo_url: ?string, cover_image_url: ?string}
     */
    public static function urls(?Company $company): array
    {
        return [
            'logo_url' => self::publicUrl($company?->logo_path),
            'cover_image_url' => self::publicUrl($company?->cover_image_path),
        ];
    }

    private static function publicUrl(?string $path): ?string
    {
        return $path === null ? null : Storage::disk('public')->url($path);
    }
}
