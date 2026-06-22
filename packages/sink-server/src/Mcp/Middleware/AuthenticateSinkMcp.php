<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Middleware;

use ArtisanBuild\BuiltForCloud\TokenRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSinkMcp
{
    public function __construct(private readonly TokenRegistry $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tokens->resolve((string) $request->bearerToken()) === null) {
            abort(401);
        }

        return $next($request);
    }
}
