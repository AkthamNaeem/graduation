<?php

namespace App\Services;

use App\Contracts\CV\CVTextParser;
use App\Services\CV\CVParsedDataNormalizer;
use InvalidArgumentException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class CVParsingService
{
    public function __construct(
        private readonly CVTextParser $textParser,
        private readonly CVParsedDataNormalizer $normalizer,
    ) {}

    public function extractText(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => $this->extractPdfText($filePath),
            'docx' => $this->extractDocxText($filePath),
            default => throw new InvalidArgumentException('Unsupported CV file type.'),
        };
    }

    /** @return array<string, mixed> */
    public function parseText(string $text): array
    {
        return $this->normalizer->normalize($this->textParser->parse($text), $text);
    }

    private function extractPdfText(string $filePath): string
    {
        return (new PdfParser)->parseFile($filePath)->getText();
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

    /** @param array<int, string> $lines */
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
}
