<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use ZBateson\MailMimeParser\Message as MimeMessage;

final class MessageBodyController
{
    private const CSP = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox";

    public function __invoke(Message $message): Response
    {
        $raw = Storage::disk((string) config('sink-server.disk'))->get($message->raw_object_key);
        $parsed = MimeMessage::from($raw, false);
        $html = $parsed->getHtmlContent();

        if ($html === null || $html === '') {
            $text = e($parsed->getTextContent() ?? '');
            $html = '<!doctype html><html><head><meta charset="utf-8"></head><body><pre>'.$text.'</pre></body></html>';
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
