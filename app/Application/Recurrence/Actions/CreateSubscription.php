<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\RecurrencePlan;
use App\Models\RecurrenceSubscription;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cria uma assinatura de recorrência e, opcionalmente, o corte de abertura.
 *
 * Usado pelos dois portais: o site (cliente assinando) e o painel (recepção).
 * A assinatura nasce `pending_payment` — sem gateway no MVP, quem confirma o
 * recebimento é a barbearia — e o corte de abertura é o único que pode ser
 * marcado antes da ativação; os demais exigem `active`.
 */
final class CreateSubscription extends Action
{
    public function __construct(private readonly ScheduleSubscriptionCut $scheduleCut) {}

    /**
     * @param  array{
     *     plan_id: int,
     *     starts_at: string,
     *     customer_id?: int|null,
     *     customer_name?: string|null,
     *     customer_phone?: string|null,
     *     barber_id?: int|null,
     *     first_appointment_date?: string|null,
     *     first_appointment_time?: string|null,
     *     created_by?: int|null,
     * }  $input
     * @return array{subscription: RecurrenceSubscription, appointment: Appointment|null}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $plan = RecurrencePlan::query()
                ->whereKey($input['plan_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $customer = $this->resolveCustomer($input);

            try {
                $startsAt = CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    $input['starts_at'],
                    config('app.timezone'),
                );
            } catch (InvalidFormatException) {
                throw ValidationException::withMessages(['starts_at' => 'Selecione uma data de início válida.']);
            }

            $hasFirstCut = trim((string) ($input['first_appointment_date'] ?? '')) !== ''
                && trim((string) ($input['first_appointment_time'] ?? '')) !== '';

            $barber = null;
            if (! empty($input['barber_id'])) {
                $barber = Barber::query()
                    ->whereKey($input['barber_id'])
                    ->where('is_active', true)
                    ->firstOrFail();
            }

            if ($hasFirstCut && $barber === null) {
                throw ValidationException::withMessages([
                    'barber_id' => 'Selecione o barbeiro para marcar o corte de abertura.',
                ]);
            }

            $subscription = RecurrenceSubscription::query()->create([
                'public_id' => (string) Str::uuid(),
                'customer_id' => $customer->getKey(),
                'plan_id' => $plan->getKey(),
                'barber_id' => $barber?->getKey(),
                'service_id' => $plan->service_id,
                'plan_name' => $plan->name,
                'price' => $plan->price,
                'duration_months' => $plan->duration_months,
                'sessions_per_month' => $plan->sessions_per_month,
                'max_sessions_per_week' => $plan->max_sessions_per_week,
                'starts_at' => $startsAt->toDateString(),
                'ends_at' => $startsAt->addMonths($plan->duration_months)->toDateString(),
                'status' => SubscriptionStatus::PendingPayment,
                'created_by' => $input['created_by'] ?? null,
            ]);

            $appointment = null;
            if ($hasFirstCut) {
                $appointment = $this->scheduleCut->execute([
                    'subscription' => $subscription,
                    'barber_id' => $barber->getKey(),
                    'date' => $input['first_appointment_date'],
                    'time' => $input['first_appointment_time'],
                    'actor_id' => $input['created_by'] ?? null,
                    'allow_unpaid' => true,
                ])['appointment'];
            }

            return [
                'subscription' => $subscription->load(['customer', 'plan', 'barber', 'service']),
                'appointment' => $appointment,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveCustomer(array $input): Customer
    {
        if (! empty($input['customer_id'])) {
            return Customer::query()->whereKey($input['customer_id'])->firstOrFail();
        }

        $phone = preg_replace('/\D+/', '', (string) ($input['customer_phone'] ?? ''));

        if ($phone === null || ! in_array(strlen($phone), [10, 11], true)) {
            throw ValidationException::withMessages([
                'customer_phone' => 'Informe um telefone com DDD válido.',
            ]);
        }

        $name = trim((string) ($input['customer_name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['customer_name' => 'Informe o nome do cliente.']);
        }

        return Customer::query()->firstOrCreate(
            ['phone' => $phone],
            ['name' => $name],
        );
    }
}
