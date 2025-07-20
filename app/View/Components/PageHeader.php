<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Illuminate\Support\Facades\Request;
use App\Models\WebPage;

class PageHeader extends Component
{
    public $page;
    public bool $isWebPage = false;
    public bool $showHeader = false;
    public $title;
    public $icon;
    public $header_image;

    /**
     * Create a new component instance.
     */
    public function __construct()
    {
        $segments = explode('/', Request::path());
            $this->page = end($segments);
            if ($this->page === '') {
                $this->page = 'start';
            } else {
                $lastSegment = end($segments);
                if (is_numeric($lastSegment) || strlen($lastSegment) > 25) {
                    $this->page = $segments[count($segments) - 2] ?? 'start';
                }
            }
        $webPage = WebPage::where('slug', $this->page)->first();
        $this->isWebPage = $webPage !== null;
        if ($webPage) {
            // Falls eine WebPage existiert, verwende deren Daten, ansonsten Standardwerte
            $this->showHeader = $webPage->settings['showHeader'];
            $this->title = $webPage->title;
            $this->icon = $webPage->icon;
            $this->header_image = $webPage->header_image;
        }
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.page-header');
    }
}
