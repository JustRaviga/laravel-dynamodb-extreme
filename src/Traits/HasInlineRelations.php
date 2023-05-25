<?php

namespace ClassManager\DynamoDb\Traits;

use ClassManager\DynamoDb\DynamoDb\BaseRelation;
use ClassManager\DynamoDb\DynamoDb\InlineRelation;

trait HasInlineRelations
{
    /**
     * List of all inline relations loaded for the model.
     * These are populated automatically when the model is instantiated
     * @var array
     */
    protected array $inlineRelations = [];

    public function inlineRelations(): array
    {
        return $this->inlineRelations;
    }

    /**
     * Configure a relationship to an inline (json blob attribute) model
     */
    protected function addInlineRelation(string $relatedClass, string $relatedProperty): BaseRelation
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
