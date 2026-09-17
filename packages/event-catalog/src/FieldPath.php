<?php

namespace TrafficOps\EventCatalog;

final class FieldPath
{
    public const PATTERN = '/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D';

    public static function valid(string $path): bool
    {
        return preg_match(self::PATTERN, $path) === 1;
    }

    public static function segment(string $segment): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/D', $segment) === 1;
    }

    public static function normalize(string $source, string $path): string
    {
        return $source === 'headers' ? strtolower($path) : $path;
    }

    public static function key(array $field): string
    {
        return $field['source'].':'.self::normalize($field['source'], $field['path']);
    }
}
