<?php

namespace App\Domain\Recurrence\Enums;

/**
 * Estado de uma assinatura de recorrência.
 *
 * O cliente cria a assinatura pelo site (ou a recepção no painel) e ela nasce
 * `pending_payment`: sem gateway de pagamento no MVP, quem confirma o recebimento
 * é a barbearia, em um clique, e aí o plano vira `active`.
 *
 * `expired` é escrito pelo comando `recurrence:expire`; `canceled` só pela
 * recepção. Cancelamento e expiração são lógicos — o histórico de cortes já
 * atendidos permanece na agenda e nos atendimentos.
 */
enum SubscriptionStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Aguardando pagamento',
            self::Active => 'Ativa',
            self::Canceled => 'Cancelada',
            self::Expired => 'Encerrada',
        };
    }

    /**
     * Estados a partir dos quais a recepção ainda pode agendar cortes.
     *
     * `pending_payment` fica de fora de propósito: o corte de abertura já nasce
     * junto com a assinatura e é o único que o cliente pode marcar sem ter
     * pago — se qualquer corte pudesse ser agendado antes do pagamento, o teto
     * de risco deixaria de ser um corte e passaria a ser o plano inteiro.
     *
     * @return array<int, self>
     */
    public function schedulable(): array
    {
        return [self::Active];
    }
}
