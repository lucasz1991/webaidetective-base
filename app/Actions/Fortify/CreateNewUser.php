<?php

namespace App\Actions\Fortify;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => $this->passwordRules(),
            'role' => ['nullable', 'string'], // Optionale Rollen-Eingabe
            'team_id' => ['nullable', 'exists:teams,id'], // Optionale Team-ID-Eingabe
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['accepted', 'required'] : '',
        ])->validate();

        return DB::transaction(function () use ($input) {
            return tap(User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'role' => $this->determineRole(), // Setze die Rolle
            ]), function (User $user) use ($input) {
                $teamId = $this->determineTeamId();
                $user->current_team_id = $teamId; // Setze die current_team_id direkt
                $user->save(); // Speichere den aktualisierten Benutzer
                $this->assignTeam($user, $teamId); // Weise dem Team zu
            });
        });
    }

    /**
     * Assign the user to a team.
     *
     * @param  User  $user
     * @param  int  $teamId
     */
    protected function assignTeam(User $user, int $teamId): void
    {
        $team = Team::findOrFail($teamId);

        // Füge den Benutzer dem Team hinzu
        $user->teams()->attach($teamId, ['role' => $user->role]);

        // Aktualisiere das aktuelle Team
        $user->switchTeam($team);
    }

    /**
     * Determine the role of the user.
     *
     * @return string
     */
    protected function determineRole(): string
    {
        if ($this->isFirstUser()) {
            return 'admin'; // Erster Benutzer ist immer Admin
        }

        return 'guest'; // Alle weiteren Benutzer sind Gäste
    }

    /**
     * Determine the team ID to assign.
     *
     * @return int
     */
    protected function determineTeamId(): int
    {
        if ($this->isFirstUser()) {
            return 1; // Erster Benutzer gehört zum Super Admins-Team
        }

        return 4; // Standardteam: Benutzer
    }

    /**
     * Check if this is the first user being registered.
     *
     * @return bool
     */
    protected function isFirstUser(): bool
    {
        return User::count() === 0;
    }
}
