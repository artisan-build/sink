<?php

use App\Models\User;
use ArtisanBuild\SinkServer\Http\Livewire\InboxList;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageHeader;
use ArtisanBuild\SinkServer\Models\MessageLink;
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    if (! Schema::connection('sink')->hasTable('messages')) {
        Artisan::call('migrate', [
            '--database' => 'sink',
            '--path' => 'packages/sink-server/database/migrations',
            '--realpath' => true,
        ]);
    }

    Storage::fake((string) config('sink-server.disk'));
});

test('inbox routes are behind auth and available to authenticated users', function (): void {
    $message = sinkMessage(subject: 'Auth gated message');

    $this->get(route('sink.inbox'))->assertRedirect(route('login'));
    $this->get(route('sink.message', $message))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('sink.inbox'))
        ->assertOk()
        ->assertSee('Inbox');

    $this->actingAs(User::factory()->create())
        ->get(route('sink.message', $message))
        ->assertOk()
        ->assertSee('Auth gated message');
});

test('inbox list shows messages and reactive filters narrow results', function (): void {
    $alpha = sinkMessage(app: 'alpha', subject: 'Welcome Alpha', receivedAt: now()->subDays(2));
    sinkRecipient($alpha, 'to', 'alpha-recipient@example.com');

    $beta = sinkMessage(app: 'beta', subject: 'Reset Beta', receivedAt: now());
    sinkRecipient($beta, 'to', 'beta-recipient@example.com');

    Livewire::actingAs(User::factory()->create())
        ->test(InboxList::class)
        ->assertSee('Welcome Alpha')
        ->assertSee('Reset Beta')
        ->set('app', 'alpha')
        ->assertSee('Welcome Alpha')
        ->assertDontSee('Reset Beta')
        ->set('app', '')
        ->set('recipient', 'beta-recipient')
        ->assertDontSee('Welcome Alpha')
        ->assertSee('Reset Beta')
        ->set('recipient', '')
        ->set('subject', 'Welcome')
        ->assertSee('Welcome Alpha')
        ->assertDontSee('Reset Beta')
        ->set('subject', '')
        ->set('receivedFrom', now()->subDay()->toDateString())
        ->assertDontSee('Welcome Alpha')
        ->assertSee('Reset Beta')
        ->set('receivedFrom', '')
        ->set('receivedTo', now()->subDay()->toDateString())
        ->assertSee('Welcome Alpha')
        ->assertDontSee('Reset Beta');
});

test('message detail shows metadata and sandboxed iframe without scripts', function (): void {
    $message = sinkMessage(subject: 'Detailed message', attachmentCount: 1, linkCount: 1);
    sinkRecipient($message, 'to', 'to@example.com');
    sinkRecipient($message, 'cc', 'cc@example.com');
    sinkRecipient($message, 'bcc', 'bcc@example.com');
    MessageHeader::query()->create(['message_id' => $message->id, 'name' => 'X-Test', 'value' => 'Header value']);
    MessageLink::query()->create(['message_id' => $message->id, 'url' => 'https://example.com/link', 'label' => null]);
    MessageAttachment::query()->create(['message_id' => $message->id, 'filename' => 'invoice.pdf', 'mime' => 'application/pdf', 'size_bytes' => 7, 'object_key' => 'attachments/invoice.pdf']);

    $response = $this->actingAs(User::factory()->create())->get(route('sink.message', $message));

    $response->assertOk()
        ->assertSee('Detailed message')
        ->assertSee('to@example.com')
        ->assertSee('cc@example.com')
        ->assertSee('bcc@example.com')
        ->assertSee('X-Test')
        ->assertSee('https://example.com/link')
        ->assertSee('invoice.pdf')
        ->assertSee('sandbox=""', false)
        ->assertDontSee('allow-scripts', false);
});

test('body route serves stored html with strict csp and no script grant', function (): void {
    $message = sinkMessage(raw: htmlMime('<html><body><h1>Email body text</h1><script>alert(1)</script></body></html>'));

    $response = $this->actingAs(User::factory()->create())->get(route('sink.message.body', $message));

    $response->assertOk()
        ->assertSee('Email body text', false);

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'")
        ->toContain('sandbox')
        ->not->toContain('script-src');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('attachment download returns bytes with sanitized filename and scopes to message', function (): void {
    $message = sinkMessage();
    $other = sinkMessage(subject: 'Other');
    $attachment = MessageAttachment::query()->create([
        'message_id' => $message->id,
        'filename' => '../unsafe.txt',
        'mime' => 'text/plain',
        'size_bytes' => 16,
        'object_key' => 'attachments/unsafe.txt',
    ]);
    Storage::disk((string) config('sink-server.disk'))->put('attachments/unsafe.txt', 'attachment bytes');

    $response = $this->actingAs(User::factory()->create())->get(route('sink.message.attachment', [$message, $attachment]));

    $response->assertOk();
    expect($response->streamedContent())->toBe('attachment bytes')
        ->and($response->headers->get('Content-Disposition'))->toContain('unsafe.txt')
        ->and($response->headers->get('Content-Disposition'))->not->toContain('../');

    $this->actingAs(User::factory()->create())
        ->get(route('sink.message.attachment', [$other, $attachment]))
        ->assertNotFound();
});

test('scoped purge deletes message rows and blobs and refuses unauthorized or unscoped purges', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $message = sinkMessage(subject: 'Delete me', rawObjectKey: 'raw/delete-me.eml');
    sinkRecipient($message, 'to', 'delete@example.com');
    $attachment = MessageAttachment::query()->create([
        'message_id' => $message->id,
        'filename' => 'delete.txt',
        'mime' => 'text/plain',
        'size_bytes' => 6,
        'object_key' => 'attachments/delete.txt',
    ]);
    Storage::disk((string) config('sink-server.disk'))->put($message->raw_object_key, 'raw bytes');
    Storage::disk((string) config('sink-server.disk'))->put($attachment->object_key, 'attach');

    $this->delete(route('sink.message.destroy', $message))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->delete(route('sink.message.destroy', $message))->assertForbidden();
    $this->actingAs($admin)->delete(route('sink.inbox.purge'))->assertStatus(422);

    $this->actingAs($admin)->delete(route('sink.message.destroy', $message))->assertRedirect(route('sink.inbox'));

    expect(Message::query()->whereKey($message->id)->exists())->toBeFalse()
        ->and(MessageRecipient::query()->where('message_id', $message->id)->exists())->toBeFalse();

    Storage::disk((string) config('sink-server.disk'))->assertMissing('raw/delete-me.eml');
    Storage::disk((string) config('sink-server.disk'))->assertMissing('attachments/delete.txt');
});

test('body text remains storage backed and is not written into database columns', function (): void {
    sinkMessage(raw: htmlMime('<html><body>Secret UI-only body phrase</body></html>'));

    $persisted = [
        ...Message::query()->get()->flatMap(fn (Message $message): array => scalarInboxValues($message->getAttributes()))->all(),
        ...MessageRecipient::query()->get()->flatMap(fn (MessageRecipient $recipient): array => scalarInboxValues($recipient->getAttributes()))->all(),
        ...MessageHeader::query()->get()->flatMap(fn (MessageHeader $header): array => scalarInboxValues($header->getAttributes()))->all(),
        ...MessageLink::query()->get()->flatMap(fn (MessageLink $link): array => scalarInboxValues($link->getAttributes()))->all(),
        ...MessageAttachment::query()->get()->flatMap(fn (MessageAttachment $attachment): array => scalarInboxValues($attachment->getAttributes()))->all(),
    ];

    expect(implode(' ', $persisted))->not->toContain('Secret UI-only body phrase');
});

function sinkMessage(
    string $app = 'fallback',
    string $subject = 'Sink message',
    ?DateTimeInterface $receivedAt = null,
    int $attachmentCount = 0,
    int $linkCount = 0,
    ?string $raw = null,
    ?string $rawObjectKey = null,
): Message {
    $idempotencyKey = (string) Str::ulid();
    $rawObjectKey ??= 'raw/'.$app.'/'.$idempotencyKey.'.eml';

    $message = Message::query()->create([
        'idempotency_key' => $idempotencyKey,
        'app' => $app,
        'stream' => null,
        'subject' => $subject,
        'from_address' => 'sender@example.com',
        'from_name' => 'Sender',
        'message_id' => '<'.$idempotencyKey.'@example.test>',
        'sent_at' => now()->subMinute(),
        'received_at' => $receivedAt ?? now(),
        'size_bytes' => strlen($raw ?? plainMime()),
        'attachment_count' => $attachmentCount,
        'link_count' => $linkCount,
        'truncation' => 'none',
        'raw_object_key' => $rawObjectKey,
        'parsed_at' => now(),
    ]);

    Storage::disk((string) config('sink-server.disk'))->put($rawObjectKey, $raw ?? plainMime());

    return $message;
}

function sinkRecipient(Message $message, string $kind, string $address): MessageRecipient
{
    return MessageRecipient::query()->create([
        'message_id' => $message->id,
        'kind' => $kind,
        'address' => $address,
        'name' => ucfirst($kind).' Person',
    ]);
}

function plainMime(): string
{
    return "From: Sender <sender@example.com>\r\nTo: To <to@example.com>\r\nSubject: Sink message\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nPlain body.";
}

function htmlMime(string $html): string
{
    return "From: Sender <sender@example.com>\r\nTo: To <to@example.com>\r\nSubject: HTML message\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}";
}

function scalarInboxValues(array $attributes): array
{
    return array_values(array_filter($attributes, is_scalar(...)));
}
