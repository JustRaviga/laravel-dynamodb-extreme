<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\Traits;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\PartitionKeyNotSet;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\SortKeyNotSet;

trait HasAttributes
{
    /**
     * Optional mapping of database fields to model attributes.
     * e.g. $fieldMappings['pk'] = 'model_uuid'
     * @var array<string,string>
     */
    protected array $fieldMappings = [];

    /**
     * @var array<string> The properties we are allowed to set on this model
     */
    protected array $fillable = [];

    /**
     * @var array<string,string> Mappings of this model's attributes and values
     */
    protected array $attributes = [];

    /**
     * @var array<string,string> List of attributes retrieved from DynamoDb
     */
    protected array $original = [];

    /**
     * @var array<string,string> List of attributes that have changed since this model was instantiated
     */
    protected array $dirty = [];

    /**
     * @var array<string,string> List of attributes that will not be returned when calling $this->attributes()
     */
    protected array $hidden = [];

    /**
     * @param array<string,string> $attributes
     * @return array<string,string>
     */
    public function getMappedAttributes(array $attributes): array
    {
        $mapped = [];
        foreach($attributes as $attrName => $value) {
            $mapped[$this->getMappedPropertyName($attrName)] = $value;
        }
        return $mapped;
    }

    public function isFillable(string $property): bool
    {
        return in_array($property, $this->fillable);
    }

    /**
     * Stores all incoming attributes IF the model is loading from dynamodb
     * @param array<string, string> $attributes
     */
    public function storeOriginalAttributes(array $attributes): void
    {
        $this->original = $attributes;
    }

    public function isAttributeDirty(string $attribute, mixed $value): bool
    {
        // Attributes are dirty if they're not in the "loaded from database" list
        if (!isset($this->original[$attribute])) {
            return true;
        }

        // Attributes are not dirty if they don't exist in the attributes list (as they're not changing)
        if (!isset($this->attributes[$attribute])) {
            return false;
        }

        // Attributes are dirty if they are changing
        return $this->attributes[$attribute] !== $value;
    }

    public function handleDirtyAttribute(string $property, mixed $value): void
    {
        // Attributes are not dirty if they match what we loaded from the database
        if (isset($this->original[$property]) && $this->original[$property] === $value) {
            unset($this->dirty[$property]);
            return;
        }

        if ($this->isAttributeDirty($property, $value)) {
            $this->dirty[$property] = true;
        }
    }

    /**
     * @param array<string, string> $attributes
     */
    public function applyDefaultValues(array &$attributes): void
    {
        // only apply default values that aren't already set
        foreach($this->defaultValues() as $attrName => $value) {
            $attrName = $this->getMappedPropertyName($attrName);

            // Only set fillable attributes...
            if ($this->isFillable($attrName) && !isset($attributes[$attrName])) {
                $attributes[$attrName] = $value;
            }

            // ... and relations
            if (method_exists($this, $attrName)) {
                $this->{$attrName}();
            }
        }
    }

    /**
     * @return array<string,string>
     */
    public function defaultValues(): array
    {
        return [];
    }

    /**
     * Populates the model's attributes, applying mapping from the fieldMapping and index arrays
     * @param array<string, string|number|bool|array|object> $attributes
     * @return $this
     */
    public function fill(array $attributes): static
    {
        // See if partition key and sort key are in $attributes, set them first
        $partitionKeyName = $this->getMappedPropertyName($this->partitionKey());
        if (isset($attributes[$partitionKeyName])) {
            $this->$partitionKeyName = $attributes[$partitionKeyName];
            unset($attributes[$partitionKeyName]);
        }

        $sortKeyName = $this->getMappedPropertyName($this->sortKey());
        if (isset($attributes[$sortKeyName])) {
            $this->$sortKeyName = $attributes[$sortKeyName];
            unset($attributes[$sortKeyName]);
        }

        // Then set the other attributes
        foreach ($attributes as $attribute => $value) {
            $this->{$this->getMappedPropertyName($attribute)} = $value;
        }

        return $this;
    }

    /**
     * Checks $this->fieldMappings for a mapped property relationship and returns a new property name if necessary
     */
    public function getMappedPropertyName(string $property): string
    {
        return array_key_exists($property, $this->fieldMappings)
            ? $this->fieldMappings[$property]
            : $property;
    }

    /**
     * Checks $this->fieldMappings for a mapped property relationship and returns the key property
     * @return string the name of the property, mapped backwards
     */
    public function getReverseMappedPropertyName(string $property): string
    {
        return in_array($property, $this->fieldMappings)
            ? array_flip($this->fieldMappings)[$property]
            : $property;
    }

    /**
     * @return array<string,string>
     */
    protected function getReverseMappedDirtyAttributes(): array
    {
        return collect($this->dirty)->mapWithKeys(function($_, $attrName) {
            return [$this->getReverseMappedPropertyName($attrName) => $this->attributes[$attrName]];
        })->toArray();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_filter(
            $this->attributes,
            fn (string $key) => !in_array($key, $this->hidden),
            mode: ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return array<string, string>
     */
    public function dirtyAttributes(): array
    {
        return array_filter(
            $this->attributes,
            fn ($key) => in_array($key, array_keys($this->dirty)),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Checks that a partition key and sort key are set in the model's attributes.
     * @param array<string, string|array|number|object> $attributes
     * @throws PartitionKeyNotSet If the partition key is not set
     * @throws SortKeyNotSet If the sort key is not set
     */
    protected function validateAttributes(array $attributes): void
    {
        if (!array_key_exists($this->partitionKey(), $attributes)) {
            throw new PartitionKeyNotSet();
        }

        if (!array_key_exists($this->sortKey(), $attributes)) {
            throw new SortKeyNotSet();
        }
    }
}
