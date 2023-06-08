<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\Exceptions;

final class InvalidRelation extends \RuntimeException
{
    public function __construct(string $relation)
    {
        parent::__construct("Invalid relation {{$relation}}");
    }
}
