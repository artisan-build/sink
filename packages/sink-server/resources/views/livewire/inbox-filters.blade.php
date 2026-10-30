<section class="grid gap-4" data-testid="inbox-filters">
    <flux:select size="sm" wire:model.live="app" :aria-label="__('Source app')" data-testid="inbox-filter-app">
        <flux:select.option value="">{{ __('All apps') }}</flux:select.option>
        @foreach ($apps as $name)
            <flux:select.option :value="$name">{{ $name }}</flux:select.option>
        @endforeach
    </flux:select>
    <flux:input size="sm" wire:model.live.debounce.300ms="recipient" :label="__('Recipient')" placeholder="person@example.com" />
    <flux:input size="sm" wire:model.live.debounce.300ms="subject" :label="__('Subject contains')" placeholder="Welcome" />

    <flux:input size="sm" wire:model.live="receivedFrom" :label="__('Received from')" type="date" />
    <flux:input size="sm" wire:model.live="receivedTo" :label="__('Received to')" type="date" />

    {{-- Clearing and purging only mean something once a filter is set; purge refuses an unscoped request. --}}
    @if ($filtered)
        <flux:button size="sm" variant="ghost" class="w-full" wire:click="clearFilters" data-testid="inbox-filters-clear">{{ __('Clear filters') }}</flux:button>

        <form method="POST" action="{{ route('sink.inbox.purge') }}" onsubmit="return confirm('{{ __('Purge all messages matching the current non-empty filters?') }}')">
            @csrf
            @method('DELETE')
            <input type="hidden" name="app" value="{{ $app }}">
            <input type="hidden" name="recipient" value="{{ $recipient }}">
            <input type="hidden" name="subject" value="{{ $subject }}">
            <input type="hidden" name="receivedFrom" value="{{ $receivedFrom }}">
            <input type="hidden" name="receivedTo" value="{{ $receivedTo }}">
            <flux:button type="submit" variant="danger" size="sm" class="w-full" data-testid="inbox-purge">{{ __('Purge filtered messages') }}</flux:button>
        </form>
    @endif
</section>
