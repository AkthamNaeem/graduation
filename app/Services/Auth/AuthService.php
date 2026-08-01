<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    public const LOGIN_INVALID = 'invalid';

    public const LOGIN_BLOCKED = 'blocked';

    public const LOGIN_UNVERIFIED = 'unverified';

    public const LOGIN_SUCCESS = 'success';

    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{status: string, token?: string, user?: User}
     */
    public function login(array $credentials): array
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return ['status' => self::LOGIN_INVALID];
        }

        if ($user->status !== UserStatus::ACTIVE) {
            return ['status' => self::LOGIN_BLOCKED];
        }

        if ($user->email_verified_at === null) {
            return ['status' => self::LOGIN_UNVERIFIED];
        }

        $user = $this->loadAuthenticatedUser($user);

        return [
            'status' => self::LOGIN_SUCCESS,
            'token' => $user->createToken('api-token')->plainTextToken,
            'user' => $user,
        ];
    }

    public function loadAuthenticatedUser(User $user): User
    {
        return $user->loadMissing([
            'jobSeekerProfile.experiences',
            'jobSeekerProfile.city',
            'jobSeekerProfile.education',
            'jobSeekerProfile.skills',
            'employerProfile.company',
        ]);
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function changePassword(User $user, string $currentPassword, string $password): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $currentToken = $user->currentAccessToken();

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        if ($currentToken) {
            $user->tokens()
                ->whereKeyNot($currentToken->getKey())
                ->delete();
        }

        return true;
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
