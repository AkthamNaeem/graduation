<?php

namespace App\Events;

class ApplicationSubmitted
{
    public function __construct(
        public readonly int $jobApplicationId,
    ) {}
}
