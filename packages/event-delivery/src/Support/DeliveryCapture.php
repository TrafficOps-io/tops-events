<?php

namespace TrafficOps\EventDelivery\Support;

use Psr\Http\Message\ResponseInterface;

class DeliveryCapture
{
    public ?array $response = null;

    public function record(ResponseInterface $response): ResponseInterface
    {
        $body = (string) $response->getBody();
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }
        $binary = ! mb_check_encoding($body, 'UTF-8');
        $this->response = ['status' => $response->getStatusCode(), 'headers' => $response->getHeaders(), 'body' => $binary ? base64_encode($body) : $body, 'body_encoding' => $binary ? 'base64' : 'utf-8'];

        return $response;
    }
}
