<?php

namespace App\Application\Services\Integrations\Assas;

use App\Models\Customer;
use App\Models\RecurrencePlan;
use App\Models\RecurrenceSubscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class LinkPagamentoService
{
    public static function gerarLinkPagamento(RecurrencePlan $plan, RecurrenceSubscription $subscription): RecurrenceSubscription
    {
        $response = Http::withHeaders([
            'access_token' => env('ASAAS_API_KEY'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Barbear Shop',
        ])->post(env('ASAAS_BASE_URL') . '/paymentLinks', [
            'name' => $plan->name,
            'endDate' => $subscription->ends_at->format('Y-m-d'),
            'value' => $plan->price,
            'billingType' => 'CREDIT_CARD',
            'chargeType' => 'RECURRENT',
            'subscriptionCycle' => 'MONTHLY',
            'externalReference' => (string) $subscription->id,
            'isAddressRequired' => false,
            'dueDateLimitDays' => 0
        ]);


        if ($response->failed()) {
            throw new \Exception('Erro ao gerar link de pagamento: ');
        }

        $responseData = $response->json();
        $subscription->paid_obs = $responseData['url'];
        $subscription->external_id = trim($responseData['id']);
        $subscription->save();

        return $subscription;
    }

    public static function webHookLinkPagamento(Request $request)
    {
        $data = $request->all();

        // Identifica o evento
        $event = $data['event'] ?? null;

        // Dados do pagamento
        $payment = $data['payment'] ?? [];

        if (!$event || empty($payment)) {
            return response()->json([
                'success' => false,
                'message' => 'Payload inválido'
            ], 400);
        }

        switch ($event) {
            case 'PAYMENT_CONFIRMED':
            case 'PAYMENT_RECEIVED':
                DB::statement(
                    "SELECT set_config('app.current_tenant', ?, false)",
                    [(string) 2]
                );
                
                $subscription = RecurrenceSubscription::where('id', $payment['externalReference'])->firstOrFail();
                $subscription->status = 'active';
                $subscription->paid_amount = $payment['value'];
                $subscription->paid_at = now();
                $subscription->paid_method = $payment['billingType'];
                $subscription->cash_posted_at = now();
                $subscription->save();

                $periodMonth = Carbon::parse($payment['dateCreated'])
                    ->startOfMonth()
                    ->toDateString();

                $subscription->payments()->create([
                    'tenant_id' => $subscription->tenant_id,
                    'recurrence_subscription_id' => $subscription->id,
                    'period_month' => $periodMonth,
                    'amount' => $payment['value'],
                    'status' => 'paid',
                    'method' => $payment['billingType'],
                    'paid_at' => now(),
                    'cash_posted_at' => now()
                ]);

                LinkPagamentoService::inativarLinkPagamento($payment['paymentLink']);
                LinkPagamentoService::atualizarSubscription($payment['subscription'], $subscription);
                
                break;
            default:
                return response()->json([
                    'success' => true,
                    'message' => 'Evento recebido, sem tratamento',
                    'event' => $event
                ]);
        }
    }

    private static function atualizarSubscription(String $subscriptionId, RecurrenceSubscription $subscription): void
    {
        $response = Http::withHeaders([
            'access_token' => env('ASAAS_API_KEY'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Barbear Shop',
        ])->put(env('ASAAS_BASE_URL') . "/subscriptions/{$subscriptionId}", [
            'endDate' => $subscription->ends_at->format('Y-m-d'),
        ]);

        if( $response->failed()) {
            throw new \Exception('Erro ao atualizar a assinatura: ');
        }
    }

    private static function inativarLinkPagamento(String $externalId): void
    {
        $response = Http::withHeaders([
            'access_token' => env('ASAAS_API_KEY'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Barbear Shop',
        ])->put(env('ASAAS_BASE_URL') . "/paymentLinks/{$externalId}", [
            'active' => false,
        ]);

        if ($response->failed()) {
            throw new \Exception('Erro ao inativar link de pagamento: ');
        }
    }
}
