<?php

namespace App\Enums;

enum ProfileAttentionType: string
{
    case CV_PROCESSING = 'cv_processing';
    case CV_PROCESSING_FAILED = 'cv_processing_failed';
    case CV_FIRST_REVIEW_REQUIRED = 'cv_first_review_required';
    case CV_DIFFERENCES_REVIEW_REQUIRED = 'cv_differences_review_required';
    case CV_FINAL_CONFIRMATION_REQUIRED = 'cv_final_confirmation_required';
    case PROFILE_INCOMPLETE = 'profile_incomplete';

    public function priority(): int
    {
        return match ($this) {
            self::CV_PROCESSING_FAILED => 110,
            self::CV_DIFFERENCES_REVIEW_REQUIRED => 100,
            self::CV_FIRST_REVIEW_REQUIRED => 95,
            self::CV_FINAL_CONFIRMATION_REQUIRED => 92,
            self::CV_PROCESSING => 90,
            self::PROFILE_INCOMPLETE => 40,
        };
    }

    public function severity(): ProfileAttentionSeverity
    {
        return match ($this) {
            self::CV_PROCESSING_FAILED => ProfileAttentionSeverity::ERROR,
            self::CV_DIFFERENCES_REVIEW_REQUIRED,
            self::CV_FIRST_REVIEW_REQUIRED,
            self::CV_FINAL_CONFIRMATION_REQUIRED => ProfileAttentionSeverity::WARNING,
            self::CV_PROCESSING,
            self::PROFILE_INCOMPLETE => ProfileAttentionSeverity::INFO,
        };
    }
}
