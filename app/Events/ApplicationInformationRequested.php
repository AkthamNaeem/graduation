<?php

namespace App\Events;

class ApplicationInformationRequested
{
    public function __construct(public readonly int $requestId) {}
}
