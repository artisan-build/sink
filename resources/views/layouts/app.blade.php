@php
    $user = auth()->user();
    $name = data_get($user, 'name', config('app.name', 'Sink'));
    $email = data_get($user, 'email');
    $initials = \Illuminate\Support\Str::of((string) $name)
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
