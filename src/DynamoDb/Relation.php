<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb;

use ClassManager\DynamoDb\DynamoDbQueryBuilder;
use ClassManager\DynamoDb\Models\DynamoDbModel;
use Illuminate\Support\Collection;

class Relation implements ModelRelationship
{
    /**
     * @var Collection<DynamoDbModel>|null
     */
    protected ?Collection $models = null;
    protected bool $haveFetchedRelation = false;

    /**
     * @param class-string<DynamoDbModel> $relatedModel
     * @param array<string> $relation
     */
    public function __construct(
        protected readonly DynamoDbModel $parent,
        protected readonly string $relatedModel,
        protected readonly array $relation,
    ) {
    }

    public function query(): DynamoDbQueryBuilder
    {
        $model = $this->relatedModel;
        $parent = $this->parent;

        return $model::query()
            ->where($parent->partitionKey(), $parent->getMappedPartitionKeyValue())
            ->where(...$this->relation);
    }

    public function reset(): void
    {
        $this->haveFetchedRelation = false;
    }

    public function get(): Collection
    {
        if (!$this->haveFetchedRelation) {
            $models = $this->query()->getAll();

            $this->models = $models->results;
            $this->haveFetchedRelation = true;
        }

        return $this->models;
    }

    public function save(array|DynamoDbModel $model): DynamoDbModel
    {
        if (! $model instanceof DynamoDbModel) {
            $class = $this->relatedModel;
            $model = $class::make($model);
        }

        return tap($model, function($model): void {
            assert($model instanceof $this->relatedModel);

            $parent = $this->parent;

            // Add the parent class's partition key to this model
            $model->update([
                $parent->partitionKey() => $parent->getMappedPartitionKeyValue(),
            ]);

            $this->add($model);
        });
    }

    /**
     * Accepts either an array of data that will be used to create a DynamoDbModel instance, or a DynamoDbModel instance
     * itself.
     * @param array|DynamoDbModel $model
     * @return Relation
     */
    public function add(array|DynamoDbModel $model): static
    {
        if ($this->models === null) {
            $this->models = collect();
        }

        if ($this->models->doesntContain(fn (DynamoDbModel $existingModel) => $model->uniqueKey() === $existingModel->uniqueKey())) {
            $this->models->add($model);
        }

        return $this;
    }

    public function relatedModel(): string
    {
        return $this->relatedModel;
    }

    /**
     * @return array<string>
     */
    public function relation(): array
    {
        return $this->relation;
    }
}
