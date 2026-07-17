<?php

namespace App\Events;

class ApplicationInformationRequestUpdated
{
    public function __construct(public readonly int $requestId) {}
}
