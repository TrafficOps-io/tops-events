<?php

namespace TrafficOps\EventCatalog\Tests;

use Illuminate\Support\Facades\Validator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TrafficOps\EventCatalog\FieldSchema;
use TrafficOps\EventCatalog\FieldValidator;

class FieldValidatorTest extends TestCase
{
    public static function exactValues(): array
    {
        return [
            [0, 0.0, '0'], [false, false, 0], [true, true, 'true'],
            [null, null, ''], ['', '', null], ['0e123', '0e123', '0e456'],
            ['paid', 'paid', ['paid']], ['paid', 'paid', ['status' => 'paid']],
        ];
    }

    #[DataProvider('exactValues')]
    public function test_exact_values_preserve_types_numeric_equivalence_and_optional_absence(mixed $expected, mixed $valid, mixed $invalid): void
    {
        $rules = [['source' => 'body', 'path' => 'value', 'required' => false, 'equals' => $expected]];
        $validator = new FieldValidator;
        $this->assertSame([], $validator->errors([], $rules));
        $this->assertSame([], $validator->errors(['body' => ['value' => $valid]], $rules));
        $this->assertSame(['body.value' => ['The field must match the configured exact value.']], $validator->errors(['body' => ['value' => $invalid]], $rules));
    }

    public function test_duplicate_rules_cannot_weaken_validation_and_headers_are_case_insensitive_literal_names(): void
    {
        $rules = [
            ['source' => 'headers', 'path' => 'X.Source', 'required' => true, 'equals' => 'private-secret'],
            ['source' => 'headers', 'path' => 'x.source', 'required' => false],
        ];
        $validator = new FieldValidator;
        $this->assertSame([], $validator->errors(['headers' => ['X.Source' => ['private-secret', 'extra']]], $rules));
        $this->assertArrayHasKey('headers.x.source', $validator->errors([], $rules));
        $this->assertStringNotContainsString('private-secret', json_encode($validator->errors(['headers' => ['x.source' => ['invalid']]], $rules)));
    }

    public function test_existing_required_and_multi_header_policies_remain_explicit(): void
    {
        $rule = [['source' => 'body', 'path' => 'value', 'required' => true, 'type' => 'any']];
        $validator = new FieldValidator;
        foreach ([[], '   '] as $value) {
            $this->assertArrayHasKey('body.value', $validator->errors(['body' => ['value' => $value]], $rule));
            $this->assertSame([], $validator->errors(['body' => ['value' => $value]], $rule, laravelRequired: false));
        }
        foreach ([null, ''] as $value) {
            $this->assertSame(['body.value' => ['The field is required.']], $validator->errors(['body' => ['value' => $value]], $rule, laravelRequired: false));
        }
        $header = [['source' => 'headers', 'path' => 'X-ID', 'required' => true, 'type' => 'string']];
        $this->assertSame([], $validator->errors(['headers' => ['x-id' => ['0']]], $header, laravelRequired: false, preserveMultipleHeaders: true));
        $this->assertSame(['headers.x-id' => ['The field has an invalid type.']], $validator->errors(['headers' => ['x-id' => ['one', 'two']]], $header, laravelRequired: false, preserveMultipleHeaders: true));
    }

    public function test_typed_fields_accept_request_numeric_and_boolean_strings_but_reject_arrays(): void
    {
        $validator = new FieldValidator;
        foreach (['number' => [1, 1.5, '1.5'], 'boolean' => [true, false, 1, 0, 'TRUE', 'false', '0', '1'], 'string' => ['', 'value']] as $type => $values) {
            $rules = [['source' => 'query', 'path' => 'nested.value', 'required' => false, 'type' => $type]];
            foreach ($values as $value) {
                $this->assertSame([], $validator->errors(['query' => ['nested' => ['value' => $value]]], $rules));
            }
            $this->assertArrayHasKey('query.nested.value', $validator->errors(['query' => ['nested' => ['value' => []]]], $rules));
        }
    }

    public function test_nested_header_indices_are_resolved_before_single_value_unwrapping(): void
    {
        $rules = [
            ['source' => 'headers', 'path' => 'X-ID.0', 'required' => true, 'type' => 'string'],
            ['source' => 'headers', 'path' => 'x-id.1', 'required' => false, 'type' => 'number'],
        ];
        $validator = new FieldValidator;
        $this->assertSame([], $validator->errors(['headers' => ['x-id' => ['one', '42']]], $rules, laravelRequired: false, preserveMultipleHeaders: true, literalHeaderNames: false));
        $this->assertSame(['headers.x-id.1' => ['The field has an invalid type.']], $validator->errors(['headers' => ['x-id' => ['one', 'invalid']]], $rules, laravelRequired: false, preserveMultipleHeaders: true, literalHeaderNames: false));
        $this->assertSame(['headers.x-id.0' => ['The field is required.']], $validator->errors(['headers' => ['x-id' => []]], $rules, laravelRequired: false, preserveMultipleHeaders: true, literalHeaderNames: false));
        // Literal-name consumers must not silently interpret a dot as an array index.
        $this->assertArrayHasKey('headers.x-id.0', $validator->errors(['headers' => ['x-id' => ['one', '42']]], $rules));
    }

    public function test_shared_editor_schema_limits_types_paths_and_exact_values(): void
    {
        $rules = FieldSchema::validation('fields', typed: true, exact: true);
        $field = ['source' => 'body', 'path' => 'items.0.id', 'required' => false, 'type' => 'number', 'equals' => 0];
        $this->assertTrue(Validator::make(['fields' => [$field]], $rules)->passes());
        foreach ([['source' => 'unknown'], ['path' => 'bad/path'], ['path' => str_repeat('a', 256)], ['type' => 'object'], ['equals' => []], ['equals' => INF], ['equals' => str_repeat('a', 4097)]] as $invalid) {
            $this->assertTrue(Validator::make(['fields' => [[...$field, ...$invalid]]], $rules)->fails());
        }
        $this->assertTrue(Validator::make(['fields' => [[...$field, 'source' => 'installation']]], FieldSchema::validation('fields', sources: ['installation']))->passes());
    }
}
