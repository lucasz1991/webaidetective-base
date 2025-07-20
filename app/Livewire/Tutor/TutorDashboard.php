<?php

namespace App\Livewire\Tutor;

use Livewire\Component;

class TutorDashboard extends Component
{
    public function render()
    {
        return view('livewire.tutor.tutor-dashboard')->layout("layouts.app-tutor");
    }
}
