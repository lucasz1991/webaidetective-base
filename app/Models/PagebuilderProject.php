<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class PagebuilderProject extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 
        'data', 
        'html', 
        'cleaned_html', 
        'js', 
        'css', 
        'last_edited_by', 
        'page', 
        'position', 
        'lang', 
        'lock', 
        'published_from', 
        'published_until', 
        'order_id', 
        'status',
        'type'
    ];

    protected $casts = [
        'page' => 'array', // Position als JSON-Array
        'position' => 'array', // Position als JSON-Array
        'lock' => 'boolean',   // Lock als Boolean speichern
    ];

    /**
     * Beziehung zum letzten Bearbeiter (User).
     */
    public function lastEditor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    /**
     * Überprüft, ob das Projekt veröffentlicht ist.
     */
    public function isPublished()
    {
        $now = Carbon::now();
        return $this->status === 1 && $this->published_from && Carbon::parse($this->published_from)->lte($now)
            && (!$this->published_until || Carbon::parse($this->published_until)->gte($now));
    }

    /**
     * Setzt das Projekt als veröffentlicht.
     */
    public function publish()
    {
        $this->update([
            'status' => 1,
            'published_from' => now(),
        ]);
    }

    /**
     * Setzt das Projekt auf Entwurf zurück.
     */
    public function unpublish()
    {
        $this->update([
            'status' => 0,
            'published_from' => null,
            'published_until' => null,
        ]);
    }

    /**
     * Gibt den HTML-Inhalt zurück.
     */
    public function getHtml()
    {
        return $this->html;
    }

    /**
     * Gibt den Css-Inhalt zurück.
     */
    public function getCss()
    {
        return $this->css;
    }

    /**
     * Gibt den Js-Inhalt zurück.
     */
    public function getJs()
    {
        return $this->js;
    }
}
