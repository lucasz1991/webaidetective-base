<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Proxy\TorProxyController;


class Dashboard extends Component
{
    use WithPagination;

    public $userData;

    protected $listeners = ['refreshParent' => '$refresh'];

    public $instagramHtml = null;

    public function fetchInstagramProfile($username = 'msdxrya')
    {
        $url = "https://www.instagram.com/$username/";

        $controller = new TorProxyController();
        $this->instagramHtml = $controller->fetchDirect($url);
    }

    public function render()
    {
        $this->userData = Auth::user();



        return view('livewire.dashboard')->layout("layouts.app");
    }
}
