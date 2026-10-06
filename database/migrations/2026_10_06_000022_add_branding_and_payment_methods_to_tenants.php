<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('logo_path')->nullable();
            $table->jsonb('payment_methods')
                ->default(DB::raw("'[\"cash\",\"pix\",\"card\"]'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'payment_methods']);
        });
    }
};
