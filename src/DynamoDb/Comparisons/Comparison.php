<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\DynamoDb\Comparisons;

abstract class Comparison
{
    protected string $fieldName;
    protected string $fieldValue;

    public function __construct(string $fieldName, string|int|float|null $fieldValue)
    {
        $this->fieldName = $fieldName;
        $this->fieldValue = $fieldValue;
    }

    abstract public function __toString(): string;

    /**
     * @param array<string|number> $values
     */
    abstract public function compare(array $values): bool;

    /**
     * @return array<string,string>
     */
    public function expressionAttributeName(): array
    {
        return ["#{$this->fieldName}" => $this->fieldName];
    }

    /**
     * @return array<string,string|number>
     */
    public function expressionAttributeValue(): array
    {
        return [":{$this->fieldName}" => $this->fieldValue];
    }
}
