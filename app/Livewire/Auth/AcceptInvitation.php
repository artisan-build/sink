<?php

namespace App\Livewire\Auth;

use App\Models\User;
use ArtisanBuild\BuiltForCloud\Exceptions\InvalidInvitation;
use ArtisanBuild\BuiltForCloud\Invitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Accept invitation')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $email = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public bool $validInvitation = false;

    public function mount(string $token): void
    {
        $this->token = $token;

        $invitation = Invitation::query()
            ->pending()
            ->where('token', $token)
            ->first();

        if (! $invitation instanceof Invitation) {
            return;
        }

        $this->validInvitation = true;
        $this->email = $invitation->email;
    }

    public function accept(): void
    {
        if (! $this->validInvitation) {
            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        try {
            $user = Invitation::accept($this->token, $validated);
        } catch (InvalidInvitation) {
            $this->validInvitation = false;

            return;
        }

        if ($user instanceof User) {
            Auth::login($user);
        }

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.accept-invitation')
            ->layout('layouts.auth');
    }
}
