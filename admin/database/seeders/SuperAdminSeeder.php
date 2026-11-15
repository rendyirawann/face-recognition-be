<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Reset Cached Roles/Permissions (Wajib agar tidak error cache)
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Buat Role 'Superadmin' jika belum ada
        $role = Role::firstOrCreate(
            ['name' => 'Superadmin', 'guard_name' => 'web']
        );

        // 3. Buat User Superadmin
        // Di dalam file SuperAdminSeeder.php bagian create user
        // Dicocokkan lewat USERNAME, bukan email.
        //
        // Migrasi 0010 sudah membuat akun `superadmin` lebih dulu — ia
        // berjalan otomatis saat API start, jauh sebelum siapa pun
        // menjalankan db:seed. Mencocokkan lewat email berarti seeder ini
        // tidak menemukannya, lalu mencoba menyisipkan username yang sudah
        // ada: `users_username_unique` menolaknya dan seluruh perintah
        // db:seed berhenti dengan galat.
        //
        // Kata sandinya pun sengaja TIDAK ditimpa. Yang berlaku adalah
        // sandi dari migrasi (Superadmin#2026, wajib diganti saat login
        // pertama); menimpanya di sini akan mengembalikan sandi yang sudah
        // diganti operator setiap kali seeder dijalankan ulang.
        $user = User::firstOrCreate(
            ['username' => 'superadmin'],
            [
                'name'              => 'Super Administrator',
                'email'             => 'superadmin@gmail.com',
                'password'          => Hash::make('12qwaszx123!!@@##'),
                'no_wa'             => '08123456789',
                'email_verified_at' => now(),
                'is_active'         => true,
            ]
        );

        // 4. Assign Role Superadmin ke User tersebut
        if (!$user->hasRole('Superadmin')) {
            $user->assignRole($role);
        }

        $this->command->info('User Superadmin berhasil dibuat!');
        $this->command->info('Email: superadmin@gmail.com');
        $this->command->info('Pass : 12qwaszx123!!@@##');
    }
}
