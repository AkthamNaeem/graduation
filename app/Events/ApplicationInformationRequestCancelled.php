<?php

namespace App\Events;

class ApplicationInformationRequestCancelled
{
    public function __construct(public readonly int $requestId) {}
}
