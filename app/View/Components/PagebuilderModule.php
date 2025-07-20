<?php

namespace App\View\Components;

use Illuminate\View\Component;
use App\Models\PagebuilderProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

class PagebuilderModule extends Component
{
    public $page;
    public $position;
    public $modules;

    public function __construct($page = null, $position = 'page')    
    {
        if(!$page){
            $segments = explode('/', Request::path());
                $page = end($segments);
                if ($page === '') {
                    $page = 'start';
                } else {
                    $lastSegment = end($segments);
                    if (is_numeric($lastSegment) || strlen($lastSegment) > 25) {
                        $page = $segments[count($segments) - 2] ?? 'start';
                    }
                }
        }
        $this->page = $page;

        $this->position = $position;

        // Aktuelles Datum/Zeit für die Prüfung
        $now = Carbon::now();

        // Cache-Schlüssel generieren
        $cacheKey = "pagebuilder_modules_{$page}_{$position}_" . app()->getLocale();

        $this->modules = Cache::remember($cacheKey, 60, function () use ($page, $position, $now) {
            return PagebuilderProject::where(function ($query) use ($page) {
                        $query->whereJsonContains('page', $page) 
                              ->orWhereJsonContains('page', 'all'); // Sucht nach "all" innerhalb des JSON-Arrays
                    })
                    ->whereJsonContains('position', $position)
                    ->whereIn('status', [1, 3])
                    ->where(function ($query) use ($now) {
                        $query->whereNull('published_from')->orWhere('published_from', '<=', $now);
                    })
                    ->where(function ($query) use ($now) {
                        $query->whereNull('published_until')->orWhere('published_until', '>=', $now);
                    })
                    ->where(function ($query) {
                        $query->where('lang', app()->getLocale()) // Prüft auf die aktuelle Sprache
                            ->orWhereNull('lang') // Optional: Erlaubt Module ohne Sprache
                            ->orWhere('lang', '');
                    })
                    ->orderBy('order_id', 'asc') // Falls du eine Reihenfolge hast
                    ->get();
        });
    }

    /**
     * Gibt die Blade-View zurück.
     */
    public function render()
    {
        return view('components.pagebuilder-module');
    }
}
