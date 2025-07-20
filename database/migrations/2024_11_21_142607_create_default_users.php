<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class CreateDefaultUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Insert default users
        DB::table('users')->insert([
            [
            'id' => 1,
            'name' => 'Lucas Zacharias',
            'email' => 'lucas@zacharias-net.de',
            'email_verified_at' => now(),
            'password' => '$2y$12$tJNewrPc1YwBi5HbezRPfuAJtb3IgQBj/wbx.CcOmEgjaH/vywYnS',
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'current_team_id' => 1,
            'profile_photo_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
            'role' => 'admin',
            ],
            [
            'id' => 2,
            'name' => 'Testzugang-Teilnehmer',
            'email' => 'test-teilnehmer@example.com',
            'email_verified_at' => now(),
            'password' => '$2y$12$w791IJjpJA6w2JEyXOq0WO3qjIvYL81/JNfxE.EsuCiU6pcelRcSq',
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'current_team_id' => null,
            'profile_photo_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
            'role' => 'guest',
            ],
            [
            'id' => 3,
            'name' => 'Testzugang-Tutor',
            'email' => 'test-tutor@example.com',
            'email_verified_at' => now(),
            'password' => '$2y$12$w791IJjpJA6w2JEyXOq0WO3qjIvYL81/JNfxE.EsuCiU6pcelRcSq',
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'current_team_id' => null,
            'profile_photo_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
            'role' => 'tutor',
            ],
            [
            'id' => 4,
            'name' => 'Testzugang-Mitarbeiter',
            'email' => 'test-mitarbeiter@example.com',
            'email_verified_at' => now(),
            'password' => '$2y$12$w791IJjpJA6w2JEyXOq0WO3qjIvYL81/JNfxE.EsuCiU6pcelRcSq',
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'current_team_id' => null,
            'profile_photo_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
            'role' => 'staff',
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Delete the default users
        DB::table('users')->whereIn('email', [
            'lucas@zacharias-net.de',
        ])->delete();
    }
}
