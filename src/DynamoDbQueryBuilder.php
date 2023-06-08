<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme;

use JustRaviga\LaravelDynamodbExtreme\DynamoDb\DynamoDbQuery;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\DynamoDbResult;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\LastEvaluatedKey;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\Relation;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\InvalidRelation;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\QueryBuilderInvalidQuery;
use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;
use JustRaviga\LaravelDynamodbExtreme\Traits\UsesDynamoDbClient;

class DynamoDbQueryBuilder
{
    use UsesDynamoDbClient;

    /**
     * Populated using ->where('field', 'value')
     * @var array<string, string>
     */
    protected array $filters = [];

    /**
     * If not set explicitly using withIndex(), will attempt to be guessed when making a query
     * @var string|null
     */
    protected ?string $index = null;

    /**
     * Maximum number of results that will be returned
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * Set using after(), will tell the query builder to return results starting after this one
     * @var LastEvaluatedKey|null
     */
    protected ?LastEvaluatedKey $after = null;

    /**
     * Allow querying against a specific Model, provides extra features like relation-mapping and key validation
     * @var DynamoDbModel|null
     */
    protected ?DynamoDbModel $model = null;

    /**
     * Whether to return results as Model objects, or as an array
     * @var bool
     */
    protected bool $raw = false;

    /**
     * @var array<Relation> list of relations to load when the query is processed
     */
    protected array $relationList = [];

    protected bool $sortOrderDescending = false;

    protected ?string $table = null;

    protected bool $withData = false;

    /**
     * Gets a single group of results.  This is probably all you'll ever need to do, unless you think there's danger
     * of the query response being >1mb.  In which case, use getAll() or paginated().
     */
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

    /**
     * Recursively makes queries to get results until there are no more results.  Only use this method if you expect
     * to hit the query size limit, otherwise just use get().
     */
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

    /**
     * Returns the first result from a query.  Automatically applies a limit of 1 item to keep queries slick.
     */
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
     * Makes a single request, optionally accepting an Exclusive Start Key of the last result returned for pagination.
     * Basically this is alternative syntax for QueryBuilder()->after(LastEvaluatedKey $key)->get().
     * @param LastEvaluatedKey|null $previous the Exclusive Start Key (combination of pk/sk) of the last result to use
     *   for pagination.  Fetches results _after_ this one
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
     * Allow for simple paginating on a query by setting the last known sort key to fetch results _after_ that one.
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
        if ($this->model === null) {
            throw new QueryBuilderInvalidQuery(
                'Cannot request results with relations when not querying against a Model'
            );
        }

        if (!$this->model->hasRelation($relationName)) {
            throw new InvalidRelation($relationName);
        }

        $relation = $this->model->{$relationName}();

        // Ensure this is an actual relationship
        if ($relation instanceof Relation) {
            $this->relationList[$relationName] = $relation;
        }

        return $this;
    }
}
