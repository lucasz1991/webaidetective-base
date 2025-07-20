<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use App\Notifications\CustomResetPasswordNotification;

use Livewire\Component;

class RequestPasswordResetLink extends Component
{
    public $email;

    public function sendResetLink()
    {
        $this->validate([
            'email' => 'required|email',
        ]);

        // Benutzer finden
        $user = \App\Models\User::where('email', $this->email)->first();

        if ($user) {
            // Benachrichtigung senden
            $token = Password::getRepository()->create($user);
            $user->notify(new CustomResetPasswordNotification( $user, $this->generateResetToken($user)));
            session()->flash('success', __('Ein Link zum Zurücksetzen deines Passworts wurde gesendet.'));
        } else {
            session()->flash('error', __('Wir konnten keinen Benutzer mit dieser E-Mail-Adresse finden.'));
        }
    }

    // Methode zum Generieren des Tokens
    protected function generateResetToken($user)
    {
        // Token generieren
        return Password::createToken($user);
    }

    public function render()
    {
        return view('livewire.auth.request-password-reset-link')->layout("layouts/app");
    }
}
