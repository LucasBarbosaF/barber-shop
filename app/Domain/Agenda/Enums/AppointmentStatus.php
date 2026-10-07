<?php

namespace App\Domain\Agenda\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case InService = 'in_service';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case NoShow = 'no_show';

    /**
     * @return array<int, string>
     */
    public static function blockingValues(): array
    {
        return [
            self::Pending->value,
            self::Confirmed->value,
            self::Arrived->value,
            self::InService->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Confirmed => 'Confirmado',
            self::Arrived => 'Cliente chegou',
            self::InService => 'Em atendimento',
            self::Completed => 'Concluído',
            self::Canceled => 'Cancelado',
            self::NoShow => 'Não compareceu',
        };
    }

    /**
     * Estados para os quais a agenda pode mudar este agendamento.
     *
     * `in_service` e `completed` não têm transição aqui de propósito: eles
     * nascem só do fluxo de atendimento (a ficha é o que pagamentos e
     * relatórios leem), e `completed` é estado final. Cancelado e
     * não-comparecem podem voltar a pendente/confirmado — é o "desfazer".
     *
     * @return array<int, self>
     */
    public function targets(): array
    {
        return self::TRANSITIONS[$this->value];
    }

    /**
     * @var array<string, array<int, self>>
     */
    private const TRANSITIONS = [
        'pending' => [
            self::Confirmed,
            self::Arrived,
            self::Canceled,
            self::NoShow,
        ],
        'confirmed' => [
            self::Pending,
            self::Arrived,
            self::Canceled,
            self::NoShow,
        ],
        'arrived' => [
            self::Confirmed,
            self::Canceled,
            self::NoShow,
        ],
        'canceled' => [
            self::Pending,
            self::Confirmed,
        ],
        'no_show' => [
            self::Pending,
            self::Confirmed,
        ],
        'in_service' => [],
        'completed' => [],
    ];
}
