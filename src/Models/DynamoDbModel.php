<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Models;

use ClassManager\DynamoDb\DynamoDb\BaseRelation;
use ClassManager\DynamoDb\DynamoDb\InlineRelation;
use ClassManager\DynamoDb\DynamoDbHelpers;
use ClassManager\DynamoDb\Exceptions\InvalidInlineModel;
use ClassManager\DynamoDb\Exceptions\PropertyNotFillable;
use ClassManager\DynamoDb\Traits\HasAttributes;
use ClassManager\DynamoDb\Traits\HasInlineRelations;
use ClassManager\DynamoDb\Traits\HasQueryBuilder;
use ClassManager\DynamoDb\Traits\HasRelations;
use ClassManager\DynamoDb\Traits\UsesDynamoDbAdapter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Ramsey\Uuid\Uuid;

abstract class DynamoDbModel
{
    use HasAttributes,
        HasInlineRelations,
        HasQueryBuilder,
        HasRelations,
        UsesDynamoDbAdapter;

    /**
     * @var bool Flag to show if we should use a Consistent Read when fetching data from DynamoDb
     */
    protected static bool $consistentRead;

    /**
     * Optional list of secondary indexes (as keys) with values being a mapping in the same way as $fieldMappings
     * @var array<string,array<string,string>>
     */
    protected static array $globalSecondaryIndexes = [];

    /**
     * Mark this as a "child model" by setting its parent class here.
     * This allows access to the parent's partition key when building relations.
     */
    protected static ?string $parent = null;

    protected static string $partitionKey;
    protected static string $sortKey;
    protected static string $table;

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

    protected function applyDefaultModelConfiguration(): void
    {
        static::$globalSecondaryIndexes = config('dynamodb.defaults.global_secondary_indexes');
        static::$table = static::$table ?? config('dynamodb.defaults.table');
        static::$partitionKey = static::$partitionKey ?? config('dynamodb.defaults.partition_key');
        static::$sortKey = static::$sortKey ?? config('dynamodb.defaults.sort_key');
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

    public static function consistentRead(): bool
    {
        return self::$consistentRead ?? config('dynamodb.defaults.consistent_read');
    }

    /**
     * Returns a populated instance of the model after persisting to DynamoDb.
     * @param array<string,string> $attributes
     */
    public static function create(array $attributes = []): static
    {
        return tap(static::make($attributes), fn (self $model): static => $model->save() );
    }

    public function defaultPartitionKey(): string
    {
        if (self::$parent !== null) {
            return DynamoDbHelpers::upperCaseClassName(self::$parent::class) . '#' . Uuid::uuid7()->toString();
        }

        return DynamoDbHelpers::upperCaseClassName(static::class) . '#' . Uuid::uuid7()->toString();
    }

    public function defaultSortKey(): string
    {
        return DynamoDbHelpers::upperCaseClassName(static::class);
    }

    /**
     * Deletes the loaded item from DynamoDb.
     * Use with ::make to reduce database overhead of fetching first
     */
    public function delete(): static
    {
        $attributes = $this->unFill();
        $this->validateAttributes($attributes);

        $adapter = self::adapter();
        $adapter->delete(
            $this,
            partitionKey: $attributes[self::partitionKey()],
            sortKey: $attributes[self::sortKey()]
        );

        return $this;
    }

    /**
     * For inline relations, this specifies the attribute name in Dynamo where the data for this object can be found.
     */
    public function fieldName(): string
    {
        return '';
    }

    public static function find(string $partitionKey, string $sortKey): ?static
    {
        $attributes = self::adapter()->get(self::class, $partitionKey, $sortKey);

        // Nothing found for the given partition/sort key combination
        if ($attributes === null) {
             return null;
        }

        return new static(
            $attributes,
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

    public function getMappedPartitionKeyValue(): string
    {
        return $this->attributes[$this->getMappedPropertyName(static::partitionKey())];
    }

    public function getMappedSortKeyValue(): string
    {
        return $this->attributes[$this->getMappedPropertyName(static::sortKey())];
    }

    /**
     * @return array<string,array<string,string>>
     */
    public static function globalSecondaryIndexes(): array
    {
        return static::$globalSecondaryIndexes;
    }

    /**
     * Returns a populated instance of the model without persisting to DynamoDb.
     * @param array<string,string> $attributes
     */
    public static function make(array $attributes = []): static
    {
        return new static($attributes);
    }

    public static function parent(): string
    {
        return self::$parent;
    }

    public static function partitionKey(?string $index = null): string
    {
        return self::$globalSecondaryIndexes[$index][self::$partitionKey] ?? self::$partitionKey;
    }

    /**
     * Reloads the model's data from Dynamo, including re-populating inline relationships
     */
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
     * Uses putItem under the hood, so any missing attributes are deleted
     */
    public function save(): static
    {
        $attributes = $this->unFill();
        $this->validateAttributes($attributes);

        self::adapter()->save($this, $attributes);

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

        $attributes = $this->unFill();

        $partitionKey = $attributes[$this::partitionKey()];
        $sortKey = $attributes[$this::sortKey()];

        // Remove the partition and sort keys from the data we're about to save
        $attributesWithoutKeys = DynamoDbHelpers::listWithoutKeys($attributes, [
            $this::partitionKey(),
            $this::sortKey(),
            $this->uniqueKeyName(),
        ]);

        self::adapter()->saveInlineRelation(
            $this,
            $partitionKey,
            $sortKey,
            $attributes
        );

        return $this;
    }

    public static function sortKey(?string $index = null): string
    {
        return self::$globalSecondaryIndexes[$index][self::$sortKey] ?? self::$sortKey;
    }

    public static function table(): string
    {
        if (isset(self::$parent)) {
            $parent = self::$parent;
            return $parent::table();
        }

        return self::$table;
    }

    public function toArray(): array
    {
        return $this->attributes();
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

        self::adapter()->update($this, $partitionKeyValue, $sortKeyValue, $attributes->toArray());

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

        $attributes = $this->unFill();

        $partitionKey = $attributes[$this::partitionKey()];
        $sortKey = $attributes[$this::sortKey()];

        // Remove the partition, sort keys, and the unique key from the data we're about to save
        $attributes = DynamoDbHelpers::listWithoutKeys($attributes, [
            $this::partitionKey(),
            $this::sortKey(),
            $this->uniqueKeyName()
        ]);

        self::adapter()->updateInlineRelation(
            $this,
            $partitionKey,
            $sortKey,
            $attributes
        );

        return $this;
    }
}
