<?php

namespace App\Livewire\Auth;

use Livewire\Component;
use App\Models\User;
use App\Models\Person;


use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Login extends Component
{

    public $message;
    public $messageType;
    public $email = 'test-teilnehmer@example.com';
    public $password = '12345678910!LMZ';
    public $remember = false;



    protected $rules = [
        'email' => 'required|email|max:255',
        'password' => 'required|min:6|max:255',
    ];
    
    protected $messages = [
        'email.required' => 'Bitte gib deine E-Mail-Adresse ein.',
        'email.email' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
        'email.max' => 'Die E-Mail-Adresse darf maximal 255 Zeichen lang sein.',
        'email.exists' => 'Diese E-Mail-Adresse ist nicht registriert.',
        'password.required' => 'Bitte gib dein Passwort ein.',
        'password.min' => 'Das Passwort muss mindestens 6 Zeichen lang sein.',
        'password.max' => 'Das Passwort darf maximal 255 Zeichen lang sein.',
    ];

    public function login()
    {
        $this->validate();

        if (!Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            // Wenn die Authentifizierung fehlschlägt, Person-Tabelle überprüfen
            $person = Person::where('email_priv', $this->email)->first();

            if ($person) {
                // Prüfen, ob bereits ein User-Eintrag mit dieser E-Mail existiert, aber noch nicht aktiviert wurde
                $existingUser = User::where('email', $person->email_priv)
                                    ->whereNull('current_team_id')
                                    ->first();

                if ($existingUser) {
                    // Bestehender unvollständiger Benutzer – Hinweis zur E-Mail-Aktivierung
                    $existingUser->sendEmailVerificationNotification(); // falls erneut versendet werden soll
                    $this->dispatch(
                        'showAlert',
                        'Dein Konto wurde bereits erstellt, ist aber noch nicht aktiviert. Bitte prüfe deine E-Mails zur Aktivierung.',
                        'info'
                    );
                } else {
                    // Neuer Benutzer wird erstellt
                    $randomPassword = \Illuminate\Support\Str::random(12);
                    $newUser = User::create([
                        'name' => $person->vorname . ' ' . $person->nachname,
                        'email' => $person->email_priv,
                        'status' => 1,
                        'role' => 'guest',
                        'password' => bcrypt($randomPassword),
                    ]);

                    $newUser->sendEmailVerificationNotification();
                    $this->dispatch(
                        'showAlert',
                        'Dies war dein erster Login-Versuch. Dein Konto wurde erstellt. Bitte prüfe deine E-Mails, um dein Passwort zu setzen und dein Konto zu aktivieren.',
                        'info'
                    );
                }

            } else {
                throw ValidationException::withMessages([
                    'email' => 'Die eingegebene E-Mail-Adresse oder das Passwort ist falsch.',
                ]);
            }
        } else {
            $this->dispatch('showAlert', 'Willkommen zurück!', 'success');
            return redirect()->route('dashboard');
        }
    }


    public function mount()
    {
        // Überprüfen, ob eine Nachricht in der Session existiert
        if (session()->has('message')) {
            $this->message = session()->get('message');
            $this->messageType = session()->get('messageType', 'default'); 
            // Event zum Anzeigen der Nachricht dispatchen
            $this->dispatch('showAlert', $this->message, $this->messageType);
        }
    }


    public function render()
    {
        return view('livewire.auth.login')->layout("layouts/app");
    }
}
