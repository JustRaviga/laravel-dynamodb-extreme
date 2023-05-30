<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Exceptions;

final class InvalidIndex extends \RuntimeException
{
    public function __construct(string $index)
    {
        parent::__construct("Invalid index {{$index}}");
    }
}
