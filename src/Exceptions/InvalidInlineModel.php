<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb\Exceptions;

use ClassManager\DynamoDb\Models\DynamoDbModel;

final class InvalidInlineModel extends \RuntimeException
{
    public function __construct(DynamoDbModel $model)
    {
        parent::__construct("Invalid inline model {{$model::class}}");
    }
}
