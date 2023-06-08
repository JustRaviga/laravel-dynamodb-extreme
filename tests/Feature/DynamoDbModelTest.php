<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\Client;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\QueryBuilderInvalidQuery;
use Ramsey\Uuid\Uuid;
use Tests\Resources\DemoModel;

beforeEach(function() {
    /**
     * Resetting the query count before each test so we can make assertions
     * based on the number of queries we expect to have been made
     */
    Client::$queryCount = 0;
});

it('can create and find an item', function() {
    $value = 'Test title';

    $model = DemoModel::create([
        'test' => $value,
    ]);

    $persisted = DemoModel::find($model->pk, $model->mapped);

    expect($model)->toBeInstanceOf(DemoModel::class)
        ->and($model->test)->toBe($value)
        ->and($persisted)->toBeInstanceOf(DemoModel::class)
        ->and($persisted->pk)->toEqual($model->pk)
        ->and($persisted->mapped)->toEqual($model->mapped)
        ->and($persisted->test)->toEqual($value)
        ->and(Client::$queryCount)->toBe(2);
});
it('applies field mappings when creating', function() {
    $sortKey = 'TEST';

    $model = DemoModel::create([
        // referencing 'sk' here
        'sk' => $sortKey
    ]);

    // but getting 'mapped' here
    expect($model->mapped)->toBe($sortKey)
        ->and(Client::$queryCount)->toBe(1);
});
it('returns null when using find method with non-existing item', function() {
    $partitionKey = Uuid::uuid4()->toString();

    $model = DemoModel::find(
        $partitionKey,
        'test'
    );

    expect($model)->toBeNull()
        ->and(Client::$queryCount)->toBe(1);
});
it('finds an existing item when using findOrFail', function() {
    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();
    $sortKey = 'DEMO';

    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => $sortKey,
    ]);

    $model = DemoModel::findOrFail(
        $partitionKey,
        $sortKey,
    );

    expect($model->pk)->toBe($partitionKey)
        ->and($model->mapped)->toBe($sortKey)
        ->and(Client::$queryCount)->toBe(2);
});
it('throws exception with non-existing item when using findOrFail', function() {
    $partitionKey = Uuid::uuid4()->toString();

    DemoModel::findOrFail($partitionKey, 'test');

    expect(Client::$queryCount)->toBe(1);
})
    ->throws(ModelNotFoundException::class);
it('applies field mappings when saving and loading from DynamoDb', function() {
    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();
    $sortKey = 'DEMO';

    // By specifying the mapped property name here, we'll check that the data was saved later
    DemoModel::create([
        'pk' => $partitionKey,
        'mapped' => $sortKey,
    ]);

    // Fetching here doesn't tell Dynamo to use the mapped property name, so we know it's querying on the column
    // called 'sk'
    $model = DemoModel::find($partitionKey, $sortKey);

    expect($model)->toBeInstanceOf(DemoModel::class)
        ->and($model->mapped)->toBe($sortKey)
        ->and(Client::$queryCount)->toBe(2);
});
it('correctly persists an item when using save method', function() {
    $newValue = 'test2';

    $model = DemoModel::create([
        'test' => 'test',
    ]);

    $model = DemoModel::findOrFail($model->pk, $model->mapped);

    $model->test = $newValue;
    $model->save();

    $model = DemoModel::findOrFail($model->pk, $model->mapped);

    expect($model->test)->toBe($newValue)
        ->and(Client::$queryCount)->toBe(4);
});
it('correctly persists an item when using update method', function() {
    $newValue = 'test2';

    $model = DemoModel::create([
        'test' => 'test',
    ]);

    $model = DemoModel::findOrFail($model->pk, $model->mapped);

    // This performs a save as well
    $model->update([
        'test' => $newValue,
    ]);

    $model = DemoModel::findOrFail($model->pk, $model->mapped);

    expect($model->test)->toBe($newValue)
        ->and(Client::$queryCount)->toBe(4);
});
it('successfully stores default data when using constructor and save method', function() {
    $value = 'test';

    $model = new DemoModel([
        'test' => $value,
    ]);

    $model->save();

    $model = DemoModel::findOrFail($model->pk, $model->mapped);

    expect($model->test)->toBe($value)
        ->and(Client::$queryCount)->toBe(2);
});
it('can update a record in dynamodb without fetching first', function() {
    $newValue = 'test2';

    $persistedModel = DemoModel::create([
        'test' => 'test',
    ]);

    $newModel = DemoModel::make([
        'pk' => $persistedModel->pk,
        'sk' => $persistedModel->mapped,
        'test' => $newValue,
    ])->save();

    expect($newModel->pk)->toBe($persistedModel->pk)
        ->and($newModel->mapped)->toBe($persistedModel->mapped);

    // re-fetch from dynamo
    $newModel->refresh();

    // assert results
    expect($newModel)->toBeInstanceOf(DemoModel::class)
        ->and($newModel->test)->toBe($newValue)
        ->and(Client::$queryCount)->toBe(3);
});
it('deletes a record after loading from dynamodb', function() {
    $model = DemoModel::create();

    $model->delete();

    $model = DemoModel::find(
        $model->pk,
        $model->mapped,
    );

    expect($model)->toBeNull()
        ->and(Client::$queryCount)->toBe(3);
});
it('deletes a record without fetching first', function() {
    $model = DemoModel::create();

    DemoModel::make([
        'pk' => $model->pk,
        'sk' => $model->mapped,
    ])->delete();

    $model = DemoModel::find(
        $model->pk,
        $model->mapped,
    );

    expect($model)->toBeNull()
        ->and(Client::$queryCount)->toBe(3);
});
it('will set default values when requested', function() {
    $model = DemoModel::make();

    $expectedSortKey = 'DEMO_MODEL';

    expect($model->pk)->not->toBeNull()
        ->and($model->mapped)->toBe($expectedSortKey)
        ->and(Client::$queryCount)->toBe(0);
});
it('populates dirty attributes list when making model', function() {
    // As this model isn't persisted, all its attributes should be dirty from the start
    $model = DemoModel::make();

    expect($model->dirtyAttributes())->toHaveCount(2)
        ->and(Client::$queryCount)->toBe(0);
});
it('does not populate dirty attributes list when creating model', function() {
    // Fresh model persisted to database should have no dirty attributes
    $model = DemoModel::create();

    expect($model->dirtyAttributes())->toHaveCount(0)
        ->and(Client::$queryCount)->toBe(1);
});
it('does not populate dirty attributes list when finding model', function() {
    $model = DemoModel::create();

    expect($model->dirtyAttributes())->toHaveCount(0);

    $model = DemoModel::findOrFail($model->pk, $model->mapped);

    expect($model->dirtyAttributes())->toHaveCount(0)
        ->and(Client::$queryCount)->toBe(2);
});
it('stores a list of changed attributes when modifying an existing model', function() {
    $model = DemoModel::create();

    $value = 'test';

    expect($model->dirtyAttributes())->toHaveCount(0);

    // This is now dirty data
    $model->test = $value;

    expect($model->dirtyAttributes())->toHaveCount(1)
        ->and($model->dirtyAttributes())->toHaveKey('test')
        ->and($model->dirtyAttributes()['test'])->toBe($value)
        ->and(Client::$queryCount)->toBe(1);
});
it('stores a list of changed attributes when updating a model not loaded from the database', function() {
    $model = DemoModel::make([
        // This counts as dirty because it's not in the database
        // as will all the other default attributes
        'test' => 'test',
    ]);

    expect($model->dirtyAttributes())->toHaveCount(3)
        ->and($model->dirtyAttributes())->toHaveKeys(['test', 'pk', 'mapped']);

    $value = 'test2';

    // this is dirty as well
    $model->test2 = $value;

    expect($model->dirtyAttributes())->toHaveCount(4)
        ->and($model->dirtyAttributes())->toHaveKeys(['test', 'test2', 'pk', 'mapped'])
        ->and($model->dirtyAttributes()['test2'])->toBe($value)
        ->and(Client::$queryCount)->toBe(0);
});
