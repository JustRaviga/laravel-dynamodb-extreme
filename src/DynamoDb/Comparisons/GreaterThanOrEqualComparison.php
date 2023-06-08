<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\DynamoDb\Comparisons;

class GreaterThanOrEqualComparison extends Comparison
{
    public function __toString(): string
    {
        return "{$this->fieldName} >= {$this->fieldValue}";
    }

    /**
     * @param array<string|number> $values
     */
    public function compare(array $values): bool
    {
        if (count($values) !== 2) {
            throw new \ValueError('Must have exactly 2 parameters to compare with ' . $this::class);
        }

        return $values[0] >= $values[1];
    }
}
