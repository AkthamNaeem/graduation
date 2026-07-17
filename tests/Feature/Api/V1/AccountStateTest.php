<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_token_is_rejected_after_status_is_changed_directly(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $token = $user->createToken('existing-session')->plainTextToken;

        $user->forceFill(['status' => UserStatus::SUSPENDED])->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your account is suspended.')
            ->assertJsonPath('code', 'USER_SUSPENDED');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_specialized_and_generic_suspension_paths_revoke_every_token_and_audit_count(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $adminToken = $admin->createToken('admin-session')->plainTextToken;

        foreach (['suspend', 'status'] as $index => $path) {
            $target = User::factory()->create(['email' => "target{$index}@example.com"]);
            $target->createToken('phone');
            $target->createToken('laptop');

            $request = $this->withToken($adminToken);
            $response = $path === 'status'
                ? $request->patchJson("/api/v1/admin/users/{$target->id}/status", ['status' => 'suspended'])
                : $request->patchJson("/api/v1/admin/users/{$target->id}/suspend");

            $response->assertOk()->assertJsonPath('data.status', 'suspended');
            $this->assertSame(0, $target->tokens()->count());

            $audit = AuditLog::query()
                ->where('action', 'user.suspended')
                ->where('entity_id', $target->id)
                ->latest('id')
                ->firstOrFail();

            $this->assertSame(2, $audit->metadata['tokens_revoked_count']);
            $this->assertSame($admin->id, $audit->metadata['actor_id']);
        }
    }

    public function test_reactivation_requires_fresh_login_and_suspended_admin_is_blocked(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $adminToken = $admin->createToken('admin-session')->plainTextToken;
        $target = User::factory()->create([
            'email' => 'reactivate@example.com',
            'status' => UserStatus::ACTIVE,
        ]);
        $oldToken = $target->createToken('old-session')->plainTextToken;

        $this->withToken($adminToken)->patchJson("/api/v1/admin/users/{$target->id}/suspend")->assertOk();
        $this->withToken($adminToken)->patchJson("/api/v1/admin/users/{$target->id}/activate")->assertOk();

        $this->assertSame(0, $target->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'reactivate@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);

        $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'USER_SUSPENDED');
    }

    public function test_suspended_login_exposes_stable_error_code_without_creating_token(): void
    {
        User::factory()->create([
            'email' => 'blocked@example.com',
            'status' => UserStatus::SUSPENDED,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'blocked@example.com',
            'password' => 'password',
        ])->assertForbidden()->assertJsonPath('code', 'USER_SUSPENDED');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_every_sanctum_protected_api_route_enforces_active_user_middleware(): void
    {
        $protectedRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->filter(fn ($route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true));

        $this->assertNotEmpty($protectedRoutes);

        foreach ($protectedRoutes as $route) {
            $this->assertContains('user.active', $route->gatherMiddleware(), "Protected route {$route->uri()} must enforce active user status.");
        }
    }
}
