<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Traits;

use JustRaviga\DynamoDb\DynamoDb\InlineRelation;
use JustRaviga\DynamoDb\DynamoDbHelpers;
use JustRaviga\DynamoDb\Exceptions\InvalidInlineModel;

trait HasInlineRelations
{
    /**
     * List of all inline relations loaded for the model.
     * These are populated automatically when the model is instantiated
     * @var array<InlineRelation>
     */
    protected array $inlineRelations = [];

    /**
     * Configure a relationship to an inline (json blob attribute) model
     */
    protected function addInlineRelation(string $relatedClass, string $relatedProperty): InlineRelation
    {
        if (!isset($this->inlineRelations[$relatedClass])) {
            $this->inlineRelations[$relatedClass] = new InlineRelation(
                $this,
                $relatedClass,
                $relatedProperty,
            );
        }

        return $this->inlineRelations[$relatedClass];
    }

    /**
     * For inline relations, this specifies the attribute name in Dynamo where the data for this object can be found.
     */
    public function fieldName(): string
    {
        return '';
    }

    /**
     * @return array<InlineRelation>
     */
    public function inlineRelations(): array
    {
        return $this->inlineRelations;
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

        // Remove partition and sort keys from attributes we're about to save
        unset($attributes[$this::partitionKey()]);
        unset($attributes[$this::sortKey()]);
        unset($attributes[$this->uniqueKeyName()]);

        self::adapter()->saveInlineRelation(
            $this,
            $partitionKey,
            $sortKey,
            $attributes
        );

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
