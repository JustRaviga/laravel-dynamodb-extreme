<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb\Comparisons;

class LessThanOrEqualComparison extends Comparison
{
    protected string $fieldName;
    protected string $fieldValue;

    public function __construct($fieldName, $fieldValue)
    {
        $this->fieldName = $fieldName;
        $this->fieldValue = $fieldValue;
    }

    public function __toString(): string
    {
        return "$this->fieldName <= $this->fieldValue";
    }
}
