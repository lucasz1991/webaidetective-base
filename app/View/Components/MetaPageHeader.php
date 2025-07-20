<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\Support\Facades\Request;
use App\Models\WebPage;

class MetaPageHeader extends Component
{
    public bool $isWebPage = false;
    public ?string $title = null;
    public ?string $metaTitle = null;
    public ?string $metaDescription = null;
    public ?string $metaKeywords = null;
    public ?string $canonicalUrl = null;
    public ?string $robotsMeta = null;
    public ?string $ogTitle = null;
    public ?string $ogDescription = null;
    public ?string $ogImage = null;
    public ?string $customCss = null;
    public ?string $customJs = null;
    public ?string $customMeta = null;

    public function __construct()
    {
        $currentSlug = trim(Request::path(), '/') ?: 'start';
        if (strlen($currentSlug) > 25 || is_numeric($currentSlug)) {
            $segments = explode('/', Request::path());
            $currentSlug = $segments[count($segments) - 2] ?? 'start';
        }
        // Versuchen, eine passende WebPage aus der Datenbank zu laden
        $webPage = WebPage::where('slug', $currentSlug)->first();

        // Prüfen, ob eine passende WebPage existiert
        $this->isWebPage = $webPage !== null;
        if ($webPage) {
            // Falls eine WebPage existiert, verwende deren Daten, ansonsten Standardwerte
            $this->title = $webPage->title ?? config('app.name');
            $this->metaTitle = $webPage->meta_title ?? $this->title;
            $this->metaDescription = $webPage->meta_description ?? '';
            $this->metaKeywords = $webPage->meta_keywords ?? '';
            $this->canonicalUrl = $webPage->canonical_url ?? url()->current();
            $this->robotsMeta = $webPage->robots_meta ?? 'noindex, nofollow';
            $this->ogTitle = $webPage->og_title ?? $this->metaTitle;
            $this->ogDescription = $webPage->og_description ?? $this->metaDescription;
            $this->ogImage = $webPage->og_image;
            $this->customCss = $webPage->custom_css ?? '';
            $this->customJs = $webPage->custom_js ?? '';
        }
    }

    public function render()
    {
        return view('components.meta-page-header');
    }
}
