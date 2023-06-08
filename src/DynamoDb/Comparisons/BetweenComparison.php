<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\DynamoDb\Comparisons;

class BetweenComparison extends Comparison
{
    protected string $fieldLowerValue;
    protected string $fieldHigherValue;

    public function __construct(string $fieldName, string|int|float $fieldLowerValue, string|int|float $fieldHigherValue)
    {
        // fieldValue is not needed for a Between Comparison
        parent::__construct($fieldName, null);
        $this->fieldLowerValue = $fieldLowerValue;
        $this->fieldHigherValue = $fieldHigherValue;
    }

    public function __toString(): string
    {
        return "#{$this->fieldName} BETWEEN :{$this->fieldName}1 AND :{$this->fieldName}2)";
    }

    /**
     * @return array<string,string|number>
     */
    public function expressionAttributeValue(): array
    {
        return [
            ":{$this->fieldName}1" => $this->fieldLowerValue,
            ":{$this->fieldName}2" => $this->fieldHigherValue
        ];
    }
    /**
     * @param array<string|number> $values
     */
    public function compare(array $values): bool
    {
        if (count($values) !== 3) {
            throw new \ValueError('Must have exactly 3 parameters to compare with ' . $this::class);
        }

        return $values[0] > $values[1] && $values[0] < $values[2];
    }
}
