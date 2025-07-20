<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class MailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $content;

    /**
     * Create a new notification instance.
     *
     * @param array $content
     */
    public function __construct(array $content)
    {
        $this->content = $content;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mailMessage = (new MailMessage)
            ->subject($this->content['subject'])
            ->greeting($this->content['header'])
            ->line($this->content['body'])
            ->salutation('Mit freundlichen Grüßen,dein CBW Schulnetz Team'); 

        if (!empty($this->content['link'])) {
            $mailMessage->action('weiter', $this->content['link']);
        }

        return $mailMessage;
    }

}
