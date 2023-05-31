<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb;

use ClassManager\DynamoDb\Models\DynamoDbModel;
use Illuminate\Support\Collection;

abstract class BaseRelation
{
    /**
     * @var Collection<DynamoDbModel>|null
     */
    protected ?Collection $models = null;
    protected bool $haveFetchedRelation = false;

    abstract public function get(): Collection;

    /**
     * Accepts either an array of data that will be used to create a DynamoDbModel instance, or a DynamoDbModel instance
     * itself.
     * @param array|DynamoDbModel $model
     * @return DynamoDbModel
     */
    abstract public function save(array|DynamoDbModel $model): DynamoDbModel;

    /**
     * Accepts either an array of data that will be used to create a DynamoDbModel instance, or a DynamoDbModel instance
     * itself.
     * @param array|DynamoDbModel $model
     * @return BaseRelation
     */
    public function add(array|DynamoDbModel $model): static
    {
        if (!$this->haveFetchedRelation) {
            $this->models = collect();
        }

        if ($this->models->doesntContain(fn (DynamoDbModel $existingModel) => $model->uniqueKey() === $existingModel->uniqueKey())) {
            $this->models->add($model);
        }

        return $this;
    }
}
