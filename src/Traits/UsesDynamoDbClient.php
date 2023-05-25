<?php

namespace ClassManager\DynamoDb\Traits;

use ClassManager\DynamoDb\DynamoDb\Client;
use ClassManager\Exceptions\DynamoDbClientNotInContainerException;
use Illuminate\Container\EntryNotFoundException;

trait UsesDynamoDbClient
{
    public function getClient(): Client
    {
        try {
            return app()->get('dynamodb');
        } catch (EntryNotFoundException $e) {
            throw new DynamoDbClientNotInContainerException();
        }
    }
}
