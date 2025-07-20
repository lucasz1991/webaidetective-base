<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebContent extends Model
{
    use HasFactory;
        /**
     * Die Tabelle, die mit dem Modell verknüpft ist.
     *
     * @var string
     */
    protected $table = 'web_contents';

    /**
     * Die Attribute, die massenzuweisbar sind.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key', // Eindeutiger Schlüssel
        'value', // Der Inhalt
        'type', // Typ des Inhalts
    ];

    /**
     * Definiere standardmäßige Typen für Inhalte.
     */
    public static function contentTypes(): array
    {
        return [
            'text' => 'Einfacher Text',
            'html' => 'HTML-Inhalt',
            'json' => 'JSON-Daten',
            'markdown' => 'Markdown',
        ];
    }

    /**
     * Prüft, ob der Inhalt vom Typ JSON ist.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        return $this->type === 'json';
    }

    /**
     * Gibt den JSON-Inhalt als Array zurück, falls der Typ JSON ist.
     *
     * @return array|null
     */
    public function getJsonValue(): ?array
    {
        if ($this->isJson()) {
            return json_decode($this->value, true);
        }

        return null;
    }
}
