<?php

declare(strict_types=1);

namespace JustRaviga\LaravelDynamodbExtreme\Traits;

use JustRaviga\LaravelDynamodbExtreme\DynamoDb\DynamoDbAdapter;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\DynamoDbAdapterNotInContainer;
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
