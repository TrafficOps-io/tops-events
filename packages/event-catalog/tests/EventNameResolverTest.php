<?php

namespace TrafficOps\EventCatalog\Tests;

use PHPUnit\Framework\TestCase;
use TrafficOps\EventCatalog\EventNameResolver;
use TrafficOps\EventCatalog\Exceptions\ReservedEventName;

class EventNameResolverTest extends TestCase
{
    public function test_explicit_name_precedence_blank_names_and_original_whitespace_are_preserved(): void
    {
        $resolver = new EventNameResolver('event_name', maxLength: 100);
        $payload = ['body' => ['event_name' => 'body'], 'query' => ['event_name' => ' query ']];
        $this->assertSame(['path', []], $resolver->resolve($payload, 'path'));
        $this->assertSame([' query ', []], $resolver->resolve($payload));
        $this->assertSame(['unnamed', []], $resolver->resolve(['body' => ['event_name' => '   ']]));
        $this->assertSame(['unnamed', []], $resolver->resolve([]));
        $this->assertSame(['unnamed', ['query.event_name' => ['Event name must be a string of at most 100 characters.']]], $resolver->resolve(['query' => ['event_name' => []]]));
    }

    public function test_parameter_selection_uses_origin_then_other_source_and_direct_names_win(): void
    {
        $resolver = new EventNameResolver('whg_event', selector: 'whg_event_param');
        $payload = ['query' => ['whg_event_param' => 'action'], 'body' => ['action' => 'purchase']];
        $this->assertSame(['purchase', []], $resolver->resolve($payload));
        $this->assertSame(['query', []], $resolver->resolve(['query' => [...$payload['query'], 'action' => 'query'], 'body' => $payload['body']]));
        $this->assertSame(['direct', []], $resolver->resolve(['query' => $payload['query'], 'body' => [...$payload['body'], 'whg_event' => 'direct']]));
        $this->assertSame(['unnamed', []], $resolver->resolve(['query' => ['whg_event_param' => 'missing']]));
        $this->assertArrayHasKey('body.whg_event_param', $resolver->resolve(['body' => ['whg_event_param' => []]])[1]);
    }

    public function test_reserved_system_name_is_a_hard_validation_failure(): void
    {
        $resolver = new EventNameResolver('event_name', reservedNames: ['delivery_failed']);
        try {
            $resolver->resolve([], 'delivery_failed');
            $this->fail('A reserved system name must not resolve.');
        } catch (ReservedEventName $rejection) {
            $this->assertSame('delivery_failed', $rejection->name);
            $this->assertSame('path.event', $rejection->field);
            $this->assertSame(['path.event' => ['System event names are reserved.']], $rejection->errors());
        }
        $this->expectException(ReservedEventName::class);
        $resolver->resolve(['query' => ['event_name' => 'delivery_failed']]);
    }

    public function test_reserved_name_found_through_a_selector_names_the_source_field(): void
    {
        $resolver = new EventNameResolver('whg_event', selector: 'whg_event_param', reservedNames: ['delivery_failed']);
        try {
            $resolver->resolve(['query' => ['whg_event_param' => 'action'], 'body' => ['action' => 'delivery_failed']]);
            $this->fail('A reserved system name must not resolve.');
        } catch (ReservedEventName $rejection) {
            $this->assertSame(['body.action' => ['System event names are reserved.']], $rejection->errors());
        }
    }

    public function test_unicode_length_and_the_reserved_list_are_consumer_policies(): void
    {
        $resolver = new EventNameResolver('event_name', maxLength: 3, reservedNames: ['sys']);
        $this->assertSame(['яяя', []], $resolver->resolve([], 'яяя'));
        $this->assertNotSame([], $resolver->resolve([], 'яяяя')[1]);
        $this->assertSame(['sys', []], (new EventNameResolver('event_name'))->resolve([], 'sys'));
    }
}
