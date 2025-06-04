<?php

namespace Tests\Resources;

use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;

/**
 * @property string $pk
 * @property string $sk
 * @property string $name
 */
class DemoModelWithDefaultAttributes extends DynamoDbModel {
    protected static string $table = 'test';

    public array $fillable = [
        'pk',
        'sk',
        'name',
    ];

    public function defaultValues(): array
    {
        return [
            'name' => 'Fred',
        ];
    }
}
