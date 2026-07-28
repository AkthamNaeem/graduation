<?php

namespace App\Exceptions\Recommendation;

use RuntimeException;

final class RecommendationMappingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('ML_MAPPING_FAILURE');
    }
}
