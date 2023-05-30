<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb;

use ClassManager\DynamoDb\DynamoDb\Client;
use ClassManager\DynamoDb\DynamoDb\ComparisonBuilder;
use ClassManager\DynamoDb\DynamoDb\Comparisons\Comparison;
use ClassManager\DynamoDb\DynamoDb\Relation;
use ClassManager\DynamoDb\Exceptions\InvalidRelationException;
use ClassManager\DynamoDb\Exceptions\QueryBuilderInvalidQueryException;
use ClassManager\DynamoDb\Models\DynamoDbModel;
use ClassManager\DynamoDb\Traits\UsesDynamoDbClient;
use Illuminate\Support\Collection;

class DynamoDbQueryBuilder
{
    use UsesDynamoDbClient;

    protected ?string $index = null;

    // Populated using ->where('field', 'value')
    protected array $filters = [];

    protected bool $sortOrderDescending = false;

    protected ?int $limit = null;

    /**
     * @var array<Relation> list of relations to load when the query is processed
     */
    protected array $relationList = [];

    protected bool $raw = false;

    protected ?string $table = null;

    protected ?DynamoDbModel $model = null;

    protected Client $client;

    public static function query(): self
    {
        return new self();
    }

    public function __construct()
    {
        $this->client = $this->getClient();
    }

    public function model(DynamoDbModel $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function where(...$props): self
    {
        $this->filters[] = $props;
        return $this;
    }

    public function sortDescending(): self
    {
        $this->sortOrderDescending = true;

        return $this;
    }
    public function sortAscending(): self
    {
        $this->sortOrderDescending = false;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function table(string $table): self
    {
        $this->table = $table;

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

    public function withIndex(string $index): self
    {
        $this->index = $index;
        return $this;
    }

    public function withRelation(string $relationName): self
    {
        if (!$this->model->hasRelation($relationName)) {
            throw new InvalidRelationException($relationName);
        }

        $relation = $this->model->{$relationName}();

        // ensure this is an actual relationship
        if ($relation instanceof Relation) {
            $this->relationList[$relationName] = $relation;
        }

        return $this;
    }

    protected function guessIndex(): void
    {
        $bestIndex = null;
        $previousBestMatches = 0;

        foreach ($this->model->globalSecondaryIndexes() as $index => $properties) {
            $foundAttributes = collect($this->filters)->reduce(
                fn (int $carry, array $filter) => $carry + (int) in_array($this->model->getReverseMappedPropertyName($filter[0]), $properties),
                0
            );

            if ($foundAttributes === count($this->filters)) {
                // Every filter applies to this index, assume this is the best index possible
                $this->index = $index;
                return;
            }

            if ($foundAttributes > $previousBestMatches) {
                // Found a new best index
                $bestIndex = $index;
                $previousBestMatches = $foundAttributes;
            }
        }

        // After searching all the indexes, one of them is the "best match", we'll use that one
        if ($bestIndex !== null) {
            $this->index = $bestIndex;
        }
    }

    protected function validateFiltersAgainstModel(): array
    {
        $mappedFilters = collect($this->filters)->map(function ($filter) {
            $filter[0] = $this->model->getReverseMappedPropertyName($filter[0], $this->index);
            return $filter;
        })->toArray();

        if (count($mappedFilters) === 1) {
            // Only 1 filter given, it must be the partition key
            [ $attributeName ] = $mappedFilters[0];

            if ($attributeName !== $this->model->partitionKey($this->index)) {
                throw new QueryBuilderInvalidQueryException("Cannot Query using {{$attributeName}}");
            }
        } else {
            foreach ($mappedFilters as $filter) {
                [ $attributeName ] = $filter;

                if ($attributeName === $this->model->partitionKey($this->index)
                    || $attributeName === $this->model->sortKey($this->index)
                ) {
                    continue;
                }

                // If this was neither partition key, nor sort key, it can't be used
                throw new QueryBuilderInvalidQueryException("Cannot Query using {{$attributeName}}");
            }
        }

        return $mappedFilters;
    }

    protected function validateFilters(): Collection
    {
        // We can only have a maximum of 2 filters (partition and sort key)
        if (count($this->filters) > 2) {
            throw new QueryBuilderInvalidQueryException('Can only search based on Partition key and Sort key');
        }

        $mappedFilters = $this->model !== null
            ? $this->validateFiltersAgainstModel()
            : $this->filters;

        return collect($mappedFilters)->map(fn ($filter) => ComparisonBuilder::fromArray($filter));
    }

    protected function _query(): Collection
    {
        // Attempt to guess which index to use only if an index hasn't already been set (through setIndex, for example)
        if ($this->index === null && $this->model !== null ) {
            $this->guessIndex($this->index);
        }

        // Ensure we have correct partition and sort key searches set up
        $parsedFilters = $this->validateFilters();

        $queryParams = $this->buildQueryParams($parsedFilters);

        // DynamoDb request
        $response = $this->client->query($queryParams);

        // If requesting raw output, just return the list of item data
        if ($this->model === null || $this->raw === true) {
            return collect($response['Items'])->map(fn (array $item)
                => collect($item)->map(fn ($property)
                    => $this->client->unmarshalValue($property)
                )->toArray()
            );
        }

        // Build model objects based on relations mentioned in the model that's been set
        $models = collect($response['Items'])->map(fn ($item) =>
            $this->buildModelFromItem($item)
        );

        // Attach relationships between models
        if (count($this->relationList)) {
            return collect([$this->attachModelRelations($models)]);
        }

        return $models;
    }

    public function get(): Collection
    {
        return $this->_query();
    }

    public function first(): DynamoDbModel
    {
        return $this->_query()->first();
    }

    /**
     * @param Collection<DynamoDbModel> $models
     * @return DynamoDbModel
     */
    protected function attachModelRelations(Collection $models): DynamoDbModel
    {
        // Find the instance of the base model
        /** @var DynamoDbModel $baseModel */
        $baseModel = $models->first(function (DynamoDbModel $model) {
            return get_class($model) === get_class($this->model);
        });

        assert($baseModel instanceof $this->model);

        // Look at each other model being returned and attach them to relations on the base model
        $models->each(function ($model) use ($baseModel) {
            foreach($this->relationList as $relationName => $relation) {
                if ($this->model->hasRelation($relationName)) {
                    $relation->add($model);
                }
            }
        });

        return $baseModel;
    }

    protected function buildModelFromItem(array $item): DynamoDbModel
    {
        foreach($this->relationList as $relationName => $relation) {
            // Check relations that we've been asked to load to see if they match with the item being created

            assert($relation instanceof Relation);

            /** @var DynamoDbModel $model */
            $class = $relation->relatedModel();
            $model = new $class;

            $comparer = ComparisonBuilder::fromArray($relation->relation());

            // This gets the values to compare against.
            // We use array_slice to account for "between" that has 2 values
            $values = array_slice($relation->relation(), 2);

            $modelSortKey = $this->client->unmarshalValue($item[$model->sortKey()]);

            // We matched the relation!
            if ($comparer->compare($modelSortKey, ...$values)) {
                return $model->fill(collect($item)->map(fn ($property) =>
                    $this->client->unmarshalValue($property)
                )->toArray());
            }
        }

        return $this->model::make(collect($item)->map(fn ($property) =>
            $this->client->unmarshalValue($property)
        )->toArray());
    }

    protected function buildQueryParams(Collection $parsedFilters): array
    {
        $queryParams = [
            'TableName' => $this->table,
            'KeyConditionExpression' => $this->buildQueryKeyConditions($parsedFilters),
            'ExpressionAttributeNames' => $this->buildQueryExprAttrNames($parsedFilters),
            'ExpressionAttributeValues' => $this->buildQueryExprAttrValues($parsedFilters),
        ];

        if ($this->index) {
            $queryParams['IndexName'] = $this->index;
        }

        if ($this->index === null && $this->model !== null) {
            // Consistent read can only be used with the Primary index, and when querying from a model
            $queryParams['ConsistentRead'] = $this->model->consistentRead();
        }

        if ($this->sortOrderDescending) {
            $queryParams['ScanIndexForward'] = false;
        }

        if ($this->limit !== null) {
            $queryParams['Limit'] = $this->limit;
        }

        return $queryParams;
    }

    protected function buildQueryKeyConditions(Collection $parsedFilters): string
    {
        return $parsedFilters
            ->map(fn ($filter) => (string) $filter)
            ->implode(' AND ');
    }

    protected function buildQueryExprAttrNames(Collection $parsedFilters): array
    {
        return $parsedFilters
            ->mapWithKeys(fn (Comparison $filter, $key) => $filter->expressionAttributeName())
            ->toArray();
    }

    protected function buildQueryExprAttrValues(Collection $parsedFilters): array
    {
        return $parsedFilters
            ->mapWithKeys(fn (Comparison $filter) => $filter->expressionAttributeValue())
            ->map(fn (string $value) => $this->client->marshalValue($value))
            ->toArray();
    }
}
