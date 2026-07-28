<?php

namespace App\Exceptions\Recommendation;

use RuntimeException;

final class RecommendationReconciliationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('ML_RECONCILIATION_FAILURE');
    }
}
