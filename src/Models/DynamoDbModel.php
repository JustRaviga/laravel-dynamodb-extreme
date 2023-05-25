<?php

namespace ClassManager\DynamoDb\Models;

use _PHPStan_a3459023a\Symfony\Component\String\Exception\RuntimeException;
use ClassManager\DynamoDb\DynamoDb\BaseRelation;
use ClassManager\DynamoDb\DynamoDb\Client;
use ClassManager\DynamoDb\DynamoDb\InlineRelation;
use ClassManager\DynamoDb\DynamoDb\Relation;
use ClassManager\DynamoDb\DynamoDbHelpers;
use ClassManager\DynamoDb\Exceptions\PartitionKeyNotSetException;
use ClassManager\DynamoDb\Exceptions\PropertyNotFillableException;
use ClassManager\DynamoDb\Exceptions\SortKeyNotSetException;
use ClassManager\DynamoDb\Traits\HasInlineRelations;
use ClassManager\DynamoDb\Traits\HasQueryBuilder;
use ClassManager\DynamoDb\Traits\HasRelations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

abstract class DynamoDbModel
{
    use HasQueryBuilder, HasRelations, HasInlineRelations;

    protected string $partitionKey;
    protected string $sortKey;
    protected string $table;

    /**
     * Mark this as a "child model" by setting its parent class here.
     * This allows access to the parent's partition key when building relations.
     * @var string
     */
    public string $parent;

    /**
     * Optional mapping of database fields to model attributes.
     * e.g. $fieldMappings['pk'] = 'model_uuid'
     * @var array<string,string>
     */
    protected array $fieldMappings = [];

    /**
     * Optional list of secondary indexes (as keys) with values being a mapping in the same way as $fieldMappings
     * @var array<string,array<string,string>>
     */
    public array $globalSecondaryIndexes = [];

    /**
     * @var bool Flag to show if we should use a Consistent Read when fetching data from DynamoDb
     */
    protected bool $consistent_read;

    /**
     * @var array The properties we are allowed to set on this model
     */
    protected array $fillable = [];

    /**
     * @var array Mappings of this model's attributes and values
     */
    protected array $attributes = [];

    /**
     * @var array List of attributes that will not be returned when calling $this->attributes()
     */
    protected array $hidden = [];

    /**
     * Get an attribute on the model, but only if it's mentioned in $fillable
     * @return mixed|null
     */
    public function __get(string $property): mixed
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

        throw new PropertyNotFillableException($property);
    }

    /**
     * Sets a value on the model, provided it's been added to $fillable!
     * @param string $property
     * @param mixed $value
     * @return void
     */
    public function __set(string $property, mixed $value): void
    {
        // Check if this is a fillable attribute
        if ($this->isFillable($property)) {
            $this->attributes[$property] = $value;
            return;
        }

        // Might be a relation
        if (method_exists($this, $property)) {
            $relation = $this->$property();

            if ($relation instanceof InlineRelation) {
                collect($value)->each(function ($value) use ($relation) {
                    $class = $relation->relatedModel();
                    $model = $class::make($value);
                    $relation->add($model);
                });
                return;
            }
        }

        throw new PropertyNotFillableException($property);
    }

    public function __construct(array $attributes = [])
    {
        $this->applyDefaultModelConfiguration();

        $this->fill($attributes);

        $this->applyDefaultValues();

        $this->applyDefaultPartitionKey();
        $this->applyDefaultSortKey();
    }

    public function isFillable(string $property): bool
    {
        return in_array($property, $this->fillable);
    }

    public function applyDefaultValues(): void
    {
        if (method_exists($this, 'defaultValues')) {
            // only apply default values that aren't already set
            $this->attributes = array_merge($this->defaultValues(), $this->attributes);
        }
    }

    public function defaultValues(): array
    {
        return [];
    }

    public function defaultPartitionKey(): string
    {
        return DynamoDbHelpers::upperCaseClassName(static::class) . '#' . Uuid::uuid7()->toString();
    }

    public function defaultSortKey(): string
    {
        return DynamoDbHelpers::upperCaseClassName(static::class);
    }

    public function getUpperCaseClassName(string $className): string
    {
        return strtoupper(substr($className, strrpos($className, '\\') + 1));
    }

    /**
     * Convenience method to return a unique string that relates to this model exactly.
     * Used to determine uniqueness across relations.
     * Defaults to partitionkey.sortkey
     * @return string
     */
    public function uniqueKey(): string
    {
        return $this->attributes[$this->getMappedPropertyName($this->partitionKey())]
            . '.' . $this->attributes[$this->getMappedPropertyName($this->sortKey)];
    }

    public function getMappedPartitionKeyValue(): string
    {
        return $this->attributes[$this->getMappedPropertyName($this->partitionKey)];
    }

    public function getMappedSortKeyValue(): string
    {
        return $this->attributes[$this->getMappedPropertyName($this->sortKey)];
    }

    /**
     * Returns a populated instance of the model after persisting to DynamoDb.
     * @param array $attributes
     * @return self
     */
    public static function create(array $attributes = []): self
    {
        return tap(self::make($attributes), fn (self $model) => $model->save());
    }

    /**
     * Returns a populated instance of the model without persisting to DynamoDb.
     * @param array $attributes
     * @return self
     */
    public static function make(array $attributes = []): self
    {
        return new static($attributes);
    }

    /**
     * Uses putItem under the hood, so any missing attributes are deleted
     * @return self
     */
    public function save(): self
    {
        $attributes = $this->unFill();
        $this->validateAttributes($attributes);

        $client = self::getClient();

        $client->putItem([
            'TableName' => $this->table(),
            'ConsistentRead' => $this->consistentRead(),
            'Item' => $client->marshalItem($attributes),
        ]);

        return $this;
    }

    /**
     * Uses updateItem under the hood so missing attributes are not deleted
     * @param array $attributes
     * @return self
     */
    public function update(array $attributes): self
    {
        // update our data first
        $this->fill($attributes);

        // get the values transformed back to 'database ready' versions
        $attributes = collect($this->unFill());

        // Extract the partition and sort keys
        $partitionKeyName = $this->partitionKey();
        $partitionKeyValue = $attributes[$partitionKeyName];
        $sortKeyName = $this->sortKey();
        $sortKeyValue = $attributes[$sortKeyName];

        // Remove the partition and sort keys from the data we're about to save
        $attributes = $attributes->mapWithKeys(fn ($value, $key)
            => $key === $partitionKeyName || $key === $sortKeyName ? [$key => null] : [$key => $value]
        )->filter();

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

        return $this;
    }

    public function saveInlineRelation(): self
    {
        // verify that partition key and sort key are set
        // need to know our column name
        // craft query to target our column name
        // use our unique key to target a line in the column

        $attributes = collect($this->unFill());

        // Extract the partition and sort keys
        $partitionKeyName = $this->partitionKey();
        $partitionKeyValue = $attributes[$partitionKeyName];
        $sortKeyName = $this->sortKey();
        $sortKeyValue = $attributes[$sortKeyName];

        // Remove the partition and sort keys from the data we're about to save
        $attributes = $attributes->mapWithKeys(fn ($value, $key)
        => $key === $partitionKeyName || $key === $sortKeyName ? [$key => null] : [$key => $value]
        )->filter();

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
    }

    /**
     * Deletes the loaded item from DynamoDb.
     * Use with ::make to reduce database overhead of fetching first
     * @return $this
     */
    public function delete(): self
    {
        $attributes = $this->unFill();
        $this->validateAttributes($attributes);

        $partitionKeyName = $this->partitionKey();
        $partitionKeyValue = $attributes[$partitionKeyName];
        $sortKeyName = $this->sortKey();
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

    public function refresh(): self
    {
        // re-fetch values from dynamodb
        $model = static::find(
            $this->{$this->getMappedPropertyName($this->partitionKey())},
            $this->{$this->getMappedPropertyName($this->sortKey())}
        );
        $this->fill($model->attributes());

        return $this;
    }

    public function partitionKey(?string $index = null): string
    {
        return $this->globalSecondaryIndexes[$index][$this->partitionKey] ?? $this->partitionKey;
    }

    public function sortKey(?string $index = null): string
    {
        return $this->globalSecondaryIndexes[$index][$this->sortKey] ?? $this->sortKey;
    }

    /**
     * Populates the model's attributes, applying mapping from the fieldMapping and index arrays
     * @param array $attributes
     * @return $this
     */
    public function fill(array $attributes): self
    {
        foreach($attributes as $property => $value) {
            // set these with magic, so we can process them in the magic methods
            $this->{$this->getMappedPropertyName($property)} = $value;
        }

        return $this;
    }

    /**
     * Checks $this->fieldMappings for a mapped property relationship and returns a new property name if necessary
     * @param string $property
     * @return string
     */
    public function getMappedPropertyName(string $property): string
    {
        return array_key_exists($property, $this->fieldMappings)
            ? $this->fieldMappings[$property]
            : $property;
    }

    /**
     * Checks $this->fieldMappings for a mapped property relationship and returns the key property
     * @param string $property
     * @return string
     */
    public function getReverseMappedPropertyName(string $property): string
    {
        return in_array($property, $this->fieldMappings)
            ? array_flip($this->fieldMappings)[$property]
            : $property;
    }

    /**
     * Builds a list of fields ready for persisting, applying reverse mapping from the fieldMappings array
     * @return array
     */
    protected function unFill(): array
    {
        // Basic key/value attributes list
        // Apply reversed field mappings to them
        $attributes = collect($this->attributes())->mapWithKeys(fn ($value, $attribute)
            => [$this->getReverseMappedPropertyName($attribute) => $value]
        )->toArray();

        // Apply any loaded inline-relations
        foreach($this->inlineRelations() as $relatedClass => $relation)
        {
            $attributes[$relation->relatedProperty()] = $relation->get()->mapWithKeys(fn ($model) =>
                [ $model->uniqueKey() => $model->attributes() ]
            )->toArray();
        }

        return $attributes;
    }

    public function table(): string
    {
        if (isset($this->parent)) {
            return (new $this->parent)->table();
        }

        return $this->table;
    }

    public function globalSecondaryIndexes(): array
    {
        return $this->globalSecondaryIndexes;
    }

    public function attributes(): array
    {
        return array_filter(
            $this->attributes,
            fn (string $key) => !in_array($key, $this->hidden),
            mode: ARRAY_FILTER_USE_KEY);
    }

    public function consistentRead(): bool
    {
        return $this->consistent_read ?? config('dynamodb.defaults.consistent_read');
    }

    public static function find(string $partitionKey, string $sortKey): ?static
    {
        $client = self::getClient();
        $model = new static();

        $response = $client->getItem([
            'TableName' => $model->table(),
            'ConsistentRead' => $model->consistentRead(),
            'Key' => $client->marshalItem([
                $model->partitionKey() => $partitionKey,
                $model->sortKey() => $sortKey,
            ]),
        ]);

        if (!isset($response['Item'])) {
            return null;
        }

        return $model->fill(collect($response['Item'])
            ->map(fn ($property) => $client->unmarshalValue($property))
            ->toArray()
        );
    }

    public static function findOrFail(string $partitionKey, string $sortKey): static
    {
        $model = self::find($partitionKey, $sortKey);

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel(
                static::class,
                [$partitionKey, $sortKey]
            );
        }

        return $model;
    }

    /**
     * Checks that a partition key and sort key are set in the model's attributes.
     * @throws PartitionKeyNotSetException If the partition key is not set
     * @throws SortKeyNotSetException If the sort key is not set
     */
    protected function validateAttributes(array $attributes): void
    {
        if (!array_key_exists($this->partitionKey(), $attributes)) {
            throw new PartitionKeyNotSetException();
        }

        if (!array_key_exists($this->sortKey(), $attributes)) {
            throw new SortKeyNotSetException();
        }
    }

    protected function buildUpdateExpression(Collection $attributes): string
    {
        return 'SET ' . $attributes->mapWithKeys(
                fn ($value, $key) => [$key => "#$key = :$key"]
            )->implode(', ');
    }

    protected function buildExpressionAttributeValues(Collection $attributes): array
    {
        return $attributes->mapWithKeys(
            fn ($value, $key) => [":$key" => self::getClient()->marshalValue($value)]
        )->toArray();
    }

    protected function buildExpressionAttributeNames(Collection $attributes): array
    {
        return $attributes->mapWithKeys(
            fn ($value, $key) => ["#$key" => $key]
        )->toArray();
    }

    protected static function getClient(): Client
    {
        try {
            return app()->get('dynamodb');
        } catch (\Throwable $t) {
            // todo replace this with a fancy error
            throw new RuntimeException('Error creating DynamoDb client');
        }
    }

    protected function applyDefaultPartitionKey(): void
    {
        $mappedPartitionKey = $this->getMappedPropertyName($this->partitionKey());
        if (!isset($this->attributes[$mappedPartitionKey]) && method_exists($this, 'defaultPartitionKey')) {
            $this->attributes[$mappedPartitionKey] = $this->defaultPartitionKey();
        }
    }

    protected function applyDefaultSortKey(): void
    {
        $mappedSortKey = $this->getMappedPropertyName($this->sortKey());
        if (!isset($this->attributes[$mappedSortKey]) && method_exists($this, 'defaultSortKey')) {
            $this->attributes[$mappedSortKey] = $this->defaultSortKey();
        }
    }

    protected function applyDefaultModelConfiguration(): void
    {
        $this->globalSecondaryIndexes = config('dynamodb.defaults.global_secondary_indexes');
        $this->table = $this->table ?? config('dynamodb.defaults.table');
        $this->partitionKey = $this->partitionKey ?? config('dynamodb.defaults.partition_key');
        $this->sortKey = $this->sortKey ?? config('dynamodb.defaults.sort_key');
    }
}
