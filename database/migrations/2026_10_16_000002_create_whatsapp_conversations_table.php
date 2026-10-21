<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Estado da conversa do bot: em qual passo do menu o telefone está e o
         * que já foi escolhido (barbeiro, serviço, dia, horário). Uma linha por
         * telefone dentro da barbearia — `last_message_id` guarda o `wamid` da
         * última mensagem processada, que é como o retry do Meta (e o
         * reentregamento de fila) fica idempotente.
         */
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('step', 32);
            $table->jsonb('context')->nullable();
            $table->string('last_message_id', 128)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
