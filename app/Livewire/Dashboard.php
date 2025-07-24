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

    public function fetchInstagramWithNode($username = 'msdxrya')
    {
        $escapedUser = escapeshellarg($username);
        $nodeScript = base_path('resources/node/scraper/scrape-instagram.js');

        // Optional: Absoluter Pfad zu node, falls nötig (z. B. /usr/bin/node)
        $output = shell_exec("node $nodeScript $escapedUser");

        $this->instagramHtml = $output ?: 'Fehler beim Ausführen des Scrapers';
    }


    public function render()
    {
        $this->userData = Auth::user();



        return view('livewire.dashboard')->layout("layouts.app");
    }
}
