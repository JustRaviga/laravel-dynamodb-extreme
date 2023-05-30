<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Sdk;

class Client
{
    protected DynamoDbClient $instance;
    protected Marshaler $marshaler;

    protected bool $shouldLogQueries = false;

    public function __construct()
    {
        $this->shouldLogQueries = config('dynamodb.defaults.log_queries', false);
        $sdk = new Sdk($this->config());

        $this->instance = $sdk->createDynamoDb();
        $this->marshaler = new Marshaler();
    }

    public function getItem(array $args): \Aws\Result
    {
        $this->logQuery('get', $args);
        return $this->instance->getItem($args);
    }

    public function putItem(array $args): \Aws\Result
    {
        $this->logQuery('put', $args);
        return $this->instance->putItem($args);
    }

    public function updateItem(array $args): \Aws\Result
    {
        $this->logQuery('update', $args);
        return $this->instance->updateItem($args);
    }

    public function deleteItem(array $args): \Aws\Result
    {
        $this->logQuery('delete', $args);
        return $this->instance->deleteItem($args);
    }

    public function query(array $args): \Aws\Result
    {
        $this->logQuery('query', $args);
        return $this->instance->query($args);
    }

    public function marshalItem(mixed $item): array
    {
        return $this->marshaler->marshalItem($item);
    }

    public function marshalValue(mixed $value): mixed
    {
        return $this->marshaler->marshalValue($value);
    }

    public function unmarshalValue(array $value): mixed
    {
        return $this->marshaler->unmarshalValue($value);
    }

    protected function config(): array
    {
        return [
            'region' => config('dynamodb.region'),
            'version' => config('dynamodb.version'),
            'credentials' => [
                'key' => config('dynamodb.credentials.key'),
                'secret' => config('dynamodb.credentials.secret'),
            ],
            'endpoint' => config('dynamodb.endpoint')
        ];
    }

    protected function logQuery(string $type, array $query): void
    {
        if ($this->shouldLogQueries) {
            info(strtoupper($type) . ' -> ' . json_encode($query));
        }
    }
}
