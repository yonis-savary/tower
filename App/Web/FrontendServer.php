<?php

namespace App\Web;

use Cube\Web\Helpers\StaticServer;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Router\Route;

/**
 * Serves the frontend build with its own cache policy. Without one, the session started on every
 * request makes PHP send `Cache-Control: no-store` : Firefox then never stores the favicon, and
 * bookmarks stay without an icon
 */
class FrontendServer extends StaticServer
{
    // Vite puts a content hash in every file name of assets/ : a new build never reuses a URL
    public const IMMUTABLE = 'public, max-age=31536000, immutable';
    // index.html, icons, manifest : kept, but checked again on each use to follow deployments
    public const REVALIDATE = 'no-cache';

    public static function serveIndexFile(Request $request): Response
    {
        return parent::serveIndexFile($request)->withHeaders(['Cache-Control' => self::REVALIDATE]);
    }

    public function handle(Request $request): Response|Route|null
    {
        $response = parent::handle($request);
        if (!$response instanceof Response)
            return $response;

        return $response->withHeaders([
            'Cache-Control' => str_starts_with($request->getPath(), '/assets/') ? self::IMMUTABLE : self::REVALIDATE,
        ]);
    }
}
