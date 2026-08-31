@php
    $name = $sinkActingPrincipal->delegated
        ? $bfcConsoleChrome->operatorLabel()
        : data_get($sinkActingPrincipal->principal, 'name');
    $name = is_string($name) && $name !== '' ? $name : config('app.name', 'Laravel');
    $email = $sinkActingPrincipal->delegated
        ? null
        : data_get($sinkActingPrincipal->principal, 'email');
    $email = is_string($email) && $email !== '' ? $email : null;
    $initials = \Illuminate\Support\Str::of($name)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => \Illuminate\Support\Str::substr($word, 0, 1))
        ->implode('');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @if ($bfcConsoleChrome->delegated)
            @include('bfc::chrome', ['chrome' => $bfcConsoleChrome])
        @endif

        @include('layouts.app.sidebar', [
            'name' => $name,
            'email' => $email,
            'initials' => $initials,
        ])

        <flux:main>
            {{ $slot ?? '' }}
            @yield('content')
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
