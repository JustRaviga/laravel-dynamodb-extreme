<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\DynamoDb;

use Illuminate\Support\Collection;
use JsonException;
use JustRaviga\DynamoDb\Contracts\ModelRelationship;
use JustRaviga\DynamoDb\Models\DynamoDbModel;

class InlineRelation implements ModelRelationship
{
    protected ?Collection $models = null;

    /**
     * @param class-string<DynamoDbModel> $relatedModel
     */
    public function __construct(
        protected readonly DynamoDbModel $parent,
        protected readonly string $relatedModel,
        protected readonly string $relatedProperty,
    ) {
    }

    /**
     * @return Collection<DynamoDbModel>
     * @throws JsonException
     */
    public function get(): Collection
    {
        if ($this->models === null) {
            $this->unpackRelatedModels();
        }

        return $this->models;
    }

    /**
     * @throws JsonException
     */
    protected function unpackRelatedModels(): void
    {
        $model = $this->relatedModel;

        $modelData = $this->parent->attributes()[$this->relatedProperty] ?? '[]';

        if (is_string($modelData)) {
            $modelData = json_decode(
                json: $modelData,
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        $models = collect($modelData)->map(fn ($data) => $model::make($data));

        $this->models = $models;
    }

    public function save(DynamoDbModel|array $model): DynamoDbModel
    {
        if (!$model instanceof DynamoDbModel) {
            $class = $this->relatedModel;
            $model = $class::make($model);
        }

        return tap($model, function (DynamoDbModel $model): void {
            assert($model instanceof $this->relatedModel);

            $parent = $this->parent;

            $mappedPartitionKey = $parent->getMappedPropertyName($parent->partitionKey());
            $mappedSortKey = $parent->getMappedPropertyName($parent->sortKey());

            $model->fill([
                $mappedPartitionKey => $parent->$mappedPartitionKey,
                $mappedSortKey => $parent->$mappedSortKey,
            ])->saveInlineRelation();

            $this->add($model);
        });
    }

    public function add(array|DynamoDbModel $model): static
    {
        if ($this->models === null) {
            $this->unpackRelatedModels();
        }

        if (! $model instanceof DynamoDbModel) {
            $class = $this->relatedModel;
            $model = $class::make($model);
        }

        // If the model hasn't been saved yet...
        // This may seem like an oversight, but if the model had ever been saved, there will be the immutable partition
        // key and sort keys set on it.
        if (count($model->dirtyAttributes()) === count($model->attributes())) {
            $parent = $this->parent;

            $mappedPartitionKey = $parent->getMappedPropertyName($parent->partitionKey());
            $mappedSortKey = $parent->getMappedPropertyName($parent->sortKey());

            $model->fill([
                $mappedPartitionKey => $parent->$mappedPartitionKey,
                $mappedSortKey => $parent->$mappedSortKey,
            ]);
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

    public function relatedProperty(): string
    {
        return $this->relatedProperty;
    }
}
