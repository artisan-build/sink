<section class="w-full max-w-5xl space-y-8">
    <div>
        <flux:heading size="xl">{{ __('Invitations') }}</flux:heading>
        <flux:subheading>{{ __('Invite humans into this Sink instance. Open registration is disabled.') }}</flux:subheading>
    </div>

    <flux:card class="space-y-6">
        <div>
            <flux:heading>{{ __('Create invitation') }}</flux:heading>
            <flux:subheading>{{ __('Send this link to the invited user so they can create their account.') }}</flux:subheading>
        </div>

        <form wire:submit="createInvitation" class="space-y-4">
            <flux:input wire:model="email" :label="__('Email address')" type="email" required placeholder="person@example.com" />

            <flux:button type="submit" variant="primary">{{ __('Create invitation') }}</flux:button>
        </form>

        @if ($invitationLink)
            <flux:callout variant="success" icon="check-circle" :heading="__('Invitation link')">
                <flux:input :value="$invitationLink" readonly />
            </flux:callout>
        @endif
    </flux:card>

    <flux:card class="space-y-4">
        <div>
            <flux:heading>{{ __('All invitations') }}</flux:heading>
            <flux:subheading>{{ __('Pending and accepted invitations are listed below.') }}</flux:subheading>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-3 py-2 text-start font-medium">{{ __('Email') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('Expires') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('Created') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($invitations as $invitation)
                        <tr wire:key="invitation-{{ $invitation->id }}">
                            <td class="px-3 py-3 text-zinc-900 dark:text-zinc-100">{{ $invitation->email }}</td>
                            <td class="px-3 py-3">
                                @if ($invitation->accepted_at)
                                    <flux:badge color="green">{{ __('Accepted') }}</flux:badge>
                                @elseif ($invitation->expires_at?->isPast())
                                    <flux:badge color="zinc">{{ __('Expired') }}</flux:badge>
                                @else
                                    <flux:badge color="amber">{{ __('Pending') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300">{{ $invitation->expires_at?->diffForHumans() ?? __('Never') }}</td>
                            <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300">{{ $invitation->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-6 text-center text-zinc-500 dark:text-zinc-400">
                                {{ __('No invitations yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</section>
