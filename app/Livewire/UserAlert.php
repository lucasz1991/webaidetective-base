<?php

namespace App\Livewire;

use Livewire\Component;

class UserAlert extends Component
{

    public $message = 'Standartnachricht';
    public $alertShow;
    public $type = 'info'; // Standardmäßig 'info'

    protected $listeners = ['showAlert' => 'displayAlert'];

    public function displayAlert($message, $type = 'info')
    {
        $this->message = $message;
        $this->type = $type;
        $this->alertShow = true;
    }

    public function mount()
    {
        $this->alertShow = false;
    }
    
    public function render()
    {
        return view('livewire.user-alert');
    }
}
