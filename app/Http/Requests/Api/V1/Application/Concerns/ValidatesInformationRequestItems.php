<?php

namespace App\Http\Requests\Api\V1\Application\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesInformationRequestItems
{
    protected function prepareInformationRequestInput(): void
    {
        $input = [];
        if ($this->has('message')) {
            $input['message'] = trim((string) $this->input('message'));
        }
        if ($this->has('requested_items') && is_array($this->input('requested_items'))) {
            $input['requested_items'] = collect($this->input('requested_items'))->map(function ($item): mixed {
                if (! is_array($item)) {
                    return $item;
                }
                $item['label'] = trim((string) ($item['label'] ?? ''));
                if (array_key_exists('description', $item) && $item['description'] !== null) {
                    $item['description'] = trim((string) $item['description']);
                }

                return $item;
            })->all();
        }
        $this->merge($input);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('requested_items');
            if (! is_array($items)) {
                return;
            }
            $labels = collect($items)->pluck('label')->filter(fn ($label) => is_string($label))->map(fn ($label) => mb_strtolower(trim($label)));
            if ($labels->count() !== $labels->unique()->count()) {
                $validator->errors()->add('requested_items', 'Requested item labels must be unique (case-insensitive).');
            }
        });
    }
}
