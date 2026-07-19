<?php

namespace App\Services;

use App\Contracts\CV\CVTextParser;
use App\Services\CV\CVParsedDataNormalizer;
use App\Services\CV\EmailAddressExtractor;
use App\Services\CV\ExtractedTextNormalizer;
use App\Services\CV\PDFEmailRecoveryService;
use InvalidArgumentException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class CVParsingService
{
    public function __construct(
        private readonly CVTextParser $textParser,
        private readonly CVParsedDataNormalizer $normalizer,
        private readonly ExtractedTextNormalizer $textNormalizer,
        private readonly PDFEmailRecoveryService $pdfEmailRecovery,
        private readonly EmailAddressExtractor $emailExtractor,
    ) {}

    public function extractText(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $text = match ($extension) {
            'pdf' => $this->extractPdfText($filePath),
            'docx' => $this->extractDocxText($filePath),
            default => throw new InvalidArgumentException('Unsupported CV file type.'),
        };

        return $this->textNormalizer->normalize($text);
    }

    /** @return array<string, mixed> */
    public function parseText(string $text): array
    {
        return $this->normalizer->normalize($this->textParser->parse($text), $text);
    }

    private function extractPdfText(string $filePath): string
    {
        $document = (new PdfParser)->parseFile($filePath);
        $text = $this->textNormalizer->normalize($document->getText());
        $email = $this->pdfEmailRecovery->recover($text, $document);

        return $email === null ? $text : $this->pdfEmailRecovery->insertAfterEmptyLabel($text, $email);
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

        return implode(PHP_EOL, array_filter($lines, fn (string $line): bool => trim($line) !== ''));
    }

    /** @param array<int, string> $lines */
    private function appendElementText(mixed $element, array &$lines): void
    {
        if (method_exists($element, 'getRows')) {
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    $text = $this->extractInlineText($cell);
                    if (trim($text) !== '') {
                        $lines[] = $text;
                    }
                }
            }

            return;
        }

        $text = $this->extractInlineText($element);
        if (trim($text) !== '') {
            $lines[] = $text;
        }
    }

    private function extractInlineText(mixed $element): string
    {
        if ($element instanceof Text) {
            return (string) $element->getText();
        }

        if ($element instanceof Link) {
            $displayText = trim((string) $element->getText());
            if ($displayText !== '') {
                return $displayText;
            }

            $source = trim((string) $element->getSource());
            $email = $this->emailExtractor->extractFromMailto($source);
            if ($email !== null) {
                return $email;
            }

            return $this->isSafeUrl($source) ? $source : '';
        }

        if ($element instanceof AbstractContainer) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text = $this->appendInlinePart($text, $this->extractInlineText($child));
            }

            return $text;
        }

        return '';
    }

    private function appendInlinePart(string $text, string $part): string
    {
        if ($part === '') {
            return $text;
        }

        $partEmail = $this->emailExtractor->extractFromText($part);
        if ($partEmail === null) {
            return $text.$part;
        }

        if (str_ends_with(strtolower(rtrim($text)), strtolower($partEmail))
            && preg_match('/^\h*'.preg_quote($partEmail, '/').'/iu', $part) === 1) {
            $part = preg_replace('/^\h*'.preg_quote($partEmail, '/').'/iu', '', $part, 1) ?? $part;
        }

        return $text.$part;
    }

    private function isSafeUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
