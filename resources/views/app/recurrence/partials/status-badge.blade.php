@switch ($subscription->status->value)
    @case('active')
        <span class="badge text-bg-success">Ativa</span>
        @break
    @case('pending_payment')
        <span class="badge text-bg-warning">Aguardando pagamento</span>
        @break
    @case('canceled')
        <span class="badge text-bg-secondary">Cancelada</span>
        @break
    @default
        <span class="badge text-bg-secondary">Encerrada</span>
@endswitch
