<?php

namespace Tests\Resources;

use Illuminate\Database\Eloquent\Model;
use JustRaviga\LaravelDynamodbExtreme\Contracts\CastsAttributes;
use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;

class DemoAttributeCast implements CastsAttributes
{
    public function get(DynamoDbModel $model, string $key, mixed $value, array $attributes): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // dummy caster reverses a given string
        return strrev($value);
    }

    public function set(DynamoDbModel $model, string $key, mixed $value, array $attributes): mixed
    {
        return strrev($value);
    }
}
