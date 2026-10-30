@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
@endpush

@push('scripts')
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
@endpush

<x-bfc-layout :title="$title ?? null">
    <x-slot:sidebar>
        {{-- The inbox's filters live beside it rather than above it, so the messages are what fills the page. --}}
        @if (request()->routeIs('bfc.dashboard', 'sink.inbox'))
            <livewire:sink-server::inbox-filters />
        @endif
    </x-slot:sidebar>

    <div data-testid="sink-content">
        {{ $slot ?? '' }}
        @yield('content')
    </div>
</x-bfc-layout>
