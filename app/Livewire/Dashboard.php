<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Proxy\TorProxyController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;


class Dashboard extends Component
{
    use WithPagination;

    public $userData;

    protected $listeners = ['refreshParent' => '$refresh'];

    public $instagramHtml = null;
    public $instagramScreenshot = null;

    public function fetchInstagramWithNode($username = 'msdxrya')
    {
        $escapedUser = $username;
        $nodeScript = base_path('resources/node/scraper/scrape-instagram.cjs');
        $nodeBin = '/usr/bin/node';

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
        if ($output === null || $output === '') {
        $this->instagramHtml = 'Fehler: Kein Output vom Node-Skript';
        return;
    }

    // Zeile 1 = Pfad; Rest = HTML
    $parts = preg_split("/\r\n|\n|\r/", $output, 2);
    $firstLine = trim($parts[0] ?? '');
    $html = $parts[1] ?? '';

    // Beispiel erste Zeile:
    // "Screenshot gespeichert unter: C:\xampp\htdocs\...\profile-screenshot-123.png"
    $prefix = 'Screenshot gespeichert unter:';
    if (!Str::startsWith($firstLine, $prefix)) {
        $this->instagramHtml = 'Fehler: Unerwartetes Output-Format (erste Zeile)';
        return;
    }

    $absolutePath = trim(Str::after($firstLine, $prefix));

    // Windows-Backslashes normalisieren
    $absolutePath = str_replace('\\', DIRECTORY_SEPARATOR, $absolutePath);

    if (!File::exists($absolutePath)) {
        $this->instagramHtml = 'Fehler: Screenshot-Datei nicht gefunden: '.$absolutePath;
        return;
    }

    // Versuche relativen Pfad ggü. storage/app zu bestimmen
    $storageApp = storage_path('app').DIRECTORY_SEPARATOR;
    $isInsideStorageApp = Str::startsWith($absolutePath, $storageApp);

    // Zielname auf public-Disk
    $targetName = 'screenshots/instagram/'.$username.'/'.basename($absolutePath);

    try {
        if ($isInsideStorageApp) {
            // Datei von storage/app/... nach storage/app/public/... kopieren
            $contents = File::get($absolutePath);
            Storage::disk('public')->put($targetName, $contents);
        } else {
            // Notfall: liegt außerhalb – trotzdem in public-Disk kopieren
            $contents = File::get($absolutePath);
            Storage::disk('public')->put($targetName, $contents);
        }
    } catch (\Throwable $e) {
        $this->instagramHtml = 'Fehler beim Kopieren des Screenshots: '.$e->getMessage();
        return;
    }

    // Öffentliche URL (erfordert: php artisan storage:link)
    $this->instagramScreenshot = url($targetName);
    $this->instagramHtml = $html ?: '— (Kein HTML im Output gefunden) —';
    }


    public function copyInstagramHtml()
    {
        if ($this->instagramHtml) {
            // Kopiere den HTML-Inhalt in die Zwischenablage
            $this->dispatchBrowserEvent('copyToClipboard', ['text' => $this->instagramHtml]);
        }
    }
    
    public function render()
    {
        $this->userData = Auth::user();



        return view('livewire.dashboard')->layout("layouts.app");
    }
}
