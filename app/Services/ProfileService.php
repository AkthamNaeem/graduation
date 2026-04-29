<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Education;
use App\Models\EmployerProfile;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProfileService
{
    public function getJobSeekerProfile(User $user): JobSeekerProfile
    {
        return $this->jobSeekerProfile($user)
            ->load(['user', 'experiences', 'education', 'skills']);
    }

    public function updateJobSeekerProfile(User $user, array $data): JobSeekerProfile
    {
        $profile = $this->jobSeekerProfile($user);
        $profile->update($data);

        return $profile->load(['user', 'experiences', 'education', 'skills']);
    }

    /**
     * @return Collection<int, Experience>
     */
    public function getExperiences(User $user): Collection
    {
        return $this->jobSeekerProfile($user)
            ->experiences()
            ->latest()
            ->get();
    }

    public function createExperience(User $user, array $data): Experience
    {
        return $this->jobSeekerProfile($user)
            ->experiences()
            ->create($data);
    }

    public function getExperience(User $user, Experience $experience): Experience
    {
        return $this->ownedExperience($user, $experience);
    }

    public function updateExperience(User $user, Experience $experience, array $data): Experience
    {
        $experience = $this->ownedExperience($user, $experience);
        $experience->update($data);

        return $experience->refresh();
    }

    public function deleteExperience(User $user, Experience $experience): void
    {
        $this->ownedExperience($user, $experience)->delete();
    }

    /**
     * @return Collection<int, Education>
     */
    public function getEducation(User $user): Collection
    {
        return $this->jobSeekerProfile($user)
            ->education()
            ->latest()
            ->get();
    }

    public function createEducation(User $user, array $data): Education
    {
        return $this->jobSeekerProfile($user)
            ->education()
            ->create($data);
    }

    public function getEducationRecord(User $user, Education $education): Education
    {
        return $this->ownedEducation($user, $education);
    }

    public function updateEducation(User $user, Education $education, array $data): Education
    {
        $education = $this->ownedEducation($user, $education);
        $education->update($data);

        return $education->refresh();
    }

    public function deleteEducation(User $user, Education $education): void
    {
        $this->ownedEducation($user, $education)->delete();
    }

    public function attachSkill(User $user, Skill $skill): JobSeekerProfile
    {
        $profile = $this->jobSeekerProfile($user);
        $profile->skills()->syncWithoutDetaching([$skill->id]);

        return $profile->load(['user', 'experiences', 'education', 'skills']);
    }

    public function detachSkill(User $user, Skill $skill): JobSeekerProfile
    {
        $profile = $this->jobSeekerProfile($user);
        $profile->skills()->detach($skill->id);

        return $profile->load(['user', 'experiences', 'education', 'skills']);
    }

    public function getCompany(User $user): Company
    {
        return $this->employerProfile($user)->company->load(['employerProfiles.user']);
    }

    public function updateCompany(User $user, array $data): Company
    {
        $company = $this->employerProfile($user)->company;
        $company->update($data);

        return $company->load(['employerProfiles.user']);
    }

    public function getEmployerProfile(User $user): EmployerProfile
    {
        return $this->employerProfile($user)->load(['user', 'company']);
    }

    public function updateEmployerProfile(User $user, array $data): EmployerProfile
    {
        $profile = $this->employerProfile($user);
        $profile->update($data);

        return $profile->load(['user', 'company']);
    }

    private function jobSeekerProfile(User $user): JobSeekerProfile
    {
        return $user->jobSeekerProfile()->firstOrFail();
    }

    private function employerProfile(User $user): EmployerProfile
    {
        return $user->employerProfile()->with('company')->firstOrFail();
    }

    private function ownedExperience(User $user, Experience $experience): Experience
    {
        abort_unless(
            $experience->job_seeker_profile_id === $this->jobSeekerProfile($user)->id,
            404,
        );

        return $experience;
    }

    private function ownedEducation(User $user, Education $education): Education
    {
        abort_unless(
            $education->job_seeker_profile_id === $this->jobSeekerProfile($user)->id,
            404,
        );

        return $education;
    }
}
