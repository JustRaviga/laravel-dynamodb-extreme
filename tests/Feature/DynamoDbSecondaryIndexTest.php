<?php

use JustRaviga\LaravelDynamodbExtreme\DynamoDb\Client;
use Ramsey\Uuid\Uuid;
use Tests\Resources\DemoModel;

beforeEach(function() {
    /**
     * Resetting the query count before each test so we can make assertions
     * based on the number of queries we expect to have been made
     */
    Client::$queryCount = 0;
});

it('can search for models based on secondary index', function() {
    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();
    $sortKey = 'DEMO#' . Uuid::uuid4()->toString();

    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => $sortKey,
        'test' => 'test',

        'gsi1_pk' => $sortKey,
        'gsi1_sk' => $partitionKey,
    ]);

    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();

    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => $sortKey,
        'test' => 'test',

        'gsi1_pk' => $sortKey,
        'gsi1_sk' => $partitionKey,
    ]);

    $models = DemoModel::withIndex('gsi1')
        ->where('gsi1_pk', $sortKey)
        ->where('gsi1_sk', 'begins_with', 'DEMOMODEL#')
        ->get();

    expect($models->results)->toHaveCount(2);
    $models->results->each(fn($model) => expect($model)->toBeInstanceOf(DemoModel::class));

    expect(Client::$queryCount)->toBe(3);
});
it('can guess secondary index based on search parameters', function() {
    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();
    $sortKey = 'DEMO#' . Uuid::uuid4()->toString();

    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => $sortKey,
        'test' => 'test',

        'gsi1_pk' => $sortKey,
        'gsi1_sk' => $partitionKey,
    ]);

    /** @var DemoModel $model */
    $model = DemoModel::where('gsi1_pk', $sortKey)
        ->where('gsi1_sk', $partitionKey)
        ->first();

    // expect correct data to be returned
    expect($model)->toBeInstanceOf(DemoModel::class)
        ->and($model->pk)->toBe($partitionKey)
        ->and($model->mapped)->toBe($sortKey)
        ->and(Client::$queryCount)->toBe(2);
});
it('can get model data after query on secondary index', function() {
    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();
    $sortKey = 'DEMO#' . Uuid::uuid4()->toString();

    $expectedValue = 'test';

    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => $sortKey,

        'test' => $expectedValue,

        'gsi1_pk' => $sortKey,
        'gsi1_sk' => $partitionKey,
    ]);

    /** @var DemoModel $model */
    $model = DemoModel::withIndex('gsi1')
        ->where('gsi1_pk', $sortKey)
        ->where('gsi1_sk', 'begins_with', 'DEMOMODEL#')
        ->withData()  // <-- this causes a Dynamo request
        ->first();

    expect($model->test)->toBe($expectedValue)
        ->and(Client::$queryCount)->toBe(3);
});
