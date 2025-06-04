<?php

namespace JustRaviga\LaravelDynamodbExtreme\Contracts;

use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;

interface CastsAttributes
{
    /**
     * Transform the attribute from its underlying model values.
     */
    public function get(DynamoDbModel $model, string $key, mixed $value, array $attributes): mixed;

    /**
     * Transform the attribute to its underlying model values.
     */
    public function set(DynamoDbModel $model, string $key, mixed $value, array $attributes): mixed;
}
