<?php

namespace App\Livewire\Admin;

use ArtisanBuild\BuiltForCloud\Invitation;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Invitations')]
class Invitations extends Component
{
    public string $email = '';

    public ?string $invitationLink = null;

    public function createInvitation(): void
    {
        $validated = $this->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
        ]);

        $invitation = Invitation::invite($validated['email'], invitedBy: (string) Auth::id());

        $this->reset('email');
        $this->invitationLink = route('register.invitation', $invitation->token);

        Flux::toast(variant: 'success', text: __('Invitation created.'));
    }

    public function render(): View
    {
        return view('livewire.admin.invitations', [
            'invitations' => $this->invitations(),
        ]);
    }

    /**
     * @return Collection<int, Invitation>
     */
    private function invitations(): Collection
    {
        return Invitation::query()
            ->latest()
            ->get();
    }
}
