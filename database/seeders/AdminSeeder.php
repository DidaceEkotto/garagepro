<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * Crée le premier Super-Admin de la plateforme à partir de .env uniquement.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');
        $name = config('admin.name');

        if (empty($email) || empty($password) || empty($name)) {
            throw new \RuntimeException(
                'ADMIN_EMAIL, ADMIN_PASSWORD et ADMIN_NAME doivent être définis dans le fichier .env avant d\'exécuter AdminSeeder.'
            );
        }

        Admin::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'is_active' => true,
            ]
        );
    }
}
