<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function registerJobSeeker(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => UserRole::JOB_SEEKER,
                'status' => UserStatus::ACTIVE,
                'password' => $data['password'],
            ]);

            JobSeekerProfile::create([
                'user_id' => $user->id,
                'phone' => $data['phone'] ?? null,
            ]);

            return $this->loadUserProfile($user);
        });
    }

    public function registerEmployer(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => UserRole::EMPLOYER,
                'status' => UserStatus::ACTIVE,
                'password' => $data['password'],
            ]);

            $company = Company::create([
                'name' => $data['company_name'],
                'website' => $data['company_website'] ?? null,
            ]);

            EmployerProfile::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'phone' => $data['phone'] ?? null,
            ]);

            return $this->loadUserProfile($user);
        });
    }

    private function loadUserProfile(User $user): User
    {
        return $user->fresh([
            'jobSeekerProfile.experiences',
            'jobSeekerProfile.education',
            'jobSeekerProfile.skills',
            'employerProfile.company',
        ]);
    }
}
