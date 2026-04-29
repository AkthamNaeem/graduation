<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{token: string, user: \App\Models\User}|null
     */
    public function login(array $credentials): ?array
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        $user = $this->loadAuthenticatedUser($user);

        return [
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
}
