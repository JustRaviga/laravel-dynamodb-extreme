<?php

namespace ClassManager\DynamoDb\DynamoDb;

use Illuminate\Support\Collection;

interface ModelRelationship
{
    public function get(): Collection;
}
