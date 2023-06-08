<?php

namespace Tests\Unit;

use Illuminate\Support\Collection;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\PropertyNotFillable;
use Tests\Resources\DemoModel;

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
