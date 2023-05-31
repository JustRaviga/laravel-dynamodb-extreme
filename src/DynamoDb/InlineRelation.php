<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb;

use ClassManager\DynamoDb\Models\DynamoDbModel;
use Illuminate\Support\Collection;
use JsonException;

class InlineRelation extends BaseRelation
{
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
        if (!$this->haveFetchedRelation) {
            $this->unpackRelatedModels();
        }

        return $this->models;
    }

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
        $this->haveFetchedRelation = true;
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

    public function add(array|DynamoDbModel $model): self
    {
        if (!$this->haveFetchedRelation) {
            // unpack field if required
            $this->get();
        }

        if (! $model instanceof DynamoDbModel) {
            $class = $this->relatedModel;
            $model = $class::make($model);
        }

        // If the model hasn't been saved yet...
        if (count($model->dirtyAttributes()) === count($model->attributes())) {
            $parent = $this->parent;

            $mappedPartitionKey = $parent->getMappedPropertyName($parent->partitionKey());
            $mappedSortKey = $parent->getMappedPropertyName($parent->sortKey());

            $model->fill([
                $mappedPartitionKey => $parent->$mappedPartitionKey,
                $mappedSortKey => $parent->$mappedSortKey,
            ]);
        }

        parent::add($model);

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
