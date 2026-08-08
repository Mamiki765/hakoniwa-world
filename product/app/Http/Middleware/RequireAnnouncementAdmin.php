<?php

namespace App\Http\Middleware;

use App\Application\AnnouncementAdminAuthorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAnnouncementAdmin
{
    public function __construct(private readonly AnnouncementAdminAuthorizer $authorizer) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->authorizer->allows($request->user()), 403);

        return $next($request);
    }
}
