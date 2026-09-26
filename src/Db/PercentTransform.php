<?php

namespace FA\GraphQL\Db;

use Anorm\TransformInterface;

/**
 * FrontAccounting stores discounts as fractions (0.1); the API shows percentages
 * (10), everywhere the same (Release 2 spec section 4.3).
 */
final class PercentTransform implements TransformInterface
{
    public function txDatabaseToModel($value)
    {
        return $value === null ? null : round((float) $value * 100, 6);
    }

    public function txModelToDatabase($value)
    {
        return $value === null ? null : (float) $value / 100;
    }
}
