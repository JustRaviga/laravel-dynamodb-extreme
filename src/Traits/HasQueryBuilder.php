<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Traits;

use ClassManager\DynamoDb\DynamoDbQueryBuilder;
use ClassManager\DynamoDb\Exceptions\InvalidRelation;
use ClassManager\DynamoDb\Models\DynamoDbModel;

trait HasQueryBuilder
{
    public static function query(): DynamoDbQueryBuilder
    {
        $model = new static();

        // Because we're in a trait, we can't know 100% that the model we're attached to is actually a DynamoDbModel
        assert($model instanceof DynamoDbModel);

        return (new DynamoDbQueryBuilder())->model($model)->table($model->table());
    }

    public static function withIndex(string $indexName): DynamoDbQueryBuilder
    {
        return self::query()->withIndex($indexName);
    }

    public static function where(...$props): DynamoDbQueryBuilder
    {
        return self::query()->where(...$props);
    }

    public static function withRelation(string $relation): DynamoDbQueryBuilder
    {
        return self::query()->withRelation($relation);
    }
}
