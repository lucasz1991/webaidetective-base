<?php

namespace App\Livewire;

use Livewire\Component;

class UserNavigationMenu extends Component
{
    public $currentUrl;

    public $receivedMessages;

    public $unreadMessagesCount;

    public $message;

    protected $listeners = [
        'refreshComponent' => 'refreshNavigationData',
        'refresh-user-navigation-menu' => 'refreshNavigationData',
    ];

    public function mount(): void
    {
        $this->refreshNavigationData();
    }

    public function refreshNavigationData(): void
    {
        $this->currentUrl = url()->current();

        if (! auth()->check()) {
            $this->receivedMessages = collect();
            $this->unreadMessagesCount = 0;

            return;
        }

        $user = auth()->user();

        $this->receivedMessages = $user
            ->receivedMessages()
            ->orderBy('status')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $this->unreadMessagesCount = $user
            ->receivedUnreadMessages()
            ->count();
    }

    public function setMessageStatus($messageId): void
    {
        $this->message = auth()->user()
            ->receivedMessages()
            ->find($messageId);

        if (! $this->message) {
            $this->refreshNavigationData();

            return;
        }

        $this->message->update([
            'status' => 2,
        ]);

        $this->refreshNavigationData();

        // Sound-Benachrichtigung abspielen
        $this->dispatch('playNotificationSound');
    }

    public function render()
    {
        return view('livewire.user-navigation-menu');
    }
}
