<?php

namespace App\Livewire\User;

use Livewire\Component;

class Absences extends Component
{
    public function render()
    {
        return view('livewire.user.absences')->layout("layouts.app");
    }
}
