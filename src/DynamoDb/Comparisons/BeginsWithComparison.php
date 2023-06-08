<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\DynamoDb\Comparisons;

class BeginsWithComparison extends Comparison
{
    public function __toString(): string
    {
        return "begins_with(#{$this->fieldName}, :{$this->fieldName})";
    }

    /**
     * @param array<string> $values
     */
    public function compare(array $values): bool
    {
        if (count($values) !== 2) {
            throw new \ValueError('Must have exactly 2 parameters to compare with ' . $this::class);
        }

        return mb_substr($values[0], 0, mb_strlen($values[1])) === $values[1];
    }
}
