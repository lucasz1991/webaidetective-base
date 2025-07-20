<?php

namespace App\Http\Controllers\Api;

use App\Models\PagebuilderProject;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PagebuilderProjectController extends Controller
{

    /**
     * Speichert ein hochgeladenes Bild und gibt die URL mit fixer Base-URL zurück.
     */
    public function uploadImage(Request $request)
    {
        try {
            Log::info('Bild-Upload angefordert', ['user_id' => Auth::id(), 'file_name' => $request->file('file')->getClientOriginalName()]);

            $request->validate([
                'file' => 'required|image|max:2048', // Max. 2MB
            ]);

            $path = $request->file('file')->store('pagebuilder_images', 'public');
            $fullUrl = rtrim(config('app.url'), '/') . '/storage/' . str_replace('public/', '', $path); // Dynamische Base-URL basierend auf der aktuellen App-URL

            Log::info('Bild erfolgreich hochgeladen', ['path' => $path, 'url' => $fullUrl]);

            return response()->json(['url' => $fullUrl]);
        } catch (\Exception $e) {
            Log::error('Fehler beim Hochladen des Bildes', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json([
                'error' => 'Bild-Upload fehlgeschlagen',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Gibt eine Liste aller gespeicherten Bilder mit fixer Base-URL zurück.
     */
    public function getAssets()
    {
        try {
            Log::info('Abruf gespeicherter Bilder angefordert');

            $files = Storage::files("public/pagebuilder_images");

            $assets = collect($files)->map(function ($file) {
                return ['src' => config('app.url').'/storage/' . str_replace('public/', '', $file)];
            });

            Log::info('Bilder erfolgreich geladen', ['count' => count($assets)]);

            return response()->json($assets);
        } catch (\Exception $e) {
            Log::error('Fehler beim Abrufen der Bilder', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json([
                'error' => 'Fehler beim Laden der Bilder',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
