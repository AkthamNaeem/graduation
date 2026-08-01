<?php

namespace App\Enums;

enum ProfileAttentionAction: string
{
    case UPLOAD_CV = 'upload_cv';
    case REVIEW_EXTRACTED_CV = 'review_extracted_cv';
    case REVIEW_CV_CHANGES = 'review_cv_changes';
    case CONFIRM_CV_REVIEW = 'confirm_cv_review';
    case COMPLETE_PROFILE = 'complete_profile';
}
