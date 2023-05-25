<?php

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

    abstract public function save(DynamoDbModel $model): DynamoDbModel;

    public function add(DynamoDbModel $model): self
    {
        if ($this->models === null) {
            $this->models = collect();
        }

        if ($this->models->doesntContain(fn (DynamoDbModel $existingModel)
            => $model->uniqueKey() === $existingModel->uniqueKey())
        ) {
            $this->models->add($model);
        }

        return $this;
    }
}
