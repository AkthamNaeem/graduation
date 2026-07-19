<?php

namespace Tests\Unit;

use App\Services\CVParsingService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\SyntheticPdf;
use Tests\TestCase;

class CVParsingExtractionTest extends TestCase
{
    public function test_pdf_text_preserves_a_natural_email(): void
    {
        $text = $this->extractPdf(['Email:', 'candidate@example.com']);

        $this->assertStringContainsString('candidate@example.com', $text);
    }

    public function test_pdf_recovers_a_missing_email_from_mailto_annotation(): void
    {
        $text = $this->extractPdf(['Email:', 'Skills'], 'mailto:linked.candidate@example.com');

        $this->assertStringContainsString('Email: linked.candidate@example.com', $text);
    }

    public function test_pdf_without_email_or_annotation_does_not_invent_one(): void
    {
        $text = $this->extractPdf(['Email:', 'Skills']);

        $this->assertDoesNotMatchRegularExpression('/\S+@\S+/', $text);
        $this->assertSame('Email:', strtok($text, "\n"));
    }

    public function test_invalid_mailto_annotation_is_not_accepted(): void
    {
        $text = $this->extractPdf(['Email:', 'Skills'], 'mailto:not-an-email');

        $this->assertDoesNotMatchRegularExpression('/\S+@\S+/', $text);
    }

    public function test_extracted_text_decodes_entities_and_normalizes_unicode_characters(): void
    {
        $text = $this->extractPdf([
            'Bachelor&#039;s degree',
            'CMS customization &amp; plugin development',
            '“Quoted” — value',
        ]);

        $this->assertStringContainsString("Bachelor's degree", $text);
        $this->assertStringContainsString('CMS customization & plugin development', $text);
        $this->assertStringContainsString('"Quoted" - value', $text);
    }

    public function test_docx_uses_the_same_entity_and_unicode_normalization(): void
    {
        $phpWord = new PhpWord;
        $phpWord->addSection()->addText('Bachelor&#039;s degree &amp; “software”');
        $path = tempnam(sys_get_temp_dir(), 'synthetic-docx-');
        $this->assertNotFalse($path);
        $docxPath = $path.'.docx';
        rename($path, $docxPath);
        IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);

        try {
            $text = $this->app->make(CVParsingService::class)->extractText($docxPath);
        } finally {
            @unlink($docxPath);
        }

        $this->assertSame('Bachelor\'s degree & "software"', $text);
    }

    /** @param array<int, string> $lines */
    private function extractPdf(array $lines, ?string $mailto = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'synthetic-cv-');
        $this->assertNotFalse($path);
        $pdfPath = $path.'.pdf';
        rename($path, $pdfPath);
        file_put_contents($pdfPath, SyntheticPdf::make($lines, $mailto));

        try {
            return $this->app->make(CVParsingService::class)->extractText($pdfPath);
        } finally {
            @unlink($pdfPath);
        }
    }
}
