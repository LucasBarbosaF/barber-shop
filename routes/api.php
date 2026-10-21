<?php

use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\SetWhatsAppWebhookTenant;
use App\Http\Middleware\VerifyWhatsAppSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * Webhook do WhatsApp Cloud API.
 *
 * `api` em vez de `web` de propósito: não há sessão nem CSRF num callback do
 * Meta — o que existe é a assinatura HMAC, conferida pelo
 * `VerifyWhatsAppSignature` antes de qualquer outra coisa. A ordem dos dois
 * middlewares importa: sem assinatura válida não há por que resolver tenant.
 */
Route::prefix('webhooks/whatsapp')->group(function (): void {
    Route::get('/', [WhatsAppWebhookController::class, 'verify']);

    Route::post('/', [WhatsAppWebhookController::class, 'receive'])
        ->middleware([VerifyWhatsAppSignature::class, SetWhatsAppWebhookTenant::class]);
});
