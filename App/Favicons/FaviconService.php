<?php

namespace App\Favicons;

use Cube\Env\Cache;

class FaviconService
{
    private const PAGE_MAX_BYTES = 1024 * 1024;
    private const ICON_MAX_BYTES = 256 * 1024;
    private const MAX_CANDIDATES = 4;
    private const DATA_URI = '~^data:([^;,]+)(;base64)?,(.*)$~s';

    public function __construct(
        protected FaviconClient $client,
        protected Cache $cache,
    ) {}

    public function of(string $url): ?Favicon
    {
        $origin = $this->originOf($url);
        if (null === $origin)
            return null;

        $cache = $this->cache->child('favicons');
        $key = sha1($origin);
        if ($cache->has($key))
            return $this->fromCache($cache->get($key));

        $favicon = $this->discover($origin);

        // A site without icon is asked again the next day, not on every page shown
        $cache->set($key, $favicon ? ['type' => $favicon->type, 'body' => base64_encode($favicon->body)] : [], $favicon ? Cache::MONTH : Cache::DAY);

        return $favicon;
    }

    public function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https']) || empty($parts['host']))
            return null;

        return $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function fromCache(array $cached): ?Favicon
    {
        return $cached ? new Favicon($cached['type'], base64_decode($cached['body'])) : null;
    }

    private function discover(string $origin): ?Favicon
    {
        $candidates = array_slice(array_unique([...$this->declaredIcons($origin), $origin.'/favicon.ico']), 0, self::MAX_CANDIDATES);

        foreach ($candidates as $candidate) {
            if ($favicon = $this->download($candidate))
                return $favicon;
        }

        return null;
    }

    /**
     * @return list<string> The icons the home page declares, `icon` before `apple-touch-icon`
     */
    private function declaredIcons(string $origin): array
    {
        $page = $this->client->read($origin.'/', self::PAGE_MAX_BYTES);
        if (200 !== $page?->getStatusCode() || '' === $page->getBody())
            return [];

        $document = new \DOMDocument();
        @$document->loadHTML($page->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        $icons = ['icon' => [], 'apple-touch-icon' => []];
        foreach ($document->getElementsByTagName('link') as $link) {
            $href = trim($link->getAttribute('href'));
            $rels = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))));
            $rel = array_values(array_intersect(['icon', 'apple-touch-icon'], $rels))[0] ?? null;
            if ('' === $href || null === $rel)
                continue;

            $resolved = str_starts_with($href, 'data:') ? $href : $this->client->resolve($origin.'/', $href);
            if (null !== $resolved)
                $icons[$rel][] = $resolved;
        }

        return [...$icons['icon'], ...$icons['apple-touch-icon']];
    }

    private function download(string $url): ?Favicon
    {
        $body = preg_match(self::DATA_URI, $url, $data)
            ? ($data[2] ? base64_decode($data[3], true) : rawurldecode($data[3]))
            : $this->downloaded($url);

        if (!$body || strlen($body) > self::ICON_MAX_BYTES)
            return null;

        // The type announced by the site is often wrong for .ico files : the content decides
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);

        return str_starts_with((string) $type, 'image/') ? new Favicon($type, $body) : null;
    }

    private function downloaded(string $url): ?string
    {
        $response = $this->client->read($url, self::ICON_MAX_BYTES);

        return 200 === $response?->getStatusCode() ? $response->getBody() : null;
    }
}
