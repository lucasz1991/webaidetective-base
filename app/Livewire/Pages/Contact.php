<?php

namespace App\Livewire\Pages;

use Livewire\Component;
use Illuminate\Support\Facades\Mail;
use App\Notifications\ContactFormSubmitted;
use Illuminate\Support\Facades\Notification;

class Contact extends Component
{
    public $name;
    public $email;
    public $subject;
    public $message;

    public function send()
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-ZäöüÄÖÜß\s]+$/'],
            'email' => ['required', 'email', 'max:255', 'regex:/^[\w._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ], [
            'name.required' => 'Bitte gib deinen Namen ein.',
            'name.string' => 'Der Name muss aus Zeichen bestehen.',
            'name.max' => 'Der Name darf maximal 255 Zeichen lang sein.',
            'name.regex' => 'Der Name darf nur Buchstaben und Leerzeichen enthalten.',
        
            'email.required' => 'Bitte gib deine E-Mail-Adresse ein.',
            'email.email' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
            'email.max' => 'Die E-Mail-Adresse darf maximal 255 Zeichen lang sein.',
            'email.regex' => 'Bitte gib eine gültige E-Mail-Adresse im Format "name@domain.com" ein.',
        
            'subject.required' => 'Bitte gib einen Betreff ein.',
            'subject.string' => 'Der Betreff muss aus Zeichen bestehen.',
            'subject.max' => 'Der Betreff darf maximal 255 Zeichen lang sein.',
        
            'message.required' => 'Bitte gib deine Nachricht ein.',
            'message.string' => 'Die Nachricht muss aus Zeichen bestehen.',
        ]);
        try {
            $adminEmail = 'info@regulierungs-check.de';

            // Benachrichtigung an den Administrator senden
            Notification::route('mail', $adminEmail)
                ->notify(new ContactFormSubmitted($this->name, $this->email, $this->subject, $this->message));
                session()->flash('success', 'Vielen Dank für deine Nachricht! Wir haben sie erhalten und werden uns so schnell wie möglich bei dir melden.');

        } catch (\Swift_TransportException $e) {
            session()->flash('error', 'Die Bestätigungs-E-Mail konnte nicht gesendet werden. Bitte überprüfen Sie Ihre E-Mail-Adresse oder versuchen Sie es später erneut.');
        } catch (\Exception $e) {        
            session()->flash('error', 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.');
        }

        session()->flash('status', 'Deine Nachricht wurde erfolgreich gesendet!');
        $this->reset(['name', 'email', 'subject', 'message']);
    }

    public function render()
    {
        return view('livewire.pages.contact')
            ->layout('layouts.app'); // Falls du ein Standard-Layout verwendest
    }
}
