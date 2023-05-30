<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb;

use ClassManager\DynamoDb\Models\DynamoDbModel;
use Illuminate\Support\Collection;

class Relation extends BaseRelation
{
    /**
     * @var Collection<DynamoDbModel>|null
     */
    protected ?Collection $models = null;
    protected bool $haveFetchedRelation = false;

    /**
     * @param DynamoDbModel $parent
     * @param class-string<DynamoDbModel> $relatedModel
     * @param array $relation
     */
    public function __construct(
        protected readonly DynamoDbModel $parent,
        protected readonly string $relatedModel,
        protected readonly array $relation,
    ) {
    }

    public function get(): Collection
    {
        if (!$this->haveFetchedRelation) {
            $model = $this->relatedModel;
            $parent = $this->parent;
            $models = $model::query()
                ->where( $parent->partitionKey(), $parent->{$parent->getMappedPropertyName($parent->partitionKey())})
                ->where(...$this->relation)
                ->get();

            $this->models = $models;
            $this->haveFetchedRelation = true;
        }

        return $this->models;
    }

    public function save(DynamoDbModel $model): DynamoDbModel
    {
        return tap($model, function($model) {
            assert($model instanceof $this->relatedModel);

            $parent = $this->parent;

            // Add the parent class's partition key to this model
            $model->update([
                $parent->partitionKey() => $parent->{$parent->getMappedPropertyName($parent->partitionKey())},
            ]);

            $this->add($model);
        });
    }

    public function relatedModel(): string
    {
        return $this->relatedModel;
    }

    public function relation(): array
    {
        return $this->relation;
    }
}
