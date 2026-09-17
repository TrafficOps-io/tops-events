<?php

namespace TrafficOps\EventCatalog\Tests;

use PHPUnit\Framework\TestCase;
use TrafficOps\EventCatalog\EventNameResolver;

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

    public function test_reserved_system_names_and_unicode_length_are_consumer_policies(): void
    {
        $resolver = new EventNameResolver('event_name', maxLength: 3, reservedNames: ['sys']);
        $this->assertSame(['sys', ['path.event' => ['System event names are reserved.']]], $resolver->resolve([], 'sys'));
        $this->assertSame(['яяя', []], $resolver->resolve([], 'яяя'));
        $this->assertNotSame([], $resolver->resolve([], 'яяяя')[1]);
        $this->assertSame(['sys', []], (new EventNameResolver('event_name'))->resolve([], 'sys'));
    }
}
