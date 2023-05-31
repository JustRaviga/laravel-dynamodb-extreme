<?php

namespace ClassManager\DynamoDb\Models;

use ClassManager\DynamoDb\Exceptions\InvalidInlineModel;
use Illuminate\Support\Collection;

class DynamoDbInlineModel extends DynamoDbModel
{

    /**
     * @return array<string,string>
     */
    protected function buildInlineExpressionAttributeNames(Collection $attributes): array
    {
        return $attributes->mapWithKeys(
            fn ($value, $key) => ["#{$key}" => $value]
        )->toArray();
    }

    protected function buildInlineSetExpression(): string
    {
        return "SET #{$this->fieldName()}.#{$this->uniqueKeyName()} = :value";
    }

    protected function buildInlineUpdateExpression(Collection $attributes): string
    {
        return "SET " . $attributes->mapWithKeys(function ($_, $key) {
                return [$key => "#{$this->fieldName()}.#{$this->uniqueKeyName()}.#{$key} = :{$key}"];
            })->implode(', ');
    }

    /**
     * For inline relations, this specifies the attribute name in Dynamo where the data for this object can be found.
     */
    public function fieldName(): string
    {
        return '';
    }

    public function saveInlineRelation(): static
    {
        $fieldName = $this->fieldName();

        if ($fieldName === '') {
            throw new InvalidInlineModel($this);
        }

        $attributes = collect($this->unFill());

        // Extract the partition and sort keys
        $partitionKeyName = $this->partitionKey();
        $partitionKeyValue = $attributes[$partitionKeyName];
        $sortKeyName = $this->sortKey();
        $sortKeyValue = $attributes[$sortKeyName];

        // Remove the partition and sort keys from the data we're about to save
        $attributes = $attributes->mapWithKeys(
            fn ($value, $key) => $key === $partitionKeyName || $key === $sortKeyName || $key === $this->uniqueKeyName()
                ? [$key => null]
                : [$key => $value]
        )->filter(fn ($attr) => $attr !== null);

        $client = self::client();

        $attributes = collect(['value' => $attributes]);

        $names = [
            $this->fieldName() => $this->fieldName(),
            $this->uniqueKeyName() => $this->uniqueKey(),
        ];

        $query = [
            'TableName' => $this->table(),
            'ConsistentRead' => $this->consistentRead(),
            'Key' => $client->marshalItem([
                $partitionKeyName => $partitionKeyValue,
                $sortKeyName => $sortKeyValue,
            ]),
            'UpdateExpression' => $this->buildInlineSetExpression($attributes),
            'ExpressionAttributeNames' => $this->buildInlineExpressionAttributeNames(collect($names)),
            'ExpressionAttributeValues' => $this->buildExpressionAttributeValues($attributes),
        ];
        $client->updateItem($query);

        return $this;
    }

    public function updateInlineRelation(): static
    {
        $fieldName = $this->fieldName();

        if ($fieldName === '') {
            throw new InvalidInlineModel($this);
        }

        $attributes = collect($this->unFill());

        // Extract the partition and sort keys
        $partitionKeyName = $this->partitionKey();
        $partitionKeyValue = $attributes[$partitionKeyName];
        $sortKeyName = $this->sortKey();
        $sortKeyValue = $attributes[$sortKeyName];

        // Remove the partition, sort keys, and the unique key from the data we're about to save
        $attributes = $attributes->mapWithKeys(
            fn ($value, $key) => $key === $partitionKeyName || $key === $sortKeyName || $key === $this->uniqueKeyName()
                ? [$key => null]
                : [$key => $value]
        )->filter(fn ($attr) => $attr !== null);

        $client = self::client();

        $names = [
            $this->fieldName() => $this->fieldName(),
            $this->uniqueKeyName() => $this->uniqueKey(),
            ...$attributes->mapWithKeys(fn ($_, $key) => [$key => $key]),
        ];

        $updateExpression = $this->buildInlineUpdateExpression($attributes);
        $attributeNames = $this->buildInlineExpressionAttributeNames(collect($names));
        $attributeValues = $this->buildExpressionAttributeValues($attributes);

        $query = [
            'TableName' => $this->table(),
            'ConsistentRead' => $this->consistentRead(),
            'Key' => $client->marshalItem([
                $partitionKeyName => $partitionKeyValue,
                $sortKeyName => $sortKeyValue,
            ]),
            'UpdateExpression' => $updateExpression,
            'ExpressionAttributeNames' => $attributeNames,
            'ExpressionAttributeValues' => $attributeValues,
        ];
        $client->updateItem($query);

        return $this;
    }
}
