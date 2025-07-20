<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class WebPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'meta_title', 'meta_description', 'meta_keywords',
        'canonical_url', 'robots_meta', 'og_title', 'og_description', 'og_image',
        'custom_css', 'custom_js', 'custom_meta', 'icon', 'header_image',
        'is_fixed', 'is_active', 'published_from', 'published_until', 'last_editor', 'language',
        'pagebuilder_project', 'settings'
    ];

    protected $casts = [
        'is_fixed' => 'boolean',
        'is_active' => 'boolean',
        'custom_meta' => 'array',
        'settings' => 'array',
        'published_from' => 'datetime',
        'published_until' => 'datetime',
    ];

    // Automatisch Slug setzen, wenn keiner vorhanden ist
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($page) {
            if (!$page->slug) {
                $page->slug = Str::slug($page->title);
            }
            if (auth()->check()) {
                $page->last_editor = auth()->id();
            }
        });
    }

    // Prüft, ob die Seite aktuell veröffentlicht ist
    public function isPublished()
    {
        $now = Carbon::now();
        return $this->is_active && (!$this->published_from || $this->published_from <= $now)
            && (!$this->published_until || $this->published_until >= $now);
    }

    // Beziehung zum letzten Bearbeiter (User)
    public function editor()
    {
        return $this->belongsTo(User::class, 'last_editor');
    }

    // Beziehung zum Pagebuilder-Projekt
    public function project()
    {
        return $this->belongsTo(PagebuilderProject::class, 'pagebuilder_project');
    }
}
