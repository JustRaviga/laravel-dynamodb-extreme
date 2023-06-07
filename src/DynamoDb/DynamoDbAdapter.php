<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\DynamoDb;

use JustRaviga\DynamoDb\Models\DynamoDbModel;
use JustRaviga\DynamoDb\Traits\UsesDynamoDbClient;

class DynamoDbAdapter
{
    use UsesDynamoDbClient;

    public function delete(DynamoDbModel $model, string $partitionKey, string $sortKey): void
    {
        $client = self::client();

        $client->deleteItem([
            'TableName' => $model::table(),
            'ConsistentRead' => $model::consistentRead(),
            'Key' => $client->marshalItem([
                $model::partitionKey() => $partitionKey,
                $model::sortKey() => $sortKey,
            ]),
        ]);
    }

    /**
     * @param class-string<DynamoDbModel> $model
     */
    public function get(string $model, string $partitionKey, string $sortKey): ?array
    {
        $client = self::client();

        $response = $client->getItem([
            'TableName' => $model::table(),
            'ConsistentRead' => $model::consistentRead(),
            'Key' => $client->marshalItem([
                $model::partitionKey() => $partitionKey,
                $model::sortKey() => $sortKey,
            ]),
        ]);

        if (!isset($response['Item'])) {
            return null;
        }

        return collect($response['Item'])->map(fn ($attribute)
            => $client->unmarshalValue($attribute)
        )->toArray();
    }

    public function save(DynamoDbModel $model, array $attributes): void
    {
        $client = self::client();

        $client->putItem([
            'TableName' => $model::table(),
            'ConsistentRead' => $model::consistentRead(),
            'Item' => $client->marshalItem($attributes),
        ]);
    }

    public function saveInlineRelation(DynamoDbModel $model, string $partitionKey, string $sortKey, array $attributes): void
    {
        $client = self::client();

        $attributes = ['value' => $attributes];

        $attrNames = [
            $model->fieldName() => $model->fieldName(),
            $model->uniqueKeyName() => $model->uniqueKey(),
        ];

        $client->updateItem([
            'TableName' => $model->table(),
            'ConsistentRead' => $model->consistentRead(),
            'Key' => $client->marshalItem([
                $model::partitionKey() => $partitionKey,
                $model::sortKey() => $sortKey,
            ]),
            'UpdateExpression' => $this->buildInlineSetExpression($model->fieldName(), $model->uniqueKeyName()),
            'ExpressionAttributeNames' => $this->buildExpressionAttributeNames($attrNames),
            'ExpressionAttributeValues' => $this->buildExpressionAttributeValues($attributes),
        ]);
    }

    public function update(DynamoDbModel $model, string $partitionKey, string $sortKey, array $attributes): void
    {
        $client = self::client();

        // [ $key => $key ]
        $attrNames = array_combine(array_keys($attributes), array_keys($attributes));

        $client->updateItem([
            'TableName' => $model::table(),
            'ConsistentRead' => $model::consistentRead(),
            'Key' => $client->marshalItem([
                $model::partitionKey() => $partitionKey,
                $model::sortKey() => $sortKey,
            ]),
            'UpdateExpression' => $this->buildUpdateExpression($attributes),
            'ExpressionAttributeNames' => $this->buildExpressionAttributeNames($attrNames),
            'ExpressionAttributeValues' => $this->buildExpressionAttributeValues($attributes),
        ]);
    }

    public function updateInlineRelation(DynamoDbModel $model, string $partitionKey, string $sortKey, array $attributes): void
    {
        $client = self::client();

        $attrNames = [];
        foreach (array_keys($attributes) as $key) {
            $attrNames[$key] = $key;
        }
        $attrNames[$model->fieldName()] = $model->fieldName();
        $attrNames[$model->uniqueKeyName()] = $model->uniqueKey();

        $client->updateItem([
            'TableName' => $model->table(),
            'ConsistentRead' => $model->consistentRead(),
            'Key' => $client->marshalItem([
                $model::partitionKey() => $partitionKey,
                $model::sortKey() => $sortKey,
            ]),
            'UpdateExpression' => $this->buildInlineUpdateExpression($model->fieldName(), $model->uniqueKeyName(), $attributes),
            'ExpressionAttributeNames' => $this->buildExpressionAttributeNames($attrNames),
            'ExpressionAttributeValues' => $this->buildExpressionAttributeValues($attributes),
        ]);
    }

    /**
     * @return array<string,string>
     */
    protected function buildExpressionAttributeNames(array $attributes): array
    {
        $names = [];
        foreach ($attributes as $key => $value) {
            $names["#{$key}"] = $value;
        }
        return $names;
    }

    /**
     * @return array<string,array<string,string>>
     */
    protected function buildExpressionAttributeValues(array $attributes): array
    {
        $values = [];
        foreach ($attributes as $key => $value) {
            $values[":{$key}"] = self::client()->marshalValue($value);
        }
        return $values;
    }

    /**
     * Set is easy - we just give it an object and it'll _set_ it
     */
    protected function buildInlineSetExpression(string $fieldName, string $uniqueKeyName): string
    {
        return "SET #{$fieldName}.#{$uniqueKeyName} = :value";
    }

    /**
     * Update is different set Save because we need to list out each attribute we want to change,
     * rather than just giving it an object to set.
     */
    protected function buildInlineUpdateExpression(string $fieldName, string $uniqueKeyName, array $attributes): string
    {
        $expr = [];
        foreach ($attributes as $key => $value) {
            $expr[$key] = "#{$fieldName}.#{$uniqueKeyName}.#{$key} = :{$key}";
        }

        return "SET " . implode(', ', $expr);
    }

    protected function buildUpdateExpression(array $attributes): string
    {
        $expr = [];
        foreach ($attributes as $key => $_) {
            $expr[] = "#{$key} = :{$key}";
        }

        return 'SET ' . implode(', ', $expr);
    }
}
