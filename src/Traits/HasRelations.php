<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Traits;

use JustRaviga\DynamoDb\DynamoDb\Relation;
use JustRaviga\DynamoDb\DynamoDbHelpers;
use JustRaviga\DynamoDb\Models\DynamoDbModel;

trait HasRelations
{
    /**
     * List of all child relations loaded for this model
     * @var array<string,Relation>
     */
    protected array $relations = [];

    /**
     * Returns a name by which this model is known as a relation to other models.
     */
    public static function relationName(): string
    {
        return static::class;
    }

    /**
     * Used for matching on sort keys for related models
     * @return array<string,string,string>
     */
    public static function relationSearchParams(): array
    {
        $className = DynamoDbHelpers::upperCaseClassName(static::class);

        return [
            static::sortKey(),
            'begins_with',
            "{$className}#"
        ];
    }

    /**
     * @return array<Relation> the relations already loaded for this model
     */
    public function relations(): array
    {
        return $this->relations;
    }

    /**
     * Flag to show whether this relation exists
     * @return bool whether the relation is defined on the model
     */
    public function hasRelation(string $relationName): bool
    {
        return array_key_exists($relationName, $this->relations) || method_exists($this, $relationName);
    }

    /**
     * Configure a relationship to a child model
     * @param class-string<DynamoDbModel> $relatedClass
     */
    protected function addRelation(string $relatedClass): Relation
    {
        if (!isset($this->relations[$relatedClass])) {
            $this->relations[$relatedClass] = new Relation(
                $this,
                $relatedClass,
                $relatedClass::relationSearchParams()
            );
        }

        return $this->relations[$relatedClass];
    }
}
