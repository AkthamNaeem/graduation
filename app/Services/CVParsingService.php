<?php

namespace App\Services;

use App\Models\Skill;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class CVParsingService
{
    public function extractText(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => $this->extractPdfText($filePath),
            'docx' => $this->extractDocxText($filePath),
            default => throw new InvalidArgumentException('Unsupported CV file type.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function parseText(string $text): array
    {
        $normalizedText = $this->normalizeText($text);

        return [
            'email' => $this->extractEmail($normalizedText),
            'phone' => $this->extractPhone($normalizedText),
            'skills' => $this->extractSkills($normalizedText),
            'experience' => $this->extractExperience($normalizedText),
            'education' => $this->extractEducation($normalizedText),
        ];
    }

    private function extractPdfText(string $filePath): string
    {
        return (new PdfParser())->parseFile($filePath)->getText();
    }

    private function extractDocxText(string $filePath): string
    {
        $phpWord = IOFactory::load($filePath);
        $lines = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->appendElementText($element, $lines);
            }
        }

        return implode(PHP_EOL, array_filter($lines));
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function appendElementText(mixed $element, array &$lines): void
    {
        if ($element instanceof Text) {
            $lines[] = $element->getText();

            return;
        }

        if ($element instanceof TextRun || $element instanceof AbstractContainer) {
            $textParts = [];

            foreach ($element->getElements() as $child) {
                if ($child instanceof Text) {
                    $textParts[] = $child->getText();
                } else {
                    $this->appendElementText($child, $lines);
                }
            }

            if ($textParts !== []) {
                $lines[] = implode('', $textParts);
            }

            return;
        }

        if (method_exists($element, 'getRows')) {
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $child) {
                        $this->appendElementText($child, $lines);
                    }
                }
            }
        }
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

    /**
     * @return array<int, string>
     */
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

    /**
     * @return array<int, array<string, string|null>>
     */
    private function extractExperience(string $text): array
    {
        return $this->sectionLines($text, ['experience', 'work experience', 'professional experience'], ['education', 'skills', 'projects', 'certifications'])
            ->map(fn (string $line): ?array => $this->parseExperienceLine($line))
            ->filter()
            ->values()
            ->take(5)
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
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
        $lines = collect(explode("\n", $text))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values();

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

    /**
     * @return array<string, string|null>|null
     */
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
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, string|null>|null
     */
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
            ];
        }

        if (preg_match('/(?<institution>[\pL\d .,&-]*(University|College|Institute|School)[\pL\d .,&-]*)/iu', $line, $matches)) {
            return [
                'institution' => trim($matches['institution']),
                'degree' => preg_match('/'.$degreePattern.'/iu', $line, $degreeMatches) ? trim($degreeMatches[0]) : null,
                'field_of_study' => preg_match('/\bin\s+(?<field>[\pL ]{2,80})/iu', $line, $fieldMatches) ? trim($fieldMatches['field']) : null,
                'description' => $line,
            ];
        }

        return null;
    }
}
