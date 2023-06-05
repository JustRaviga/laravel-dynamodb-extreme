<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Traits;

use ClassManager\DynamoDb\DynamoDb\Client;
use ClassManager\DynamoDb\DynamoDb\DynamoDbAdapter;
use ClassManager\DynamoDb\Exceptions\DynamoDbAdapterNotInContainer;
use ClassManager\DynamoDb\Exceptions\DynamoDbClientNotInContainer;
use Illuminate\Container\EntryNotFoundException;
use Psr\Container\ContainerExceptionInterface;

trait UsesDynamoDbAdapter
{
    public static function adapter(): DynamoDbAdapter
    {
        try {
            return app()->get(DynamoDbAdapter::class);
        } catch (EntryNotFoundException | ContainerExceptionInterface) {
            throw new DynamoDbAdapterNotInContainer();
        }
    }
}
