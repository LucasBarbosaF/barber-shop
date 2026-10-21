<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Integração é configuração de barbearia, e fica junto com o restante
         * (`payment_methods`, `logo_path`) em vez de virar tabela própria: o MVP
         * é um número por barbearia. `phone_number_id` é o que o webhook do Meta
         * envia em cada notificação — é a chave com que a mensagem acha o
         * tenant. `access_token` é opcional e existe por barbearia porque cada
         * número pode pertencer a uma conta business própria; sem ele o bot cai
         * no token global de `services.whatsapp.access_token`.
         */
        Schema::table('tenants', function (Blueprint $table): void {
            $table->jsonb('whatsapp_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_settings');
        });
    }
};
