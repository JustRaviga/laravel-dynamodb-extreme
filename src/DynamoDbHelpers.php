<?php

namespace ClassManager\DynamoDb;

class DynamoDbHelpers
{
    public static function upperCaseClassName(string $class): string
    {
        return strtoupper(substr($class, strrpos($class, '\\') + 1));
    }
}
