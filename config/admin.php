<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Compte Super-Admin initial (utilisé uniquement par AdminSeeder)
    |--------------------------------------------------------------------------
    */
    'email' => env('ADMIN_EMAIL', 'admin@admin.test'),

    'password' => env('ADMIN_PASSWORD', '000000'),

    'name' => env('ADMIN_NAME', 'Super Admin'),
];
