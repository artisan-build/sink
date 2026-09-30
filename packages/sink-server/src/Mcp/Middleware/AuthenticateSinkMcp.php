<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Middleware;

use ArtisanBuild\BuiltForCloud\Auth\CredentialResolver;
use ArtisanBuild\BuiltForCloud\Console\AssertionVerifier;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialUsageRecorder;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSinkMcp
{
    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly CredentialUsageRecorder $usage,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $request->headers->remove('Authorization');
        $request->server->remove('HTTP_AUTHORIZATION');
        $request->server->remove('REDIRECT_HTTP_AUTHORIZATION');

        if (is_string($bearer) && str_starts_with($bearer, AssertionVerifier::HEADER)) {
            abort(401);
        }

        $credential = $this->credentials->resolve(CredentialKind::Bearer, $bearer);

        if ($credential?->purpose !== CredentialPurpose::Mcp
            || $credential->subject_type !== SubjectType::Installation
            || $credential->user_id !== null
            || ! $this->usage->recordUsage($credential)) {
            abort(401);
        }

        $request->attributes->set(Credential::class, $credential);
        $request->setUserResolver(static fn (): Credential => $credential);
        RequestEffectCeiling::publish($request, Effect::Destructive->value);

        return $next($request);
    }
}
