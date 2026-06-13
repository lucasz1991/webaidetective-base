<?php

namespace Tests\Feature;

use App\Mail\TestMail;
use App\Models\User;
use App\Notifications\ContactFormSubmitted;
use App\Notifications\CustomResetPasswordNotification;
use App\Notifications\CustomVerifyEmail;
use App\Notifications\MailNotification;
use App\Notifications\SetPasswordNotification;
use Tests\TestCase;

class SocialScopeMailLayoutTest extends TestCase
{
    public function test_socialscope_markdown_mail_uses_the_new_layout(): void
    {
        config([
            'app.name' => 'SocialScope',
            'app.url' => 'https://socialscope.test',
        ]);

        $html = (new TestMail)->render();

        $this->assertStringContainsString('SocialScope Mailtest', $html);
        $this->assertStringContainsString('Digitale Spuren. Klar verbunden.', $html);
        $this->assertStringContainsString('background-color: #f1f5f9', $html);
        $this->assertStringNotContainsString('data:image', $html);
        $this->assertStringNotContainsString('scale-bounce', $html);
    }

    public function test_verification_notification_renders_with_socialscope_branding(): void
    {
        config([
            'app.name' => 'SocialScope',
            'app.url' => 'https://socialscope.test',
        ]);

        $user = new User([
            'name' => 'Erika Beispiel',
            'email' => 'erika@example.test',
        ]);
        $user->forceFill(['id' => 1]);

        $message = (new CustomVerifyEmail)->toMail($user);
        $html = $message->render();

        $this->assertSame('E-Mail-Adresse bestätigen | SocialScope', $message->subject);
        $this->assertStringContainsString('Willkommen bei SocialScope, Erika Beispiel!', $html);
        $this->assertStringContainsString('E-Mail-Adresse bestätigen', $html);
        $this->assertStringContainsString('dein SocialScope Team', $html);
    }

    public function test_all_custom_notifications_render_with_socialscope_branding(): void
    {
        config([
            'app.name' => 'SocialScope',
            'app.url' => 'https://socialscope.test',
            'mail.from.address' => 'system@socialscope.test',
            'mail.from.name' => 'SocialScope',
        ]);

        $user = new User([
            'name' => 'Erika Beispiel',
            'email' => 'erika@example.test',
        ]);
        $user->forceFill(['id' => 1]);

        $messages = [
            (new CustomResetPasswordNotification($user, 'reset-token'))->toMail($user),
            (new SetPasswordNotification($user, 'set-password-token'))->toMail($user),
            (new ContactFormSubmitted(
                'Max Beispiel',
                'max@example.test',
                'Frage zu SocialScope',
                'Das ist eine Testanfrage.',
            ))->toMail($user),
            (new MailNotification([
                'subject' => 'Neue Rechercheergebnisse',
                'header' => 'Dein Scan ist abgeschlossen',
                'body' => 'Die neuen Ergebnisse stehen jetzt bereit.',
                'link' => 'https://socialscope.test/dashboard',
            ]))->toMail($user),
        ];

        foreach ($messages as $message) {
            $html = $message->render();

            $this->assertStringContainsString('SocialScope', $html);
            $this->assertStringNotContainsString('CBW Schulnetz', $html);
            $this->assertStringNotContainsString('WebAIDetective', $html);
        }
    }
}
