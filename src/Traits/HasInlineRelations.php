<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Traits;

use ClassManager\DynamoDb\DynamoDb\InlineRelation;

trait HasInlineRelations
{
    /**
     * List of all inline relations loaded for the model.
     * These are populated automatically when the model is instantiated
     * @var array<InlineRelation>
     */
    protected array $inlineRelations = [];

    /**
     * @return array<InlineRelation>
     */
    public function inlineRelations(): array
    {
        return $this->inlineRelations;
    }

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
}
