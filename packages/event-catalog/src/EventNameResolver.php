<?php

namespace TrafficOps\EventCatalog;

final class EventNameResolver
{
    public function __construct(
        private readonly string $parameter,
        private readonly int $maxLength = 255,
        private readonly ?string $selector = null,
        private readonly array $reservedNames = [],
    ) {}

    /** @return array{0: string, 1: array<string, list<string>>} */
    public function resolve(array $payload, ?string $pathName = null): array
    {
        if ($pathName !== null) {
            return $this->normalize($pathName, 'path.event');
        }
        foreach (['query', 'body'] as $source) {
            $values = $this->values($payload, $source);
            if (array_key_exists($this->parameter, $values)) {
                return $this->normalize($values[$this->parameter], $source.'.'.$this->parameter);
            }
        }
        if ($this->selector !== null) {
            foreach (['query', 'body'] as $source) {
                $values = $this->values($payload, $source);
                if (! array_key_exists($this->selector, $values)) {
                    continue;
                }
                $parameter = $values[$this->selector];
                if (! is_string($parameter) || mb_strlen($parameter) > $this->maxLength) {
                    return ['unnamed', [$source.'.'.$this->selector => ["Event parameter name must be a string of at most {$this->maxLength} characters."]]];
                }
                if (trim($parameter) !== '') {
                    foreach ([$source, $source === 'query' ? 'body' : 'query'] as $valueSource) {
                        $candidate = $this->values($payload, $valueSource);
                        if (array_key_exists($parameter, $candidate)) {
                            return $this->normalize($candidate[$parameter], $valueSource.'.'.$parameter);
                        }
                    }
                }

                return ['unnamed', []];
            }
        }

        return ['unnamed', []];
    }

    private function values(array $payload, string $source): array
    {
        return is_array($payload[$source] ?? null) ? $payload[$source] : [];
    }

    private function normalize(mixed $value, string $field): array
    {
        if (! is_string($value) || mb_strlen($value) > $this->maxLength) {
            return ['unnamed', [$field => ["Event name must be a string of at most {$this->maxLength} characters."]]];
        }
        $name = trim($value) === '' ? 'unnamed' : $value;

        return [$name, in_array($name, $this->reservedNames, true) ? [$field => ['System event names are reserved.']] : []];
    }
}
