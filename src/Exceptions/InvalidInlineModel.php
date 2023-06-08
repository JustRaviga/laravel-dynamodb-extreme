<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\Exceptions;

use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;
use RuntimeException;

final class InvalidInlineModel extends RuntimeException
{
    public function __construct(DynamoDbModel $model)
    {
        $class = $model::class;
        parent::__construct("Invalid inline model {{$class}}");
    }
}
