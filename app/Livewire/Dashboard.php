<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;

class Dashboard extends Component
{
    use WithPagination;

    public $userData;

    protected $listeners = ['refreshParent' => '$refresh'];

    public function render()
    {
        $this->userData = Auth::user();



        return view('livewire.dashboard')->layout("layouts.app");
    }
}
