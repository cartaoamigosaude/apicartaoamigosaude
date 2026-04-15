<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run()
    {
        DB::table('users')->insert([
            'name' => 'sergiomsa',
            'email' => 'admin@cartaoamigosaude.com.br',
            'password' => Hash::make('P104310@p'),
            'perfil' => 'admin',
            'escopos' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}