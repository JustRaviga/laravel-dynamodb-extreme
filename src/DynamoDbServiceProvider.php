<?php

declare(strict_types=1);

namespace ClassManager\DynamoDb;

use ClassManager\DynamoDb\DynamoDb\Client;
use Illuminate\Support\ServiceProvider;

class DynamoDbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__) . '/config/dynamodb.php' => config_path('dynamodb.php'),
        ]);

        $this->mergeConfigFrom(dirname(__DIR__) . '/config/dynamodb.php', 'dynamodb');

        $this->app->singleton('dynamodb', fn() => new Client());
    }
}
