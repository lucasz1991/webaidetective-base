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
        $recipients = is_array($this->mail->recipients) ? $this->mail->recipients : [];
        $content = is_array($this->mail->content) ? $this->mail->content : [];
        $type = $this->resolveDeliveryType($this->mail->type ?? null);

        $sendMessage = in_array($type, ['message', 'both'], true);
        $sendEmail = in_array($type, ['mail', 'email', 'both'], true);

        foreach ($recipients as &$recipient) {
            try {
                $recipient['status'] = false;

                $userId = (int) ($recipient['user_id'] ?? 0);
                $email = trim((string) ($recipient['email'] ?? ''));
                $processed = false;

                if ($userId > 0) {
                    $user = User::find($userId);

                    if (! $user) {
                        Log::warning("Benutzer mit ID {$userId} nicht gefunden.", [
                            'mail_id' => $this->mail->id,
                        ]);

                        continue;
                    }

                    if ($sendMessage) {
                        $user->receiveMessage(
                            $content['subject'] ?? 'Nachricht',
                            $content['body'] ?? '',
                            $this->resolveSenderId($this->mail->from_user_id, $user),
                        );

                        $processed = true;
                    }

                    if ($sendEmail) {
                        $user->notify(new MailNotification($this->mail));
                        $processed = true;
                    }

                    $recipient['status'] = $processed;
                    continue;
                }

                if ($sendEmail && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Notification::route('mail', $email)->notify(new MailNotification($this->mail));
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

    private function resolveDeliveryType(mixed $type): string
    {
        if (is_bool($type)) {
            return $type ? 'both' : 'message';
        }

        $normalized = strtolower(trim((string) $type));

        return $normalized !== '' ? $normalized : 'message';
    }

    private function resolveSenderId(?int $senderId, User $recipient): int
    {
        if ($senderId && User::whereKey($senderId)->exists()) {
            return $senderId;
        }

        if (User::whereKey(1)->exists()) {
            return 1;
        }

        return $recipient->id;
    }
}
