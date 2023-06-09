<?php

namespace Tests\Resources;

use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;

/**
 * @property string $pk
 * @property string $sk
 * @property string $id
 * @property string $test
 * @property string $test2
 */
class DemoModelInlineRelation extends DynamoDbModel
{
    public static ?string $parent = DemoModelWithInlineRelation::class;

    public array $fillable = [
        'pk',
        'sk',
        'id',
        'test',
        'test2',
    ];

    public function uniqueKey(): string
    {
        return $this->id;
    }

    public function uniqueKeyName(): string
    {
        return 'id';
    }

    public function fieldName(): string
    {
        return 'map';
    }
}
