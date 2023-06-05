<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Result;
use Aws\Sdk;
use ClassManager\DynamoDb\Enums\DynamoDbQueryType;
use ClassManager\DynamoDb\Exceptions\DynamoDbQueryError;
use Illuminate\Support\Facades\Log;

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

    /**
     * @param DynamoDbQueryType $type
     * @param array $args
     * @return Result
     */
    protected function _query(DynamoDbQueryType $type, array $args): Result
    {
        $this->logQuery($type, $args);
        try {
            return $this->instance->{$type->value}($args);
        } catch (\Throwable $t) {
            Log::warning($t->getMessage());
            throw new DynamoDbQueryError($t);
        }
    }

    /**
     * @param array<string,string> $args
     */
    public function getItem(array $args): Result
    {
        return $this->_query(DynamoDbQueryType::GET, $args);
    }

    /**
     * @param array<string,string> $args
     */
    public function putItem(array $args): Result
    {
        return $this->_query(DynamoDbQueryType::PUT, $args);
    }

    /**
     * @param array<string,string> $args
     */
    public function updateItem(array $args): Result
    {
        return $this->_query(DynamoDbQueryType::UPDATE, $args);
    }

    /**
     * @param array<string,string> $args
     */
    public function deleteItem(array $args): Result
    {
        return $this->_query(DynamoDbQueryType::DELETE, $args);
    }

    /**
     * @param array<string,string> $args
     */
    public function query(array $args): Result
    {
        return $this->_query(DynamoDbQueryType::QUERY, $args);
    }

    /**
     * Converts a php variable into a marshalled item for use in Dynamo, e.g:
     * "string" -> {"S": "string"}
     * @return array<string,string>
     */
    public function marshalItem(mixed $item): array
    {
        return $this->marshaler->marshalItem($item);
    }

    /**
     * Converts a php variable into a marshalled value for use in Dynamo, e.g:
     * "string" -> {"S": "string"}
     * @return array<string,string>
     */
    public function marshalValue(mixed $value): array
    {
        return $this->marshaler->marshalValue($value);
    }

    /**
     * Takes a marshalled value like {"S": "this is a string"} and returns a php variable equivalent
     * e.g. "this is a string"
     * @param array<string,string> $value
     */
    public function unmarshalValue(array $value): mixed
    {
        return $this->marshaler->unmarshalValue($value);
    }

    /**
     * @return array<string,string>
     */
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

    /**
     * @param array<string,string> $query
     */
    protected function logQuery(DynamoDbQueryType $type, array $query): void
    {
        if ($this->shouldLogQueries) {
            info(strtoupper($type->value) . ' -> ' . json_encode($query));
        }
    }
}
