<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
//use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
//    use CreatesApplication, DatabaseTransactions;

    protected function getPackageProviders($app)
    {
        return [
            'JustRaviga\\DynamoDb\\DynamoDbServiceProvider'
        ];
    }
}
