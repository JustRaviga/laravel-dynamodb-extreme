# DynamoDb Query Builder

This is a Query Builder package for DynamoDb access.  Inspired by other versions that didn't quite feel eloquent enough
(see what I did there?), this one has the following features, just like Laravel:

----

#### Get a single Model instance from DynamoDb
```php
/** @var ?Model $model */
$model = Model::find($partitionKey, $sortKey);
```

Or, to throw Illuminate\Database\Eloquent\ModelNotFoundException if the model isn't found:
```php
/** @var Model $model */
$model = Model::findOrFail($partitionKey, $sortKey);
```

----

#### Get a Collection of Model instances from DynamoDb
Note: only a single Model type is supported here.  You must make sure your query will only return models of the same
type.  An Exception will be thrown if a property found in the database is not in the $fillable array on the model.
NB: DynamoDb only supports exact matches on Partition keys, and `<`, `<=`, `=`, `>=`, `>`, `begins_with`, and `between`.
```php
/** @var \Illuminate\Support\Collection<Model> $models */
$models = Model::where('partitionKey', $partitionKey)
    ->where('sortKey', 'begins_with', 'MODEL#')
    ->get();
```

You can optionally sort on the sortKey and limit the number of results:
```php
/** @var \Illuminate\Support\Collection<Model> $models */
$models = Model::where('partitionKey', $partitionKey)
    ->sortDescending()
    ->limit(10)
    ->get();
```

----

#### Get a collection of models using a Secondary Index
Provided the secondary index is configured (see below), it will be detected from the fields you are querying.
```php
/** @var \Illuminate\Support\Collection<Model> $models */
$models = Model::where('index_partition_key', $partitionKey)
    ->where('index_sort_key', $sortKey)
    ->get();
```

----

#### Create a Model instance in DynamoDb
```php
/** @var Model $model */
$model = Model::create([
    'partitionKey' => 'value',
    'sortKey' => 'value',
    // other attributes...
]);
```

----

## Setup and global config

### Environment variables
* `DYNAMODB_REGION` defaults to `localhost`, should be set to your main DynamoDb instance region (eu-west-2, for example)
* `DYNAMODB_VERSION` defaults to `latest`, should be set to the version of your DynamoDb instance if you need it
* `DYNAMODB_KEY` your DynamoDb access key (username)
* `DYNAMODB_SECRET` your DynamoDb secret (password)
* `DYNAMODB_ENDPOINT` defaults to `http://localhost:8000`, the address of your DynamoDb installation
* `DYNAMODB_TABLE` to define a default table for all models (useful when working with a single-table design in a specific application)
* `DYNAMODB_CONSISTENT_READ` defaults to `true`, use to set a default consistent read value (can still be overwritten by specific models)
* `DYNAMODB_LOG_QUERIES` defaults to `false`, use to add logging for all DynamoDb queries made (the json object being sent to Dynamo will be logged)

### Configuration options

The config file can be exported with Laravel's publish command: `php artisan vendor:publish`, it looks like this:
```php
[
    'region' => env('DYNAMODB_REGION', 'localhost'),
    'version' => env('DYNAMODB_VERSION', 'latest'),
    'credentials' => [
        'key' => env('DYNAMODB_KEY', ''),
        'secret' => env('DYNAMODB_SECRET', ''),
    ],
    'endpoint' => env('DYNAMODB_ENDPOINT', 'http://localhost:8000'),
    'defaults' => [
        'consistent_read' => env('DYNAMODB_CONSISTENT_READ', true),
        'table' => env('DYNAMODB_TABLE', 'default'),
        'partition_key' => 'pk',
        'sort_key' => 'sk',
        'global_secondary_indexes' => [
            'gsi1' => [
                'pk' => 'gsi1_pk',
                'sk' => 'gsi1_sk',
            ]
        ],
        'log_queries' => env('DYNAMODB_LOG_QUERIES', false),
    ],
]
```

Apart from the environment-based config, here you can specify defaults for partition key, sort key, and global secondary indexes.
These are intended to be sensible defaults based on general usage patterns of DynamoDb.

## Model Configuration
Creating a model is easy.  At a bare minimum, you just need to define the table name, the partitionKey and sortKey, and
an array of "fillable" attributes (note the partition and sort keys need to be fillable!):

```php
class Model extends \ClassManager\DynamoDb\Models\DynamoDbModel
{
    // optional, see environment variable for table name
    protected string $table = 'models';
    
    // optional, see environment variable for partition/sort keys
    protected string $partitionKey = 'pk';
    protected string $sortKey = 'sk';
    
    // required!  must include at least partition/sort keys
    public array $fillable = [
        'pk',
        'sk',
        // other attributes...
    ];
}
```

---

To write more verbose code, you can create a mapping from internal/database fields to friendly attributes by overriding
the `fieldMappings` protected property. The mappings are applied when fetching from the database and when saving to the
database.  The rest of the time, you always use the mapped property name.
```php
class Model extends \ClassManager\DynamoDb\Models\DynamoDbModel
{
    // optional
    protected array $fieldMappings = [
        'pk' => 'my_uuid',
        'sk' => 'created_date',
    ];
}
```

---

You can configure related models that share the same Partition Key with some minor extra setup:
```php
class ParentModel extends \ClassManager\DynamoDb\Models\DynamoDbModel
{
    public function childModels(): \ClassManager\DynamoDb\DynamoDb\Relation
    {
        return $this->addRelation(ChildModel::class);
    }
}
```
By default, this uses the Partition key of the parent model, and matches on the Sort key with a "begins_with" query against the child model's class name followed by a hash (#), something like `begins_with(sk, 'CHILDMODEL#')`.
The matching itself can be configured on the child models by overriding the `relationSearchParams` method:
```php
class ChildModel extends \ClassManager\DynamoDb\Models\DynamoDbModel
{
    public static function relationSearchParams(): array
    {
        return [
            'sk',
            'begins_with',
            'CLASSNAME#'        
        ];
    }
}
```

The related models can be accessed as a Collection using a property of the same name as the relationship method, e.g:
```php
class Model extends \ClassManager\DynamoDb\Models\DynamoDbModel
{
    public function children(): \ClassManager\DynamoDb\DynamoDb\Relation
    {
        return $this->addRelation(Child::class);
    }
}

$model = Model::find(...);

$model->children->map(fn ($child) => $child->doSomething());
```

---

If you want to use a secondary index on your table, just define the Partition and Sort Key names in the `$indexes` array.
Be sure to add them to the `$fillable` array as well.

```php
class Model extends \ClassManager\DynamoDb\Models\DynamoDbModel
{
    // optional, see config values
    protected array $indexes = [
        'gsi1' => [
            'pk' => 'gsi1_pk',
            'sk' => 'gsi1_sk',
        ],
    ];
    
    // required if you want to use a secondary index
    public array $fillable = [
        'gsi1_pk',
        'gsi1_sk',
        // other attributes...    
    ];
}
```

---

You can define mappings from secondary indexes to friendly model properties in the `$fieldMappings` array too.
```php
class Model extends \JustRaviga\DynamoDb\Models\DynamoDbModel
{
    // optional
    protected array $fieldMappings = [
        'gsi1_pk' => 'user_uuid',
        'gsi1_sk' => 'created_date',
    ];
}
```

## Minimum Requirements
1. PHP 8.2
2. Laravel 10.0


## Wishlist
The things we might like to include (eventually) but didn't have time to properly consider:

> Wrapping DynamoDb responses in a cache layer

Optionally have models fetched from DynamoDb stored in memory for the duration of the request so multiple calls to the
same partition/sort key return the same data without querying Dynamo.

> Attribute casts

Similar to the Laravel model $casts array, attributes should be coerced to/from these data types when fetching and
saving with DynamoDb.

> Extend the Collection class to include filter methods based on DynamoDb models

Potential for new methods on the Collection class for pagination based on the Last Evaluated Key from DynamoDb.

> Better documentation 🙈

Docs can always be improved
