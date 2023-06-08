<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\DynamoDb;

use JustRaviga\LaravelDynamodbExtreme\Traits\UsesDynamoDbClient;

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
