<?php

namespace TrafficOps\EventCatalog;

use Illuminate\Validation\Rule;

final class FieldSchema
{
    public const SOURCES = ['body', 'query', 'headers'];

    public const TYPES = ['any', 'string', 'number', 'boolean'];

    /** Common Laravel editor/API rules; consumers may add their own row metadata. */
    public static function validation(string $prefix, bool $typed = false, bool $exact = false, array $sources = self::SOURCES): array
    {
        $rules = [
            $prefix.'.*.source' => ['required', Rule::in($sources)],
            $prefix.'.*.path' => ['required', 'string', 'max:255', 'regex:'.FieldPath::PATTERN],
            $prefix.'.*.required' => ['required', 'boolean'],
        ];
        if ($typed) {
            $rules[$prefix.'.*.type'] = ['required', Rule::in(self::TYPES)];
        }
        if ($exact) {
            $rules[$prefix.'.*.equals'] = ['sometimes', 'nullable', function ($attribute, $value, $fail) {
                if (! is_scalar($value) || (is_float($value) && ! is_finite($value)) || (is_string($value) && strlen($value) > 4096)) {
                    $fail(__('Exact values must be text, numbers, booleans or null, up to 4096 bytes.'));
                }
            }];
        }

        return $rules;
    }
}
