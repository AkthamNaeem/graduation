<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthService
{
    public const LOGIN_INVALID = 'invalid';

    public const LOGIN_BLOCKED = 'blocked';

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
            'jobSeekerProfile.education',
            'jobSeekerProfile.skills',
            'employerProfile.company',
        ]);
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /**
     * @param  array{email: string}  $data
     */
    public function sendPasswordResetLink(array $data): void
    {
        Password::sendResetLink(['email' => $data['email']]);
    }

    /**
     * @param  array{email: string, token: string, password: string, password_confirmation?: string}  $data
     */
    public function resetPassword(array $data): bool
    {
        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        return $status === PasswordBroker::PASSWORD_RESET;
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
