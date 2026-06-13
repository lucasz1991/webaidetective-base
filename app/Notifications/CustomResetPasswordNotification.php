<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class CustomResetPasswordNotification extends Notification
{
    public function __construct(
        protected object $user,
        protected string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Passwort zurücksetzen | '.config('app.name'))
            ->greeting('Hallo '.$this->user->name.'!')
            ->line('Für dein SocialScope-Konto wurde das Zurücksetzen des Passworts angefordert.')
            ->action('Neues Passwort festlegen', $this->resetUrl($notifiable, $minutes))
            ->line("Dieser Link ist {$minutes} Minuten gültig.")
            ->line('Falls du das nicht angefordert hast, musst du nichts weiter tun.')
            ->salutation('Viele Grüße, dein SocialScope Team');
    }

    protected function resetUrl(object $notifiable, int $minutes): string
    {
        return URL::temporarySignedRoute(
            'password.reset',
            Carbon::now()->addMinutes($minutes),
            ['token' => $this->token, 'email' => $notifiable->email],
        );
    }
}
