<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb\Comparisons;

class BeginsWithComparison extends Comparison
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
        return "begins_with(#$this->fieldName, :$this->fieldName)";
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
        return mb_substr($value1, 0, mb_strlen($value2)) === $value2;
    }
}
