<?php

namespace Tests\Unit;

use App\Services\CVParsingService;
use PhpOffice\PhpWord\Element\Section;
use Tests\Support\SyntheticDocx;
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
        $text = $this->extractDocx(fn (Section $section) => $section->addText('Bachelor&#039;s degree &amp; “software”'));

        $this->assertSame('Bachelor\'s degree & "software"', $text);
    }

    public function test_docx_preserves_an_email_stored_as_normal_text(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addText('Email: normal@example.com'));

        $this->assertSame('Email: normal@example.com', $text);
    }

    public function test_docx_uses_a_links_displayed_email_once(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addLink('mailto:linked@example.com', 'linked@example.com'));

        $this->assertSame('linked@example.com', $text);
    }

    public function test_docx_recovers_email_from_a_blank_mailto_link(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addLink('mailto:source@example.com', ''));

        $this->assertSame('source@example.com', $text);
    }

    public function test_docx_keeps_text_and_link_order_inside_a_text_run(): void
    {
        $text = $this->extractDocx(function (Section $section): void {
            $run = $section->addTextRun();
            $run->addText('Email: ');
            $run->addLink('mailto:ordered@example.com', 'ordered@example.com');
        });

        $this->assertSame('Email: ordered@example.com', $text);
    }

    public function test_docx_extracts_a_link_inside_a_table_cell(): void
    {
        $text = $this->extractDocx(function (Section $section): void {
            $table = $section->addTable();
            $table->addRow();
            $run = $table->addCell()->addTextRun();
            $run->addText('Email: ');
            $run->addLink('mailto:table@example.com', 'table@example.com');
        });

        $this->assertSame('Email: table@example.com', $text);
    }

    public function test_docx_decodes_a_url_encoded_mailto_email(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addLink('mailto:encoded%2Ecandidate%40example%2Ecom', ''));

        $this->assertSame('encoded.candidate@example.com', $text);
    }

    public function test_docx_removes_mailto_query_parameters(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addLink('mailto:query@example.com?subject=Hello&body=Text', ''));

        $this->assertSame('query@example.com', $text);
    }

    public function test_docx_rejects_an_invalid_mailto_link(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addLink('mailto:not-an-email', ''));

        $this->assertSame('', $text);
    }

    public function test_docx_keeps_display_text_for_a_normal_link(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addLink('https://example.com/profile', 'Professional profile'));

        $this->assertSame('Professional profile', $text);
    }

    public function test_docx_keeps_a_safe_normal_url_without_treating_it_as_email(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addLink('https://example.com/profile', ''));

        $this->assertSame('https://example.com/profile', $text);
        $this->assertDoesNotMatchRegularExpression('/\S+@\S+/', $text);
    }

    public function test_docx_does_not_duplicate_an_email_from_adjacent_text_and_link(): void
    {
        $text = $this->extractDocx(function (Section $section): void {
            $run = $section->addTextRun();
            $run->addText('Email: duplicate@example.com');
            $run->addLink('mailto:duplicate@example.com', 'duplicate@example.com');
        });

        $this->assertSame('Email: duplicate@example.com', $text);
    }

    public function test_docx_without_email_does_not_invent_one(): void
    {
        $text = $this->extractDocx(fn (Section $section) => $section->addText('Candidate without contact details'));

        $this->assertSame('Candidate without contact details', $text);
        $this->assertDoesNotMatchRegularExpression('/\S+@\S+/', $text);
    }

    public function test_docx_preserves_the_full_text_run_element_order(): void
    {
        $text = $this->extractDocx(function (Section $section): void {
            $run = $section->addTextRun();
            $run->addText('Before ');
            $run->addLink('https://example.com', 'middle');
            $run->addText(' after');
        });

        $this->assertSame('Before middle after', $text);
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

    /** @param callable(Section): void $build */
    private function extractDocx(callable $build): string
    {
        $path = tempnam(sys_get_temp_dir(), 'synthetic-cv-');
        $this->assertNotFalse($path);
        $docxPath = $path.'.docx';
        rename($path, $docxPath);
        file_put_contents($docxPath, SyntheticDocx::make($build));

        try {
            return $this->app->make(CVParsingService::class)->extractText($docxPath);
        } finally {
            @unlink($docxPath);
        }
    }
}
