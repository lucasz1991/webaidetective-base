<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class CustomVerifyEmail extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('E-Mail-Adresse bestätigen | '.config('app.name'))
            ->greeting('Willkommen bei SocialScope, '.$notifiable->name.'!')
            ->line('Bestätige jetzt deine E-Mail-Adresse, damit dein Konto vollständig aktiviert wird.')
            ->action('E-Mail-Adresse bestätigen', $this->verificationUrl($notifiable))
            ->line('Der Bestätigungslink ist 60 Minuten gültig.')
            ->line('Falls du kein SocialScope-Konto erstellt hast, kannst du diese Nachricht ignorieren.')
            ->salutation('Viele Grüße, dein SocialScope Team');
    }

    protected function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
