<?php

namespace App\Contracts\CVSummary;

interface CVSummaryClient
{
    /**
     * @param  array<string, mixed>  $source
     * @return array{data: array<string, mixed>, request_id: ?string}
     */
    public function generate(array $source, string $locale): array;

    public function provider(): string;

    public function model(): string;
}
