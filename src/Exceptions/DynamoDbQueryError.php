<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Exceptions;

use Throwable;

final class DynamoDbQueryError extends \RuntimeException
{
    public function __construct(Throwable $previous = null)
    {
        parent::__construct('An error occurred executing the DynamoDb query', previous: $previous);
    }
}
