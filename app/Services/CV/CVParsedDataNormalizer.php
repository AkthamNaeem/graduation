<?php

namespace App\Services\CV;

use DateTimeImmutable;

class CVParsedDataNormalizer
{
    private readonly ExtractedTextNormalizer $textNormalizer;

    public function __construct(?ExtractedTextNormalizer $textNormalizer = null)
    {
        $this->textNormalizer = $textNormalizer ?? new ExtractedTextNormalizer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data, string $rawText): array
    {
        $inputCounts = [
            'experience' => is_array($data['experience'] ?? null) ? count($data['experience']) : 0,
            'education' => is_array($data['education'] ?? null) ? count($data['education']) : 0,
            'skills' => is_array($data['skills'] ?? null) ? count($data['skills']) : 0,
        ];
        $droppedCounts = [
            'experience_missing_identity' => 0,
            'experience_invalid_evidence' => 0,
            'experience_reversed_dates' => 0,
            'education_missing_institution' => 0,
            'education_invalid_evidence' => 0,
        ];

        $rawText = $this->textNormalizer->normalize($rawText);
        $data = $this->trimRecursively($data);
        $data['birth_date'] = $this->normalizeBirthDate($data['birth_date'] ?? null);
        $data['skills'] = $this->normalizeSkills($data['skills'] ?? []);
        $data['experience'] = $this->normalizeExperiences($data['experience'] ?? [], $rawText, $droppedCounts);
        $data['education'] = $this->normalizeEducation($data['education'] ?? [], $rawText, $droppedCounts);
        $data['_meta'] = is_array($data['_meta'] ?? null) ? $data['_meta'] : [];
        $data['_meta']['normalization'] = [
            'input_counts' => $inputCounts,
            'output_counts' => [
                'experience' => count($data['experience']),
                'education' => count($data['education']),
                'skills' => count($data['skills']),
            ],
            'dropped_counts' => $droppedCounts,
        ];

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

            foreach ($this->splitSkillOutsideParentheses($skill) as $part) {
                $part = $this->textNormalizer->normalize($part);
                if ($part === '') {
                    continue;
                }
                $key = mb_strtolower($part, 'UTF-8');
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[] = $part;
                }
            }
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeExperiences(mixed $items, string $rawText, array &$droppedCounts): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $droppedCounts['experience_missing_identity']++;

                continue;
            }
            $title = $item['title'] ?? null;
            $company = $item['company_name'] ?? null;
            if (! is_string($title) || ! is_string($company) || $title === '' || $company === '') {
                $droppedCounts['experience_missing_identity']++;

                continue;
            }
            if ($this->isDateOnly($title) || $this->isDateOnly($company) || preg_match('/^(present|current)$/i', $company) || $this->looksLikeProse($title, $company)) {
                $droppedCounts['experience_missing_identity']++;

                continue;
            }
            $titleMatched = $this->matchesAnchor($rawText, $title);
            $companyMatched = $this->matchesAnchor($rawText, $company);
            if (! $titleMatched || ! $companyMatched) {
                $droppedCounts['experience_invalid_evidence']++;

                continue;
            }

            $item['responsibilities'] = $this->normalizeResponsibilities($item['responsibilities'] ?? []);
            if (is_string($item['description'] ?? null) && $this->duplicatesResponsibility($item['description'], $item['responsibilities'])) {
                $item['description'] = null;
            }
            if (isset($item['end_date']) && is_string($item['end_date']) && preg_match('/^(present|current)$/i', $item['end_date'])) {
                $item['end_date'] = null;
                $item['is_current'] = true;
            }
            if (($item['is_current'] ?? false) === true) {
                $item['end_date'] = null;
            }
            if ($this->datesAreReversed($item['start_date'] ?? null, $item['end_date'] ?? null)) {
                $droppedCounts['experience_reversed_dates']++;

                continue;
            }

            $startMatched = $this->dateMatches($rawText, $item['start_date'] ?? null);
            $endMatched = ($item['is_current'] ?? false) === true
                ? $this->containsAny($rawText, ['present', 'current'])
                : $this->dateMatches($rawText, $item['end_date'] ?? null);
            $evidenceMatched = $this->evidenceMatches($rawText, $item['evidence'] ?? null);
            $responsibilitiesSupported = $this->anyResponsibilitySupported($rawText, $item['responsibilities']);
            if (! $startMatched && ! $endMatched && ! $evidenceMatched) {
                $droppedCounts['experience_invalid_evidence']++;

                continue;
            }

            $derivedConfidence = 0.25 + 0.25
                + ($startMatched ? 0.15 : 0.0)
                + ($endMatched ? 0.10 : 0.0)
                + ($evidenceMatched ? 0.15 : 0.0)
                + ($responsibilitiesSupported ? 0.10 : 0.0);
            $item['confidence_score'] = $this->boundedByEvidence($item['confidence_score'] ?? null, $derivedConfidence);
            $result[] = $item;
        }

        return array_values($result);
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeEducation(mixed $items, string $rawText, array &$droppedCounts): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)
                || ! is_string($item['institution'] ?? null)
                || $item['institution'] === '') {
                $droppedCounts['education_missing_institution']++;

                continue;
            }

            $institutionMatched = $this->matchesAnchor($rawText, $item['institution']);
            $degreeMatched = $this->matchesAnchor($rawText, $item['degree'] ?? null)
                || $this->matchesAnchor($rawText, $item['field_of_study'] ?? null);
            $yearMatched = $this->yearMatches($rawText, $item['start_year'] ?? null)
                || $this->yearMatches($rawText, $item['graduation_year'] ?? null);
            $evidenceMatched = $this->evidenceMatches($rawText, $item['evidence'] ?? null);
            if (! $institutionMatched || (! $degreeMatched && ! $yearMatched && ! $evidenceMatched)) {
                $droppedCounts['education_invalid_evidence']++;

                continue;
            }

            $derivedConfidence = 0.35
                + ($degreeMatched ? 0.25 : 0.0)
                + ($yearMatched ? 0.20 : 0.0)
                + ($evidenceMatched ? 0.20 : 0.0);
            $item['confidence_score'] = $this->boundedByEvidence($item['confidence_score'] ?? null, $derivedConfidence);
            $result[] = $item;
        }

        return $result;
    }

    private function canonicalizeEvidence(string $value): string
    {
        return $this->textNormalizer->canonicalizeForMatching($value);
    }

    /** @return array<int, string> */
    private function splitSkillOutsideParentheses(string $skill): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        foreach (mb_str_split($skill) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && $depth > 0) {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $character;
            }
        }
        $parts[] = $current;

        return $parts;
    }

    /** @return array<int, string> */
    private function normalizeResponsibilities(mixed $responsibilities): array
    {
        if (! is_array($responsibilities)) {
            return [];
        }

        $seen = [];
        $result = [];
        foreach ($responsibilities as $responsibility) {
            if (! is_string($responsibility)) {
                continue;
            }
            $responsibility = $this->textNormalizer->normalize($responsibility);
            $key = $this->canonicalizeEvidence($responsibility);
            if ($key !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $responsibility;
            }
        }

        return $result;
    }

    /** @param array<int, string> $responsibilities */
    private function duplicatesResponsibility(string $description, array $responsibilities): bool
    {
        $description = $this->canonicalizeEvidence($description);

        return $description !== '' && collect($responsibilities)
            ->contains(fn (string $responsibility): bool => $this->canonicalizeEvidence($responsibility) === $description);
    }

    private function matchesAnchor(string $rawText, mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        return str_contains($this->canonicalizeEvidence($rawText), $this->canonicalizeEvidence($value));
    }

    private function evidenceMatches(string $rawText, mixed $evidence): bool
    {
        if (! is_string($evidence) || trim($evidence) === '') {
            return false;
        }

        $raw = $this->canonicalizeEvidence($rawText);
        $candidate = $this->canonicalizeEvidence($evidence);
        if ($candidate !== '' && str_contains($raw, $candidate)) {
            return true;
        }

        $tokens = array_values(array_unique(array_filter(explode(' ', $candidate), fn (string $token): bool => mb_strlen($token) >= 2)));
        if (count($tokens) < 3) {
            return false;
        }
        $rawTokens = array_fill_keys(explode(' ', $raw), true);
        $matched = count(array_filter($tokens, fn (string $token): bool => isset($rawTokens[$token])));

        return $matched >= 3 && ($matched / count($tokens)) >= 0.7;
    }

    private function dateMatches(string $rawText, mixed $date): bool
    {
        if (! is_string($date) || $date === '') {
            return false;
        }
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $date, $matches) === 1) {
            $months = [1 => 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

            return $this->containsAny($rawText, [$matches[1]])
                && $this->containsAny($rawText, [$months[(int) $matches[2]], substr($months[(int) $matches[2]], 0, 3)]);
        }

        return preg_match('/^\d{4}$/', $date) === 1 && $this->containsAny($rawText, [$date]);
    }

    private function yearMatches(string $rawText, mixed $year): bool
    {
        return (is_int($year) || (is_string($year) && ctype_digit($year)))
            && preg_match('/\b'.preg_quote((string) $year, '/').'\b/u', $rawText) === 1;
    }

    /** @param array<int, string> $needles */
    private function containsAny(string $rawText, array $needles): bool
    {
        $rawText = $this->canonicalizeEvidence($rawText);

        return collect($needles)->contains(fn (string $needle): bool => str_contains($rawText, $this->canonicalizeEvidence($needle)));
    }

    /** @param array<int, string> $responsibilities */
    private function anyResponsibilitySupported(string $rawText, array $responsibilities): bool
    {
        return collect($responsibilities)->contains(fn (string $responsibility): bool => $this->evidenceMatches($rawText, $responsibility));
    }

    private function boundedByEvidence(mixed $providerConfidence, float $derivedConfidence): float
    {
        $derivedConfidence = max(0.0, min(1.0, $derivedConfidence));
        if (! is_numeric($providerConfidence)) {
            return $derivedConfidence;
        }

        return min(max(0.0, min(1.0, (float) $providerConfidence)), $derivedConfidence);
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
