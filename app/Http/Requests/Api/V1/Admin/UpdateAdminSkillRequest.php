<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use App\Models\Skill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminSkillRequest extends FormRequest
{
    use AuthorizesAdmin;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $skill = $this->route('skill');
        $skillId = $skill instanceof Skill ? $skill->id : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('skills', 'name')->ignore($skillId)],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('skills', 'slug')->ignore($skillId)],
        ];
    }
}
