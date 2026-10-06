<?php

namespace App\Application\Reports;

use App\Application\Payments\PaymentBalance;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

final class ReportBuilder
{
    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     columns: array<int, string>,
     *     summary: array<string, string>,
     *     rows: array<int, array<int, string>>
     * }
     */
    public function build(string $report, string $from, string $to, int $tenantId): array
    {
        return match ($report) {
            'faturamento' => $this->revenue($from, $to, $tenantId),
            'clientes' => $this->customers($from, $to, $tenantId),
            'barbeiros' => $this->barbers($from, $to, $tenantId),
            'comissoes' => $this->commissions($from, $to, $tenantId),
            default => throw new \InvalidArgumentException('Unsupported report type.'),
        };
    }

    /**
     * @return array{title: string, description: string, columns: array<int, string>, summary: array<string, string>, rows: array<int, array<int, string>>}
     */
    private function revenue(string $from, string $to, int $tenantId): array
    {
        $payments = DB::table('payments')
            ->select('method')
            ->selectRaw('COUNT(*) AS quantity, SUM(amount) AS amount')
            ->where('tenant_id', $tenantId)
            ->whereBetween(DB::raw('created_at::date'), [$from, $to])
            ->groupBy('method')
            ->orderBy('method')
            ->get();
        $refunds = DB::table('payment_refunds')
            ->where('tenant_id', $tenantId)
            ->whereBetween(DB::raw('refunded_at::date'), [$from, $to])
            ->sum('amount');

        $grossMinor = 0;
        $paymentCount = 0;
        $rows = [];
        $methods = ['cash' => 'Dinheiro', 'pix' => 'Pix', 'card' => 'Cartão'];
        foreach ($payments as $payment) {
            $grossMinor += $this->toMinorUnits((string) $payment->amount);
            $paymentCount += (int) $payment->quantity;
            $rows[] = [
                $methods[$payment->method] ?? (string) $payment->method,
                (string) $payment->quantity,
                $this->formatMoney((string) $payment->amount),
            ];
        }
        $refundMinor = $this->toMinorUnits((string) $refunds);
        $rows[] = ['Estornos', '—', $this->formatMoney((string) $refunds)];
        $rows[] = ['Líquido', (string) $paymentCount, $this->formatMoney(PaymentBalance::fromMinorUnits($grossMinor - $refundMinor))];

        return [
            'title' => 'Relatório de faturamento',
            'description' => 'Recebimentos e estornos lançados no período, agrupados por forma de pagamento.',
            'columns' => ['Forma de pagamento', 'Recebimentos', 'Valor recebido / líquido'],
            'summary' => [
                'Recebido' => 'R$ '.$this->formatMoney(PaymentBalance::fromMinorUnits($grossMinor)),
                'Estornado' => 'R$ '.$this->formatMoney(PaymentBalance::fromMinorUnits($refundMinor)),
                'Líquido' => 'R$ '.$this->formatMoney(PaymentBalance::fromMinorUnits($grossMinor - $refundMinor)),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{title: string, description: string, columns: array<int, string>, summary: array<string, string>, rows: array<int, array<int, string>>}
     */
    private function customers(string $from, string $to, int $tenantId): array
    {
        $appointmentsJoin = static function ($join) use ($tenantId, $from, $to): void {
            $join->on('appointments.customer_id', '=', 'customers.id')
                ->where('appointments.tenant_id', $tenantId)
                ->where('appointments.status', 'completed')
                ->whereBetween(DB::raw('appointments.starts_at::date'), [$from, $to]);
        };
        $customerRows = DB::table('customers')
            ->leftJoin('appointments', $appointmentsJoin)
            ->where('customers.tenant_id', $tenantId)
            ->select('customers.id', 'customers.name', 'customers.phone')
            ->selectRaw('COUNT(appointments.id) AS visits, MAX(appointments.starts_at) AS last_visit')
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->havingRaw('COUNT(appointments.id) > 0')
            ->orderByDesc('visits')
            ->orderBy('customers.name')
            ->get();
        $newCustomers = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->whereBetween(DB::raw('created_at::date'), [$from, $to])
            ->count();
        $visitCount = $customerRows->sum(fn (object $customer): int => (int) $customer->visits);

        return [
            'title' => 'Relatório de clientes',
            'description' => 'Clientes com atendimentos concluídos no período e sua frequência de visitas.',
            'columns' => ['Cliente', 'Telefone', 'Visitas', 'Última visita'],
            'summary' => [
                'Clientes atendidos' => (string) $customerRows->count(),
                'Novos cadastros' => (string) $newCustomers,
                'Visitas agendadas' => (string) $visitCount,
            ],
            'rows' => $customerRows->map(fn (object $customer): array => [
                (string) $customer->name,
                (string) $customer->phone,
                (string) $customer->visits,
                $customer->last_visit === null ? '—' : date('d/m/Y', strtotime((string) $customer->last_visit)),
            ])->all(),
        ];
    }

    /**
     * @return array{title: string, description: string, columns: array<int, string>, summary: array<string, string>, rows: array<int, array<int, string>>}
     */
    private function barbers(string $from, string $to, int $tenantId): array
    {
        $barberRows = DB::table('barbers')
            ->join('appointments', function ($join) use ($tenantId): void {
                $join->on('appointments.barber_id', '=', 'barbers.id')
                    ->where('appointments.tenant_id', $tenantId);
            })
            ->join('attendances', function ($join) use ($tenantId, $from, $to): void {
                $join->on('attendances.appointment_id', '=', 'appointments.id')
                    ->where('attendances.tenant_id', $tenantId)
                    ->where('attendances.status', 'closed')
                    ->whereBetween(DB::raw('attendances.closed_at::date'), [$from, $to]);
            })
            ->join('attendance_items', function ($join) use ($tenantId): void {
                $join->on('attendance_items.attendance_id', '=', 'attendances.id')
                    ->where('attendance_items.tenant_id', $tenantId);
            })
            ->where('barbers.tenant_id', $tenantId)
            ->select('barbers.id', 'barbers.name')
            ->selectRaw('COUNT(DISTINCT attendances.id) AS visits')
            ->selectRaw('SUM(attendance_items.quantity) AS services')
            ->selectRaw('SUM(attendance_items.unit_price_snapshot * attendance_items.quantity) AS revenue')
            ->selectRaw('SUM(attendance_items.commission_amount_snapshot) AS commissions')
            ->groupBy('barbers.id', 'barbers.name')
            ->orderByDesc('revenue')
            ->orderBy('barbers.name')
            ->get();
        $revenueMinor = 0;
        $commissionMinor = 0;
        foreach ($barberRows as $barber) {
            $revenueMinor += $this->toMinorUnits((string) $barber->revenue);
            $commissionMinor += $this->toMinorUnits((string) $barber->commissions);
        }

        return [
            'title' => 'Relatório de barbeiros',
            'description' => 'Atendimentos concluídos, serviços, valor dos serviços e comissões dos snapshots.',
            'columns' => ['Barbeiro', 'Atendimentos', 'Serviços', 'Valor dos serviços', 'Comissões'],
            'summary' => [
                'Barbeiros no período' => (string) $barberRows->count(),
                'Atendimentos concluídos' => (string) $barberRows->sum(fn (object $barber): int => (int) $barber->visits),
                'Valor dos serviços' => 'R$ '.$this->formatMoney(PaymentBalance::fromMinorUnits($revenueMinor)),
                'Comissões apuradas' => 'R$ '.$this->formatMoney(PaymentBalance::fromMinorUnits($commissionMinor)),
            ],
            'rows' => $barberRows->map(fn (object $barber): array => [
                (string) $barber->name,
                (string) $barber->visits,
                (string) $barber->services,
                $this->formatMoney((string) $barber->revenue),
                $this->formatMoney((string) $barber->commissions),
            ])->all(),
        ];
    }

    /**
     * @return array{title: string, description: string, columns: array<int, string>, summary: array<string, string>, rows: array<int, array<int, string>>}
     */
    private function commissions(string $from, string $to, int $tenantId): array
    {
        $commissionRows = DB::table('commissions')
            ->join('commission_periods', function ($join) use ($tenantId, $from, $to): void {
                $join->on('commission_periods.id', '=', 'commissions.period_id')
                    ->where('commission_periods.tenant_id', $tenantId)
                    ->whereDate('commission_periods.period_start', '<=', $to)
                    ->whereDate('commission_periods.period_end', '>=', $from);
            })
            ->where('commissions.tenant_id', $tenantId)
            ->select('commissions.barber_id', 'commissions.barber_name_snapshot')
            ->selectRaw('COUNT(*) AS quantity, SUM(commissions.amount) AS total')
            ->selectRaw('SUM(CASE WHEN commissions.commission_payment_id IS NULL AND commissions.amount > 0 THEN commissions.amount ELSE 0 END) AS pending')
            ->selectRaw('SUM(CASE WHEN commissions.commission_payment_id IS NOT NULL THEN commissions.amount ELSE 0 END) AS paid')
            ->groupBy('commissions.barber_id', 'commissions.barber_name_snapshot')
            ->orderBy('commissions.barber_name_snapshot')
            ->get();
        $totalMinor = 0;
        $pendingMinor = 0;
        $paidMinor = 0;
        foreach ($commissionRows as $commission) {
            $totalMinor += $this->toMinorUnits((string) $commission->total);
            $pendingMinor += $this->toMinorUnits((string) $commission->pending);
            $paidMinor += $this->toMinorUnits((string) $commission->paid);
        }

        return [
            'title' => 'Relatório de comissões',
            'description' => 'Comissões apuradas nos períodos que cruzam o intervalo escolhido, separadas por situação de repasse.',
            'columns' => ['Barbeiro', 'Lançamentos', 'Total apurado', 'Pendente', 'Repassado'],
            'summary' => [
                'Barbeiros com comissão' => (string) $commissionRows->count(),
                'Total apurado' => 'R$ '.$this->formatMoney(PaymentBalance::fromMinorUnits($totalMinor)),
                'Pendente' => 'R$ '.$this->formatMoney(PaymentBalance::fromMinorUnits($pendingMinor)),
                'Repassado' => 'R$ '.$this->formatMoney(PaymentBalance::fromMinorUnits($paidMinor)),
            ],
            'rows' => $commissionRows->map(fn (object $commission): array => [
                (string) $commission->barber_name_snapshot,
                (string) $commission->quantity,
                $this->formatMoney((string) $commission->total),
                $this->formatMoney((string) $commission->pending),
                $this->formatMoney((string) $commission->paid),
            ])->all(),
        ];
    }

    private function formatMoney(string $amount): string
    {
        return (new Money(PaymentBalance::fromMinorUnits($this->toMinorUnits($amount))))->format();
    }

    private function toMinorUnits(string $amount): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid report amount.');
        }

        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$minor : $minor;
    }
}
