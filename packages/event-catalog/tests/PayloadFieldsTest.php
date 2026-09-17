<?php

namespace TrafficOps\EventCatalog\Tests;

use PHPUnit\Framework\TestCase;
use TrafficOps\EventCatalog\PayloadFields;

class PayloadFieldsTest extends TestCase
{
    public function test_discovery_preserves_source_order_nested_lists_and_literal_header_names_without_values(): void
    {
        $fields = (new PayloadFields)->discover([
            'body' => ['order' => ['items' => [['sku' => 'private-sku']]], 'event_name' => 'purchase', 'empty' => [], 'bad.key' => 'private-value'],
            'query' => ['source' => 'private-source'],
            'headers' => ['X.Source' => ['private-header'], 'X-Token' => ['private-token']],
        ], excludedRoots: ['body' => ['event_name']], dottedHeaderNames: true);

        $this->assertSame([
            ['source' => 'body', 'path' => 'order.items.0.sku', 'required' => false],
            ['source' => 'query', 'path' => 'source', 'required' => false],
            ['source' => 'headers', 'path' => 'x.source', 'required' => false],
            ['source' => 'headers', 'path' => 'x-token', 'required' => false],
        ], $fields);
        $this->assertStringNotContainsString('private-', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    public function test_empty_arrays_and_source_priority_are_consumer_policies(): void
    {
        $payload = ['body' => ['empty' => []], 'query' => ['first' => false]];
        $fields = (new PayloadFields)->discover($payload, ['query', 'body'], includeEmptyArrays: true);
        $this->assertSame(['first', 'empty'], array_column($fields, 'path'));
        $this->assertSame(['first'], array_column((new PayloadFields)->discover($payload), 'path'));
    }

    public function test_total_discovery_is_bounded_and_ignores_unaddressable_and_overlong_paths(): void
    {
        $payload = ['body' => ['invalid/path' => 1, str_repeat('a', 256) => 2, 'items' => range(1, 150)], 'query' => ['extra' => true]];
        $fields = (new PayloadFields)->discover($payload);
        $this->assertCount(100, $fields);
        $this->assertSame('items.99', $fields[99]['path']);
        $this->assertSame([], (new PayloadFields)->discover($payload, limit: 0));
        $this->assertSame([], (new PayloadFields)->discover(['body' => null, 'headers' => 'invalid']));
        $this->assertSame('0', (new PayloadFields)->discover(['body' => [true]])[0]['path']);
    }

    public function test_merge_preserves_configured_constraints_and_canonical_header_identity(): void
    {
        $existing = [
            ['source' => 'headers', 'path' => 'X-Auth', 'required' => true, 'equals' => 'private-secret'],
            ['source' => 'body', 'path' => 'id', 'required' => true],
        ];
        $discovery = new PayloadFields;
        $discovered = $discovery->discover(['headers' => ['x-auth' => ['ignored']], 'body' => ['id' => 1, 'new' => 2]]);
        $this->assertSame([...$existing, ['source' => 'body', 'path' => 'new', 'required' => false]], $discovery->merge($existing, $discovered));
        $this->assertSame($existing, $discovery->merge($existing, $discovered, limit: 2));
        $this->assertSame($existing, $discovery->merge($existing, $discovered, limit: 1));
    }

    public function test_header_discovery_policy_preserves_segment_only_consumers(): void
    {
        $payload = ['headers' => ['X-Source' => ['value'], 'X.Source' => ['value']]];
        $this->assertSame(['x-source'], array_column((new PayloadFields)->discover($payload), 'path'));
        $this->assertSame(['x-source', 'x.source'], array_column((new PayloadFields)->discover($payload, dottedHeaderNames: true), 'path'));
    }
}
