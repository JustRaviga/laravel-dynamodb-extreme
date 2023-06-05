<?php

namespace ClassManager\DynamoDb\Enums;

enum DynamoDbQueryType: string
{
    case PUT = 'putItem';
    case GET = 'getItem';
    case UPDATE = 'updateItem';
    case DELETE = 'deleteItem';
    case QUERY = 'query';
}
