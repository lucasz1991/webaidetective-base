<?php

namespace App\Jobs;

use App\Models\Mail;
use App\Models\User;
use App\Notifications\MailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProcessMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Mail $mail,
    ) {
    }

    public function handle(): void
    {
        $this->mail->createInternalMessages();
        $this->mail->refresh();

        $recipients = is_array($this->mail->recipients) ? $this->mail->recipients : [];
        $sendEmail = $this->mail->shouldSendEmail();

        foreach ($recipients as &$recipient) {
            try {
                $userId = (int) ($recipient['user_id'] ?? 0);
                $email = trim((string) ($recipient['email'] ?? ''));

                if ($userId > 0) {
                    $user = User::find($userId);

                    if (! $user) {
                        Log::warning("Benutzer mit ID {$userId} nicht gefunden.", [
                            'mail_id' => $this->mail->id,
                        ]);

                        continue;
                    }

                    if ($sendEmail && ! (bool) ($recipient['mail_status'] ?? false)) {
                        $user->notifyNow(new MailNotification($this->mail));
                        $recipient['mail_status'] = true;
                    }

                    $recipient['status'] = $this->mail->recipientIsComplete($recipient);
                    continue;
                }

                if ($sendEmail && filter_var($email, FILTER_VALIDATE_EMAIL) && ! (bool) ($recipient['mail_status'] ?? false)) {
                    Notification::route('mail', $email)->notifyNow(new MailNotification($this->mail));
                    $recipient['mail_status'] = true;
                    $recipient['status'] = $this->mail->recipientIsComplete($recipient);
                    continue;
                }

                if ($this->mail->recipientIsComplete($recipient)) {
                    $recipient['status'] = true;
                    continue;
                }

                Log::warning('Empfaenger konnte fuer den gewaehlten Mail-Typ nicht verarbeitet werden.', [
                    'mail_id' => $this->mail->id,
                    'recipient' => $recipient,
                    'type' => $this->mail->type,
                ]);
            } catch (\Throwable $exception) {
                Log::error('Fehler beim Verarbeiten der Mail.', [
                    'mail_id' => $this->mail->id,
                    'recipient' => $recipient,
                    'error' => $exception->getMessage(),
                ]);

                $recipient['status'] = false;
            }
        }
        unset($recipient);

        $this->mail->update([
            'recipients' => $recipients,
            'status' => $recipients !== [] && collect($recipients)->every(
                fn (array $recipient) => (bool) ($recipient['status'] ?? false),
            ),
        ]);
    }
}
