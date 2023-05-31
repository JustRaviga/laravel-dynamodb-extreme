<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb;

use ClassManager\DynamoDb\DynamoDb\Client;
use ClassManager\DynamoDb\DynamoDb\ComparisonBuilder;
use ClassManager\DynamoDb\DynamoDb\Comparisons\Comparison;
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
        $response = self::client()->query($queryParams);

        // If requesting raw output, just return the list of item data
        // NB: This can't be used with "withData" as we need a model to map indexes to partition keys
        if ($this->model === null || $this->raw === true) {
            return collect($response['Items'])->map(
                fn (array $item) => collect($item)->map(
                    fn($property) => self::client()->unmarshalValue($property)
                )->toArray()
            );
        }

        // Build model objects based on relations mentioned in the model that's been set
        $models = collect($response['Items'])->map(fn ($item) => $this->buildModelFromItem($item));

        // Attach relationships between models
        if (count($this->relationList)) {
            return collect([$this->attachModelRelations($models)]);
        }

        return $models;
    }

    /**
     * @param Collection<DynamoDbModel> $models
     */
    protected function attachModelRelations(Collection $models): DynamoDbModel
    {
        // Find the instance of the base model
        /** @var DynamoDbModel $baseModel */
        $baseModel = $models->first(function (DynamoDbModel $model) {
            return $model::class === $this->model::class;
        });

        assert($baseModel instanceof $this->model);

        // Look at each other model being returned and attach them to relations on the base model
        $models->each(function ($model): void {
            foreach($this->relationList as $relationName => $relation) {
                if ($this->model->hasRelation($relationName)) {
                    $relation->add($model);
                }
            }
        });

        return $baseModel;
    }

    /**
     * @param array<string,array<string,string|number|bool>|string|number|bool $item
     */
    protected function buildModelFromItem(array $item): DynamoDbModel
    {
        if ($this->withData) {
            // make additional request to fetch more data from dynamo
            $class = $this->model;
            return $class::find(
                self::client()->unmarshalValue($item[$class::partitionKey()]),
                self::client()->unmarshalValue($item[$class::sortKey()])
            );
        }

        foreach ($this->relationList as $relation) {
            // Check relations that we've been asked to load to see if they match with the item being created

            assert($relation instanceof Relation);

            $class = $relation->relatedModel();
            /** @var DynamoDbModel $model */
            $model = new $class();

            $comparer = ComparisonBuilder::fromArray($relation->relation());

            // This gets the values to compare against.
            // We use array_slice to account for "between" that has 2 values
            $values = array_slice($relation->relation(), 2);

            $modelSortKey = self::client()->unmarshalValue($item[$model->sortKey()]);

            // We matched the relation!
            if ($comparer->compare([$modelSortKey, ...$values])) {
                return $model->fill(collect($item)->map(
                    fn ($property) => self::client()->unmarshalValue($property)
                )->toArray());
            }
        }

        return $this->model::make(collect($item)->map(
            fn ($property) => self::client()->unmarshalValue($property)
        )->toArray());
    }

    /**
     * @return array<int,string>
     */
    protected function buildQueryExprAttrNames(Collection $parsedFilters): array
    {
        return $parsedFilters
            ->mapWithKeys(fn (Comparison $filter, $key) => $filter->expressionAttributeName())
            ->toArray();
    }

    /**
     * @return array<int,string>
     */
    protected function buildQueryExprAttrValues(Collection $parsedFilters): array
    {
        return $parsedFilters
            ->mapWithKeys(fn (Comparison $filter) => $filter->expressionAttributeValue())
            ->map(fn (string $value) => self::client()->marshalValue($value))
            ->toArray();
    }

    protected function buildQueryKeyConditions(Collection $parsedFilters): string
    {
        return $parsedFilters
            ->map(fn ($filter) => (string) $filter)
            ->implode(' AND ');
    }

    /**
     * @return array<string,string|array<string|int,string>>
     */
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

    public function get(): Collection
    {
        return $this->_query();
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

    public function first(): DynamoDbModel
    {
        return $this->_query()->first();
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

    /**
     * @return array<string,string>
     */
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
                throw new QueryBuilderInvalidQuery("Cannot Query using {{$attributeName}}");
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
                throw new QueryBuilderInvalidQuery("Cannot Query using {{$attributeName}}");
            }
        }

        return $mappedFilters;
    }

    protected function validateFilters(): Collection
    {
        // We can only have a maximum of 2 filters (partition and sort key)
        if (count($this->filters) > 2) {
            throw new QueryBuilderInvalidQuery('Can only search based on Partition key and Sort key');
        }

        $mappedFilters = $this->model !== null
            ? $this->validateFiltersAgainstModel()
            : $this->filters;

        return collect($mappedFilters)->map(fn ($filter) => ComparisonBuilder::fromArray($filter));
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
