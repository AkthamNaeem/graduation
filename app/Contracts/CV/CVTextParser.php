<?php

namespace App\Contracts\CV;

interface CVTextParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $rawText): array;
}
