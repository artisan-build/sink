<nav aria-label="{{ __('Platform') }}" class="bfc-sidebar-links" data-testid="sink-navigation">
    <a href="{{ route('bfc.dashboard') }}" class="bfc-shell-link" @if (request()->routeIs('bfc.dashboard')) aria-current="page" @endif wire:navigate data-testid="sidebar-dashboard">
        {{ __('Dashboard') }}
    </a>
    <a href="{{ route('sink.inbox') }}" class="bfc-shell-link" @if (request()->routeIs('sink.*')) aria-current="page" @endif wire:navigate data-testid="sidebar-inbox">
        {{ __('Inbox') }}
    </a>

    @if (\ArtisanBuild\BuiltForCloud\RolePolicy::canManageMembers(data_get(auth()->user(), 'role')))
        <a href="{{ route('bfc.ui.home') }}" class="bfc-shell-link" @if (request()->routeIs('bfc.*')) aria-current="page" @endif wire:navigate data-testid="sidebar-members">
            {{ __('Members') }}
        </a>
    @endif

    <a href="https://github.com/laravel/livewire-starter-kit" class="bfc-shell-link" target="_blank" data-testid="sidebar-repository">
        {{ __('Repository') }}
    </a>
    <a href="https://laravel.com/docs/starter-kits#livewire" class="bfc-shell-link" target="_blank" data-testid="sidebar-documentation">
        {{ __('Documentation') }}
    </a>
    <a href="{{ route('bfc.ui.home') }}" class="bfc-shell-link" wire:navigate data-testid="sidebar-account">
        {{ __('Account') }}
    </a>
</nav>
