<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::where('email', 'lucass_br@hotmail.com')->first();
if (! $user) {
    echo "user not found\n";
    exit;
}

$user->password = \Illuminate\Support\Facades\Hash::make('Teste@12345');
$user->must_change_password = false;
$user->save();

echo 'password reset for '.$user->email.' (id '.$user->id.')'.PHP_EOL;