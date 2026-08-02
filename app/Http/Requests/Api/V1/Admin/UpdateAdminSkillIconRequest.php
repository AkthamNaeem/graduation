<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminSkillIconRequest extends FormRequest
{
    use AuthorizesAdmin;

    public function rules(): array
    {
        return [
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
