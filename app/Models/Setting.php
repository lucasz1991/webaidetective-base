<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

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
    public static function getValue($type, $key)
    {
        $query = self::where('key', $key);

        if ($type !== null) {
            $query->where('type', $type);
        }

        $setting = $query->first();
        return $setting ? $setting->value : null;
    }

    public static function getDecryptedValue(string $type, string $key): ?string
    {
        $value = self::getValue($type, $key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return trim(Crypt::decryptString($value));
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                "Die Einstellung {$type}.{$key} kann nicht entschluesselt werden.",
                previous: $exception,
            );
        }
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
