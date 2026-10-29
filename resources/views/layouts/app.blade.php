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
        @include('partials.app-navigation')
    </x-slot:sidebar>

    <div data-testid="sink-content">
        {{ $slot ?? '' }}
        @yield('content')
    </div>
</x-bfc-layout>
