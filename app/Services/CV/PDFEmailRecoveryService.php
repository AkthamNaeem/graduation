<?php

namespace App\Services\CV;

use Smalot\PdfParser\Document;
use Throwable;

class PDFEmailRecoveryService
{
    public function recover(string $text, Document $document): ?string
    {
        $email = $this->emailFromString($text);
        if ($email !== null) {
            return $email;
        }

        if (preg_match('/\b(?:e-?mail|mail)\s*:/iu', $text) !== 1) {
            return null;
        }

        foreach ($document->getObjects() as $object) {
            try {
                $sources = [$object->getDetails(), $object->getContent()];
            } catch (Throwable) {
                continue;
            }

            foreach ($sources as $source) {
                $email = $this->emailFromMailtoValue($source);
                if ($email !== null) {
                    return $email;
                }
            }
        }

        return null;
    }

    public function insertAfterEmptyLabel(string $text, string $email): string
    {
        if (! $this->isValidEmail($email) || $this->emailFromString($text) !== null) {
            return $text;
        }

        return preg_replace(
            '/\b((?:e-?mail|mail)\s*:)[\h]*(?=\R|$)/iu',
            '$1 '.$email,
            $text,
            1,
        ) ?? $text;
    }

    private function emailFromMailtoValue(mixed $value, int $depth = 0): ?string
    {
        if ($depth > 8) {
            return null;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $email = $this->emailFromMailtoValue($item, $depth + 1);
                if ($email !== null) {
                    return $email;
                }
            }

            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/mailto:([^?\s<>()]+)/iu', $value, $matches) !== 1) {
            return null;
        }

        $candidate = rawurldecode($matches[1]);

        return $this->isValidEmail($candidate) ? $candidate : null;
    }

    private function emailFromString(string $value): ?string
    {
        if (preg_match('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+/iu', $value, $matches) !== 1) {
            return null;
        }

        return $this->isValidEmail($matches[0]) ? $matches[0] : null;
    }

    private function isValidEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
