<?php

namespace TrafficOps\EventCatalog;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

final class FieldValidator
{
    /**
     * Policies preserve established consumers: Laravel required treats empty arrays
     * and whitespace as missing; presence-only validation accepts them. HTTP headers
     * may use their first value, or retain multiple values for type validation.
     * Literal names and dot traversal through raw header arrays are distinct policies.
     *
     * @param  iterable<array{source: string, path: string, required: bool, type?: string, equals?: mixed}>  $fields
     * @return array<string, list<string>>
     */
    public function errors(
        array $payload,
        iterable $fields,
        bool $laravelRequired = true,
        bool $preserveMultipleHeaders = false,
        bool $literalHeaderNames = true,
    ): array {
        $headers = [];
        foreach (is_array($payload['headers'] ?? null) ? $payload['headers'] : [] as $name => $values) {
            $headers[strtolower((string) $name)] = $literalHeaderNames
                ? $this->headerValue($values, $preserveMultipleHeaders)
                : $values;
        }
        $data = [...$payload, 'headers' => $headers];
        $errors = [];
        foreach ($fields as $field) {
            $header = $field['source'] === 'headers';
            $literalHeader = $header && $literalHeaderNames;
            $name = FieldPath::normalize($field['source'], $field['path']);
            $path = $field['source'].'.'.$name;
            $exists = $literalHeader ? array_key_exists($name, $headers) : Arr::has($data, $path);
            $value = $literalHeader ? ($headers[$name] ?? null) : Arr::get($data, $path);
            if ($header && ! $literalHeaderNames) {
                // Resolve x-id.0 against the raw header array before unwrapping it.
                $value = $this->headerValue($value, $preserveMultipleHeaders);
            }
            if ($field['required']) {
                if ($laravelRequired) {
                    $validationPath = $literalHeader ? 'headers.'.str_replace('.', '\\.', $name) : $path;
                    $errors = array_merge_recursive($errors, Validator::make($data, [$validationPath => ['required']])->errors()->toArray());
                } elseif (! $exists || $value === null || $value === '') {
                    $errors[$path][] = 'The field is required.';

                    continue;
                }
            }
            if (! $exists) {
                continue;
            }
            if (array_key_exists('equals', $field) && ! $this->equal($value, $field['equals'])) {
                // Public ingest errors must never disclose the configured value.
                $errors[$path][] = 'The field must match the configured exact value.';
            }
            if ($value !== null && ! $this->validType($value, $field['type'] ?? 'any')) {
                $errors[$path][] = 'The field has an invalid type.';
            }
        }

        return $errors;
    }

    private function headerValue(mixed $values, bool $preserveMultiple): mixed
    {
        if (! is_array($values)) {
            return $values;
        }
        if ($preserveMultiple) {
            return count($values) === 1 ? reset($values) : $values;
        }

        return $values[0] ?? null;
    }

    private function equal(mixed $actual, mixed $expected): bool
    {
        $numbers = (is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected));

        return $numbers ? $actual == $expected : $actual === $expected;
    }

    private function validType(mixed $value, string $type): bool
    {
        return match ($type) {
            'any' => true,
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value) || is_string($value) && is_numeric($value),
            'boolean' => is_bool($value) || is_string($value) && in_array(strtolower($value), ['true', 'false', '1', '0'], true) || is_int($value) && in_array($value, [0, 1], true),
            default => false,
        };
    }
}
