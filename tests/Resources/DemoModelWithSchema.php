<?php

namespace Tests\Resources;

use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;

/**
 * @property string $pk
 * @property string $sk
 * @property string $name
 */
class DemoModelWithSchema extends DynamoDbModel {
    protected static string $table = 'test';

    public array $fillable = [
        'pk',
        'sk',

        'name',
    ];

    protected array $schema = [
        'name' => 'required|string|max:255',
    ];
}
