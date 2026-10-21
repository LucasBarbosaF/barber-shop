<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::where('email', 'lucass_br@hotmail.com')->first();
if (! $user) {
    echo "user not found\n";
    exit;
}

$user->password = Hash::make('Teste@12345');
$user->must_change_password = false;
$user->save();

echo 'password reset for '.$user->email.' (id '.$user->id.')'.PHP_EOL;
