<?php

namespace Tests\Unit;

use Illuminate\Support\Collection;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\PropertyNotFillable;
use Tests\Resources\DemoModel;
use Tests\Resources\DemoModelWithCasts;

it('can instantiate a model', function() {
    // These models should be instantiatable with no side effects
    $model = new DemoModel();

    expect($model)->toBeInstanceOf(DemoModel::class);
});
it('can set properties manually', function() {
    $model = new DemoModel();
    $value = 'test';

    $model->pk = $value;

    expect($model->pk)->toBe($value);
});
it('cannot set unfillable property', function() {
    $model = new DemoModel();

    // 'unfillable' does not appear in the $fillable property list
    $model->unfillable = false;
})->throws(PropertyNotFillable::class);
it('cannot set property mapped property', function() {
    $model = new DemoModel();

    // 'sk' is mapped to 'mapped' so we shouldn't be able to set it
    $model->sk = 'test';
})->throws(PropertyNotFillable::class);
it('can set mapped property', function() {
    $model = new DemoModel();
    $value = 'test';
    $model->mapped = $value;

    expect($model->mapped)->toBe($value);
});
it('correctly casts attributes when creating a model instance', function() {
    $model = new DemoModelWithCasts([
        'json_field' => '["test", "value"]',
        'object_field' => '{"test": "value"}',
        'array_field' => '[1, 2]',
        'list_field' => '["a", "b", "c"]',
        'map_field' => '{"prop": "value"}',
        'string_set_field' => '["a", "b"]',
        'number_set_field' => '[1, 2]',
        'binary_set_field' => '["hello", "world"]',
        'collection_field' => '[1,2,3]',
    ]);

    expect($model->json_field)->toBeArray()
        ->and($model->json_field)->toHaveCount(2)
        ->and($model->object_field)->toBeObject()
        ->and($model->object_field)->toHaveProperty('test')
        ->and($model->array_field)->toBeArray()
        ->and($model->array_field)->toHaveCount(2)
        ->and($model->list_field)->toBeArray()
        ->and($model->list_field)->toHaveCount(3)
        ->and($model->map_field)->toBeObject()
        ->and($model->map_field)->toHaveProperty('prop')
        ->and($model->string_set_field)->toBeArray()
        ->and($model->string_set_field)->toHaveCount(2)
        ->and($model->number_set_field)->toBeArray()
        ->and($model->string_set_field)->toHaveCount(2)
        ->and($model->binary_set_field)->toBeArray()
        ->and($model->string_set_field)->toHaveCount(2)
        ->and($model->collection_field)->toBeInstanceOf(Collection::class)
        ->and($model->collection_field)->toHaveCount(3);
});
it('correctly casts attributes when saving values to dynamodb', function() {
    $model = DemoModelWithCasts::create([
        'json_field' => '["test", "value"]',
        'object_field' => '{"test": "value"}',
        'array_field' => '[1, 2]',
        'list_field' => '["a", "b", "c"]',
        'map_field' => '{"prop": {"attr": "value"}}',
        'string_set_field' => '["a", "b"]',
        'number_set_field' => '[1, 2]',
        'binary_set_field' => '["hello", "world"]',
        'collection_field' => '[1,2,3]',
    ]);

    $model = $model::find($model->pk, $model->sk);

    expect($model->json_field)->toBeArray()
        ->and($model->json_field)->toHaveCount(2)
        ->and($model->object_field)->toBeObject()
        ->and($model->object_field)->toHaveProperty('test')
        ->and($model->array_field)->toBeArray()
        ->and($model->array_field)->toHaveCount(2)
        ->and($model->list_field)->toBeArray()
        ->and($model->list_field)->toHaveCount(3)
        ->and($model->map_field)->toBeObject()
        ->and($model->map_field)->toHaveProperty('prop')
        ->and($model->string_set_field)->toBeArray()
        ->and($model->string_set_field)->toHaveCount(2)
        ->and($model->number_set_field)->toBeArray()
        ->and($model->string_set_field)->toHaveCount(2)
        ->and($model->binary_set_field)->toBeArray()
        ->and($model->string_set_field)->toHaveCount(2)
        ->and($model->collection_field)->toBeInstanceOf(Collection::class)
        ->and($model->collection_field)->toHaveCount(3);
});
