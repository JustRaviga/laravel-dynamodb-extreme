<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb;

use ClassManager\DynamoDb\Models\DynamoDbModel;
use Illuminate\Support\Collection;
use JsonException;

class InlineRelation extends BaseRelation
{
    /**
     * @param DynamoDbModel $parent
     * @param class-string<DynamoDbModel> $relatedModel
     * @param string $relatedProperty
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
            $model = $this->relatedModel;
            $models = collect(
                json_decode(
                    json: $this->parent->attributes()[$this->relatedProperty] ?? '[]',
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                )
            )->map(fn ($data) => $model::make($data));

            $this->models = $models;
            $this->haveFetchedRelation = true;
        }

        return $this->models;
    }

    public function save(DynamoDbModel $model): DynamoDbModel
    {
        return tap($model, function(DynamoDbModel $model) {
            assert($model instanceof $this->relatedModel);

            $parent = $this->parent;

            $mappedPartitionKey = $parent->getMappedPropertyName($parent->partitionKey());
            $mappedSortKey = $parent->getMappedPropertyName($parent->sortKey());

            $model->fill([
                $mappedPartitionKey => $parent->$mappedPartitionKey,
                $mappedSortKey => $parent->$mappedSortKey,
            ]);
            // todo save model using putItem with a complicated search key

            $this->add($model);
        });
    }

    public function add(DynamoDbModel $model): self
    {
        if (!$this->haveFetchedRelation) {
            // unpack field if required
            $this->get();
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
