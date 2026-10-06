<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => '/app',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => true,
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => 'passkeys',
    ],
    'redirects' => [
        'login' => '/app',
        'logout' => '/',
        'password-reset' => '/login',
        'email-verification' => '/app',
        'password-confirmation' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fortify Features
    |--------------------------------------------------------------------------
    |
    | Features::registration() está DESATIVADO de propósito: o produto é
    | fechado, não existe auto-cadastro público. As barbearias e seus
    | responsáveis são cadastrados exclusivamente pelo superadmin em
    | /admin/barbearias. O único usuário inicial é o superadmin, criado
    | pelo seeder DatabaseSeeder.
    |
    */

    'features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
    ],
];
