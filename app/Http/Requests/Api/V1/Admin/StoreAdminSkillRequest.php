<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use App\Models\Skill;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdminSkillRequest extends FormRequest
{
    use AuthorizesAdmin;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Skill::query()->whereRaw('LOWER(name) = ?', [strtolower((string) $value)])->exists()) {
                        $fail('The name has already been taken.');
                    }
                },
            ],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', 'unique:skills,slug'],
        ];
    }
}
