<?php

namespace App\Livewire;

use Livewire\Component;

class AdminConfig extends Component
{
    public function render()
    {
        return view('livewire.admin-config')->layout('layouts.app');
    }
}
