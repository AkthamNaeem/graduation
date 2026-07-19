<?php

namespace Tests\Support;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;

class SyntheticDocx
{
    /** @param null|callable(Section): void $build */
    public static function make(?callable $build = null): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        if ($build !== null) {
            $build($section);
        } else {
            $section->addText('Synthetic Candidate');
            $section->addText('Phone: +000000000');
            $email = $section->addTextRun();
            $email->addText('Email: ');
            $email->addLink('mailto:synthetic@example.com', 'synthetic@example.com');
            $section->addText('Experience');
            $section->addText('Backend Developer');
            $section->addText('Example Company');
            $section->addText('2024 - Present');
            $section->addText('CERTIFICATIONS');
            $section->addText('2024');
            $section->addText('First Aid');
            $section->addText('PERSONAL INFORMATION');
            $section->addText('Nationality: Example Nationality');
            $section->addText('Marital Status: Single');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'synthetic-docx-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary DOCX fixture.');
        }

        $path = $temporary.'.docx';
        rename($temporary, $path);

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Unable to read the temporary DOCX fixture.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }
}
