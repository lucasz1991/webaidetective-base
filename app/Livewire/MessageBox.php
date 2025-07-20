<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Message;
use Livewire\WithPagination;


class MessageBox extends Component
{
    use WithPagination;  

    public $selectedMessage;
    public $showMessageModal = false;
    public $loadedPages = 1;

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

    public function render()
    {
       
        $messages = auth()->user()->receivedMessages()
            ->orderBy('created_at', 'desc')
            ->paginate(12 * $this->loadedPages);  

        return view('livewire.message-box', compact('messages'))->layout("layouts/app");
    }
}
