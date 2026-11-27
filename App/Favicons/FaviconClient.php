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

    public function isPublicOnly(): bool
    {
        // A link is typed by a user : it must not make Tower read its own network
        return true;
    }

    private function readOnce(string $url, int $maxBytes): ?Response
    {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https']) || !parse_url($url, PHP_URL_HOST))
            return null;

        try {
            return $this->fetch(
                new Request('GET', $url),
                timeout: self::TIMEOUT_SECONDS,
                supportRedirection: false,
                logFlags: 0,
                // No CURLOPT_ENCODING : the size limit then applies to the bytes read, not to a compressed body that expands
                curlMutator: fn (\CurlHandle $handle) => curl_setopt($handle, CURLOPT_MAXFILESIZE_LARGE, $maxBytes),
            );
        } catch (\RuntimeException) {
            return null;
        }
    }
}
