<?php

namespace App\Http\Middleware;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleGuardConfiguration;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureConsoleSession;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateConsoleOrLocal implements AuthenticatesRequests
{
    public function __construct(
        private ActingPrincipalResolver $principalResolver,
        private EnsureConsoleSession $ensureConsoleSession,
        private EnsureUserIsAuthenticated $ensureLocalUser,
        private Authenticate $authenticate,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->principalResolver->resolve()->delegatedSessionPresent()) {
            return $this->ensureConsoleSession->handle(
                $request,
                fn (Request $request): Response => $this->authenticate->handle(
                    $request,
                    $next,
                    ConsoleGuardConfiguration::GUARD,
                ),
            );
        }

        return $this->authenticate->handle(
            $request,
            fn (Request $request): Response => $this->ensureLocalUser->handle($request, $next),
            'web',
        );
    }
}
