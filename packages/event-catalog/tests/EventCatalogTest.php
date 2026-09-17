<?php

namespace TrafficOps\EventCatalog\Tests;

use PHPUnit\Framework\TestCase;
use TrafficOps\EventCatalog\EventCatalog;
use TrafficOps\EventCatalog\FieldSuggestions;

class EventCatalogTest extends TestCase
{
    public function test_catalog_selects_custom_and_system_events_without_exposing_schema_values(): void
    {
        $catalog = (new EventCatalog)
            ->add('purchase', [
                ['source' => 'body', 'path' => 'amount', 'type' => 'number', 'equals' => 'private-value'],
                ['source' => 'headers', 'path' => 'X.Source', 'type' => 'string'],
                ['source' => 'body', 'path' => 'bad/path'],
            ])
            ->add('purchase', [['source' => 'body', 'path' => 'retry', 'type' => 'boolean']], 'system')
            ->add('other', [['source' => 'query', 'path' => 'private_field']]);

        $this->assertSame([['name' => 'purchase', 'kind' => 'system']], $catalog->events('system'));
        $this->assertSame([
            ['path' => 'incoming.body.amount', 'type' => 'number', 'events' => ['purchase']],
            ['path' => 'incoming.headers.x.source', 'type' => 'string', 'events' => ['purchase']],
        ], $catalog->suggestions(['purchase'], prefix: 'incoming.'));
        $this->assertSame(['headers.x.source.0', 'body.amount'], array_reverse(array_column($catalog->suggestions(['purchase'], headerSuffix: '.0'), 'path')));
        $this->assertSame(['body.retry'], array_column($catalog->suggestions(['purchase'], kind: 'system'), 'path'));
        $this->assertSame([], $catalog->suggestions(['unknown']));
        $this->assertCount(3, $catalog->suggestions([], all: true));
        $this->assertStringNotContainsString('private-value', serialize($catalog));
        $this->assertStringNotContainsString('equals', serialize($catalog));
    }

    public function test_overlapping_event_fields_merge_types_and_event_associations(): void
    {
        $catalog = (new EventCatalog)
            ->add('first', [['source' => 'body', 'path' => 'amount', 'type' => 'number']])
            ->add('second', [['source' => 'body', 'path' => 'amount', 'type' => 'string']]);

        $this->assertSame([
            ['path' => 'body.amount', 'type' => 'any', 'events' => ['first', 'second']],
        ], $catalog->suggestions(['first', 'second']));
    }

    public function test_observations_support_installation_and_system_fields_with_types_and_no_values(): void
    {
        $suggestions = (new FieldSuggestions)
            ->observe(['ref' => 'private-ref', 'nested' => ['number' => 5]], 'installation.query.')
            ->observe(['number' => true, 'items' => ['private-item']], 'body.', ['system.event'])
            ->observe(['number' => 'private-number'], 'body.', ['custom.event']);
        $byPath = array_column($suggestions->all(sorted: true), null, 'path');

        $this->assertSame('number', $byPath['installation.query.nested.number']['type']);
        $this->assertSame('any', $byPath['body.number']['type']);
        $this->assertSame(['system.event', 'custom.event'], $byPath['body.number']['events']);
        $this->assertSame(['system.event'], $byPath['body.items.0']['events']);
        $this->assertStringNotContainsString('private-', json_encode($suggestions->all(), JSON_THROW_ON_ERROR));
    }

    public function test_catalog_instances_and_source_extensions_are_isolated(): void
    {
        $first = (new EventCatalog)->add('purchase', [['source' => 'installation', 'path' => 'query.click_id', 'type' => 'string']]);
        $second = (new EventCatalog)->add('purchase');

        $this->assertSame([], $second->suggestions(['purchase']));
        $this->assertSame('installation.query.click_id', $first->suggestions(['purchase'])[0]['path']);
    }

    public function test_observed_dotted_headers_keep_each_value_index_without_exposing_values(): void
    {
        $fields = (new FieldSuggestions)->observe(
            ['x.source' => ['private-first', 'private-second']],
            'headers.',
            ['storefront.view'],
            literalRootKeys: true,
        )->all();

        $this->assertSame(['headers.x.source.0', 'headers.x.source.1'], array_column($fields, 'path'));
        $this->assertSame(['storefront.view'], $fields[1]['events']);
        $this->assertStringNotContainsString('private-', json_encode($fields, JSON_THROW_ON_ERROR));
    }
}
