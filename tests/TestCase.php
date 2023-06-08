<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
//use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            'JustRaviga\\LaravelDynamodbExtreme\\DynamoDbServiceProvider'
        ];
    }
}
