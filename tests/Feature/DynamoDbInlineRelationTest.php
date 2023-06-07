<?php

use JustRaviga\DynamoDb\DynamoDb\Client;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;
use Tests\Resources\DemoModelInlineRelation;
use Tests\Resources\DemoModelWithInlineRelation;

beforeEach(function() {
    /**
     * Resetting the query count before each test so we can make assertions
     * based on the number of queries we expect to have been made
     */
    Client::$queryCount = 0;
});

it('can access inline relations for a model when there are none', function() {
    $parentModel = DemoModelWithInlineRelation::create();

    // This lazy-loads the relation which should be an empty collection
    expect($parentModel->demoModels)->toBeInstanceOf(Collection::class)
        ->and($parentModel->demoModels)->toHaveCount(0)
        ->and(Client::$queryCount)->toBe(1);
});
it('can add an inline relation to an existing model', function() {
    $parentModel = DemoModelWithInlineRelation::create();

    // Fetch model from dynamodb
    $parentModel = DemoModelWithInlineRelation::find($parentModel->pk, $parentModel->sk);

    // Attach inline related model
    $parentModel->demoModels()->save([
        'id' => Uuid::uuid4()->toString(),
        'test' => 'test',
    ]);

    // Confirm that the model is now attached
    expect($parentModel)->toBeInstanceOf(DemoModelWithInlineRelation::class)
        ->and($parentModel->demoModels)->toBeInstanceOf(Collection::class)
        ->and($parentModel->demoModels)->toHaveCount(1)
        ->and($parentModel->demoModels->first())->toBeInstanceOf(DemoModelInlineRelation::class)
        ->and(Client::$queryCount)->toBe(3);
});
it('can get inline relations from a model not persisted in dynamo', function() {
    // MAKE model in memory
    $parentModel = DemoModelWithInlineRelation::make([
        'pk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
    ]);

    // ADD inline relation to model in memory
    $parentModel->demoModels()->add([
        'id' => Uuid::uuid4()->toString(),
        'test' => 'test',
    ]);

    // Confirm we can access the relation
    expect($parentModel->demoModels)->toBeInstanceOf(Collection::class)
        ->and($parentModel->demoModels)->toHaveCount(1)
        ->and($parentModel->demoModels->first())->toBeInstanceOf(DemoModelInlineRelation::class)
        ->and(Client::$queryCount)->toBe(0);
});
it('can save new inline relation models in dynamodb', function() {
    // CREATE the message in dynamo
    $message = DemoModelWithInlineRelation::create([
        'pk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
        'sk' => 'DEMOMODEL#' . now()->toISOString(),
    ]);

    // SAVE the status to dynamo
    $message->demoModels()->save([
        'id' => Uuid::uuid4(),
        'test' => 'test',
    ]);

    expect($message->demoModels)->toBeInstanceOf(Collection::class)
        ->and($message->demoModels)->toHaveCount(1)
        ->and($message->demoModels->first())->toBeInstanceOf(DemoModelInlineRelation::class)
        ->and(Client::$queryCount)->toBe(2);
});
it('can update inline relation model without fetching from dynamodb first', function() {
    $parentModel = DemoModelWithInlineRelation::create([
        'pk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
        'sk' => 'DEMOMODEL#' . now()->toISOString(),
    ]);

    // Create a single related item
    $id = Uuid::uuid4();
    $value = now()->toISOString();

    $parentModel->demoModels()->save([
        'id' => $id,
        'test' => $value,
    ]);

    expect($parentModel->demoModels)->toBeInstanceOf(Collection::class)
        ->and($parentModel->demoModels->first())->toBeInstanceOf(DemoModelInlineRelation::class)
        ->and($parentModel->demoModels->first()->test)->toBe($value);

    $nextValue = now()->addMinutes(5)->toISOString();

    $status = DemoModelInlineRelation::make([
        // partition key
        'pk' => $parentModel->pk,
        // sort key
        'sk' => $parentModel->sk,
        // unique key for Status row
        'id' => $id,
        // actual data we want to update
        'test2' => $nextValue,
    ])->updateInlineRelation();

    // Refresh parent model from dynamo
    $parentModel = DemoModelWithInlineRelation::findOrFail($parentModel->pk, $parentModel->sk);

    // Confirm the status has been loaded with the new data, and that the original "test" attribute hasn't been lost
    expect($parentModel->demoModels)->toBeInstanceOf(Collection::class)
        ->and($parentModel->demoModels->first())->toBeInstanceOf(DemoModelInlineRelation::class)
        ->and($parentModel->demoModels->first()->test)->toBe($value)
        ->and($parentModel->demoModels->first()->test2)->toBe($nextValue)
        ->and(Client::$queryCount)->toBe(4);
});
it('reloads inline relations when refreshing a model', function() {
    $parentModel = DemoModelWithInlineRelation::create([
        'pk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
        'sk' => 'DEMOMODEL#' . now()->toISOString(),
    ]);

    $id = Uuid::uuid4()->toString();
    $value = now()->toISOString();
    $nextValue = now()->addMinutes(5)->toISOString();

    $parentModel->demoModels()->save([
        'id' => $id,
        'test' => $value,
    ]);

    expect($parentModel->demoModels)->toBeInstanceOf(Collection::class)
        ->and($parentModel->demoModels->first())->toBeInstanceOf(DemoModelInlineRelation::class)
        ->and($parentModel->demoModels->first()->test)->toBe($value)
        ->and($parentModel->demoModels->first()->test2)->toBeNull();

    // This persists just the new status in Dynamo
    $status = DemoModelInlineRelation::make([
        'pk' => $parentModel->pk,
        'sk' => $parentModel->sk,
        'id' => $id,
        'test2' => $nextValue,
    ])->updateInlineRelation();

    // Should reload the relations
    $parentModel->refresh();

    expect($parentModel->demoModels)->toBeInstanceOf(Collection::class)
        ->and($parentModel->demoModels->first())->toBeInstanceOf(DemoModelInlineRelation::class)
        ->and($parentModel->demoModels->first()->test)->toBe($value)
        ->and($parentModel->demoModels->first()->test2)->toBe($nextValue)
        ->and(Client::$queryCount)->toBe(4);
});
