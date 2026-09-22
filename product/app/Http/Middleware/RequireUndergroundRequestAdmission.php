<?php

namespace App\Http\Middleware;

use App\Application\Underground\UndergroundRequestAdmission;
use App\Application\Underground\UndergroundRuntimeException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireUndergroundRequestAdmission
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! UndergroundRequestAdmission::required($request->method(), $request->path())) {
            return $next($request);
        }
        try {
            app(UndergroundRequestAdmission::class)->validate($request);

            return $next($request);
        } catch (UndergroundRuntimeException $exception) {
            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], 409);
        } finally {
            $request->attributes->remove(UndergroundRequestAdmission::ATTRIBUTE);
        }
    }
}
