<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\Traits;

use JustRaviga\LaravelDynamodbExtreme\DynamoDb\Client;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\DynamoDbClientNotInContainer;
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
