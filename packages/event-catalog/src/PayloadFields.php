<?php

namespace TrafficOps\EventCatalog;

final class PayloadFields
{
    /**
     * Only paths leave this method. Exclusions apply to top-level control keys.
     * Headers are literal names, not nested objects or individual array values.
     *
     * @return list<array{source: string, path: string, required: false}>
     */
    public function discover(
        array $payload,
        array $sources = ['body', 'query', 'headers'],
        array $excludedRoots = [],
        bool $includeEmptyArrays = false,
        int $limit = 100,
        bool $dottedHeaderNames = false,
    ): array {
        $fields = [];
        if ($limit < 1) {
            return $fields;
        }
        foreach ($sources as $source) {
            $values = is_array($payload[$source] ?? null) ? $payload[$source] : [];
            foreach ($excludedRoots[$source] ?? [] as $key) {
                unset($values[$key]);
            }
            $paths = $source === 'headers'
                ? $this->headerPaths($values, $dottedHeaderNames)
                : array_keys($this->leaves($values, $includeEmptyArrays, $limit - count($fields)));
            foreach ($paths as $path) {
                $fields[] = ['source' => $source, 'path' => (string) $path, 'required' => false];
                if (count($fields) >= $limit) {
                    return $fields;
                }
            }
        }

        return $fields;
    }

    /** Existing configured rules always win, including exact-value constraints. */
    public function merge(array $existing, array $discovered, int $limit = 100): array
    {
        $known = [];
        foreach ($existing as $field) {
            $known[FieldPath::key($field)] = true;
        }
        foreach ($discovered as $field) {
            if (count($existing) >= $limit) {
                break;
            }
            $key = FieldPath::key($field);
            if (! isset($known[$key])) {
                $existing[] = $field;
                $known[$key] = true;
            }
        }

        return $existing;
    }

    /** @return array<string, string> Paths and inferred types, never values. */
    public function leaves(array $values, bool $includeEmptyArrays = false, int $limit = 100, bool $literalRootKeys = false): array
    {
        $paths = [];
        if ($limit > 0) {
            $this->walk($values, '', $paths, $includeEmptyArrays, $limit, $literalRootKeys);
        }

        return $paths;
    }

    private function walk(array $values, string $prefix, array &$paths, bool $includeEmptyArrays, int $limit, bool $literalRootKeys = false): void
    {
        foreach ($values as $key => $value) {
            if (count($paths) >= $limit) {
                return;
            }
            $segment = (string) $key;
            $path = $prefix === '' ? $segment : $prefix.'.'.$segment;
            $validSegment = $literalRootKeys ? FieldPath::valid($segment) : FieldPath::segment($segment);
            if (! $validSegment || strlen($path) > 255) {
                continue;
            }
            if (is_array($value) && ($value !== [] || ! $includeEmptyArrays)) {
                $this->walk($value, $path, $paths, $includeEmptyArrays, $limit);
            } elseif (is_scalar($value) || $value === null || $value === []) {
                $paths[$path] = match (true) {
                    is_bool($value) => 'boolean',
                    is_int($value), is_float($value) => 'number',
                    $value === [] => 'any',
                    default => 'string',
                };
            }
        }
    }

    private function headerPaths(array $headers, bool $dottedHeaderNames): iterable
    {
        foreach (array_keys($headers) as $header) {
            $path = strtolower((string) $header);
            if ($dottedHeaderNames ? FieldPath::valid($path) : FieldPath::segment($path)) {
                yield $path;
            }
        }
    }
}
