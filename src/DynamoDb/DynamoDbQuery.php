<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\DynamoDb;

use JustRaviga\DynamoDb\Exceptions\QueryBuilderInvalidQuery;
use JustRaviga\DynamoDb\Models\DynamoDbModel;
use JustRaviga\DynamoDb\Traits\UsesDynamoDbClient;

class DynamoDbQuery
{
    use UsesDynamoDbClient;

    public function query(array $params): DynamoDbResult
    {
        $params['index'] = $this->guessIndex($params['model'], $params['filters'], $params['index']);

        // Ensure we have correct partition and sort key searches set up
        $parsedFilters = $this->validateFilters($params['model'], $params['filters'], $params['index']);

        $queryParams = $this->buildQueryParams($params, $parsedFilters);

        // Make DynamoDb request
        $response = self::client()->query($queryParams);

        // Make a note of whether more results were available
        // NB: only available when querying against a Model
        $lastEvaluatedKey = $this->getLastEvaluatedKey($params['model'], $response['LastEvaluatedKey'] ?? null);

        // If requesting raw output, just return the list of item data
        // NB: This can't be used with "withData" as we need a model to map indexes to partition keys
        if ($params['model'] === null || $params['raw'] === true) {
            return new DynamoDbResult(
                results: array_map(
                    fn (array $item) => array_map(
                        fn($property) => self::client()->unmarshalValue($property),
                        $item
                    ),
                    $response['Items'],
                ),
                raw: true,
                lastEvaluatedKey: $lastEvaluatedKey,
            );
        }

        // Load all the results into models, we use the relations list here so we know which models to make
        $models = array_map(fn ($item) => $this->buildModelFromItem($item, $params['model'], $params['withData'], $params['relations']), $response['Items']);

        // If any relations have been requested, check each model and see if it has a matching relation.
        // Call the relation to load its contents.
        $this->loadRelations($models, $params['relations']);

        return new DynamoDbResult(
            results: $models,
            lastEvaluatedKey: $lastEvaluatedKey,
        );
    }

    protected function loadRelations(array $models, array $relations): void
    {
        foreach ($models as $model) {
            foreach ($relations as $relationName => $relation) {
                if (method_exists($model, $relationName)) {
                    $modelRelation = $model->{$relationName}();

                    if ($modelRelation instanceof Relation) {
                        $modelRelation->get();
                    }
                }
            }
        }
    }

    protected function guessIndex(?DynamoDbModel $model, array $filters, ?string $selectedIndex): ?string
    {
        // If we're not operating on a model, we can't use this feature
        if ($model === null) {
            return $selectedIndex;
        }

        // If we already have a selected index, use that instead of attempting to guess
        if ($selectedIndex !== null) {
            return $selectedIndex;
        }

        $bestIndex = null;
        $previousBestMatches = 0;

        foreach ($model->globalSecondaryIndexes() as $index => $properties) {
            $foundAttributes = collect($filters)->reduce(
                fn (int $carry, array $filter) => $carry + (int) in_array($model->getReverseMappedPropertyName($filter[0]), $properties),
                0
            );

            if ($foundAttributes === count($filters)) {
                // Every filter applies to this index, assume this is the best index possible
                return $index;
            }

            if ($foundAttributes > $previousBestMatches) {
                // Found a new best index
                $bestIndex = $index;
                $previousBestMatches = $foundAttributes;
            }
        }

        // After searching all the indexes, one of them is the "best match", we'll use that one
        if ($bestIndex !== null) {
            return $bestIndex;
        }

        // No good index found at all
        return null;
    }

    protected function validateFilters(?DynamoDbModel $model, array $filters, ?string $index): array
    {
        // We can only have a maximum of 2 filters (partition and sort key)
        if (count($filters) > 2) {
            throw new QueryBuilderInvalidQuery('Can only search based on Partition key and Sort key');
        }

        // We can only validate the filters if we're using a Model, otherwise we cannot know the attribute names
        $mappedFilters = $model !== null
            ? $this->validateFiltersAgainstModel($model, $filters, $index)
            : $filters;

        return array_map(fn ($filter) => ComparisonBuilder::fromArray($filter), $mappedFilters);
    }

    /**
     * @return array<string,string>
     */
    protected function validateFiltersAgainstModel(DynamoDbModel $model, array $filters, ?string $index): array
    {
        $mappedFilters = array_map(function($filter) use ($model, $index) {
            $filter[0] = $model->getReverseMappedPropertyName($filter[0], $index);
            return $filter;
        }, $filters);

        if (count($mappedFilters) === 1) {
            // Only 1 filter given, it must be the partition key
            [ $attributeName ] = $mappedFilters[0];

            if ($attributeName !== $model::partitionKey($index)) {
                throw new QueryBuilderInvalidQuery("Cannot Query using {{$attributeName}}");
            }
        } else {
            foreach ($mappedFilters as $filter) {
                [ $attributeName ] = $filter;

                if ($attributeName === $model::partitionKey($index)
                    || $attributeName === $model::sortKey($index)
                ) {
                    continue;
                }

                // If this was neither partition key nor sort key, it can't be used
                throw new QueryBuilderInvalidQuery("Cannot Query using {{$attributeName}}");
            }
        }

        return $mappedFilters;
    }

    /**
     * @return array<string,string|array<string|int,string>>
     */
    protected function buildQueryParams(array $params, array $parsedFilters): array
    {
        $queryParams = [
            'TableName' => $params['table'],
            'KeyConditionExpression' => $this->buildQueryKeyConditions($parsedFilters),
            'ExpressionAttributeNames' => $this->buildQueryExprAttrNames($parsedFilters),
            'ExpressionAttributeValues' => $this->buildQueryExprAttrValues($parsedFilters),
        ];

        if ($params['index']) {
            $queryParams['IndexName'] = $params['index'];
        }

        if ($params['index'] === null && $params['model'] !== null) {
            // Consistent read can only be used with the Primary index, and when querying from a model
            $queryParams['ConsistentRead'] = $params['model']->consistentRead();
        }

        if ($params['sortDescending']) {
            $queryParams['ScanIndexForward'] = false;
        }

        if ($params['limit'] !== null) {
            $queryParams['Limit'] = $params['limit'];
        }

        if ($params['after'] !== null) {
            $queryParams['ExclusiveStartKey'] = $params['after']->toArray();
        }

        return $queryParams;
    }

    /**
     * @return array<int,string>
     */
    protected function buildQueryExprAttrNames(array $parsedFilters): array
    {
        $names = [];
        foreach ($parsedFilters as $filter) {
            foreach ($filter->expressionAttributeName() as $key => $value) {
                $names[$key] = $value;
            }
        }
        return $names;
    }

    /**
     * @return array<int,string>
     */
    protected function buildQueryExprAttrValues(array $parsedFilters): array
    {
        $values = [];
        foreach($parsedFilters as $filter) {
            foreach($filter->expressionAttributeValue() as $key => $value) {
                $values[$key] = self::client()->marshalValue($value);
            }
        }

        return $values;
    }

    protected function buildQueryKeyConditions(array $parsedFilters): string
    {
        return implode(' AND ', array_map(fn ($filter) => (string) $filter, $parsedFilters));
    }

    /**
     * @param array<string,array<string,string|number|bool>|string|number|bool $item
     */
    protected function buildModelFromItem(array $item, DynamoDbModel $model, bool $withData, array $relations): DynamoDbModel
    {
        if ($withData) {
            // make additional request to fetch more data from dynamo
            $class = $model;
            return $class::find(
                self::client()->unmarshalValue($item[$class::partitionKey()]),
                self::client()->unmarshalValue($item[$class::sortKey()])
            );
        }

        foreach ($relations as $relation) {
            // Check relations that we've been asked to load to see if they match with the item being created

            assert($relation instanceof Relation);

            $class = $relation->relatedModel();
            /** @var DynamoDbModel $relatedMmodel */
            $relatedModel = new $class();

            $comparer = ComparisonBuilder::fromArray($relation->relation());

            // This gets the values to compare against.
            // We use array_slice to account for "between" that has 2 values
            $values = array_slice($relation->relation(), 2);

            $modelSortKey = self::client()->unmarshalValue($item[$relatedModel->sortKey()]);

            // We matched the relation!
            if ($comparer->compare([$modelSortKey, ...$values])) {
                return $relatedModel->fill(collect($item)->map(
                    fn ($property) => self::client()->unmarshalValue($property)
                )->toArray());
            }
        }

        return $model::make(collect($item)->map(
            fn ($property) => self::client()->unmarshalValue($property)
        )->toArray());
    }

    protected function getLastEvaluatedKey(?DynamoDbModel $model = null, ?array $lastEvaluatedKey = null): ?LastEvaluatedKey
    {
        if ($model === null) {
            return null;
        }

        if (!is_array($lastEvaluatedKey)) {
            return null;
        }

        $skName = $model::sortKey();
        $pkName = $model::partitionKey();

        $skValue = $lastEvaluatedKey[$skName];
        $pkValue = $lastEvaluatedKey[$pkName];

        return new LastEvaluatedKey(
            $pkName,
            $this->client()->unmarshalValue($pkValue),
            $skName,
            $this->client()->unmarshalValue($skValue)
        );
    }
}
