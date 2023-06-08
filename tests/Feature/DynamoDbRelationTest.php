<?php

use Illuminate\Support\Collection;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\Client;
use JustRaviga\LaravelDynamodbExtreme\Exceptions\QueryBuilderInvalidQuery;
use Ramsey\Uuid\Uuid;
use Tests\Resources\DemoModel;
use Tests\Resources\DemoModelWithRelation;

beforeEach(function() {
    /**
     * Resetting the query count before each test so we can make assertions
     * based on the number of queries we expect to have been made
     */
    Client::$queryCount = 0;
});

it('can eager-load related models when searching', function() {
    $parentModel = DemoModelWithRelation::create();

    DemoModel::create([
        'pk' => $parentModel->pk,
        'sk' => 'DEMOMODEL#' . Uuid::uuid4()->toString(),
    ]);
    DemoModel::create([
        'pk' => $parentModel->pk,
        'sk' => 'DEMOMODEL#' . Uuid::uuid4()->toString(),
    ]);
    DemoModel::create([
        'pk' => $parentModel->pk,
        'sk' => 'DEMOMODEL#' . Uuid::uuid4()->toString(),
    ]);

    /** @var DemoModelWithRelation $parentModel */
    $parentModel = DemoModelWithRelation::where('pk', $parentModel->pk)
        ->where('sk', $parentModel->sk)
        ->withRelation('demoModels')
        ->first();

    expect($parentModel)->toBeInstanceOf(DemoModelWithRelation::class)
        ->and($parentModel->demoModels)->toBeInstanceOf(Collection::class)
        ->and($parentModel->demoModels)->toHaveCount(3);
    $parentModel->demoModels->each(fn ($model) => expect($model)->toBeInstanceOf(DemoModel::class));

    expect(Client::$queryCount)->toBe(6);
});
it('can lazy-load relations for a model', function() {
    $parentModel = DemoModelWithRelation::create();

    DemoModel::create([
        'pk' => $parentModel->pk,
        'sk' => 'DEMOMODEL#' . now()->subSeconds(3)->toISOString(),
    ]);
    DemoModel::create([
        'pk' => $parentModel->pk,
        'sk' => 'DEMOMODEL#' . now()->toISOString(),
    ]);

    $model = DemoModelWithRelation::findOrFail($parentModel->pk, $parentModel->sk);

    expect($model)->toBeInstanceOf(DemoModelWithRelation::class)
        // This makes a request to fetch the 'messages' relation as it's not been loaded yet
        ->and($model->demoModels)->toBeInstanceOf(Collection::class)
        ->and($model->demoModels)->toHaveCount(2);

    $model->demoModels->each(fn ($message) => expect($message)->toBeInstanceOf(DemoModel::class));

    expect(Client::$queryCount)->toBe(5);
});
it('can add related models to a model by passing array of data', function() {
    $parentModel = DemoModelWithRelation::create();

    DemoModel::create([
        'pk' => $parentModel->pk,
        'sk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
    ]);

    $parentModel->demoModels()->save([
        'test' => 'test',
        'sk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
    ]);

    // This still does a lazy-load of relations
    expect($parentModel->demoModels)->toHaveCount(2);
    $parentModel->demoModels->map(fn ($model) => expect($model)->toBeInstanceOf(DemoModel::class));

    expect(Client::$queryCount)->toBe(4);
});
it('can add related models to a model by passing model class', function() {
    $parentModel = DemoModelWithRelation::create();

    DemoModel::create([
        'pk' => $parentModel->pk,
        'sk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
    ]);

    $parentModel->demoModels()->save(DemoModel::make([
        'sk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
        'test' => 'test',
    ]));

    // This still does a lazy-load of relations
    expect($parentModel->demoModels)->toHaveCount(2);
    $parentModel->demoModels->map(fn ($model) => expect($model)->toBeInstanceOf(DemoModel::class));

    expect(Client::$queryCount)->toBe(4);
});
it('does not add multiple related models', function() {
    $parentModel = DemoModelWithRelation::create();

    $model = DemoModel::make([
        'sk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
        'test' => 'test',
    ]);

    // Only the first of these should add to the related models collection
    $parentModel->demoModels()->save($model);
    $parentModel->demoModels()->save($model);
    $parentModel->demoModels()->save($model);

    expect($parentModel->demoModels)->toHaveCount(1);
    $parentModel->demoModels->map(function ($model) {
        expect($model)->toBeInstanceOf(DemoModel::class);
    });

    expect(Client::$queryCount)->toBe(3);
});
it('can add an existing related model to its relations list', function() {
    $parentModel = DemoModelWithRelation::create();

    $model = DemoModel::create([
        'pk' => $parentModel->pk,
        'sk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
        'test' => 'test',
    ]);

    $parentModel->demoModels()->add($model);

    // This still does a lazy-load of relations
    expect($parentModel->demoModels)->toHaveCount(1);
    $parentModel->demoModels->map(fn ($model) => expect($model)->toBeInstanceOf(DemoModel::class));

    expect(Client::$queryCount)->toBe(3);
});
it('can paginate relationships', function() {
    $parentModel = DemoModelWithRelation::create();

    $modelCount = 10;
    $limit = 2;
    for ($i = 0; $i < $modelCount; $i++) {
        DemoModel::create([
            'pk' => $parentModel->pk,
            'sk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
        ]);
    }

    $demoModels = $parentModel->demoModels()->query()->limit($limit)->get();

    expect($demoModels->results)->toHaveCount($limit)
        ->and($demoModels->hasMoreResults())->toBeTrue();

    // get more messages
    $moreMessages = $parentModel->demoModels()->query()->after($demoModels->lastEvaluatedKey)->limit($limit)->get();

    expect($moreMessages->results)->toHaveCount($limit)
        ->and($moreMessages->hasMoreResults())->toBeTrue()
        ->and(Client::$queryCount)->toBe($modelCount + 3);
});
//it('throws an error if the requested model is not returned from a query', function() {
//    $parentModel = DemoModelWithRelation::create([
//        'pk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
//        'sk' => 'Z',
//    ]);
//
//    $related = DemoModel::create([
//        'pk' => $parentModel->pk,
//        'sk' => 'DEMOMODEL#' . Uuid::uuid7()->toString(),
//    ]);
//
//    dump(DemoModelWithRelation::where('pk', $parentModel->pk)
//        ->withRelation('demoModels')
//        ->get());
//})->throws(QueryBuilderInvalidQuery::class);
