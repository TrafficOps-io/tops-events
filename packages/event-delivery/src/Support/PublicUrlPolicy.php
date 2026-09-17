<?php

namespace TrafficOps\EventDelivery\Support;

use Symfony\Component\HttpFoundation\IpUtils;
use TrafficOps\EventDelivery\Exceptions\PreparationFailed;

class PublicUrlPolicy
{
    public function __construct(private DnsResolver $dns) {}

    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! $parts || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new PreparationFailed('Destination must be an HTTP(S) URL without credentials or a fragment.');
        }
        $host = trim($parts['host'], '[]');
        $addresses = $this->dns->resolve($host);
        if ($addresses === [] || collect($addresses)->contains(fn ($address) => ! $this->isPublic($address))) {
            throw new PreparationFailed('Destination must resolve only to public IP addresses.');
        }

        return ['host' => $host, 'port' => $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80), 'ip' => $addresses[0]];
    }

    public function isPublic(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($address, ':')) {
            return IpUtils::checkIp($address, '2000::/3') && ! IpUtils::checkIp($address, ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']);
        }

        return ! IpUtils::checkIp($address, ['0.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']);
    }
}
