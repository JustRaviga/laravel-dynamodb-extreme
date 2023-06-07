<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Enums;

enum DynamoDbQueryType: string
{
    case PUT = 'putItem';
    case GET = 'getItem';
    case UPDATE = 'updateItem';
    case DELETE = 'deleteItem';
    case QUERY = 'query';
}
