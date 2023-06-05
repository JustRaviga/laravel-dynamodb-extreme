<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb;

use ClassManager\DynamoDb\DynamoDb\DynamoDbQuery;
use ClassManager\DynamoDb\DynamoDb\DynamoDbResult;
use ClassManager\DynamoDb\DynamoDb\LastEvaluatedKey;
use ClassManager\DynamoDb\DynamoDb\Relation;
use ClassManager\DynamoDb\Exceptions\InvalidRelation;
use ClassManager\DynamoDb\Models\DynamoDbModel;
use ClassManager\DynamoDb\Traits\UsesDynamoDbClient;

class DynamoDbQueryBuilder
{
    use UsesDynamoDbClient;

    /**
     * Populated using ->where('field', 'value')
     * @var array<string, string> $filters
     */
    protected array $filters = [];

    protected ?string $index = null;

    protected ?int $limit = null;

    protected ?LastEvaluatedKey $after = null;

    protected ?DynamoDbModel $model = null;

    protected bool $raw = false;

    /**
     * @var array<Relation> list of relations to load when the query is processed
     */
    protected array $relationList = [];

    protected bool $sortOrderDescending = false;

    protected ?string $table = null;

    protected bool $withData = false;

    public function get(): DynamoDbResult
    {
        return (new DynamoDbQuery())->query([
            'filters' => $this->filters,
            'after' => $this->after,
            'index' => $this->index,
            'limit' => $this->limit,
            'model' => $this->model,
            'raw' => $this->raw,
            'relations' => $this->relationList,
            'sortDescending' => $this->sortOrderDescending,
            'table' => $this->table,
            'withData' => $this->withData,
        ]);
    }

    public function getAll(): DynamoDbResult
    {
        $params = [
            'after' => $this->after ?? null,
            'filters' => $this->filters,
            'index' => $this->index,
            'limit' => $this->limit,
            'model' => $this->model,
            'raw' => $this->raw,
            'relations' => $this->relationList,
            'sortDescending' => $this->sortOrderDescending,
            'table' => $this->table,
            'withData' => $this->withData,
        ];

        $data = collect();

        do {
            $result = (new DynamoDbQuery())->query($params);

            $data->push(...$result->results);

            if ($result->hasMoreResults()) {
                $params['after'] = $result->lastEvaluatedKey;
                continue;
            }

            break;
        } while (true);

        return new DynamoDbResult(
            $data->toArray(),
            $this->raw,
        );
    }

    public function first(): ?DynamoDbModel
    {
        return (new DynamoDbQuery())->query([
            'after' => $this->after ?? null,
            'filters' => $this->filters,
            'index' => $this->index,
            'limit' => 1,
            'model' => $this->model,
            'raw' => $this->raw,
            'relations' => $this->relationList,
            'sortDescending' => $this->sortOrderDescending,
            'table' => $this->table,
            'withData' => $this->withData,
        ])->results->first();
    }

    /**
     * @param LastEvaluatedKey|null $previous the Sort Key of the last result to use for pagination.  Fetches all results _after_ this one
     */
    public function paginate(?LastEvaluatedKey $previous = null): DynamoDbResult
    {
        return (new DynamoDbQuery())->query([
            'after' => $previous ?? $this->after ?? null,
            'filters' => $this->filters,
            'index' => $this->index,
            'limit' => $this->limit,
            'model' => $this->model,
            'raw' => $this->raw,
            'relations' => $this->relationList,
            'sortDescending' => $this->sortOrderDescending,
            'table' => $this->table,
            'withData' => $this->withData,
        ]);
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Allow for simple paginating on a query by setting the last known sort key and we'll fetch results _after_ that one.
     */
    public function after(LastEvaluatedKey $after): self
    {
        $this->after = $after;

        return $this;
    }

    public function model(DynamoDbModel $model): self
    {
        $this->model = $model;

        return $this;
    }

    public static function query(): self
    {
        return new self();
    }

    /**
     * Will return the raw data fetched from DynamoDb rather than coercing it into models
     * @return $this
     */
    public function raw(): self
    {
        $this->raw = true;
        return $this;
    }

    public function sortAscending(): self
    {
        $this->sortOrderDescending = false;

        return $this;
    }

    public function sortDescending(): self
    {
        $this->sortOrderDescending = true;

        return $this;
    }

    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function where(...$props): self
    {
        $this->filters[] = $props;
        return $this;
    }

    public function withIndex(string $index): self
    {
        $this->index = $index;
        return $this;
    }

    public function withData(): self
    {
        $this->withData = true;
        return $this;
    }

    public function withRelation(string $relationName): self
    {
        if (!$this->model->hasRelation($relationName)) {
            throw new InvalidRelation($relationName);
        }

        $relation = $this->model->{$relationName}();

        // ensure this is an actual relationship
        if ($relation instanceof Relation) {
            $this->relationList[$relationName] = $relation;
        }

        return $this;
    }
}
