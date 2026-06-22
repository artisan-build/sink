<?php

declare(strict_types=1);

use ArtisanBuild\SinkClient\Exceptions\SinkNotConfigured;
use ArtisanBuild\SinkClient\Exceptions\SinkProductionFuse;
use ArtisanBuild\SinkClient\SinkClient;
use ArtisanBuild\SinkClient\SinkClientServiceProvider;
use ArtisanBuild\SinkContracts\Envelope;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Output\BufferedOutput;

function selectSinkMailer(array $overrides = []): void
{
    config(array_merge([
        'app.env' => 'testing',
        'mail.default' => 'sink',
        'mail.mailers.sink' => ['transport' => 'sink'],
        'sink-client.url' => 'https://sink.test',
        'sink-client.token' => 'secret',
        'sink-client.stream' => null,
        'sink-client.allow_production' => false,
        'sink-client.retry_attempts' => 3,
        'sink-client.retry_base_ms' => 1,
        'sink-client.timeout' => 1,
        'sink-client.max_message_bytes' => 10485760,
    ], $overrides));

    app()->detectEnvironment(fn (): string => (string) config('app.env'));
    app('mail.manager')->forgetMailers();
}

function sendSinkRaw(string $body = 'Hello from Sink.', string $subject = 'Captured subject'): void
{
    Mail::raw($body, function ($message) use ($subject): void {
        $message->from('sender@example.test');
        $message->to('recipient@example.test');
        $message->subject($subject);
    });
}

it('merges its package config through the service provider', function (): void {
    expect(SinkClient::CONFIG_KEY)->toBe('sink-client')
        ->and(config('sink-client.retry_attempts'))->toBe(3)
        ->and(config('mail.mailers.sink'))->toBe(['transport' => 'sink']);
});

it('registers the client provider in the test application', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(SinkClientServiceProvider::class);
});

it('captures outbound mail as a Sink envelope without delivering elsewhere', function (): void {
    selectSinkMailer();
    Http::fake(['https://sink.test/ingest' => Http::response(['id' => 'stored'], 202)]);

    sendSinkRaw();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $envelope = Envelope::fromArray($body);
        $rawMime = base64_decode($body['message'], true);

        expect($request->url())->toBe('https://sink.test/ingest')
            ->and($request->header('Authorization'))->toBe(['Bearer secret'])
            ->and($body['idempotency_key'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($body['sent_at'])->not->toBeEmpty()
            ->and($rawMime)->toContain('Captured subject')
            ->and($rawMime)->toContain('recipient@example.test')
            ->and($body['truncation'])->toBe('none')
            ->and($body['envelope_version'])->toBe(Envelope::VERSION)
            ->and($envelope->truncation->value)->toBe('none');

        return true;
    });
});

it('refuses production unless the production fuse is explicitly allowed', function (): void {
    selectSinkMailer(['app.env' => 'production']);
    Http::fake();

    expect(fn () => sendSinkRaw())->toThrow(SinkProductionFuse::class);

    Http::assertSentCount(0);
});

it('captures in production when the production fuse is explicitly allowed', function (): void {
    selectSinkMailer(['app.env' => 'production', 'sink-client.allow_production' => true]);
    Http::fake(['https://sink.test/ingest' => Http::response(status: 202)]);

    sendSinkRaw();

    Http::assertSentCount(1);
});

it('throws clearly when the sink mailer is selected without a URL', function (): void {
    selectSinkMailer(['sink-client.url' => '']);
    Http::fake();

    expect(fn () => sendSinkRaw())->toThrow(SinkNotConfigured::class);

    Http::assertSentCount(0);
});

it('throws clearly when the sink mailer is selected without a token', function (): void {
    selectSinkMailer(['sink-client.token' => '']);
    Http::fake();

    expect(fn () => sendSinkRaw())->toThrow(SinkNotConfigured::class);

    Http::assertSentCount(0);
});

it('retries failed ingest attempts and throws after exhaustion', function (): void {
    selectSinkMailer(['sink-client.retry_attempts' => 3]);
    Http::fake(['https://sink.test/ingest' => Http::response('Nope', 500)]);

    expect(fn () => sendSinkRaw())->toThrow(RequestException::class);

    Http::assertSentCount(3);
});

it('reuses the same idempotency key for transient retry attempts', function (): void {
    selectSinkMailer(['sink-client.retry_attempts' => 3]);
    $idempotencyKeys = [];
    $attempt = 0;

    Http::fake(function (Request $request) use (&$idempotencyKeys, &$attempt) {
        $attempt++;
        $idempotencyKeys[] = $request->data()['idempotency_key'];

        return Http::response(status: $attempt === 1 ? 500 : 202);
    });

    sendSinkRaw();

    expect($idempotencyKeys)->toHaveCount(2)
        ->and($idempotencyKeys[0])->toBe($idempotencyKeys[1]);
});

it('drops attachment bodies when raw MIME exceeds the configured limit', function (): void {
    selectSinkMailer(['sink-client.max_message_bytes' => 1500]);
    Http::fake(['https://sink.test/ingest' => Http::response(status: 202)]);

    Mail::send([], [], function ($message): void {
        $message->from('sender@example.test');
        $message->to('recipient@example.test');
        $message->subject('Attachment test');
        $message->text('Keep this body.');
        $message->attachData(str_repeat('ATTACHMENT-BYTES-', 200), 'large.txt', ['mime' => 'text/plain']);
    });

    Http::assertSent(function (Request $request): bool {
        $rawMime = base64_decode($request->data()['message'], true);

        expect($request->data()['truncation'])->toBe('attachments_dropped')
            ->and($rawMime)->toContain('Keep this body.')
            ->and($rawMime)->not->toContain('ATTACHMENT-BYTES');

        return true;
    });
});

it('falls back to a headers-only stub when the message is still too large after dropping attachments', function (): void {
    selectSinkMailer(['sink-client.max_message_bytes' => 300]);
    Http::fake(['https://sink.test/ingest' => Http::response(status: 202)]);

    Mail::send([], [], function ($message): void {
        $message->from('sender@example.test');
        $message->to('recipient@example.test');
        $message->subject('Headers only test');
        $message->text(str_repeat('body-still-too-large-', 80));
        $message->attachData(str_repeat('ATTACHMENT-BYTES-', 50), 'large.txt', ['mime' => 'text/plain']);
    });

    Http::assertSent(function (Request $request): bool {
        $rawMime = base64_decode($request->data()['message'], true);

        expect($request->data()['truncation'])->toBe('headers_only')
            ->and($rawMime)->toContain('Headers only test')
            ->and($rawMime)->not->toContain('body-still-too-large')
            ->and($rawMime)->not->toContain('ATTACHMENT-BYTES');

        return true;
    });
});

it('installs Sink settings into a host env file and pins the client constraint', function (): void {
    $host = sys_get_temp_dir().'/sink-client-install-'.bin2hex(random_bytes(5));
    mkdir($host, 0755, true);
    file_put_contents($host.'/.env', 'APP_NAME=Test'.PHP_EOL);
    file_put_contents($host.'/composer.json', json_encode(['require' => []], JSON_THROW_ON_ERROR));

    app()->setBasePath($host);
    app()->useEnvironmentPath($host);

    Artisan::call('sink:install', [
        '--url' => 'https://sink.test',
        '--token' => 'secret',
        '--no-interaction' => true,
    ]);

    $composer = json_decode((string) file_get_contents($host.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect(Artisan::output())->toContain('Set MAIL_MAILER=sink')
        ->and((string) file_get_contents($host.'/.env'))->toContain('SINK_URL=https://sink.test')
        ->and((string) file_get_contents($host.'/.env'))->toContain('SINK_TOKEN=secret')
        ->and($composer['require']['artisan-build/sink-client'])->toBe('^1');
});

it('reports compatible and incompatible server capability ranges', function (): void {
    selectSinkMailer();
    Http::fake(['https://sink.test/capabilities' => Http::sequence()
        ->push(['envelope' => ['min_major' => 1, 'max_major' => Envelope::VERSION]])
        ->push(['envelope' => ['min_major' => Envelope::VERSION + 1, 'max_major' => Envelope::VERSION + 2]])]);

    $compatibleOutput = new BufferedOutput;
    Artisan::call('sink:update', outputBuffer: $compatibleOutput);

    expect($compatibleOutput->fetch())->toContain('understands envelope v'.Envelope::VERSION);

    $incompatibleOutput = new BufferedOutput;
    Artisan::call('sink:update', outputBuffer: $incompatibleOutput);

    expect($incompatibleOutput->fetch())->toContain('expects envelope v'.(Envelope::VERSION + 1));
});
