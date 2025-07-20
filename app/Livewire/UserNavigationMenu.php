<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Message;

class UserNavigationMenu extends Component
{

    
    public $currentUrl;
    public $receivedMessages;
    public $unreadMessagesCount;


    public $message;

    protected $listeners = ['refreshComponent' => '$refresh',];

    public function mount()
    {
        if (auth()->check()) {
            $this->receivedMessages = auth()->user()
                ->receivedMessages
                ->sort(function ($a, $b) {
                    // Priorität: Status (ungelesen zuerst)
                    if ($a->status !== $b->status) {
                        return $a->status <=> $b->status; // Ungelesene zuerst
                    }
                    // Zweite Priorität: Erstellungsdatum (neueste zuerst)
                    return $b->created_at <=> $a->created_at;
                })
                ->take(3);
            $this->unreadMessagesCount= auth()->user()->receivedUnreadMessages->count();

        }
        $this->currentUrl = url()->current();
    }

    public function setMessageStatus($messageId)
    {
        $this->message = auth()->user()->receivedMessages->firstWhere('id', $messageId);
        $this->message->update([
            'status' => 2, 
        ]);
        $this->message->save();

        $this->receivedMessages = auth()->user()
                ->receivedMessages
                ->sort(function ($a, $b) {
                    // Priorität: Status (ungelesen zuerst)
                    if ($a->status !== $b->status) {
                        return $a->status <=> $b->status; // Ungelesene zuerst
                    }
                    // Zweite Priorität: Erstellungsdatum (neueste zuerst)
                    return $b->created_at <=> $a->created_at;
                })
                ->take(3);
        $this->unreadMessagesCount= auth()->user()->receivedUnreadMessages->count();
        
        
    }

    public function render()
    {
        return view('livewire.user-navigation-menu');
    }
}
