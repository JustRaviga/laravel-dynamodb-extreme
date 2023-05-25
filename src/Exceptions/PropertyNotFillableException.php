<?php

namespace ClassManager\DynamoDb\Exceptions;

class PropertyNotFillableException extends \RuntimeException
{
    public function __construct(string $property)
    {
        parent::__construct("Property {{$property}} is not fillable");
    }
}
