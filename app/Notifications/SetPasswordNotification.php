<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class SetPasswordNotification extends Notification
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
            ->subject('Dein SocialScope-Konto einrichten')
            ->greeting('Willkommen bei SocialScope, '.$this->user->name.'!')
            ->line('Lege jetzt ein Passwort fest, um dein Konto zu vervollständigen.')
            ->action('Passwort festlegen', $this->resetUrl($notifiable, $minutes))
            ->line("Der Link ist {$minutes} Minuten gültig.")
            ->line('Ist der Link bereits abgelaufen, kannst du auf der Login-Seite jederzeit einen neuen anfordern.')
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

    protected function newRequestUrl(): string
    {
        return URL::route('password.request');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $notifiable->getKey(),
            'token' => $this->token,
        ];
    }
}
