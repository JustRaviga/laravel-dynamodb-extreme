<?php

namespace Tests\Resources;

use Illuminate\Support\Collection;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\InlineRelation;
use JustRaviga\LaravelDynamodbExtreme\DynamoDb\Relation;
use JustRaviga\LaravelDynamodbExtreme\Models\DynamoDbModel;
use stdClass;

/**
 * @property string $pk
 * @property string $sk
 * @property Collection $demoModels
 */
class DemoModelWithInlineRelation extends DynamoDbModel {
    protected static string $table = 'test';

    public array $fillable = [
        'pk',
        'sk',
        'map',
    ];

    public function defaultValues(): array
    {
        return [
            'demoModels' => new stdClass(),
        ];
    }

    public function demoModels(): InlineRelation
    {
        return $this->addInlineRelation(DemoModelInlineRelation::class, 'map');
    }
}
