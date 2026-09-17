<?php

namespace TrafficOps\EventCatalog;

final class FieldSuggestions
{
    private array $fields = [];

    public function add(string $path, string $type = 'any', array $events = []): self
    {
        if (! FieldPath::valid($path)) {
            return $this;
        }
        $field = $this->fields[$path] ?? ['path' => $path, 'type' => $type, 'events' => []];
        $field['type'] = $field['type'] === $type ? $type : 'any';
        $field['events'] = array_values(array_unique([...$field['events'], ...array_filter($events, 'is_string')]));
        $this->fields[$path] = $field;

        return $this;
    }

    /** Literal root keys allow HTTP header names containing dots. */
    public function observe(array $payload, string $prefix = '', array $events = [], bool $literalRootKeys = false): self
    {
        foreach ((new PayloadFields)->leaves($payload, literalRootKeys: $literalRootKeys) as $path => $type) {
            $this->add($prefix.$path, $type, $events);
        }

        return $this;
    }

    /** @return list<array{path: string, type: string, events: list<string>}> */
    public function all(bool $sorted = false): array
    {
        $fields = $this->fields;
        if ($sorted) {
            ksort($fields);
        }

        return array_values($fields);
    }
}
