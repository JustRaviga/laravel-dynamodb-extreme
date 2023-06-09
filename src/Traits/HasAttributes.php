<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\Traits;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use JsonException;
use JustRaviga\LaravelDynamodbExtreme\Contracts\CastsAttributes;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\AttributeCastError;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\PartitionKeyNotSet;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\SortKeyNotSet;

trait HasAttributes
{
    /**
     * @var array<string,string> Mappings of this model's attributes and values
     */
    protected array $attributes = [];

    private static array $builtInCasts = [
        'array',
        'json',
        'object',

        // dynamodb data types
        'list',
        'map',
        'set:string',
        'set:number',
        'set:binary',

        // laravel
        'collection',

        // date/time
        'date',
        'datetime',
        'immutable_date',
        'immutable_datetime',
        'timestamp',
    ];

    /**
     * @var array <string,string> A list of attributes (keys) and casts that will be applied to them (values)
     */
    protected array $casts = [];

    /**
     * @var array<string,string> List of attributes that have changed since this model was instantiated
     */
    protected array $dirty = [];

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
     * @var array<string,string> List of attributes that will not be returned when calling $this->attributes()
     */
    protected array $hidden = [];

    /**
     * @var array<string,string> List of attributes retrieved from DynamoDb
     */
    protected array $original = [];

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
     * Returns the cast value of an attribute based on the contents of the $casts array
     */
    public function castAttribute(string $attributeName, mixed $value): mixed
    {
        if (!isset($this->casts[$attributeName]) || is_null($value)) {
            return $value;
        }

        $cast = $this->casts[$attributeName];

        // We have a built-in cast that exists for this type
        if (in_array($cast, self::$builtInCasts)) {
            switch ($cast) {
                case 'list':
                case 'set:string':
                case 'set:number':
                case 'set:binary':
                case 'array':
                case 'json':
                    return $this->castJson($value, true);

                case 'map':
                case 'object':
                    return $this->castJson($value);

                case 'collection':
                    return new Collection($this->castJson($value, true));

                case 'date':
                    return $this->castDateTime($value)->startOfDay();
                case 'datetime':
                    return $this->castDateTime($value);
                case 'immutable_date':
                    return $this->castDateTime($value)->startOfDay()->toImmutable();
                case 'immutable_datetime':
                    return $this->castDateTime($value)->toImmutable();
                case 'timestamp':
                    return $this->castDateTime($value)->getTimestamp();
            }
        }

        // the cast might be a class name
        if (class_exists($cast)) {
            $castObject = new $cast();
            if (!$castObject instanceof CastsAttributes) {
                // invalid cast
                throw new AttributeCastError("Cannot cast property {{$attributeName}} using cast {{$cast}}");
            }
            return $castObject->get($this, $attributeName, $value, $this->attributes);
        }

        // No way to cast this value to just return it with no transformation
        return $value;
    }

    /**
     * Casts all given attributes according to the $casts list on this model
     */
    protected function castAttributes(array &$attributes): void
    {
        foreach ($attributes as $attributeName => &$value) {
            $value = $this->castAttribute($attributeName, $value);
        }
    }

    protected function castDateTime(string $value): Carbon
    {
        return Carbon::make($value);
    }

    protected function castJson(mixed $value, bool $asArray = false): array|object
    {
        if (is_array($value) && $asArray === true) {
            return $value;
        }

        if (is_object($value) && $asArray === false) {
            return $value;
        }

        if (is_object($value) && $asArray === true) {
            return (array) $value;
        }

        if (is_array($value) && $asArray === false) {
            return (object) $value;
        }

        try {
            return json_decode($value, associative: $asArray, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $je) {
            throw new AttributeCastError("Unable to decode json blob: [{$je->getMessage()}]");
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

    public function isFillable(string $property): bool
    {
        return in_array($property, $this->fillable);
    }

    /**
     * The opposite of _cast_, this takes a "casted" attribute and converts it back to something we can store
     */
    protected function packAttribute(string $attributeName, mixed $value): mixed
    {
        if (!isset($this->casts[$attributeName]) || is_null($value)) {
            return $value;
        }

        $cast = $this->casts[$attributeName];

        // We have a built-in cast that exists for this type
        if (in_array($cast, self::$builtInCasts)) {
            switch ($cast) {
                case 'list':
                case 'set:string':
                case 'set:number':
                case 'set:binary':
                case 'map':
                    // DynamoDb handles packing these data types for us
                    return $value;

                case 'array':
                case 'json':
                case 'object':
                    return json_encode($value);

                case 'collection':
                    return new Collection($this->castJson($value, true));

                case 'date':
                case 'datetime':
                case 'immutable_date':
                case 'immutable_datetime':
                    return $this->packDateTime($value);

                case 'timestamp':
                    // Timestamp is just an integer so we don't need to do anything with it
                    return $value;
            }
        }

        // the cast might be a class name
        if (class_exists($cast)) {
            $castObject = new $cast();
            if (!$castObject instanceof CastsAttributes) {
                // invalid cast object
                throw new AttributeCastError("Cannot cast property {{$attributeName}} using cast {{$cast}}");
            }
            return $castObject->set($this, $attributeName, $value, $this->attributes);
        }

        // No way to pack this value so just return it with no transformation
        return $value;
    }

    /**
     * Packs (un-casts) all given attributes according to the $casts list on this model
     */
    protected function packAttributes(array &$attributes): void
    {
        foreach ($attributes as $attributeName => &$value) {
            $value = $this->packAttribute($attributeName, $value);
        }
    }

    protected function packDateTime(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }

        return $value;
    }

    /**
     * Stores all incoming attributes IF the model is loading from dynamodb
     * @param array<string, string> $attributes
     */
    public function storeOriginalAttributes(array $attributes): void
    {
        $this->original = $attributes;
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
