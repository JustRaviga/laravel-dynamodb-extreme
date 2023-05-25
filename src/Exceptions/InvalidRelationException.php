<?php

namespace ClassManager\DynamoDb\Exceptions;

class InvalidRelationException extends \RuntimeException
{
    public function __construct(string $relation)
    {
        parent::__construct("Invalid relation {{$relation}}");
    }
}
