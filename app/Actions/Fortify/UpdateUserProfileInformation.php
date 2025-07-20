<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Http\UploadedFile;


class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:10240'],
        ])->validateWithBag('updateProfileInformation');
    
        if (isset($input['photo'])) {
            // Bild skalieren
            $photo = $input['photo'];
            $img = Image::read($photo->getRealPath()); // Bild laden
            // Berechne das Seitenverhältnis
            $aspectRatio = $img->width() / $img->height();

            // Berechne neue Dimensionen basierend auf der maximalen Größe
            $size = 800; // Maximale Größe für die Dimensionen
            $newWidth = $size;
            $newHeight = $size / $aspectRatio;

            // Wenn die neue Höhe größer ist als das maximale Limit, passe die Breite an
            if ($newHeight > $size) {
                $newHeight = $size;
                $newWidth = $size * $aspectRatio;
            }

            // Skaliere das Bild proportional
            $img->resize($newWidth, $newHeight);
    
                    // Speichern des Bildes in einer temporären Datei
            $tempPath = tempnam(sys_get_temp_dir(), 'profile_') . '.jpg';
            $img->save($tempPath);

            // Erstelle ein UploadedFile aus der temporären Datei
            $tempFile = new UploadedFile($tempPath, 'profile_photo.jpg', 'image/jpeg', null, true);

            // Profilfoto aktualisieren
            $user->updateProfilePhoto($tempFile);
        }
    
        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
