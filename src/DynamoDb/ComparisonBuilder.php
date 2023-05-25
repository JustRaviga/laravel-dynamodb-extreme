<?php

namespace ClassManager\DynamoDb\DynamoDb;

use ClassManager\DynamoDb\DynamoDb\Comparisons\BeginsWithComparison;
use ClassManager\DynamoDb\DynamoDb\Comparisons\BetweenComparison;
use ClassManager\DynamoDb\DynamoDb\Comparisons\Comparison;
use ClassManager\DynamoDb\DynamoDb\Comparisons\EqualsComparison;
use ClassManager\DynamoDb\DynamoDb\Comparisons\GreaterThanComparison;
use ClassManager\DynamoDb\DynamoDb\Comparisons\GreaterThanOrEqualComparison;
use ClassManager\DynamoDb\DynamoDb\Comparisons\LessThanComparison;
use ClassManager\DynamoDb\DynamoDb\Comparisons\LessThanOrEqualComparison;
use ClassManager\DynamoDb\Exceptions\QueryBuilderInvalidQueryException;

class ComparisonBuilder
{
    public static function fromArray(array $props): Comparison
    {
        // basic case of ->where('field', 'value')
        if (count($props) === 2) {
            return new EqualsComparison(...$props);
        }

        // special case of ->where('field', 'between', 'first_value', 'second_value')
        if (count($props) === 4) {
            return new BetweenComparison($props[0], $props[2], $props[3]);
        }

        // all other cases like ->where('field', '>', 'value')
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

        throw new QueryBuilderInvalidQueryException('Invalid comparison values passed to query builder');
    }
}
