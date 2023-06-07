<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\DynamoDb;

use JustRaviga\DynamoDb\DynamoDb\Comparisons\BeginsWithComparison;
use JustRaviga\DynamoDb\DynamoDb\Comparisons\BetweenComparison;
use JustRaviga\DynamoDb\DynamoDb\Comparisons\Comparison;
use JustRaviga\DynamoDb\DynamoDb\Comparisons\EqualsComparison;
use JustRaviga\DynamoDb\DynamoDb\Comparisons\GreaterThanComparison;
use JustRaviga\DynamoDb\DynamoDb\Comparisons\GreaterThanOrEqualComparison;
use JustRaviga\DynamoDb\DynamoDb\Comparisons\LessThanComparison;
use JustRaviga\DynamoDb\DynamoDb\Comparisons\LessThanOrEqualComparison;
use JustRaviga\DynamoDb\Exceptions\QueryBuilderInvalidQuery;

class ComparisonBuilder
{
    /**
     * @param array<string|int|float> $props
     */
    public static function fromArray(array $props): Comparison
    {
        // basic case of ->where('field', 'value')
        if (count($props) === 2) {
            return self::buildEqualsComparison($props);
        }

        // special case of ->where('field', 'between', 'first_value', 'second_value')
        if (count($props) === 4) {
            return self::buildBetweenComparison($props);
        }

        // all other cases like ->where('field', '>', 'value')
        return self::buildSimpleComparison($props);
    }

    /**
     * @param array<string|int|float> $props
     */
    private static function buildEqualsComparison(array $props): EqualsComparison
    {
        return new EqualsComparison(...$props);
    }

    /**
     * @param array<string|int|float> $props
     */
    private static function buildBetweenComparison(array $props): BetweenComparison
    {
        return new BetweenComparison($props[0], $props[2], $props[3]);
    }

    /**
     * @param array<string|int|float> $props
     */
    private static function buildSimpleComparison(array $props): Comparison
    {
        [ $fieldName, $comparisonOperator, $fieldValue ] = $props;

        switch($comparisonOperator) {
            case 'begins_with':
                return new BeginsWithComparison($fieldName, $fieldValue);
            case '<':
                return new LessThanComparison($fieldName, $fieldValue);
            case '<=':
                return new LessThanOrEqualComparison($fieldName, $fieldValue);
            case '>':
                return new GreaterThanComparison($fieldName, $fieldValue);
            case '>=':
                return new GreaterThanOrEqualComparison($fieldName, $fieldValue);
            case '=':
                return new EqualsComparison($fieldName, $fieldValue);
        }

        throw new QueryBuilderInvalidQuery('Invalid comparison values passed to query builder');
    }
}
