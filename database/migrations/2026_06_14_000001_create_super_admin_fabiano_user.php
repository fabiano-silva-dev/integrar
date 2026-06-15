<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private const EMAIL = 'fabianosilvagestao@gmail.com';

    public function up(): void
    {
        $now = now();

        $exists = DB::table('users')->where('email', self::EMAIL)->exists();

        if ($exists) {
            DB::table('users')->where('email', self::EMAIL)->update([
                'name' => 'Fabiano Silva',
                'password' => Hash::make('Black1*Black1*'),
                'role' => 'super_admin',
                'empresa_operadora_id' => null,
                'email_verified_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('users')->insert([
            'name' => 'Fabiano Silva',
            'email' => self::EMAIL,
            'password' => Hash::make('Black1*Black1*'),
            'role' => 'super_admin',
            'empresa_operadora_id' => null,
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', self::EMAIL)->delete();
    }
};
