<?php

namespace Tests\Unit;

use JustRaviga\DynamoDb\Exceptions\PropertyNotFillable;
use Tests\Resources\DemoModel;

test('can instantiate', function() {
    // These models should be instantiatable with no side effects
    $model = new DemoModel();

    expect($model)->toBeInstanceOf(DemoModel::class);
});

test('can set properties manually', function() {
    $model = new DemoModel();
    $value = 'test';

    $model->pk = $value;

    expect($model->pk)->toBe($value);
});

test('cannot set unfillable property', function() {
    $model = new DemoModel();

    // 'unfillable' does not appear in the $fillable property list
    $model->unfillable = false;
})->throws(PropertyNotFillable::class);

test('cannot set property mapped property', function() {
    $model = new DemoModel();

    // 'sk' is mapped to 'mapped' so we shouldn't be able to set it
    $model->sk = 'test';
})->throws(PropertyNotFillable::class);

test('can set mapped property', function() {
    $model = new DemoModel();
    $value = 'test';
    $model->mapped = $value;

    expect($model->mapped)->toBe($value);
});
