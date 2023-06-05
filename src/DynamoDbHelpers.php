<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb;

class DynamoDbHelpers
{
    public static function upperCaseClassName(string $class): string
    {
        return strtoupper(substr($class, strrpos($class, '\\') + 1));
    }

    public static function listWithoutKeys(array $list, array $keys): array
    {
        $newList = [];
        foreach ($list as $key => $value) {
            if (!in_array($key, $keys)) {
                $newList[$key] = $value;
            }
        }

        return $newList;
    }
}
