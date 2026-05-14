<?php

namespace App\Models;

use App\Jobs\ProcessMailJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Mail extends Model
{
    protected $fillable = [
        'type',
        'from_user_id',
        'status',
        'content',
        'recipients',
    ];

    protected $casts = [
        'content' => 'json',
        'recipients' => 'json',
        'status' => 'boolean',
    ];

    protected $attributes = [
        'type' => 'message',
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function (Mail $mail) {
            $mail->createInternalMessages();

            ProcessMailJob::dispatch($mail->fresh());
        });
    }

    public function createInternalMessages(): void
    {
        if (! $this->shouldSendInternalMessage()) {
            return;
        }

        $recipients = is_array($this->recipients) ? $this->recipients : [];
        $content = is_array($this->content) ? $this->content : [];
        $changed = false;

        foreach ($recipients as &$recipient) {
            if ((bool) ($recipient['message_status'] ?? false)) {
                continue;
            }

            $userId = (int) ($recipient['user_id'] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $user = User::find($userId);

            if (! $user) {
                Log::warning("Benutzer mit ID {$userId} fuer interne Nachricht nicht gefunden.", [
                    'mail_id' => $this->id,
                ]);

                continue;
            }

            try {
                $user->receiveMessage(
                    $content['subject'] ?? 'Nachricht',
                    $content['body'] ?? '',
                    $this->resolveSenderId($user),
                );

                $recipient['message_status'] = true;
                $changed = true;
            } catch (\Throwable $exception) {
                Log::error('Interne Nachricht konnte nicht erstellt werden.', [
                    'mail_id' => $this->id,
                    'recipient' => $recipient,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
        unset($recipient);

        if (! $changed) {
            return;
        }

        foreach ($recipients as &$recipient) {
            $recipient['status'] = $this->recipientIsComplete($recipient);
        }
        unset($recipient);

        $this->recipients = $recipients;
        $this->status = $recipients !== [] && collect($recipients)->every(
            fn (array $recipient) => (bool) ($recipient['status'] ?? false),
        );
        $this->saveQuietly();
    }

    public function deliveryType(): string
    {
        if (is_bool($this->type)) {
            return $this->type ? 'both' : 'message';
        }

        $type = strtolower(trim((string) $this->type));

        if (in_array($type, ['1', 'true'], true)) {
            return 'both';
        }

        if (in_array($type, ['0', 'false'], true)) {
            return 'message';
        }

        return in_array($type, ['message', 'mail', 'email', 'both'], true) ? $type : 'message';
    }

    public function shouldSendInternalMessage(): bool
    {
        return in_array($this->deliveryType(), ['message', 'both'], true);
    }

    public function shouldSendEmail(): bool
    {
        return in_array($this->deliveryType(), ['mail', 'email', 'both'], true);
    }

    public function recipientIsComplete(array $recipient): bool
    {
        $hasInternalRecipient = (int) ($recipient['user_id'] ?? 0) > 0;

        if (! $hasInternalRecipient && $this->shouldSendInternalMessage() && ! $this->shouldSendEmail()) {
            return false;
        }

        $messageComplete = ! $this->shouldSendInternalMessage()
            || ! $hasInternalRecipient
            || (bool) ($recipient['message_status'] ?? false);
        $mailComplete = ! $this->shouldSendEmail() || (bool) ($recipient['mail_status'] ?? false);

        return $messageComplete && $mailComplete;
    }

    private function resolveSenderId(User $recipient): int
    {
        $senderId = (int) $this->from_user_id;

        if ($senderId > 0 && User::whereKey($senderId)->exists()) {
            return $senderId;
        }

        if (User::whereKey(1)->exists()) {
            return 1;
        }

        return $recipient->id;
    }
}
