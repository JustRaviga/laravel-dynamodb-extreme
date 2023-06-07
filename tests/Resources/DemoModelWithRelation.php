<?php

namespace Tests\Resources;

use Illuminate\Support\Collection;
use JustRaviga\DynamoDb\DynamoDb\Relation;
use JustRaviga\DynamoDb\Models\DynamoDbModel;

/**
 * @property string $pk
 * @property string $sk
 * @property string $test
 * @property Collection $demoModels
 */
class DemoModelWithRelation extends DynamoDbModel {
    protected static string $table = 'test';

    public array $fillable = [
        'pk',
        'sk',

        'test',
    ];

    public function demoModels(): Relation
    {
        return $this->addRelation(DemoModel::class);
    }
}
