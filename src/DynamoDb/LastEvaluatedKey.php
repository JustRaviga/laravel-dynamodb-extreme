<?php

namespace ClassManager\DynamoDb\DynamoDb;

use ClassManager\DynamoDb\Traits\UsesDynamoDbClient;

readonly class LastEvaluatedKey
{
    use UsesDynamoDbClient;

    public function __construct(
        public string $pkName,
        public string $pkValue,
        public string $skName,
        public string $skValue,
    ) {
    }

    public function toArray(): array
    {
        return [
            $this->pkName => $this->client()->marshalValue($this->pkValue),
            $this->skName => $this->client()->marshalValue($this->skValue),
        ];
    }
}
