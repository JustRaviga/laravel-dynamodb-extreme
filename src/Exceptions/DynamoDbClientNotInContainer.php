<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Exceptions;

final class DynamoDbClientNotInContainer extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The DynamoDb client has not been injected into the container!');
    }
}
