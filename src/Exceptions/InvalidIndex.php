<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Exceptions;

class InvalidIndexException extends \RuntimeException
{
    public function __construct(string $index)
    {
        parent::__construct("Invalid index {{$index}}");
    }
}
