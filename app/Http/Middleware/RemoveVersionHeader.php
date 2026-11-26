<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strips the header that advertises the exact PHP version.
 *
 * PHP sends `X-Powered-By` on every response unless `expose_php` is turned off in
 * php.ini — which a deployment cannot rely on, since the file often belongs to the
 * host rather than the application. Naming the precise version tells an attacker
 * which published vulnerabilities are worth trying, for no benefit to any client.
 *
 * Global rather than API-only: the admin pages leak it just as readily.
 */
class RemoveVersionHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->remove('X-Powered-By');

        // Also removed at the PHP level, because the header is emitted by the
        // engine and can be re-added after the response object is built.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
