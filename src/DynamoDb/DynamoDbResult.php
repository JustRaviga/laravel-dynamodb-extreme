<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\DynamoDb;

use Illuminate\Support\Collection;

readonly class DynamoDbResult
{
    public Collection $results;

    public function __construct(
        array $results,
        public bool $raw = false,
        public ?LastEvaluatedKey $lastEvaluatedKey = null,
    ) {
        $this->results = collect($results);
    }

    public function hasMoreResults(): bool {
        return $this->lastEvaluatedKey !== null;
    }
}
