<section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:button size="sm" variant="ghost" :href="route('sink.inbox')" wire:navigate>{{ __('Back to inbox') }}</flux:button>
            <flux:heading size="xl" class="mt-3">{{ $message->subject ?: __('(No subject)') }}</flux:heading>
            <flux:text>{{ $message->from_name ? $message->from_name.' <'.$message->from_address.'>' : ($message->from_address ?: __('Unknown sender')) }}</flux:text>
        </div>

        @if (auth()->user()?->is_admin)
            <form method="POST" action="{{ route('sink.message.destroy', $message) }}" onsubmit="return confirm('{{ __('Delete this message and its stored blobs?') }}')">
                @csrf
                @method('DELETE')
                <flux:button type="submit" variant="danger">{{ __('Delete message') }}</flux:button>
            </form>
        @endif
    </div>

    <flux:card>
        <dl class="grid gap-4 md:grid-cols-3">
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('App') }}</dt><dd class="font-medium">{{ $message->app }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Message ID') }}</dt><dd class="break-all font-medium">{{ $message->message_id ?: __('None') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Truncation') }}</dt><dd class="font-medium">{{ $message->truncation }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Sent') }}</dt><dd>{{ $message->sent_at?->format('Y-m-d H:i:s') ?: __('Unknown') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Received') }}</dt><dd>{{ $message->received_at?->format('Y-m-d H:i:s') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Size') }}</dt><dd>{{ number_format((int) $message->size_bytes) }} {{ __('bytes') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Attachments') }}</dt><dd>{{ $message->attachment_count }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Links') }}</dt><dd>{{ $message->link_count }}</dd></div>
        </dl>
    </flux:card>

    <flux:card class="space-y-4">
        <flux:heading>{{ __('Recipients') }}</flux:heading>
        <div class="grid gap-4 md:grid-cols-3">
            @foreach (['to', 'cc', 'bcc'] as $kind)
                <div wire:key="recipients-{{ $kind }}">
                    <div class="mb-2 text-xs font-medium uppercase text-zinc-500">{{ strtoupper($kind) }}</div>
                    <div class="space-y-1">
                        @forelse (($recipientsByKind[$kind] ?? collect()) as $recipient)
                            <div wire:key="recipient-{{ $recipient->getKey() }}" class="break-all text-sm">
                                {{ $recipient->name ? $recipient->name.' <'.$recipient->address.'>' : $recipient->address }}
                            </div>
                        @empty
                            <flux:text>{{ __('None') }}</flux:text>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </flux:card>

    <flux:card class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <flux:heading>{{ __('Body') }}</flux:heading>
            <flux:button size="sm" variant="ghost" :href="route('sink.message.raw', $message)" target="_blank">{{ __('View raw source') }}</flux:button>
        </div>
        <iframe class="h-[36rem] w-full rounded-lg border border-zinc-200 bg-white dark:border-zinc-700" sandbox="" src="{{ route('sink.message.body', $message) }}" title="{{ __('Sandboxed message body') }}"></iframe>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading>{{ __('Headers') }}</flux:heading>
            <div class="max-h-96 overflow-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($message->headers as $header)
                            <tr wire:key="header-{{ $header->getKey() }}" class="align-top">
                                <th class="w-48 px-3 py-2 text-left font-medium">{{ $header->name }}</th>
                                <td class="px-3 py-2 break-all">{{ $header->value }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-4 text-zinc-500">{{ __('No headers parsed.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading>{{ __('Links') }}</flux:heading>
            <div class="space-y-2">
                @forelse ($message->links as $link)
                    <div wire:key="link-{{ $link->getKey() }}" class="break-all rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">{{ $link->url }}</div>
                @empty
                    <flux:text>{{ __('No links parsed.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>
    </div>

    <flux:card class="space-y-3">
        <flux:heading>{{ __('Attachments') }}</flux:heading>
        <div class="space-y-2">
            @forelse ($message->attachments as $attachment)
                <div wire:key="attachment-{{ $attachment->getKey() }}" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="font-medium">{{ $attachment->filename }}</div>
                        <div class="text-zinc-500">{{ $attachment->mime }} · {{ number_format((int) $attachment->size_bytes) }} {{ __('bytes') }}</div>
                    </div>
                    <flux:button size="sm" :href="route('sink.message.attachment', [$message, $attachment])">{{ __('Download') }}</flux:button>
                </div>
            @empty
                <flux:text>{{ __('No attachments.') }}</flux:text>
            @endforelse
        </div>
    </flux:card>
</section>
