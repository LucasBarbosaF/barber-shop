<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'bot_user_default_channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | WhatsApp Cloud API (Meta)
    |
    | `app_secret` confirma a assinatura do webhook, `verify_token` responde ao
    | desafio de configuração do app e `access_token` é o token global de
    | envio — quando a barbearia tem token próprio em
    | `tenants.whatsapp_settings.access_token`, ele tem prioridade.
    */
    'whatsapp' => [
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    /*
    | Asaas (gateway de pagamento)
    |
    | Integração futura da cobrança recorrente das assinaturas de recorrência:
    | as mensalidades nascerão `pending` em `recurrence_subscription_payments`
    | com `gateway`/`gateway_payment_id` preenchidos e serão confirmadas pelo
    | webhook (`webhook_token`) — até lá a recepção registra os pagamentos
    | manualmente e o mês só libera agendamento quando fica `paid`.
    */
    'asaas' => [
        'base_url' => env('ASAAS_BASE_URL', 'https://api.asaas.com/v3'),
        'api_key' => env('ASAAS_API_KEY'),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
        'sandbox' => env('ASAAS_SANDBOX', true),
    ],

];
