<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Não existe registro público: o superadmin é a única porta de entrada
     * para as barbearias. Configure-o em config/superadmin.php.
     */
    public function run(): void
    {
        $this->createSuperadmin();
    }

    private function createSuperadmin(): void
    {
        $email = (string) config('superadmin.email');

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $password = config('superadmin.password');
        $generated = blank($password);

        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        User::create([
            'name' => (string) config('superadmin.name'),
            'email' => $email,
            'password' => Hash::make((string) $password),
            'email_verified_at' => now(),
            'is_superadmin' => true,
            'must_change_password' => false,
        ]);

        $this->command?->info("Superadmin criado: {$email}");

        if ($generated) {
            $this->command?->warn("Senha provisória: {$password}");
            $this->command?->warn('Anote agora: ela não será exibida novamente.');
        }
    }
}
