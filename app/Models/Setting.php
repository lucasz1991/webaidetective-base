<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory;
    // Erlaubte Felder, die in der Datenbank gespeichert werden können
    protected $fillable = ['type', 'key', 'value'];

    // JSON-Daten als Array speichern und abrufen
    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Get the value of a setting by key.
     *
     * @param string $key
     * @return mixed
     */
    public static function getValue($type = null, $key)
    {
        $query = self::where('key', $key);
        
        if ($type !== null) {
            $query->where('type', $type);
        }
        
        $setting = $query->first();
        return $setting ? $setting->value : null;
    }

    /**
     * Set or update the value of a setting by key.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function setValue($key, $value)
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}

