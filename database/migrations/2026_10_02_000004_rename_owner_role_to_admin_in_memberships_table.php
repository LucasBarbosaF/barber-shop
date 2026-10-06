<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Alinha o valor persistido da role com o enum MembershipRole: a role
     * `owner` passa a se chamar `admin` (Alinhamento com o README, que já
     * usava ADMIN). Alvos de dado, não de código.
     */
    public function up(): void
    {
        DB::table('memberships')
            ->where('role', 'owner')
            ->update(['role' => 'admin']);
    }

    public function down(): void
    {
        DB::table('memberships')
            ->where('role', 'admin')
            ->update(['role' => 'owner']);
    }
};
