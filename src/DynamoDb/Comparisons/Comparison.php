<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb\Comparisons;

abstract class Comparison
{
    abstract public function __toString(): string;
    abstract public function expressionAttributeName(): array;
    abstract public function expressionAttributeValue(): array;

    abstract public function compare($value1, $value2): bool;
}
