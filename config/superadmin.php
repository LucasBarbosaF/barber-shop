<?php

/*
|--------------------------------------------------------------------------
| Superadmin
|--------------------------------------------------------------------------
|
| Não existe registro público no sistema. O superadmin é o único usuário
| inicial e a única porta de entrada das barbearias. Ele é criado pelo
| seeder DatabaseSeeder; sem SUPERADMIN_PASSWORD, a senha é gerada
| aleatoriamente e exibida uma única vez no console.
|
*/

return [
    'name' => env('SUPERADMIN_NAME', 'Super Admin'),
    'email' => env('SUPERADMIN_EMAIL', 'superadmin@barber.test'),
    'password' => env('SUPERADMIN_PASSWORD'),
];
