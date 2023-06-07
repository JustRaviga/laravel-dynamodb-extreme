<?php

namespace Tests\Resources;

use Illuminate\Support\Collection;
use JustRaviga\DynamoDb\DynamoDb\InlineRelation;
use JustRaviga\DynamoDb\DynamoDb\Relation;
use JustRaviga\DynamoDb\Models\DynamoDbModel;
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
