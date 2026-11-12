<?php

namespace App\Http\Middleware;

use App\Support\Performance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 1 of Requirement 3: HTTP caching with ETag.
 *
 *  1st request:  200 + body + header  ETag: "<hash of the body>"
 *  next request: the client sends     If-None-Match: "<that hash>"
 *                unchanged -> 304 Not Modified, EMPTY body (the client reuses its copy)
 *                changed   -> 200 + the new body + the new ETag
 *
 * The ETag is a hash of the JSON body, so ANY change (name, price, stock...) changes it:
 * a stale copy can never be confirmed as fresh.
 *
 * Usage on routes:  ->middleware('etag:public')   anyone may cache it (product catalog)
 *                   ->middleware('etag:private')  only the user's own browser (orders)
 * "no-cache" means: you may keep a copy, but ask the server (If-None-Match) before using it.
 */
class ETag
{
    public function handle(Request $request, Closure $next, string $visibility = 'public'): Response
    {
        $response = $next($request);

        if (! Performance::on('etag')
            || ! $request->isMethodCacheable()
            || $response->getStatusCode() !== 200
            || $response->getContent() === false) {
            return $response;
        }

        $response->setEtag(hash('xxh128', $response->getContent()));
        $response->headers->set('Cache-Control', ($visibility === 'private' ? 'private' : 'public').', no-cache');
        // Responses differ per token for private data: never share them between users.
        $response->setVary(['Authorization', 'Accept']);

        // Compares If-None-Match with the ETag; on a match: status 304 and the body is removed.
        $response->isNotModified($request);

        return $response;
    }
}
