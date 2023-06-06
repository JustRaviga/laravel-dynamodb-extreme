<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Traits;

use ClassManager\DynamoDb\DynamoDb\Client;
use ClassManager\DynamoDb\Exceptions\DynamoDbClientNotInContainer;
use Illuminate\Container\EntryNotFoundException;
use Psr\Container\ContainerExceptionInterface;

trait UsesDynamoDbClient
{
    public static function client(): Client
    {
        try {
            return app()->get('dynamodb');
        } catch (EntryNotFoundException | ContainerExceptionInterface) {
            throw new DynamoDbClientNotInContainer();
        }
    }
}
