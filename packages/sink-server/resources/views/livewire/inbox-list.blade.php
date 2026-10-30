<section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Inbox') }}</flux:heading>
            <flux:text>{{ __('Captured mail, newest first. Filter it from the sidebar.') }}</flux:text>
        </div>

        @if (session('status'))
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        @endif
    </div>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        <th class="px-3 py-2">{{ __('App') }}</th>
                        <th class="px-3 py-2">{{ __('Subject') }}</th>
                        <th class="px-3 py-2">{{ __('From') }}</th>
                        <th class="px-3 py-2">{{ __('Recipients') }}</th>
                        <th class="px-3 py-2">{{ __('Received') }}</th>
                        <th class="px-3 py-2">{{ __('Attachments') }}</th>
                        <th class="px-3 py-2">{{ __('Links') }}</th>
                        <th class="px-3 py-2">{{ __('Truncation') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($messages as $message)
                        <tr wire:key="sink-message-{{ $message->getKey() }}" class="align-top">
                            <td class="px-3 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $message->app }}</td>
                            <td class="px-3 py-3">
                                <a class="font-medium text-blue-600 hover:underline dark:text-blue-400" href="{{ route('sink.message', $message) }}" wire:navigate>
                                    {{ $message->subject ?: __('(No subject)') }}
                                </a>
                            </td>
                            <td class="px-3 py-3">{{ $message->from_address ?: __('Unknown') }}</td>
                            <td class="px-3 py-3">{{ $message->recipients_count }}</td>
                            <td class="px-3 py-3 whitespace-nowrap">{{ $message->received_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="px-3 py-3">{{ $message->attachment_count }}</td>
                            <td class="px-3 py-3">{{ $message->link_count }}</td>
                            <td class="px-3 py-3">{{ $message->truncation }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-8 text-center text-zinc-500">{{ __('No messages match these filters.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $messages->links() }}
        </div>
    </flux:card>
</section>
