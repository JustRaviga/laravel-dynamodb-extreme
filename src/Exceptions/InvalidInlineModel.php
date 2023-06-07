<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Exceptions;

use JustRaviga\DynamoDb\Models\DynamoDbModel;

final class InvalidInlineModel extends \RuntimeException
{
    public function __construct(DynamoDbModel $model)
    {
        parent::__construct("Invalid inline model {{$model::class}}");
    }
}
