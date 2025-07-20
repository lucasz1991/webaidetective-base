<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Message;
use App\Models\Customer;
use App\Notifications\CustomVerifyEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Notifications\CustomResetPasswordNotification;
use App\Models\Course;



class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name', 'email', 'password','role', 'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function customer()
    {
        return $this->hasOne(Customer::class, 'user_id');
    }
    
    public function receivedMessages()
    {
        return  $this->hasMany(Message::class, 'to_user')->where('to_user', $this->id);
    }
    public function receivedUnreadMessages()
    {   
        $unreadmessages = $this->receivedMessages()->where('status',1);
        return $unreadmessages;
    }

        /**
     * Sende eine Nachricht an einen anderen Benutzer.
     *
     * @param int $toUserId
     * @param string $subject
     * @param string $message
     * @return void
     */
    public function sendMessage($toUserId, $subject, $message)
    {
        Message::create([
            'subject' => $subject,
            'message' => $message,
            'from_user' => $this->id, 
            'to_user' => $toUserId,
            'status' => '1',
        ]);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status;
    }

    public function courses()
    {
        return $this->hasMany(Course::class, 'tutor_id');
    }


    public function followers()
    {
        return $this->belongsToMany(
            User::class, // Der Typ der Benutzer, die folgen
            'customer_followers', // Pivot-Tabelle
            'customer_id', // Spalte in der Pivot-Tabelle, die den aktuellen Benutzer repräsentiert
            'follower_id' // Spalte in der Pivot-Tabelle, die die Follower repräsentiert
        )->withPivot('date')->withTimestamps(); // Zusätzliche Pivot-Daten
    }

    public function followedCustomers()
    {
        return $this->belongsToMany(
            User::class, // Der Typ der Benutzer, denen gefolgt wird
            'customer_followers', // Pivot-Tabelle
            'follower_id', // Spalte in der Pivot-Tabelle, die den aktuellen Benutzer als Follower repräsentiert
            'customer_id' // Spalte in der Pivot-Tabelle, die die Benutzer repräsentiert, denen gefolgt wird
        )->withPivot('date')->withTimestamps(); // Zusätzliche Pivot-Daten
    }

 
    
    public function sendEmailVerificationNotification()
    {
        try {
            // Überprüfung, ob die E-Mail-Adresse gültig ist (optional)
            if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("Ungültige E-Mail-Adresse: " . $this->email);
            }
    
            $this->notify(new CustomVerifyEmail);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            Log::error('Transport-Fehler beim Senden der E-Mail: ' . $e->getMessage());
            session()->flash('error', 'Die E-Mail konnte nicht zugestellt werden. Bitte überprüfen Sie Ihre E-Mail-Adresse.');
        } catch (\Symfony\Component\Mailer\Exception\UnexpectedResponseException $e) {
            Log::error('Unerwartete Antwort vom Mailserver: ' . $e->getMessage());
            session()->flash('error', 'Die E-Mail konnte nicht zugestellt werden. Bitte wenden Sie sich an den Support.');
        } catch (\Exception $e) {
            Log::error('Allgemeiner Fehler beim Senden der E-Mail: ' . $e->getMessage());
            session()->flash('error', 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.');
        }
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPasswordNotification($this, $token));
    }

    public function sendEmailShelfBookingConfirmationNotification()
    {
        try {
            // Überprüfung, ob die E-Mail-Adresse gültig ist (optional)
            if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("Ungültige E-Mail-Adresse: " . $this->email);
            }
    
            $this->notify(new CustomVerifyEmail);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            Log::error('Transport-Fehler beim Senden der E-Mail: ' . $e->getMessage());
            session()->flash('error', 'Die E-Mail konnte nicht zugestellt werden. Bitte überprüfen Sie Ihre E-Mail-Adresse.');
        } catch (\Symfony\Component\Mailer\Exception\UnexpectedResponseException $e) {
            Log::error('Unerwartete Antwort vom Mailserver: ' . $e->getMessage());
            session()->flash('error', 'Die E-Mail konnte nicht zugestellt werden. Bitte wenden Sie sich an den Support.');
        } catch (\Exception $e) {
            Log::error('Allgemeiner Fehler beim Senden der E-Mail: ' . $e->getMessage());
            session()->flash('error', 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.');
        }
    }

    
    public function hasAccessToInvoice($filename)
    {
        // Extrahiere die Benutzer-ID aus dem Dateinamen (z. B. "1_Doe_rental_bill_12345_date_2024_12_15.pdf")
        if (preg_match('/^(\d+)_/', $filename, $matches)) {
            $userIdFromFilename = $matches[1];

            // Prüfe, ob die Benutzer-ID mit der aktuellen Benutzer-ID übereinstimmt
            return $this->id == $userIdFromFilename;
        }

        return false; // Zugriff verweigern, wenn der Dateiname nicht das richtige Format hat
    }
}
