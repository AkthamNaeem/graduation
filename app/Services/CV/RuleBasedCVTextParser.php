<?php

namespace App\Services\CV;

use App\Contracts\CV\CVTextParser;
use App\Models\Skill;
use Illuminate\Support\Collection;

class RuleBasedCVTextParser implements CVTextParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $rawText): array
    {
        $text = $this->normalizeText($rawText);

        return [
            'full_name' => null,
            'email' => $this->extractEmail($text),
            'phone' => $this->extractPhone($text),
            'location' => null,
            'birth_date' => null,
            'nationality' => $this->extractLabeledValue($text, ['nationality']),
            'marital_status' => $this->extractLabeledValue($text, ['marital status', 'civil status']),
            'summary' => null,
            'skills' => $this->extractSkills($text),
            'experience' => $this->extractExperience($text),
            'education' => $this->extractEducation($text),
            'certifications' => $this->extractCertifications($text),
            'languages' => [],
            '_meta' => [
                'parser_driver' => 'rules',
                'fallback_used' => false,
                'schema_version' => '1.0',
            ],
        ];
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;

        return trim($text);
    }

    private function extractEmail(string $text): ?string
    {
        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);

        return $matches[0] ?? null;
    }

    private function extractPhone(string $text): ?string
    {
        preg_match('/(?<!\w)(?:\+?\d[\d\s().-]{7,}\d)(?!\w)/', $text, $matches);

        return isset($matches[0])
            ? trim(preg_replace('/\s+/', ' ', $matches[0]) ?? $matches[0])
            : null;
    }

    /** @param array<int, string> $labels */
    private function extractLabeledValue(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            if (preg_match('/^'.preg_quote($label, '/').'\s*:\s*(?<value>[^\n]+)$/imu', $text, $matches) === 1) {
                return trim($matches['value']);
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function extractCertifications(string $text): array
    {
        $lines = $this->sectionLines(
            $text,
            ['certifications', 'certificates', 'licenses', 'courses', 'training', 'professional training'],
            ['experience', 'work experience', 'education', 'skills', 'projects', 'languages', 'personal information'],
        );
        $result = [];
        $pendingYear = null;

        foreach ($lines as $line) {
            $line = trim($line, "- \t");
            if (preg_match('/^(19|20)\d{2}$/D', $line) === 1) {
                $pendingYear = (int) $line;

                continue;
            }
            if ($line === '') {
                continue;
            }

            $result[] = [
                'name' => $line,
                'issuer' => null,
                'issue_year' => $pendingYear,
                'expiration_year' => null,
                'description' => null,
                'evidence' => $pendingYear === null ? $line : $pendingYear.' '.$line,
                'confidence_score' => 0.75,
            ];
            $pendingYear = null;
        }

        return $result;
    }

    /** @return array<int, string> */
    private function extractSkills(string $text): array
    {
        return Skill::query()
            ->orderBy('name')
            ->get(['name'])
            ->filter(fn (Skill $skill): bool => (bool) preg_match(
                '/(?<![A-Z0-9])'.preg_quote($skill->name, '/').'(?![A-Z0-9])/i',
                $text,
            ))
            ->pluck('name')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function extractExperience(string $text): array
    {
        return $this->sectionLines($text, ['experience', 'work experience', 'professional experience'], ['education', 'skills', 'projects', 'certifications'])
            ->map(fn (string $line): ?array => $this->parseExperienceLine($line))
            ->filter()
            ->values()
            ->take(5)
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function extractEducation(string $text): array
    {
        return $this->sectionLines($text, ['education', 'academic background'], ['experience', 'work experience', 'skills', 'projects', 'certifications'])
            ->map(fn (string $line): ?array => $this->parseEducationLine($line))
            ->filter()
            ->values()
            ->take(5)
            ->all();
    }

    /**
     * @param  array<int, string>  $starts
     * @param  array<int, string>  $stops
     * @return Collection<int, string>
     */
    private function sectionLines(string $text, array $starts, array $stops): Collection
    {
        $lines = collect(explode("\n", $text))->map(fn (string $line): string => trim($line))->filter()->values();
        $capturing = false;
        $matches = collect();

        foreach ($lines as $line) {
            $heading = strtolower(trim($line, " \t\n\r\0\x0B:"));
            if (in_array($heading, $starts, true)) {
                $capturing = true;

                continue;
            }
            if ($capturing && in_array($heading, $stops, true)) {
                break;
            }
            if ($capturing) {
                $matches->push($line);
            }
        }

        return $matches;
    }

    /** @return array<string, mixed>|null */
    private function parseExperienceLine(string $line): ?array
    {
        $line = trim($line, "- \t");
        $patterns = [
            '/^(?<title>[\pL\d .\/&+#-]{2,80})\s+(?:at|@)\s+(?<company>[\pL\d .,&+#-]{2,100})$/iu',
            '/^(?<title>[\pL\d .\/&+#-]{2,80})\s+-\s+(?<company>[\pL\d .,&+#-]{2,100})$/iu',
            '/^(?<title>[\pL\d .\/&+#-]{2,80}),\s+(?<company>[\pL\d .,&+#-]{2,100})$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                return [
                    'title' => trim($matches['title']),
                    'company_name' => trim($matches['company']),
                    'description' => $line,
                    'evidence' => $line,
                    'confidence_score' => 0.5,
                ];
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function parseEducationLine(string $line): ?array
    {
        $line = trim($line, "- \t");
        $degreePattern = '(Bachelor of Science|Bachelor of Arts|Master of Science|Master of Arts|Bachelor|Master|B\.?S\.?|M\.?S\.?|BSc|MSc|PhD|Diploma|Associate)';

        if (preg_match('/^(?<degree>'.$degreePattern.')(?:\s+in\s+(?<field>[\pL ]{2,80}))?,\s+(?<institution>[\pL\d .,&-]{2,120})$/iu', $line, $matches)) {
            return [
                'institution' => trim($matches['institution']),
                'degree' => trim($matches['degree']),
                'field_of_study' => isset($matches['field']) ? trim($matches['field']) : null,
                'description' => $line,
                'evidence' => $line,
                'confidence_score' => 0.5,
            ];
        }

        if (preg_match('/(?<institution>[\pL\d .,&-]*(University|College|Institute|School)[\pL\d .,&-]*)/iu', $line, $matches)) {
            return [
                'institution' => trim($matches['institution']),
                'degree' => preg_match('/'.$degreePattern.'/iu', $line, $degreeMatches) ? trim($degreeMatches[0]) : null,
                'field_of_study' => preg_match('/\bin\s+(?<field>[\pL ]{2,80})/iu', $line, $fieldMatches) ? trim($fieldMatches['field']) : null,
                'description' => $line,
                'evidence' => $line,
                'confidence_score' => 0.5,
            ];
        }

        return null;
    }
}
