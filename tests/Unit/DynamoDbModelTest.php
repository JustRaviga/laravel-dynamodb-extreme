<?php

namespace Tests\Unit;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JustRaviga\LaravelDynamodbExtreme\DynamoDbHelpers;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\AttributeCastError;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\PartitionKeyNotSet;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\PropertyNotFillable;
use Tests\Resources\DemoModel;
use Tests\Resources\DemoModelInlineRelation;
use Tests\Resources\DemoModelWithCasts;
use Tests\Resources\DemoModelWithDefaultAttributes;
use Tests\Resources\DemoModelWithInlineRelation;
use Tests\Resources\DemoModelWithSchema;

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

    $model = $model::findOrFail($model->pk, $model->sk);

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
it('can cast multiple data types to json', function() {
    $model = DemoModelWithCasts::make([
        // scenario 1: value is an object, we want an object
        'object_field' => (object) ['hello' => 'world'],

        // scenario 2: value is an object, we want an array
        'array_field' => (object) ['hello' => 'world'],
    ]);

    expect($model->object_field)->toBeObject()
        ->and($model->object_field)->toHaveProperty('hello')
        ->and($model->array_field)->toBeArray()
        ->and($model->array_field)->toHaveKey('hello');
});
it('throws an error when casting invalid json to an array', function() {
    $model = DemoModelWithCasts::make([
        'array_field' => '["hello": "world"]', // invalid because an assoc. array should be in curly braces
    ]);
})->throws(AttributeCastError::class);
it('correctly casts a custom cast value', function() {
    $model = DemoModelWithCasts::make([
        'reversed' => 'hello',
    ]);

    expect($model->reversed)->toBe('olleh');
});
it('returns the same data if a cast cannot be used', function() {
    $model = DemoModelWithCasts::make([
        'no_cast' => 'value',
    ]);

    expect($model->no_cast)->toBe('value');
});
it('fails to cast a custom cast value that is malformed', function() {
    $model = DemoModelWithCasts::make([
        'invalid' => 'value',
    ]);
})->throws(AttributeCastError::class);
it('correctly passes validation when making a model instance', function() {
    $value = 'hello';

    // will be validated automatically when adding each attribute
    $model = DemoModelWithSchema::make([
        'name' => $value,
    ]);

    expect($model->name)->toBe($value);
});
it('correctly fails validation when making a model instance', function() {
    $value = [];
    $model = DemoModelWithSchema::make([
        'name' => $value,
    ]);
})->throws(ValidationException::class);
it('correctly passes validation when setting an attribute to a model', function() {
    $value = 'hello';
    $model = DemoModelWithSchema::make();

    $model->name = $value;

    expect($model->name)->toBe($value);
});
it('correctly fails validation when setting an attribute to a model', function() {
    $value = [];
    $model = DemoModelWithSchema::make();

    $model->name = $value;
})->throws(ValidationException::class);
it('sets default values on attributes that are fillable', function() {
    $model = new DemoModelWithDefaultAttributes();

    expect($model->name)->toBe('Fred');
});
it('creates default partition key based on parent model', function() {
    $model = new DemoModelInlineRelation();

    expect($model->pk)
        ->toStartWith(DynamoDbHelpers::upperCaseClassName(DemoModelWithInlineRelation::class) . '#');
});
it('uses table name based on parent model', function() {
    $model = new DemoModelInlineRelation();
    $parent = new DemoModelWithInlineRelation();

    expect($model::table())
        ->toBe($parent::table());
});
it('returns a models attributes when casting to array', function() {
    $model = DemoModel::make();
    $array = $model->toArray();

    expect($array)->toBeArray()
        ->and($array)->toHaveKeys(['pk', 'mapped']);
});
it('can get empty unique key name when not overridden', function() {
    $model = new DemoModel();

    expect($model->uniqueKeyName())->toBe('');
});
it('throws an error when attempting to get an unfillable attribute', function() {
    $model = new DemoModel();

    $model->something;
})->throws(PropertyNotFillable::class);
