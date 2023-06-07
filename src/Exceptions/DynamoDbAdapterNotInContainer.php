<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Exceptions;

use RuntimeException;

final class DynamoDbAdapterNotInContainer extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The DynamoDb adapter has not been injected into the container!');
    }
}
