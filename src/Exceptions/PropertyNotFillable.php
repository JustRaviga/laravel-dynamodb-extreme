<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Exceptions;

final class PropertyNotFillable extends \RuntimeException
{
    public function __construct(string $property)
    {
        parent::__construct("Property {{$property}} is not fillable");
    }
}
