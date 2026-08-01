<?php

namespace App\Enums;

enum CandidateCVStage: string
{
    case PROCESSING = 'processing';
    case FIRST_REVIEW = 'first_review';
    case DIFFERENCES_REVIEW = 'differences_review';
    case FINAL_CONFIRMATION = 'final_confirmation';
    case CONFIRMED = 'confirmed';
    case FAILED = 'failed';
}
