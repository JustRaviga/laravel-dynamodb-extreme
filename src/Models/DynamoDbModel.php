<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Models;

use ClassManager\DynamoDb\DynamoDb\BaseRelation;
use ClassManager\DynamoDb\DynamoDb\Client;
use ClassManager\DynamoDb\DynamoDb\InlineRelation;
use ClassManager\DynamoDb\DynamoDbHelpers;
use ClassManager\DynamoDb\Exceptions\DynamoDbClientNotInContainer;
use ClassManager\DynamoDb\Exceptions\InvalidInlineModel;
use ClassManager\DynamoDb\Exceptions\PropertyNotFillable;
use ClassManager\DynamoDb\Traits\HasAttributes;
use ClassManager\DynamoDb\Traits\HasInlineRelations;
use ClassManager\DynamoDb\Traits\HasQueryBuilder;
use ClassManager\DynamoDb\Traits\HasRelations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;
use Throwable;

abstract class DynamoDbModel
{
    use HasAttributes, HasQueryBuilder, HasRelations, HasInlineRelations;

    protected static string $partitionKey;
    protected static string $sortKey;
    protected static string $table;

    /**
     * @var bool Flag to show if we should use a Consistent Read when fetching data from DynamoDb
     */
    protected static bool $consistentRead;

    /**
     * Mark this as a "child model" by setting its parent class here.
     * This allows access to the parent's partition key when building relations.
     */
    protected static string $parent;

    /**
     * Optional list of secondary indexes (as keys) with values being a mapping in the same way as $fieldMappings
     * @var array<string,array<string,string>>
     */
    protected static array $globalSecondaryIndexes = [];

    /**
     * Get an attribute on the model, but only if it's mentioned in $fillable
     */
    public function __get(string $property): string|object|array|int|float|bool|null
    {
        // Might be a fillable attribute
        if ($this->isFillable($property)) {
            return $this->attributes[$property] ?? null;
        }

        // Might be a relation
        if (method_exists($this, $property)) {
            $relation = $this->$property();

            if ($relation instanceof BaseRelation) {
                return $relation->get();
            }
        }

        throw new PropertyNotFillable($property);
    }

    /**
     * Sets a value on the model, provided it's been added to $fillable!
     */
    public function __set(string $property, string|int|float|bool|object|array $value): void
    {
        // Check if this is a fillable attribute
        if ($this->isFillable($property)) {
            // An attribute is considered dirty if it wasn't loaded from the database or its value is changing
            $this->handleDirtyAttribute($property, $value);

            $this->attributes[$property] = $value;
            return;
        }

        // Might be a relation
        if (method_exists($this, $property)) {
            $relation = $this->$property();

            if ($relation instanceof InlineRelation) {
                collect($value)->each(function ($value) use ($relation): void {
                    $class = $relation->relatedModel();
                    $model = $class::make($value);
                    $relation->add($model);
                });
                return;
            }
        }

        throw new PropertyNotFillable($property);
    }

    /**
     * @param array<string,string> $attributes
     */
    public function __construct(array $attributes = [], bool $loading = false)
    {
        $this->applyDefaultModelConfiguration();

        $attributes = $this->getMappedAttributes($attributes);

        $this->applyDefaultValues($attributes);
        $this->applyDefaultPartitionKey($attributes);
        $this->applyDefaultSortKey($attributes);

        if ($loading === true) {
            $this->storeOriginalAttributes($attributes);
        }
        $this->fill($attributes);
    }

    /**
     * Convenience method to return a unique string that relates to this model exactly.
     * Used to determine uniqueness across relations and keys for inline child models.
     * Defaults to partitionKey.sortKey
     */
    public function uniqueKey(): string
    {
        return $this->attributes[$this->getMappedPropertyName(static::partitionKey())]
            . '.' . $this->attributes[$this->getMappedPropertyName(static::sortKey())];
    }

    /**
     * Convenience method to get the name of the attribute used to generate a unique key.
     * Used for Inline relations only
     */
    public function uniqueKeyName(): string
    {
        return '';
    }

    /**
     * Returns a populated instance of the model after persisting to DynamoDb.
     * @param array<string,string> $attributes
     */
    public static function create(array $attributes = []): static
    {
        return tap(static::make($attributes), fn (self $model): static => $model->save() );
    }

    /**
     * Returns a populated instance of the model without persisting to DynamoDb.
     * @param array<string,string> $attributes
     */
    public static function make(array $attributes = []): static
    {
        return new static($attributes);
    }

    /**
     * Uses putItem under the hood, so any missing attributes are deleted
     */
    public function save(): static
    {
        $attributes = $this->unFill();
        $this->validateAttributes($attributes);

        $client = self::getClient();

        $client->putItem([
            'TableName' => $this->table(),
            'ConsistentRead' => $this->consistentRead(),
            'Item' => $client->marshalItem($attributes),
        ]);

        // Now we have persisted our data, we no longer have any dirty data
        $this->original = $this->attributes;
        $this->dirty = [];

        return $this;
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

        $client = self::getClient();

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

    /**
     * Uses updateItem under the hood so missing attributes are not deleted
     * @param array<string,string> $attributes
     */
    public function update(array $attributes): static
    {
        $this->fill($attributes);

        // get the values transformed back to 'database ready' versions
        $attributes = collect($this->unFill());

        // Extract the partition and sort keys
        $partitionKeyName = $this->partitionKey();
        $partitionKeyValue = $attributes[$partitionKeyName];
        $sortKeyName = $this->sortKey();
        $sortKeyValue = $attributes[$sortKeyName];

        // Remove the partition and sort keys from the data we're about to save
        $attributes = $attributes->mapWithKeys(
            fn ($value, $key) => $key === $partitionKeyName || $key === $sortKeyName
                ? [$key => null]
                : [$key => $value]
        )->filter(fn($val) => $val !== null);

        $client = self::getClient();

        $client->updateItem([
            'TableName' => $this->table(),
            'ConsistentRead' => $this->consistentRead(),
            'Key' => $client->marshalItem([
                $partitionKeyName => $partitionKeyValue,
                $sortKeyName => $sortKeyValue,
            ]),
            'UpdateExpression' => $this->buildUpdateExpression($attributes),
            'ExpressionAttributeNames' => $this->buildExpressionAttributeNames($attributes),
            'ExpressionAttributeValues' => $this->buildExpressionAttributeValues($attributes),
        ]);

        // Now we have persisted our data, we no longer have any dirty data
        $this->original = $this->attributes;
        $this->dirty = [];

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

        $client = self::getClient();

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

    /**
     * Deletes the loaded item from DynamoDb.
     * Use with ::make to reduce database overhead of fetching first
     */
    public function delete(): static
    {
        $attributes = $this->unFill();
        $this->validateAttributes($attributes);

        $partitionKeyName = self::partitionKey();
        $partitionKeyValue = $attributes[$partitionKeyName];
        $sortKeyName = self::sortKey();
        $sortKeyValue = $attributes[$sortKeyName];

        $client = self::getClient();

        $client->deleteItem([
            'TableName' => $this->table(),
            'ConsistentRead' => $this->consistentRead(),
            'Key' => $client->marshalItem([
                $partitionKeyName => $partitionKeyValue,
                $sortKeyName => $sortKeyValue,
            ]),
        ]);

        return $this;
    }

    public function refresh(): static
    {
        // re-fetch values from dynamodb
        $model = static::find(
            $this->getMappedPartitionKeyValue(),
            $this->getMappedSortKeyValue(),
        );

        $this->fill($model->attributes());
        $this->inlineRelations = $model->inlineRelations;

        return $this;
    }

    /**
     * Builds a list of fields ready for persisting, applying reverse mapping from the fieldMappings array
     * @return array<string,string>
     */
    protected function unFill(): array
    {
        // Apply reversed field mappings to attributes on this model
        $attributes = collect($this->attributes)
            ->mapWithKeys(fn ($value, $attribute) => [$this->getReverseMappedPropertyName($attribute) => $value])
            ->toArray();

        // Apply any loaded inline-relations
        foreach($this->inlineRelations() as $relation)
        {
            $models = $relation
                ->get()
                ->mapWithKeys(fn ($model) => [ $model->uniqueKey() => $model->attributes() ])
                ->toArray();

            $attributes[$relation->relatedProperty()] = count($models) === 0 ? new \stdClass() : $models;
        }

        return $attributes;
    }

    public static function table(): string
    {
        if (isset(self::$parent)) {
            $parent = self::$parent;
            return $parent::table();
        }

        return self::$table;
    }

    /**
     * @return array<string,array<string,string>>
     */
    public static function globalSecondaryIndexes(): array
    {
        return static::$globalSecondaryIndexes;
    }

    public static function consistentRead(): bool
    {
        return self::$consistentRead ?? config('dynamodb.defaults.consistent_read');
    }

    public static function find(string $partitionKey, string $sortKey): ?static
    {
        $client = self::getClient();

        $response = $client->getItem([
            'TableName' => static::table(),
            'ConsistentRead' => static::consistentRead(),
            'Key' => $client->marshalItem([
                static::partitionKey() => $partitionKey,
                static::sortKey() => $sortKey,
            ]),
        ]);

        if (!isset($response['Item'])) {
            return null;
        }

        return new static(
            attributes: collect($response['Item'])
                ->map(fn ($attribute) => $client->unmarshalValue($attribute))
                ->toArray(),
            loading: true,
        );
    }

    public static function findOrFail(string $partitionKey, string $sortKey): static
    {
        $model = self::find($partitionKey, $sortKey);

        if ($model === null) {
            throw (new ModelNotFoundException())->setModel(
                static::class,
                [$partitionKey, $sortKey]
            );
        }

        return $model;
    }

    protected function buildUpdateExpression(Collection $attributes): string
    {
        return 'SET ' . $attributes->mapWithKeys(
            fn ($_, $key) => [$key => "#{$key} = :{$key}"]
        )->implode(', ');
    }

    /**
     * @return array<string,array<string,string>>
     */
    protected function buildExpressionAttributeValues(Collection $attributes): array
    {
        return $attributes->mapWithKeys(
            fn ($value, $key) => [":{$key}" => self::getClient()->marshalValue($value)]
        )->toArray();
    }

    /**
     * @return array<string,string>
     */
    protected function buildExpressionAttributeNames(Collection $attributes): array
    {
        return $attributes->mapWithKeys(
            fn ($_, $key) => ["#{$key}" => $key]
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
     * @return array<string,string>
     */
    protected function buildInlineExpressionAttributeNames(Collection $attributes): array
    {
        return $attributes->mapWithKeys(
            fn ($value, $key) => ["#{$key}" => $value]
        )->toArray();
    }

    protected static function getClient(): Client
    {
        try {
            return app()->get('dynamodb');
        } catch (Throwable) {
            throw new DynamoDbClientNotInContainer();
        }
    }

    protected function applyDefaultModelConfiguration(): void
    {
        static::$globalSecondaryIndexes = config('dynamodb.defaults.global_secondary_indexes');
        static::$table = static::$table ?? config('dynamodb.defaults.table');
        static::$partitionKey = static::$partitionKey ?? config('dynamodb.defaults.partition_key');
        static::$sortKey = static::$sortKey ?? config('dynamodb.defaults.sort_key');
    }

    public static function partitionKey(?string $index = null): string
    {
        return self::$globalSecondaryIndexes[$index][self::$partitionKey] ?? self::$partitionKey;
    }

    public function defaultPartitionKey(): string
    {
        // todo if model has a parent, partition key should be the parent's class name
        return DynamoDbHelpers::upperCaseClassName(static::class) . '#' . Uuid::uuid7()->toString();
    }

    public function getMappedPartitionKeyValue(): string
    {
        return $this->attributes[$this->getMappedPropertyName(static::partitionKey())];
    }

    /**
     * @param array<string,string> $attributes
     */
    protected function applyDefaultPartitionKey(array &$attributes): void
    {
        $mappedPartitionKey = $this->getMappedPropertyName(self::partitionKey());
        if (!isset($attributes[$mappedPartitionKey]) && method_exists($this, 'defaultPartitionKey')) {
            $attributes[$mappedPartitionKey] = $this->defaultPartitionKey();
        }
    }

    public static function sortKey(?string $index = null): string
    {
        return self::$globalSecondaryIndexes[$index][self::$sortKey] ?? self::$sortKey;
    }

    public function defaultSortKey(): string
    {
        return DynamoDbHelpers::upperCaseClassName(static::class);
    }

    public function getMappedSortKeyValue(): string
    {
        return $this->attributes[$this->getMappedPropertyName(static::sortKey())];
    }

    /**
     * @param array<string,string> $attributes
     */
    protected function applyDefaultSortKey(array &$attributes): void
    {
        $mappedSortKey = $this->getMappedPropertyName(self::sortKey());
        if (!isset($attributes[$mappedSortKey]) && method_exists($this, 'defaultSortKey')) {
            $attributes[$mappedSortKey] = $this->defaultSortKey();
        }
    }

    public static function parent(): string
    {
        return self::$parent;
    }

    public function toArray(): array
    {
        return $this->attributes();
    }

    /**
     * For inline relations, this specifies the attribute name in Dynamo where the data for this object can be found.
     */
    public function fieldName(): string
    {
        return '';
    }
}
