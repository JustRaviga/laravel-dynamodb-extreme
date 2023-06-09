<?php

namespace Tests\Unit;

use Illuminate\Container\EntryNotFoundException;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\Client;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\DynamoDbAdapter;
use Tests\Resources\DemoModel;

/**
 * The purpose of these tests are to ensure that Laravel's bootstrapping has happened in the testing environment
 */

it('can get a DynamoDb config value', function() {
    // get expected value from .env
    $envRegion = env('DYNAMODB_REGION', 'localhost');

    // get set value from config
    $region = config('dynamodb.region');

    expect($region)->toBe($envRegion);
});
it('can get a DynamoDb Client instance', function() {
    $client = app('dynamodb');

    expect($client)->toBeInstanceOf(Client::class);
});
