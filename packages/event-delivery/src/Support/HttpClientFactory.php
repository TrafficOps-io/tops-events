<?php

namespace TrafficOps\EventDelivery\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;

class HttpClientFactory
{
    public function make(DeliveryCapture $capture, array $endpoint): Client
    {
        $stack = HandlerStack::create(new CurlHandler);
        $stack->push(fn ($handler) => fn ($request, $options) => $handler($request, $options)->then(fn ($response) => $capture->record($response)));
        $options = ['handler' => $stack, 'timeout' => config('event-delivery.http_timeout', 30), 'connect_timeout' => 10, 'allow_redirects' => false, 'verify' => true, 'proxy' => ''];
        if (! filter_var($endpoint['host'], FILTER_VALIDATE_IP)) {
            $address = str_contains($endpoint['ip'], ':') ? '['.$endpoint['ip'].']' : $endpoint['ip'];
            $options['curl'] = [CURLOPT_RESOLVE => [$endpoint['host'].':'.$endpoint['port'].':'.$address]];
        }

        return new Client($options);
    }
}
