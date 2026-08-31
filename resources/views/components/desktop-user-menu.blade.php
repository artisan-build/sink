@props([
    'name' => null,
    'email' => null,
    'initials' => null,
])

@php
    $name ??= data_get(auth()->user(), 'name', config('app.name', 'Laravel'));
    $email ??= data_get(auth()->user(), 'email');
    $initials ??= \Illuminate\Support\Str::of((string) $name)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => \Illuminate\Support\Str::substr($word, 0, 1))
        ->implode('');
@endphp

<flux:dropdown position="bottom" align="start">
    <flux:sidebar.profile
        :$name
        :$initials
        icon:trailing="chevrons-up-down"
        data-test="sidebar-menu-button"
    />

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :$name
                :$initials
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ $name }}</flux:heading>
                @if ($email !== null)
                    <flux:text class="truncate">{{ $email }}</flux:text>
                @endif
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('Settings') }}
            </flux:menu.item>
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
