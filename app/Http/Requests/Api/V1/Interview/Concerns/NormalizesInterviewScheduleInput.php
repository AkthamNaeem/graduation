<?php

namespace App\Http\Requests\Api\V1\Interview\Concerns;

use Carbon\CarbonImmutable;

trait NormalizesInterviewScheduleInput
{
    protected function normalizeScheduleInput(): void
    {
        $type = $this->input('type', $this->input('interview_type'));
        $mode = $this->input('mode', $this->input('interview_mode'));
        $mode = match ($mode) {
            'video', 'phone' => 'online',
            'in_person' => 'on_site',
            default => $mode,
        };
        $type = match (true) {
            ! is_string($type) => $type,
            str_contains(strtolower($type), 'final') => 'final',
            str_contains(strtolower($type), 'technical') => 'technical',
            str_contains(strtolower($type), 'hr') => 'hr',
            default => $type,
        };
        $start = $this->input('scheduled_start_at', $this->input('scheduled_at'));
        $end = $this->input('scheduled_end_at');

        if ($end === null && is_string($start) && $this->filled('duration_minutes')) {
            try {
                $end = CarbonImmutable::parse($start)->addMinutes($this->integer('duration_minutes'))->toISOString();
            } catch (\Throwable) {
                // Date validation reports the invalid value.
            }
        }

        $this->merge([
            'type' => $type,
            'mode' => $mode,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $end,
            'location_text' => $this->input('location_text', $this->input('location')),
            'candidate_message' => $this->input('candidate_message'),
            'internal_note' => $this->input('internal_note', $this->input('note')),
        ]);
    }
}
