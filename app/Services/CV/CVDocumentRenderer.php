<?php

namespace App\Services\CV;

use Dompdf\Dompdf;
use Dompdf\Options;

class CVDocumentRenderer
{
    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setTempDir(sys_get_temp_dir());

        $renderer = new Dompdf($options);
        $renderer->loadHtml(view('cv.document', [
            'cv' => $data,
            'direction' => app()->getLocale() === 'ar' ? 'rtl' : 'ltr',
        ])->render(), 'UTF-8');
        $renderer->setPaper('a4', 'portrait');
        $renderer->render();

        return $renderer->output();
    }
}
