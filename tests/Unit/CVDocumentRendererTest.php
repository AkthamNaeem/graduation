<?php

namespace Tests\Unit;

use App\Services\CV\CVDocumentRenderer;
use Tests\TestCase;

class CVDocumentRendererTest extends TestCase
{
    public function test_renderer_produces_a_pdf_from_normalized_structured_data(): void
    {
        $pdf = app(CVDocumentRenderer::class)->render([
            'name' => 'Renderer Candidate',
            'headline' => 'Backend Engineer',
            'summary' => 'Builds reliable APIs.',
            'contact' => ['email' => 'candidate@example.com', 'phone' => null, 'location' => 'Damascus'],
            'links' => [],
            'experiences' => [],
            'education' => [],
            'skills' => ['Laravel'],
        ]);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(500, strlen($pdf));
    }
}
