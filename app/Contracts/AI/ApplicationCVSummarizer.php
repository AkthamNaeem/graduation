<?php

namespace App\Contracts\AI;

interface ApplicationCVSummarizer
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{headline:string,summary:string,strengths:list<string>,gaps:list<string>,evidence:list<string>,provider:string,model:string,provider_request_id:?string}
     */
    public function summarize(array $context, string $locale): array;
}
