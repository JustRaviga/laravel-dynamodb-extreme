<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Traits;

use ClassManager\DynamoDb\DynamoDb\BaseRelation;
use ClassManager\DynamoDb\DynamoDb\Relation;
use ClassManager\DynamoDb\DynamoDbHelpers;
use ClassManager\DynamoDb\Models\DynamoDbModel;

trait HasRelations
{
    /**
     * List of all child relations loaded for this model
     * @var array<string,Relation>
     */
    protected array $relations = [];

    /**
     * Returns a name by which this model is known as a relation to other models.
     * @return string
     */
    public static function relationName(): string
    {
        return static::class;
    }

    /**
     * Used for matching on sort keys for related models
     * @return array
     */
    public static function relationSearchParams(): array
    {
        $className = DynamoDbHelpers::upperCaseClassName(static::class);

        return [
            (new static)->sortKey(),
            'begins_with',
            "$className#"
        ];
    }

    public function relations(): array
    {
        return $this->relations;
    }

    /**
     * Flag to show whether this relation exists
     * @param string $relationName
     * @return bool
     */
    public function hasRelation(string $relationName): bool
    {
        return array_key_exists($relationName, $this->relations) || method_exists($this, $relationName);
    }

    /**
     * Configure a relationship to a child model
     * @param class-string<DynamoDbModel> $relatedClass
     * @return BaseRelation
     */
    protected function addRelation(string $relatedClass): BaseRelation
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
