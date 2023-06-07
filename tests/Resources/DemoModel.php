<?php

namespace Tests\Resources;

use JustRaviga\DynamoDb\Models\DynamoDbModel;

/**
 * @property string $pk
 * @property string $mapped
 * @property string $test
 * @property string $test2
 */
class DemoModel extends DynamoDbModel {
    protected static string $table = 'test';

    protected array $fieldMappings = [
        'sk' => 'mapped',
    ];

    public array $fillable = [
        'pk',
        'mapped',
        'gsi1_pk',
        'gsi1_sk',

        'test',
        'test2',
    ];

    public function defaultSortKey(): string
    {
        // This is different to the default applied by the DynamoDbModel
        return 'DEMO_MODEL';
    }
}
