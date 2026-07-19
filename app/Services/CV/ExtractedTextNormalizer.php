<?php

namespace App\Services\CV;

class ExtractedTextNormalizer
{
    public function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(
            ["\r\n", "\r", "\u{00A0}", "\u{2007}", "\u{202F}", "\u{2013}", "\u{2014}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}"],
            ["\n", "\n", ' ', ' ', ' ', '-', '-', "'", "'", '"', '"'],
            $text,
        );
        $text = preg_replace('/^[\h]*[\x{2022}\x{2023}\x{2043}\x{2219}\x{25AA}\x{25CF}\x{25E6}][\h]*/mu', '- ', $text) ?? $text;
        $text = preg_replace('/[\p{Z}\t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function canonicalizeForMatching(string $text): string
    {
        $text = mb_strtolower($this->normalize($text), 'UTF-8');
        $text = preg_replace('/\s*&\s*/u', ' and ', $text) ?? $text;
        $text = preg_replace('/[^\pL\pN+#]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
