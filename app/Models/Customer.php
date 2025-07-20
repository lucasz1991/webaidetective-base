<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends User
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'username',
        'profile_picture',
        'phone_number',
        'street',
        'city',
        'state',
        'postal_code',
        'country'
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function rentals()
    {
        return $this->hasMany(ShelfRental::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function earnings()
    {
        // Summe der Verkaufserlöse berechnen
        $totalEarnings = $this->sales()->sum('sale_price'); // Verkaufserlöse summieren
    
        // Provisionsrate aus den Settings laden oder Standardwert von 16 % verwenden
        $provisionRate = 0.16; // Standardprovision
        $provisionSetting = Setting::where('key', 'provision')->first();
    
        if ($provisionSetting) {
            $provisionValue = json_decode($provisionSetting->value, true);
            if (isset($provisionValue['percentage'])) {
                $provisionRate = $provisionValue['percentage'] / 100;
            }
        }
    
        // Provision abziehen
        $netEarnings = $totalEarnings * (1 - $provisionRate);
    
        return $netEarnings;
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }

    public function isSeller()
    {
        return $this->rentals()->exists();
    }

 
}
