<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Register extends Component
{
    public $email, $password, $password_confirmation;
    public $first_name, $last_name, $username, $phone_number, $street, $city, $state, $postal_code, $country ,$terms;

    

    public function register()
    {
        // Validierung
        $this->validate(
            [
                'email' => 'required|email|unique:users,email',
                'password' => [
                    'required',
                    'min:10', 
                    'regex:/[A-Z]/',
                    'regex:/[\W]/', 
                    'confirmed'
                ],
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'username' => ['required', 'string', 'max:255', Rule::unique('customers', 'username')],
                'phone_number' => 'nullable|string|max:15',
                'street' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'state' => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:255',
                'terms' => 'required',
            ],
            [
                'email.required' => 'Die E-Mail-Adresse ist erforderlich.',
                'email.email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
                'email.unique' => 'Diese E-Mail-Adresse wird bereits verwendet.',
        
                'password.required' => 'Das Passwort ist erforderlich.',
                'password.min' => 'Das Passwort muss mindestens 10 Zeichen lang sein.',
                'password.regex' => 'Das Passwort muss mindestens einen Großbuchstaben und ein Sonderzeichen enthalten.',
                'password.confirmed' => 'Die Passwort-Bestätigung stimmt nicht überein.',
        
                'first_name.required' => 'Der Vorname ist erforderlich.',
                'first_name.string' => 'Der Vorname muss eine Zeichenkette sein.',
                'first_name.max' => 'Der Vorname darf maximal 255 Zeichen lang sein.',
        
                'last_name.required' => 'Der Nachname ist erforderlich.',
                'last_name.string' => 'Der Nachname muss eine Zeichenkette sein.',
                'last_name.max' => 'Der Nachname darf maximal 255 Zeichen lang sein.',
        
                'username.required' => 'Der Benutzername ist erforderlich.',
                'username.string' => 'Der Benutzername muss eine Zeichenkette sein.',
                'username.max' => 'Der Benutzername darf maximal 255 Zeichen lang sein.',
                'username.unique' => 'Dieser Benutzername wird bereits verwendet.',
        
                'phone_number.string' => 'Die Telefonnummer muss eine Zeichenkette sein.',
                'phone_number.max' => 'Die Telefonnummer darf maximal 15 Zeichen lang sein.',
        
                'street.string' => 'Die Straße muss eine Zeichenkette sein.',
                'street.max' => 'Die Straße darf maximal 255 Zeichen lang sein.',
        
                'city.string' => 'Die Stadt muss eine Zeichenkette sein.',
                'city.max' => 'Die Stadt darf maximal 255 Zeichen lang sein.',
        
                'state.string' => 'Das Bundesland muss eine Zeichenkette sein.',
                'state.max' => 'Das Bundesland darf maximal 255 Zeichen lang sein.',
        
                'postal_code.string' => 'Die Postleitzahl muss eine Zeichenkette sein.',
                'postal_code.max' => 'Die Postleitzahl darf maximal 20 Zeichen lang sein.',
        
                'country.string' => 'Das Land muss eine Zeichenkette sein.',
                'country.max' => 'Das Land darf maximal 255 Zeichen lang sein.',
        
                'terms.required' => 'Sie müssen den AGBs und der Datenschutzerklärung zustimmen.',
            ]
        );

        // User erstellen
        $user = User::create([
            'name' => $this->username,
            'email' => $this->email,
            'password' => Hash::make($this->password),
            'current_team_id' => 4,
            'role' => 'guest', 
        ]);

        // Customer erstellen
        Customer::create([
            'user_id' => $user->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'username' => $this->username,
            'phone_number' => $this->phone_number,
            'street' => $this->street,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
        ]);

        // Verifizierungs-E-Mail senden
        $user->sendEmailVerificationNotification();

        // User automatisch einloggen
        Auth::login($user);

        // Erfolgsmeldung und Weiterleitung
        $this->dispatch('showAlert', 'Registrierung erfolgreich! Bitte überprüfen Sie Ihre E-Mail, um Ihr Konto zu verifizieren.', 'success');
        session()->flash('message', 'Registrierung erfolgreich! Bitte überprüfen Sie Ihre E-Mail, um Ihr Konto zu verifizieren.');
        return redirect('dashboard');
    }


    public function render()
    {
        return view('livewire.auth.register')->layout("layouts/app");
    }
}
