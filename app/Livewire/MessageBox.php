<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;


class MessageBox extends Component
{
    use WithPagination;  

    public $selectedMessage;
    public $showMessageModal = false;
    public $loadedPages = 1;
    public $messageBoxStatus = null;
    public $messageBoxStatusLevel = 'success';

    protected $listeners = [
        'refreshComponent' => '$refresh',
    ];

    public function mount()
    {
        
        $this->dispatch('refreshComponent');
    }

    public function loadMore()
    {
        $this->loadedPages++;
    }

    public function showMessage($messageId)
    {
        $this->selectedMessage = auth()->user()->receivedMessages()->find($messageId);
        
        if ($this->selectedMessage) {
            $this->selectedMessage->update(['status' => 2]);
            $this->showMessageModal = true;
        }
        $this->dispatch('refreshComponent');
    }

    public function markAllAsRead(): void
    {
        auth()->user()->receivedMessages()
            ->where('status', '1')
            ->update(['status' => '2']);

        $this->setMessageBoxStatus('Alle Nachrichten wurden als gelesen markiert.', 'success');
        $this->dispatch('refreshComponent');
    }

    public function deleteAllMessages(): void
    {
        auth()->user()->receivedMessages()->delete();

        $this->selectedMessage = null;
        $this->showMessageModal = false;
        $this->loadedPages = 1;
        $this->resetPage();
        $this->setMessageBoxStatus('Alle Nachrichten wurden geloescht.', 'success');
        $this->dispatch('refreshComponent');
    }

    public function deleteMessage(int $messageId): void
    {
        $message = auth()->user()->receivedMessages()->find($messageId);

        if (! $message) {
            $this->setMessageBoxStatus('Die Nachricht wurde nicht gefunden.', 'error');

            return;
        }

        if ($this->selectedMessage && (int) $this->selectedMessage->id === $message->id) {
            $this->selectedMessage = null;
            $this->showMessageModal = false;
        }

        $message->delete();
        $this->setMessageBoxStatus('Nachricht wurde geloescht.', 'success');
        $this->dispatch('refreshComponent');
    }

    public function render()
    {
       
        $messages = auth()->user()->receivedMessages()
            ->orderBy('created_at', 'desc')
            ->paginate(12 * $this->loadedPages);  

        return view('livewire.message-box', compact('messages'))->layout("layouts/app");
    }

    private function setMessageBoxStatus(string $message, string $level = 'success'): void
    {
        $this->messageBoxStatus = $message;
        $this->messageBoxStatusLevel = $level;
    }
}
