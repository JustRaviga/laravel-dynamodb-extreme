<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Traits;

use JustRaviga\DynamoDb\DynamoDb\Client;
use JustRaviga\DynamoDb\Exceptions\DynamoDbClientNotInContainer;
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
