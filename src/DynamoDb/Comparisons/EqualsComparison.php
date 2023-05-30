<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb\Comparisons;

class EqualsComparison extends Comparison
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
        return "#$this->fieldName = :$this->fieldName";
    }

    public function expressionAttributeName(): array
    {
        return ["#$this->fieldName" => $this->fieldName];
    }

    public function expressionAttributeValue(): array
    {
        return [":$this->fieldName" => $this->fieldValue];
    }

    public function compare($value1, $value2): bool
    {
        return $value1 === $value2;
    }
}
