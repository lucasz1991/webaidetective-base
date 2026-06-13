<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactFormSubmitted extends Notification
{
    public function __construct(
        public string $name,
        public string $email,
        public string $subject,
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Neue Kontaktanfrage | '.config('app.name'))
            ->replyTo($this->email, $this->name)
            ->greeting('Neue Kontaktanfrage')
            ->line('Über das SocialScope-Kontaktformular ist eine neue Nachricht eingegangen.')
            ->line('Name: '.$this->name)
            ->line('E-Mail: '.$this->email)
            ->line('Betreff: '.$this->subject)
            ->line('Nachricht: '.$this->message)
            ->line('Du kannst direkt auf diese E-Mail antworten, um die anfragende Person zu kontaktieren.')
            ->salutation('SocialScope Systembenachrichtigung');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
        ];
    }
}
