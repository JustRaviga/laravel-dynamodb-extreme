<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb;

use ClassManager\DynamoDb\DynamoDb\Client;
use ClassManager\DynamoDb\DynamoDb\ComparisonBuilder;
use ClassManager\DynamoDb\DynamoDb\Comparisons\Comparison;
use ClassManager\DynamoDb\DynamoDb\DynamoDbQuery;
use ClassManager\DynamoDb\DynamoDb\DynamoDbResult;
use ClassManager\DynamoDb\DynamoDb\Relation;
use ClassManager\DynamoDb\Exceptions\InvalidRelation;
use ClassManager\DynamoDb\Exceptions\QueryBuilderInvalidQuery;
use ClassManager\DynamoDb\Models\DynamoDbModel;
use ClassManager\DynamoDb\Traits\UsesDynamoDbClient;
use Illuminate\Support\Collection;

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
        // recursively fetch pages of data until we have all the results
        return (new DynamoDbQuery())->query([
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

    public function first(): DynamoDbModel
    {
        return (new DynamoDbQuery())->query([
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

    public function paginate(?DynamoDbModel $previous = null): DynamoDbResult
    {
        // todo

        // if $previous is null, we're making the first request for paginated data
        // if $previous is set, use its partition/sort key as part of the query to get the next page of results
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

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
