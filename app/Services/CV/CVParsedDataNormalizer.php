<?php

namespace App\Services\CV;

use DateTimeImmutable;

class CVParsedDataNormalizer
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data, string $rawText): array
    {
        $data = $this->trimRecursively($data);
        $data['birth_date'] = $this->normalizeBirthDate($data['birth_date'] ?? null);
        $data['skills'] = $this->normalizeSkills($data['skills'] ?? []);
        $data['experience'] = $this->normalizeExperiences($data['experience'] ?? [], $rawText);
        $data['education'] = $this->normalizeEducation($data['education'] ?? [], $rawText);

        return $data;
    }

    private function trimRecursively(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->trimRecursively($item);
        }

        return $value;
    }

    /** @return array<int, string> */
    private function normalizeSkills(mixed $skills): array
    {
        if (! is_array($skills)) {
            return [];
        }

        $seen = [];
        $result = [];
        foreach ($skills as $skill) {
            if (! is_string($skill) || trim($skill) === '') {
                continue;
            }
            $skill = trim($skill);
            $key = mb_strtolower($skill);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $skill;
            }
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeExperiences(mixed $items, string $rawText): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = $item['title'] ?? null;
            $company = $item['company_name'] ?? null;
            if (! is_string($title) || ! is_string($company) || $title === '' || $company === '') {
                continue;
            }
            if ($this->isDateOnly($title) || $this->isDateOnly($company) || preg_match('/^(present|current)$/i', $company) || $this->looksLikeProse($title, $company)) {
                continue;
            }
            if (! $this->hasValidEvidence($item, $rawText)) {
                continue;
            }

            if (isset($item['end_date']) && is_string($item['end_date']) && preg_match('/^(present|current)$/i', $item['end_date'])) {
                $item['end_date'] = null;
                $item['is_current'] = true;
            }
            if (($item['is_current'] ?? false) === true) {
                $item['end_date'] = null;
            }
            if ($this->datesAreReversed($item['start_date'] ?? null, $item['end_date'] ?? null)) {
                continue;
            }
            $result[] = $item;
        }

        return array_values($result);
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeEducation(mixed $items, string $rawText): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, function (mixed $item) use ($rawText): bool {
            return is_array($item)
                && isset($item['institution'])
                && is_string($item['institution'])
                && $item['institution'] !== ''
                && $this->hasValidEvidence($item, $rawText);
        }));
    }

    /** @param array<string, mixed> $item */
    private function hasValidEvidence(array $item, string $rawText): bool
    {
        if (! array_key_exists('evidence', $item)) {
            return true; // Legacy rule-based records remain compatible.
        }
        if (! is_string($item['evidence']) || trim($item['evidence']) === '') {
            return false;
        }

        return str_contains($this->canonicalizeEvidence($rawText), $this->canonicalizeEvidence($item['evidence']));
    }

    private function canonicalizeEvidence(string $value): string
    {
        $value = str_replace(
            ["\u{2013}", "\u{2014}", "\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{201C}", "\u{201D}", "\u{201E}", "\u{201F}"],
            ['-', '-', "'", "'", "'", "'", '"', '"', '"', '"'],
            $value,
        );
        $value = preg_replace('/[\x{2022}\x{2023}\x{2043}\x{2219}\x{25AA}\x{25CF}\x{25E6}]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s*-\s*/u', ' - ', $value) ?? $value;
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value), 'UTF-8');
    }

    private function normalizeBirthDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        foreach (['Y-m-d', 'j F Y', 'F j, Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date instanceof DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function isDateOnly(string $value): bool
    {
        $months = 'january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec';

        return (bool) preg_match('/^(?:(?:'.$months.')\s+)?\d{4}(?:\s*(?:-|\x{2013}|\x{2014}|to)\s*(?:(?:'.$months.')\s+)?(?:\d{4}|present|current))?$/iu', trim($value));
    }

    private function looksLikeProse(string $title, string $company): bool
    {
        return (bool) preg_match('/[,;:]|[.!?]$/u', $company)
            && (bool) preg_match('/^[\p{Ll}][\pL-]*(?:\s+[\p{Ll}][\pL-]*){0,2}$/u', $title);
    }

    private function datesAreReversed(mixed $start, mixed $end): bool
    {
        if (! is_string($start) || ! is_string($end)) {
            return false;
        }
        if (! preg_match('/^\d{4}(?:-(?:0[1-9]|1[0-2]))?$/', $start) || ! preg_match('/^\d{4}(?:-(?:0[1-9]|1[0-2]))?$/', $end)) {
            return false;
        }

        return $start > $end;
    }
}
