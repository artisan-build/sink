<?php

namespace App;

use ArtisanBuild\BuiltForCloud\BurnMode;
use ArtisanBuild\BuiltForCloud\Contracts\AuthorizesCredentialVerbs;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresBurnMode;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresHeadlineStat;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresHolderResolution;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresPresentationCadence;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialVerb;
use ArtisanBuild\BuiltForCloud\DefaultCredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\Vitals\HeadlineStat;
use ArtisanBuild\BuiltForCloud\Vitals\HeadlineUnit;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Http\Request;

final readonly class SinkCredentialDeclaration implements AuthorizesCredentialVerbs, CredentialDeclaration, DeclaresBurnMode, DeclaresHeadlineStat, DeclaresHolderResolution, DeclaresPresentationCadence
{
    public const ?string HEADLINE_VOCABULARY = SinkHeadlineLabel::class;

    public function __construct(private DefaultCredentialDeclaration $default) {}

    public function resolveSubject(Request $request): ?Subject
    {
        return $this->default->resolveSubject($request);
    }

    public function authorize(Credential $credential, ?string $ability, Request $request): bool
    {
        return $this->default->authorize($credential, $ability, $request);
    }

    public function authorizeVerb(CredentialVerb $verb, ?Subject $subject, Request $request): bool
    {
        return $this->default->authorizeVerb($verb, $subject, $request);
    }

    public function burnMode(): BurnMode
    {
        return $this->default->burnMode();
    }

    public function resolveHolderEmail(string $credentialId): ?string
    {
        return $this->default->resolveHolderEmail($credentialId);
    }

    public function presentationCadenceSeconds(): ?int
    {
        return $this->default->presentationCadenceSeconds();
    }

    public function headlineStat(): HeadlineStat
    {
        return new HeadlineStat(
            Message::query()->count(),
            SinkHeadlineLabel::RetainedMessages,
            HeadlineUnit::Count,
        );
    }
}
