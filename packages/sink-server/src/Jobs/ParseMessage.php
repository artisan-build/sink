<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Jobs;

use ArtisanBuild\SinkServer\Models\Message as SinkMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Message as MimeMessage;
use ZBateson\MailMimeParser\Message\IMessagePart;

final class ParseMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $messageId)
    {
        $this->connection = config('sink-server.queue');
    }

    public function handle(): void
    {
        /** @var SinkMessage $message */
        $message = SinkMessage::query()->with(['recipients', 'headers', 'links', 'attachments'])->findOrFail($this->messageId);
        $disk = Storage::disk((string) config('sink-server.disk'));
        $raw = $disk->get($message->raw_object_key);
        $parsed = MimeMessage::from($raw, false);

        $message->recipients()->delete();
        $message->headers()->delete();
        $message->links()->delete();
        $message->attachments()->delete();

        $from = $parsed->getHeader('From');
        $fromAddress = $from instanceof AddressHeader ? $from->getEmail() : null;
        $fromName = $from instanceof AddressHeader ? $from->getPersonName() : null;

        $message->forceFill([
            'subject' => $parsed->getHeaderValue('Subject'),
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'message_id' => $parsed->getHeaderValue('Message-ID'),
        ])->save();

        foreach (['to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc'] as $kind => $headerName) {
            $this->storeRecipients($message, $kind, $parsed->getHeader($headerName));
        }

        foreach ($parsed->getAllHeaders() as $header) {
            $message->headers()->create([
                'name' => $header->getName(),
                'value' => $header->getRawValue(),
            ]);
        }

        $links = $this->extractLinks($parsed->getTextContent() ?? '', $parsed->getHtmlContent() ?? '');

        foreach ($links as $url) {
            $message->links()->create([
                'url' => $url,
                'label' => null,
            ]);
        }

        $attachmentCount = 0;

        foreach ($parsed->getAllAttachmentParts() as $index => $part) {
            if (! $part instanceof IMessagePart) {
                continue;
            }

            $bytes = (string) ($part->getBinaryContentStream()?->getContents() ?? '');
            $filename = $this->filename($part, $index + 1);
            $objectKey = "attachments/{$message->app}/{$message->idempotency_key}/".($index + 1).'-'.$filename;

            $disk->put($objectKey, $bytes);

            $message->attachments()->create([
                'filename' => $filename,
                'mime' => $part->getContentType('application/octet-stream') ?? 'application/octet-stream',
                'size_bytes' => strlen($bytes),
                'object_key' => $objectKey,
            ]);

            $attachmentCount++;
        }

        $message->forceFill([
            'attachment_count' => $attachmentCount,
            'link_count' => count($links),
            'parsed_at' => now(),
        ])->save();
    }

    private function storeRecipients(SinkMessage $message, string $kind, mixed $header): void
    {
        if (! $header instanceof AddressHeader) {
            return;
        }

        foreach ($header->getAddresses() as $address) {
            $message->recipients()->create([
                'kind' => $kind,
                'address' => $address->getEmail(),
                'name' => $address->getName() === '' ? null : $address->getName(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function extractLinks(string $text, string $html): array
    {
        preg_match_all('~https?://[^\s<>"]+~i', $text."\n".$html, $matches);

        $urls = [];

        foreach ($matches[0] ?? [] as $url) {
            $normalized = rtrim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5), '.,);]\'"');

            if ($normalized !== '') {
                $urls[$normalized] = $normalized;
            }
        }

        return array_values($urls);
    }

    private function filename(IMessagePart $part, int $index): string
    {
        $filename = $part->getFilename() ?: "attachment-{$index}";
        $filename = Str::of($filename)->replace(['/', '\\'], '-')->trim()->toString();

        return $filename === '' ? "attachment-{$index}" : $filename;
    }
}
