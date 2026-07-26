<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicApiResponse
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
