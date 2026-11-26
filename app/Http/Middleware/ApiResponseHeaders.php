<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseHeaders
{
    /**
     * Apply security and cache headers to API responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP advertises its exact version here unless expose_php is turned off in
        // php.ini, which a deployment cannot rely on. Removing it takes away a
        // free hint about which published vulnerabilities to try.
        $response->headers->remove('X-Powered-By');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Vary', 'Authorization, Accept, Origin', false);

        if ($request->bearerToken() || ! $request->isMethodCacheable()) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        if ($request->is('api/*') && $response->headers->has('Set-Cookie')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
