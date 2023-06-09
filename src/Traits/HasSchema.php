<?php

namespace JustRaviga\LaravelDynamodbExtreme\Traits;

use Illuminate\Validation\Validator;

trait HasSchema
{
    protected array $schema = [

    ];

    /**
     * Validates the entire model against the validation schema.  Used when persisting models.
     * @throws \RuntimeException if attributes don't validate successfully
     */
    public function validateSchema(array $attributes): void
    {
        // Check if we have a schema to validate against
        if (! count($this->schema)) {
            return;
        }

        // Create validator
        $validator = app()->make(Validator::class, [
            'data' => $attributes,
            'rules' => $this->schema,
        ]);

        $validator->validate();
    }

    /**
     * Validates a single attribute against the model's schema
     * @param string $attributeName
     * @param mixed $value
     * @return void
     */
    public function validateSchemaForAttribute(string $attributeName, mixed $value): void
    {
        if (! isset($this->schema[$attributeName])) {
            return;
        }

        $validator = app()->make(Validator::class, [
            'data' => [$attributeName => $value],
            'rules' => [$attributeName => $this->schema[$attributeName]],
        ]);

        $validator->validated();
    }
}
