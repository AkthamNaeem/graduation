<?php

namespace App\Events;

class ApplicationInformationResponded
{
    public function __construct(public readonly int $requestId) {}
}
