<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\OptionalImageService;
use App\Services\PublicImageOptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PublicImageOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_large_avatar_is_webp_reduced_with_aspect_ratio_and_valid_url(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($this->token($user))->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->image('avatar.jpg', 2000, 1000),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = (string) $user->refresh()->avatar_path;
        $this->assertOptimizedImage($path, 512, 256);
        $this->assertSame(Storage::disk('public')->url($path), $response->json('data.avatar_url'));
    }

    public function test_large_company_logo_is_webp_reduced_with_aspect_ratio(): void
    {
        [$owner, $company] = $this->employerCompany();

        $response = $this->withToken($this->token($owner))->post('/api/v1/company', [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png', 1200, 600),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = (string) $company->refresh()->logo_path;
        $this->assertOptimizedImage($path, 512, 256);
        $this->assertSame(Storage::disk('public')->url($path), $response->json('data.logo_url'));
    }

    public function test_large_company_cover_is_webp_reduced_with_aspect_ratio(): void
    {
        [$owner, $company] = $this->employerCompany();

        $response = $this->withToken($this->token($owner))->post('/api/v1/company/cover-image', [
            'image' => UploadedFile::fake()->image('cover.jpg', 2400, 1800),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = (string) $company->refresh()->cover_image_path;
        $this->assertOptimizedImage($path, 1600, 1200);
        $this->assertSame(Storage::disk('public')->url($path), $response->json('data.cover_image_url'));
    }

    public function test_large_skill_icon_is_webp_reduced_and_non_admin_remains_forbidden(): void
    {
        $skill = Skill::create(['name' => 'Image optimization', 'slug' => 'image-optimization']);
        $regular = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $url = "/api/v1/admin/skills/{$skill->id}/icon";

        $this->withToken($this->token($regular))->post($url, [
            'image' => UploadedFile::fake()->image('icon.png', 800, 400),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->resetAuth();
        $response = $this->withToken($this->token($admin))->post($url, [
            'image' => UploadedFile::fake()->image('icon.png', 800, 400),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = (string) $skill->refresh()->icon_path;
        $this->assertOptimizedImage($path, 256, 128);
        $this->assertSame(Storage::disk('public')->url($path), $response->json('data.icon_url'));
    }

    public function test_small_images_are_reencoded_but_never_upscaled_and_replacement_removes_old_file(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);

        $this->withToken($token)->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->image('first.png', 300, 150),
        ], ['Accept' => 'application/json'])->assertOk();
        $oldPath = (string) $user->refresh()->avatar_path;

        $this->withToken($token)->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->image('small.png', 120, 80),
        ], ['Accept' => 'application/json'])->assertOk();
        $newPath = (string) $user->refresh()->avatar_path;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        $this->assertOptimizedImage($newPath, 120, 80);
    }

    public function test_database_failure_removes_new_file_but_keeps_previous_current_image(): void
    {
        $user = User::factory()->create();
        $this->withToken($this->token($user))->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->image('current.png', 300, 200),
        ], ['Accept' => 'application/json'])->assertOk();
        $oldPath = (string) $user->refresh()->avatar_path;

        Event::listen('eloquent.updating: '.User::class, static function (): void {
            throw new RuntimeException('Forced replacement database failure.');
        });

        try {
            app(OptionalImageService::class)->updateAvatar(
                $user,
                UploadedFile::fake()->image('replacement.jpg', 1000, 500),
            );
            $this->fail('Expected the image replacement to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced replacement database failure.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.updating: '.User::class);
        }

        $this->assertSame($oldPath, $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles());
    }

    public function test_corrupt_image_is_rejected_by_endpoint_and_decoder(): void
    {
        $user = User::factory()->create();
        $this->withToken($this->token($user))->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->image('current.png', 200, 100),
        ], ['Accept' => 'application/json'])->assertOk();
        $oldPath = (string) $user->refresh()->avatar_path;

        $this->withToken($this->token($user))->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->create('corrupt.png', 10, 'image/png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        try {
            app(PublicImageOptimizationService::class)->store(
                UploadedFile::fake()->createWithContent('corrupt.png', 'not an image'),
                "user-avatars/{$user->id}",
                512,
                512,
            );
            $this->fail('Expected corrupt image decoding to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('image', $exception->errors());
        }

        $this->assertSame($oldPath, $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles());
    }

    public function test_excessive_source_dimensions_are_rejected_before_decode(): void
    {
        $ihdr = pack('NNCCCCC', 9000, 1, 8, 6, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        $this->expectException(ValidationException::class);

        app(PublicImageOptimizationService::class)->store(
            UploadedFile::fake()->createWithContent('oversized-dimensions.png', $png),
            'user-avatars/1',
            512,
            512,
        );
    }

    public function test_transparency_is_preserved_in_webp_output(): void
    {
        $source = imagecreatetruecolor(40, 20);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        $transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);
        imagefill($source, 0, 0, $transparent);
        $red = imagecolorallocatealpha($source, 255, 0, 0, 0);
        imagefilledrectangle($source, 10, 5, 30, 15, $red);

        ob_start();
        imagepng($source);
        $png = (string) ob_get_clean();
        imagedestroy($source);

        $path = app(PublicImageOptimizationService::class)->store(
            UploadedFile::fake()->createWithContent('transparent.png', $png),
            'skill-icons/1',
            256,
            256,
        );
        $output = imagecreatefromwebp(Storage::disk('public')->path($path));

        $this->assertInstanceOf(\GdImage::class, $output);
        $alpha = (imagecolorat($output, 0, 0) >> 24) & 0x7F;
        $this->assertGreaterThan(100, $alpha);
        imagedestroy($output);
    }

    private function assertOptimizedImage(string $path, int $expectedWidth, int $expectedHeight): void
    {
        Storage::disk('public')->assertExists($path);
        $this->assertSame('webp', pathinfo($path, PATHINFO_EXTENSION));

        $metadata = getimagesize(Storage::disk('public')->path($path));
        $this->assertIsArray($metadata);
        $this->assertSame($expectedWidth, $metadata[0]);
        $this->assertSame($expectedHeight, $metadata[1]);
        $this->assertSame(IMAGETYPE_WEBP, $metadata[2]);
    }

    /** @return array{User, Company} */
    private function employerCompany(): array
    {
        $company = Company::create([
            'name' => 'Optimized Images '.Str::random(5),
            'approval_status' => 'approved',
        ]);
        $owner = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create([
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'company_role' => CompanyRole::OWNER,
        ]);

        return [$owner->load('employerProfile.company'), $company];
    }

    private function token(User $user): string
    {
        return $user->createToken('public-image-'.Str::random(5))->plainTextToken;
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
