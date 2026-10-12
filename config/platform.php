<?php

return [

    /*
    | The first System Admin (platform Super Admin), created by `php artisan db:seed`.
    | Set these in .env, and change the password after the first sign-in.
    */
    'admin' => [
        'name' => env('SYSTEM_ADMIN_NAME', 'System Admin'),
        'email' => env('SYSTEM_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('SYSTEM_ADMIN_PASSWORD', 'password'),
    ],

    /*
    | Company registration uploads (trade licence and other documents).
    */
    'documents' => [
        'max_kb' => 5120,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'max_files' => 5,
    ],

];
