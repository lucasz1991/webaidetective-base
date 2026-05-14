<?php

namespace App\Notifications;

use App\Models\Mail as MailModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

class MailNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        protected MailModel|array $mail,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content();
        $subject = $content['subject'] ?? 'Nachricht';
        $greeting = $content['header'] ?? null;
        $body = $content['body'] ?? '';
        $link = $content['link'] ?? null;

        $message = (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($subject);

        if ($greeting) {
            $message->greeting($greeting);
        }

        if ($body !== '') {
            $message->line($this->line($body));
        }

        if (! empty($link)) {
            $message->action('Weiter', $link);
        }

        return $message->salutation('Mit freundlichen Gruessen, dein WebAIDetective Team');
    }

    private function content(): array
    {
        if ($this->mail instanceof MailModel) {
            return is_array($this->mail->content) ? $this->mail->content : [];
        }

        return $this->mail;
    }

    private function line(string $body): string|HtmlString
    {
        if ($body !== strip_tags($body)) {
            return new HtmlString($body);
        }

        return $body;
    }
}
