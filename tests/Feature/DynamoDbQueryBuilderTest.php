<?php

namespace Tests\Feature;

use Illuminate\Support\Collection;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\Client;
use JustRaviga\LaravelDynamodbExtreme\DynamoDbQueryBuilder;
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

it('can get many model instances', function() {
    /**
     * Example: Create 3 models in Dynamo that all share the same partition key.
     * Querying with sort key "begins with" to find all the with DEMO prefix
     */

    $partitionKey = Uuid::uuid7()->toString();

    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => 'DEMO#' . Uuid::uuid7()->toString(),
        'test' => 'test',
    ]);
    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => 'DEMO#' . Uuid::uuid7()->toString(),
        'test' => 'test',
    ]);
    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => 'DEMO#' . Uuid::uuid7()->toString(),
        'test' => 'test',
    ]);

    $models = DemoModel::where('pk', $partitionKey)
        ->where('sk', 'begins_with', 'DEMO#')
        ->get();

    expect($models->results)->toHaveCount(3);
    $models->results->each(fn ($model) => expect($model)->toBeInstanceOf(DemoModel::class));

    expect(Client::$queryCount)->toBe(4);
});
it('can search on database fields', function() {
    $model = DemoModel::create();

    expect($model)->toBeInstanceOf(DemoModel::class);

    $models = DemoModel::where('pk', $model->pk)->get();

    $models->results->each(
        fn ($foundModel) => expect($foundModel)->toBeInstanceOf(DemoModel::class)
            ->and($foundModel->pk)->toBe($model->pk)
    );

    expect(Client::$queryCount)->toBe(2);
});
it('can search on mapped fields', function() {
    $model = DemoModel::create();

    expect($model)->toBeInstanceOf(DemoModel::class);

    $models = DemoModel::where('pk', $model->pk)->get();

    $models->results->each(
        fn ($foundModel) => expect($foundModel)->toBeInstanceOf(DemoModel::class)
            ->and($foundModel->pk)->toBe($model->pk)
    );

    expect(Client::$queryCount)->toBe(2);
});
it('raises exception during search when searching on invalid fields', function() {
    DemoModel::where('any_field', 'any_value')->get();
})
    ->throws(QueryBuilderInvalidQuery::class)
    ->and(Client::$queryCount)->toBe(0);
it('raises exception during search when partition key is not specified', function() {
    DemoModel::where('sk', 'some_value')->get();
})
    ->throws(QueryBuilderInvalidQuery::class)
    ->and(Client::$queryCount)->toBe(0);
it('will return model as array when raw output is requested', function() {
    $model = DemoModel::create();

    $models = DemoModel::where('pk', $model->pk)
        ->where('sk', $model->mapped)
        ->raw()
        ->get();

    expect($models->results)->toBeInstanceOf(Collection::class)
        ->and($models->results->first())->toBeArray()
        ->and(Client::$queryCount)->toBe(2);
});
it('will sort results in descending order', function() {
    $partitionKey = Uuid::uuid7()->toString();

    $modelCount = 5;
    // the meta_name field is a STRING type so all entries must be strings, not numbers
    for($i = 1; $i <= $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => "$i",
        ]);
    }

    $models = DemoModel::where('pk', $partitionKey)->get();

    // validate that each item is in the correct order, 1-5
    $i = 1;
    $models->results->each(function (DemoModel $model) use (&$i) {
        // The value is a "string"
        expect($model->mapped)->toBe("$i");
        $i++;
    });

    $models = DemoModel::where('pk', $partitionKey)
        ->sortDescending()
        ->get();

    // validate that each item is in the correct order, 5-1
    $i = 5;
    $models->results->each(function (DemoModel $model) use (&$i) {
        // The value is a "string"
        expect($model->mapped)->toBe("$i");
        $i--;
    });

    expect(Client::$queryCount)->toBe($modelCount + 2);
});
it('will revert to sorting results in ascending order after using descending order', function() {
    $partitionKey = Uuid::uuid4()->toString();

    $modelCount = 5;
    for($i = 1; $i <= $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => "$i",
        ]);
    }

    $models = DemoModel::where('pk', $partitionKey)->get();

    // validate that each item is in the correct order, 1-5
    $i = 1;
    $models->results->each(function (DemoModel $model) use (&$i) {
        expect($model->mapped)->toBe("$i");
        $i++;
    });

    // Technically this has no effect, but it's good to capture that fact
    $models = DemoModel::where('pk', $partitionKey)
        ->sortAscending()
        ->get();

    // validate that each item is in the correct order, 5-1
    $i = 1;
    $models->results->each(function (DemoModel $model) use (&$i) {
        expect($model->mapped)->toBe("$i");
        $i++;
    });

    expect(Client::$queryCount)->toBe($modelCount + 2);
});
it('will return a limited set of results when requested', function() {
    $partitionKey = Uuid::uuid7()->toString();

    $limit = 2;
    $modelCount = 4;

    for ($i = 0; $i < $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => "$i",
        ]);
    }

    $conversations = DemoModel::where('pk', $partitionKey)
        ->limit($limit)
        ->get();

    expect($conversations->results)->toHaveCount($limit)
        ->and(Client::$queryCount)->toBe($modelCount + 1);
});
it('will get all models when requested', function() {
    // this is tough to test - Dynamo will return a maximum of 1mb for a single request
    // we need to make enough models that a single query won't return them all
    // then check that all expected models were fetched over multiple queries

    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();

    // If we make 11 models, each of >100kb in size, that'll hit the limit and have at least 1 model left over
    $modelCount = 11;
    for ($i = 0; $i < $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => 'DEMO_MODEL#' . Uuid::uuid7()->toString(),
            'test' => str_repeat('a', 204800),  // 200kb
        ]);
    }

    // This makes as many requests as needed to fetch all the data (in this case, that's 2 queries)
    $models = DemoModel::where('pk', $partitionKey)
        ->where('sk', 'begins_with', 'DEMO_MODEL#')
        ->getAll();

    expect($models->hasMoreResults())->toBeFalse()
        ->and($models->results)->toHaveCount($modelCount)
        ->and(Client::$queryCount)->toBe($modelCount + 2);
});
it('will get models up to the dynamodb query limit', function() {
    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();

    // make models with a total size greater than 1mb
    $modelCount = 11;

    for ($i = 0; $i < $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => 'DEMO_MODEL#' . Uuid::uuid7()->toString(),
            'test' => str_repeat('a', 204800),
        ]);
    }

    $models = DemoModel::where('pk', $partitionKey)
        ->where('sk', 'begins_with', 'DEMO_MODEL#')
        ->get();

    // Expect not all the models to be returned
    expect($models->hasMoreResults())->toBeTrue()
        ->and($models->results->count())->toBeLessThan($modelCount)
        ->and(Client::$queryCount)->toBe($modelCount + 1);
});
it('can return the first result from a query', function() {
    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();

    // Create 2 models that would normally be returned by a query
    DemoModel::create(['pk' => $partitionKey]);
    DemoModel::create(['pk' => $partitionKey]);

    $model = DemoModel::where('pk', $partitionKey)
        ->first();

    expect($model)->toBeInstanceOf(DemoModel::class)
        ->and(Client::$queryCount)->toBe(3);
});
it('will return array models when raw output is requested', function() {
    $partitionKey = 'DEMOMODEL#' . Uuid::uuid7()->toString();

    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => Uuid::uuid7()->toString(),
        'test' => 'test',
    ]);
    DemoModel::create([
        'pk' => $partitionKey,
        'sk' => Uuid::uuid7()->toString(),
        'test' => 'test',
    ]);

    // Get items from Dynamo, the actual database columns must be used for this
    $models = (new DynamoDbQueryBuilder)->where('pk', $partitionKey)
        ->table('test')
        ->get();

    // Validate that each item is an array
    $models->results->each(fn ($model) => expect($model)->toBeArray());

    expect(Client::$queryCount)->toBe(3);
});
it('will detect no further pages are available', function() {
    $partitionKey = Uuid::uuid7()->toString();

    $modelCount = 4;

    for ($i = 0; $i < $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => "$i",
        ]);
    }

    $models = DemoModel::where('pk', $partitionKey)
        ->get();

    expect($models->results)->toHaveCount($modelCount)
        ->and($models->hasMoreResults())->toBeFalse()
        ->and(Client::$queryCount)->toBe($modelCount + 1);
});
it('will detect further pages available', function() {
    $partitionKey = Uuid::uuid7()->toString();

    $limit = 2;
    $modelCount = 4;

    for ($i = 0; $i < $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => "$i",
        ]);
    }

    $models = DemoModel::where('pk', $partitionKey)
        ->limit($limit)
        ->get();

    expect($models->results)->toHaveCount($limit)
        ->and($models->hasMoreResults())->toBeTrue()
        ->and(Client::$queryCount)->toBe($modelCount + 1);
});
it('can get more pages by passing last result into paginate method', function() {
    $partitionKey = Uuid::uuid7()->toString();

    $limit = 2;
    $modelCount = 4;

    for ($i = 0; $i < $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => "$i",
        ]);
    }

    $models = DemoModel::where('pk', $partitionKey)
        ->limit($limit)
        ->paginate();

    expect($models->results)->toHaveCount($limit)
        ->and($models->hasMoreResults())->toBeTrue();

    $moreModels =  DemoModel::where('pk', $partitionKey)
        ->paginate($models->lastEvaluatedKey);

    // confirm expected results were fetched
    expect($moreModels->results)->toHaveCount($limit)
        ->and($moreModels->hasMoreResults())->toBeFalse()
        ->and(Client::$queryCount)->toBe($modelCount + 2);
});
it('can get more pages using after method on query builder', function() {
    $partitionKey = Uuid::uuid7()->toString();

    $limit = 2;
    $modelCount = 4;

    for ($i = 0; $i < $modelCount; $i++) {
        DemoModel::create([
            'pk' => $partitionKey,
            'sk' => "$i",
        ]);
    }

    $models = DemoModel::where('pk', $partitionKey)
        ->limit($limit)
        ->paginate();

    expect($models->results)->toHaveCount($limit)
        ->and($models->hasMoreResults())->toBeTrue();

    $moreModels = DemoModel::where('pk', $partitionKey)
        ->after($models->lastEvaluatedKey)
        ->paginate();

    // Confirm expected results were fetched
    expect($moreModels->results)->toHaveCount($limit)
        ->and($moreModels->hasMoreResults())->toBeFalse()
        ->and(Client::$queryCount)->toBe($modelCount + 2);
});
