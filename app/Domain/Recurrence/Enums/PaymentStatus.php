<?php

namespace App\Domain\Recurrence\Enums;

/**
 * Estado de uma mensalidade da assinatura de recorrência.
 *
 * No fluxo manual da recepção a linha nasce `paid` — quem registra o
 * pagamento o confirma na hora. `pending` é o estado para a integração
 * futura com o Asaas: a cobrança recorrente criará a mensalidade antes do
 * vencimento e a webhook confirmará a transição `pending → paid`.
 *
 * O corte só é liberado em mês com mensalidade `paid`
 * (ver RecurrenceQuota::assertCanSchedule).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando pagamento',
            self::Paid => 'Pago',
            self::Failed => 'Falhou',
            self::Canceled => 'Cancelado',
        };
    }
}
