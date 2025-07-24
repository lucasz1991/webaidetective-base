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

        // Stelle sicher, dass der Pfad zum Node-Skript korrekt ist
        if (!file_exists($nodeScript)) {
            $this->instagramHtml = 'Node-Skript nicht gefunden';
            return;
        }
        try {
            // Führe das Node-Skript aus und übergebe den Benutzernamen
            $output = shell_exec("node $nodeScript $escapedUser 2>&1");
        } catch (\Exception $e) {
            $this->instagramHtml = 'Fehler beim Ausführen des Skripts: ' . $e->getMessage();
            return;
        }
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
