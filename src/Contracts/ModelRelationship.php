<?php

declare(strict_types=1);

namespace JustRaviga\DynamoDb\Contracts;

use Illuminate\Support\Collection;

interface ModelRelationship
{
    public function get(): Collection;
}
