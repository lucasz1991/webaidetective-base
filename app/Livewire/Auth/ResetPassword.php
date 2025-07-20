<?php

namespace App\Livewire\Auth;

use App\Actions\Fortify\ResetUserPassword; // Importiere Fortify's ResetUserPassword Aktion
use Livewire\Component;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use App\Notifications\CustomResetPasswordNotification;

class ResetPassword extends Component
{
    public $token;
    public $email;
    public $password;
    public $password_confirmation;

    public function mount($token)
    {
        $this->token = $token;
        $this->email = request()->query('email'); // E-Mail aus der URL abrufen
        
        // Token validieren, indem wir den entsprechenden Fortify-Mechanismus verwenden
        $this->validateToken();
    }

    private function validateToken()
    {
          // Überprüfen, ob der Reset-Link und Token korrekt sind
          $user = \App\Models\User::where('email', $this->email)->first();

          if (!$user || !Password::getRepository()->exists($user, $this->token)) {
              session()->flash('error', 'Der Link zum Zurücksetzen des Passworts ist ungültig oder abgelaufen.');
              return redirect()->route('password.request');
          }
    }

    public function resetPassword()
    {
        // Eingaben validieren
        $this->validate([
            'email' => 'required|email',
            'password' => [
                    'required',
                    'min:10', 
                    'regex:/[A-Z]/',
                    'regex:/[\W]/', 
                    'confirmed'
                ],
        ], [
            'email.required' => 'Bitte gib deine E-Mail-Adresse ein.',
            'email.email' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
            'password.required' => 'Das Passwort ist erforderlich.',
            'password.min' => 'Das Passwort muss mindestens 10 Zeichen lang sein.',
            'password.regex' => 'Das Passwort muss mindestens einen Großbuchstaben und ein Sonderzeichen enthalten.',
            'password.confirmed' => 'Die Passwort-Bestätigung stimmt nicht überein.',
        ]);
    
        // Benutzer abfragen
        $user = User::where('email', $this->email)->first();
    
        if (!$user) {
            session()->flash('error', 'Kein Benutzer mit dieser E-Mail-Adresse gefunden.');
            return;
        }
    
        try {
            // Passwort mit Fortify's Logik zurücksetzen
            app(ResetUserPassword::class)->reset(
                $user, // Benutzer
                [
                    'password' => $this->password, // Neues Passwort
                    'password_confirmation' => $this->password_confirmation, // Passwortbestätigung
                ]
            );
    
            session()->flash('status', 'Dein Passwort wurde erfolgreich zurückgesetzt!');
            return redirect()->route('login'); // Weiterleitung zur Login-Seite
    
        } catch (\Exception $e) {
            // Fehlerbehandlung, falls das Zurücksetzen des Passworts fehlschlägt
            session()->flash('error', 'Es gab ein Problem beim Zurücksetzen des Passworts. Bitte versuche es erneut.');
        }
    }

    public function render()
    {
        return view('livewire.auth.reset-password')->layout("layouts.app");
    }
}
