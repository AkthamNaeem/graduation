<?php

namespace App\Http\Requests\Api\V1\Auth\Concerns;

trait NormalizesEmail
{
    protected function normalizeEmailInput(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge([
                'email' => mb_strtolower(trim($this->input('email'))),
            ]);
        }
    }
}
