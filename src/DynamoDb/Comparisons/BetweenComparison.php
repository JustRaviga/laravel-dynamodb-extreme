<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb\Comparisons;

class BetweenComparison extends Comparison
{
    protected string $fieldName;
    protected string $fieldLowerValue;
    protected string $fieldHigherValue;

    public function __construct($fieldName, $fieldLowerValue, $fieldHigherValue)
    {
        $this->fieldName = $fieldName;
        $this->fieldLowerValue = $fieldLowerValue;
        $this->fieldHigherValue = $fieldHigherValue;
    }

    public function __toString(): string
    {
        return "#$this->fieldName BETWEEN :{$this->fieldName}1 AND :{$this->fieldName}2)";
    }

    public function expressionAttributeName(): array
    {
        return ["#$this->fieldName" => $this->fieldName];
    }

    public function expressionAttributeValue(): array
    {
        return [
            ":{$this->fieldName}1" => $this->fieldLowerValue,
            ":{$this->fieldName}2" => $this->fieldHigherValue
        ];
    }
}
