<?php

namespace ClassManager\DynamoDb\DynamoDb;

use Illuminate\Support\Collection;

readonly class DynamoDbResult
{
    public Collection $results;
    public readonly int $count;

    public function __construct(
        array $results,
        public readonly bool $raw = false
    ) {
        $this->results = collect($results);
        $this->count = count($results);
    }
}
