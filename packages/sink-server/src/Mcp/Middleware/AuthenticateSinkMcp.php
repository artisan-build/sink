<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Middleware;

use ArtisanBuild\BuiltForCloud\ApiToken;
use ArtisanBuild\BuiltForCloud\TokenRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSinkMcp
{
    public function __construct(private readonly TokenRegistry $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->resolveModel((string) $request->bearerToken());

        if (! $token instanceof ApiToken) {
            abort(401);
        }

        $request->attributes->set(ApiToken::class, $token);

        return $next($request);
    }
}
