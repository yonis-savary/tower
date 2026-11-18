<?php

namespace App\Favicons;

use Cube\Web\Http\HttpClient;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;

class FaviconClient extends HttpClient
{
    private const TIMEOUT_SECONDS = 5;
    private const FOLLOWED_REDIRECTIONS = 3;

    public function baseUserAgent(): string
    {
        return 'Mozilla/5.0 (compatible; Tower favicon reader)';
    }

    /**
     * @return ?Response null when the address is not public, unreachable, too big, or redirects too often
     */
    public function read(string $url, int $maxBytes): ?Response
    {
        for ($redirections = 0; $redirections <= self::FOLLOWED_REDIRECTIONS; ++$redirections) {
            $response = $this->readOnce($url, $maxBytes);
            $location = $response?->getHeader('location');
            if (!$response || !$location || !in_array($response->getStatusCode(), self::REDIRECTION_STATUSES))
                return $response;

            $url = $this->resolveLocation($url, $location);
            if (null === $url)
                return null;
        }

        return null;
    }

    public function resolve(string $base, string $reference): ?string
    {
        return $this->resolveLocation($base, $reference);
    }

    private function readOnce(string $url, int $maxBytes): ?Response
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = trim($parts['host'] ?? '', '[]');
        if (!in_array($scheme, ['http', 'https']) || '' === $host)
            return null;

        // A link is typed by a user : it must not make Tower read its own network
        $address = $this->publicAddressOf($host);
        if (null === $address)
            return null;

        $port = $parts['port'] ?? ('https' === $scheme ? 443 : 80);
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        try {
            return $this->fetch(
                new Request('GET', $url),
                timeout: self::TIMEOUT_SECONDS,
                supportRedirection: false,
                logFlags: 0,
                // The address checked is the one connected to : a second DNS answer cannot point elsewhere
                curlMutator: fn (\CurlHandle $handle) => curl_setopt_array($handle, [
                    CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"],
                    CURLOPT_MAXFILESIZE_LARGE => $maxBytes,
                    CURLOPT_ENCODING => '',
                ]),
            );
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function publicAddressOf(string $host): ?string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->lookup($host);
        if (!$addresses)
            return null;

        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
                return null;
        }

        return $addresses[0];
    }

    /**
     * @return list<string>
     */
    private function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
