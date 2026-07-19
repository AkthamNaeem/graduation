<?php

namespace App\Services\CV;

class EmailAddressExtractor
{
    private const EMAIL_PATTERN = '/[a-z0-9.!#$%&\'*+\/=\?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+/iu';

    public function extractFromText(string $value): ?string
    {
        if (preg_match(self::EMAIL_PATTERN, $value, $matches) !== 1) {
            return null;
        }

        return $this->isValid($matches[0]) ? $matches[0] : null;
    }

    public function extractFromMailto(string $value): ?string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (stripos($value, 'mailto:') !== 0) {
            return null;
        }

        $candidate = explode('?', substr($value, strlen('mailto:')), 2)[0];
        $candidate = trim(rawurldecode($candidate));

        return $this->isValid($candidate) ? $candidate : null;
    }

    public function isValid(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
