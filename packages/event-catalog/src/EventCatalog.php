<?php

namespace TrafficOps\EventCatalog;

final class EventCatalog
{
    private array $events = [];

    /**
     * Build one catalog per authorized owner. Only schema metadata is retained;
     * payload samples, equals values, model IDs and credentials are discarded.
     */
    public function add(string $name, iterable $fields = [], string $kind = 'custom'): self
    {
        $key = $kind.':'.$name;
        $event = $this->events[$key] ?? ['name' => $name, 'kind' => $kind, 'fields' => []];
        foreach ($fields as $field) {
            $source = $field['source'] ?? '';
            $path = $field['path'] ?? '';
            if (! is_string($source) || ! FieldPath::segment($source) || ! is_string($path) || ! FieldPath::valid($path)) {
                continue;
            }
            $event['fields'][] = [
                'source' => $source,
                'path' => FieldPath::normalize($source, $path),
                'type' => in_array($field['type'] ?? '', FieldSchema::TYPES, true) ? $field['type'] : 'any',
            ];
        }
        $this->events[$key] = $event;

        return $this;
    }

    public function events(?string $kind = null): array
    {
        return array_values(array_map(
            fn ($event) => ['name' => $event['name'], 'kind' => $event['kind']],
            array_filter($this->events, fn ($event) => $kind === null || $event['kind'] === $kind),
        ));
    }

    /** Custom and system names have independent namespaces, even when names match. */
    public function suggestions(array $names, string $kind = 'custom', string $prefix = '', string $headerSuffix = '', bool $all = false): array
    {
        $suggestions = new FieldSuggestions;
        foreach ($this->events as $event) {
            if ($event['kind'] !== $kind || (! $all && ! in_array($event['name'], $names, true))) {
                continue;
            }
            foreach ($event['fields'] as $field) {
                $path = $prefix.$field['source'].'.'.$field['path'].($field['source'] === 'headers' ? $headerSuffix : '');
                $suggestions->add($path, $field['type'], [$event['name']]);
            }
        }

        return $suggestions->all();
    }
}
