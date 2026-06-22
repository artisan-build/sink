<div class="flex flex-col gap-6">
    @if ($validInvitation)
        <x-auth-header :title="__('Accept your invitation')" :description="__('Create your Sink account for :email', ['email' => $email])" />

        <form wire:submit="accept" class="flex flex-col gap-6">
            <flux:input wire:model="email" :label="__('Email address')" type="email" readonly disabled />

            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Create account') }}
            </flux:button>
        </form>
    @else
        <x-auth-header :title="__('Invitation invalid or expired')" :description="__('Ask an administrator to send you a new invitation.')" />

        <flux:button :href="route('login')" variant="primary" class="w-full" wire:navigate>
            {{ __('Return to login') }}
        </flux:button>
    @endif
</div>
