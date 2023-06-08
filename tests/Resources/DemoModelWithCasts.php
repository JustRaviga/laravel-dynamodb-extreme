<?php

namespace Tests\Resources;

use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;

/**
 * @property string $pk
 * @property string $sk
 * @property string $array_field
 * @property string $json_field
 * @property string $object_field
 * @property string $list_field
 * @property string $map_field
 * @property string $string_set_field
 * @property string $number_set_field
 * @property string $binary_set_field
 * @property string $collection_field
 */
class DemoModelWithCasts extends DynamoDbModel {
    protected static string $table = 'test';

    public array $fillable = [
        'pk',
        'sk',

        'array_field',
        'json_field',
        'object_field',
        'list_field',
        'map_field',
        'string_set_field',
        'number_set_field',
        'binary_set_field',
        'collection_field',

        // todo
        'date_field',
    ];

    protected array $casts = [
        'array_field' => 'array',
        'json_field' => 'json',
        'object_field' => 'object',
        'list_field' => 'list',
        'map_field' => 'map',
        'string_set_field' => 'set:string',
        'number_set_field' => 'set:number',
        'binary_set_field' => 'set:binary',
        'collection_field' => 'collection',
    ];
}
