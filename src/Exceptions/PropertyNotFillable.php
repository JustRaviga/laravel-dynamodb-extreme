<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Exceptions;

class PropertyNotFillableException extends \RuntimeException
{
    public function __construct(string $property)
    {
        parent::__construct("Property {{$property}} is not fillable");
    }
}
