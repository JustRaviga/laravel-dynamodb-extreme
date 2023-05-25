<?php

namespace ClassManager\DynamoDb\Exceptions;

class DynamoDbClientNotInContainerException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct("The DynamoDb client has not been injected into the container!");
    }
}
