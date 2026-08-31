<?php

use App\SinkCredentialDeclaration;
use App\SinkHeadlineLabel;
use ArtisanBuild\BuiltForCloud\Auth\CredentialGuard;
use ArtisanBuild\BuiltForCloud\BurnMode;
use ArtisanBuild\BuiltForCloud\Contracts\AuthorizesCredentialVerbs;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresBurnMode;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresHeadlineStat;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresHolderResolution;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresPresentationCadence;
use ArtisanBuild\BuiltForCloud\CredentialVerb;
use ArtisanBuild\BuiltForCloud\DefaultCredentialDeclaration;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

uses(WithCredentials::class, ContractAssertions::class);

beforeEach(function (): void {
    config([
        'built-for-cloud.vitals.app_version' => '0.2.0',
        'built-for-cloud.vitals.deployed_at' => '2026-08-31T00:00:00+00:00',
        'built-for-cloud.vitals.deployment_id' => 'sink-console-vitals-test',
        'built-for-cloud.vitals.queue_cache_seconds' => 0,
        'queue.default' => 'sync',
    ]);

    $connection = (string) config('sink-server.database.connection');

    if (! Schema::connection($connection)->hasTable('messages')) {
        Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => 'packages/sink-server/database/migrations',
            '--realpath' => true,
        ]);
    }

    Message::query()->delete();
});

test('production wiring preserves the default declaration contract and behavior', function (): void {
    $declaration = resolve(CredentialDeclaration::class);
    $request = Request::create('/bfc/console/vitals');
    $credential = $this->mintCredential()->credential;

    $defaultOptionalInterfaces = [
        AuthorizesCredentialVerbs::class,
        DeclaresBurnMode::class,
        DeclaresHolderResolution::class,
        DeclaresPresentationCadence::class,
    ];
    $actualDefaultInterfaces = array_values(array_diff(
        class_implements(DefaultCredentialDeclaration::class),
        [CredentialDeclaration::class],
    ));
    $expectedSinkInterfaces = [
        ...$defaultOptionalInterfaces,
        CredentialDeclaration::class,
        DeclaresHeadlineStat::class,
    ];
    $actualSinkInterfaces = array_values(class_implements(SinkCredentialDeclaration::class));

    sort($defaultOptionalInterfaces);
    sort($actualDefaultInterfaces);
    sort($expectedSinkInterfaces);
    sort($actualSinkInterfaces);

    expect(config('auth.guards.bfc'))->toBe([
        'driver' => 'bfc',
        'provider' => 'users',
    ])->and(Auth::guard('bfc'))->toBeInstanceOf(CredentialGuard::class)
        ->and($declaration)->toBeInstanceOf(SinkCredentialDeclaration::class)
        ->and($actualDefaultInterfaces)->toBe($defaultOptionalInterfaces)
        ->and($actualSinkInterfaces)->toBe($expectedSinkInterfaces)
        ->and($declaration::HEADLINE_VOCABULARY)->toBe(SinkHeadlineLabel::class)
        ->and($declaration->burnMode())->toBe(BurnMode::FirstUse)
        ->and($declaration->resolveSubject($request))->toBeNull()
        ->and($declaration->resolveHolderEmail($credential->id))->toBeNull()
        ->and($declaration->presentationCadenceSeconds())->toBeNull()
        ->and($declaration->authorize($credential, null, $request))->toBeTrue()
        ->and($declaration->authorize($credential, OperatorAbility::MetadataRead->value, $request))->toBeTrue();

    foreach (CredentialVerb::cases() as $verb) {
        expect($declaration->authorizeVerb($verb, null, $request))->toBeTrue();
    }
});

test('an exact metadata reader receives the retained message headline and bounded shape', function (): void {
    seedConsoleVitalsMessages(3);

    $reader = $this->mintCredential([
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'console-vitals-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);

    $response = $this->getJson('/bfc/console/vitals', [
        'Authorization' => $reader->bearerHeader(),
    ])->assertSuccessful()
        ->assertJsonPath('headline', [
            'value' => 3,
            'label' => 'retained-messages',
            'unit' => 'count',
        ]);

    $this->assertBuiltForCloudMetadataEndpoint($response, 'GET /bfc/console/vitals');
});

test('the dashboard rejects a metadata reader carrying any additional ability', function (): void {
    $reader = $this->mintCredential([
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'exact-console-vitals-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);
    $overpowered = $this->mintCredential([
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'overpowered-console-vitals-reader',
        'abilities' => [
            OperatorAbility::MetadataRead->value,
            OperatorAbility::CredentialRead->value,
        ],
    ]);

    $this->getJson('/bfc/console/vitals', [
        'Authorization' => $reader->bearerHeader(),
    ])->assertSuccessful();

    $this->getJson('/bfc/console/vitals', [
        'Authorization' => $overpowered->bearerHeader(),
    ])->assertForbidden();
});

test('headline collection counts on the sink connection without loading message rows', function (): void {
    seedConsoleVitalsMessages(4);

    $reader = $this->mintCredential([
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'query-shape-console-vitals-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);
    $sink = DB::connection((string) config('sink-server.database.connection'));
    $sink->flushQueryLog();
    $sink->enableQueryLog();

    try {
        $this->getJson('/bfc/console/vitals', [
            'Authorization' => $reader->bearerHeader(),
        ])->assertSuccessful()
            ->assertJsonPath('headline.value', 4);
    } finally {
        $queries = $sink->getQueryLog();
        $sink->disableQueryLog();
    }

    $messageQueries = collect($queries)
        ->filter(fn (array $query): bool => Str::contains(Str::lower($query['query']), 'from "messages"'))
        ->values();
    $messageSql = Str::of((string) $messageQueries->first()['query'])->lower()->squish()->toString();

    expect($messageQueries)->toHaveCount(1)
        ->and($messageSql)->toBe('select count(*) as "aggregate" from "messages"');
});

test('the metadata conformance instrument rejects an added free text field', function (): void {
    $reader = $this->mintCredential([
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'conformance-console-vitals-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);

    $valid = $this->getJson('/bfc/console/vitals', [
        'Authorization' => $reader->bearerHeader(),
    ])->assertSuccessful();

    $this->assertBuiltForCloudMetadataEndpoint($valid, 'GET /bfc/console/vitals');

    $decoy = new TestResponse(response()->json([
        ...$valid->json(),
        'note' => 'pending review',
    ]));

    expect(fn () => $this->assertBuiltForCloudMetadataEndpoint($decoy, 'GET /bfc/console/vitals'))
        ->toThrow(AssertionFailedError::class);
});

function seedConsoleVitalsMessages(int $count): void
{
    foreach (range(1, $count) as $sequence) {
        $idempotencyKey = (string) Str::ulid();

        Message::query()->create([
            'idempotency_key' => $idempotencyKey,
            'app' => 'console-vitals-test',
            'stream' => null,
            'subject' => 'Retained message '.$sequence,
            'from_address' => 'sender@example.test',
            'from_name' => 'Sender',
            'message_id' => '<'.$idempotencyKey.'@example.test>',
            'sent_at' => now(),
            'received_at' => now(),
            'size_bytes' => 1,
            'attachment_count' => 0,
            'link_count' => 0,
            'truncation' => 'none',
            'raw_object_key' => 'raw/console-vitals/'.$idempotencyKey.'.eml',
            'parsed_at' => now(),
        ]);
    }
}
