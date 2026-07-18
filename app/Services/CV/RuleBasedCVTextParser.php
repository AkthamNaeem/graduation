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
            'email' => $this->extractEmail($text),
            'phone' => $this->extractPhone($text),
            'skills' => $this->extractSkills($text),
            'experience' => $this->extractExperience($text),
            'education' => $this->extractEducation($text),
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
