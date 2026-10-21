<?php

namespace App\Application\WhatsApp\Actions;

use App\Application\Agenda\Actions\CreatePublicAppointment;
use App\Application\Agenda\Actions\ListAvailableSlots;
use App\Application\Shared\Actions\Action;
use App\Application\WhatsApp\Enums\ConversationStep;
use App\Application\WhatsApp\Support\WhatsAppPhone;
use App\Models\Customer;
use App\Models\WhatsAppConversation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Máquina de estados do bot de WhatsApp.
 *
 * Recebe uma mensagem de texto e devolve a resposta: a conversa avança no menu
 * numerado (barbeiro → serviço → dia → horário → nome, se novo → confirmação)
 * e o agendamento final é gravado pelo mesmo `CreatePublicAppointment` do
 * booking público — mesma regra de slot, mesma criação de cliente, mesma
 * notificação no painel.
 *
 * A conversa fica em `whatsapp_conversations` (um telefone por barbearia), o
 * que torna o fluxo retomável entre mensagens e sobrevivível a deploy. O
 * `last_message_id` é o antídoto contra o retry do Meta: a mesma `wamid`
 * reenviada não move o estado nem agenda em duplicidade.
 *
 * Horários aparecem paginados porque uma página cheia de agenda não cabe num
 * menu numerado legível: `0` mostra a próxima leva.
 */
final class HandleIncomingMessage extends Action
{
    private const SLOTS_PER_PAGE = 8;

    private const STALE_AFTER_HOURS = 2;

    public function __construct(
        private readonly ListBookableBarbers $listBookableBarbers,
        private readonly ListBookableServices $listBookableServices,
        private readonly ListAvailableSlots $listAvailableSlots,
        private readonly CreatePublicAppointment $createPublicAppointment,
    ) {}

    /**
     * @param  array{phone: string, text: string, message_id: string, tenant_name: string}  $input
     * @return array{replies: array<int, string>}
     */
    protected function handle(array $input): array
    {
        $phone = WhatsAppPhone::international($input['phone']);

        if ($phone === null) {
            return ['replies' => []];
        }

        $conversation = WhatsAppConversation::query()
            ->where('phone', $phone)
            ->first();

        if ($conversation?->last_message_id === $input['message_id']) {
            return ['replies' => []];
        }

        if ($conversation === null) {
            $conversation = WhatsAppConversation::query()->create([
                'phone' => $phone,
                'step' => ConversationStep::ChoosingBarber->value,
                'context' => [],
            ]);
        } elseif ($conversation->updated_at !== null
            && $conversation->updated_at->lt(now()->subHours(self::STALE_AFTER_HOURS))) {
            $conversation->forceFill([
                'step' => ConversationStep::ChoosingBarber->value,
                'context' => [],
            ])->save();
        }

        $context = $conversation->context ?? [];
        $text = trim($input['text']);

        if (in_array(mb_strtolower($text), ['menu', 'inicio', 'início'], true)) {
            $conversation->forceFill([
                'step' => ConversationStep::ChoosingBarber->value,
                'context' => [],
            ])->save();
            $context = [];
            $text = '';
        }

        $step = ConversationStep::tryFrom($conversation->step) ?? ConversationStep::ChoosingBarber;

        try {
            $replies = match ($step) {
                ConversationStep::ChoosingBarber => $this->chooseBarber($conversation, $input['tenant_name'], $text),
                ConversationStep::ChoosingService => $this->chooseService($conversation, $context, $text),
                ConversationStep::ChoosingDate => $this->chooseDate($conversation, $context, $text),
                ConversationStep::ChoosingTime => $this->chooseTime($conversation, $context, $phone, $text),
                ConversationStep::AskingName => $this->askName($conversation, $context, $text),
                ConversationStep::Confirming => $this->confirm($conversation, $context, $phone, $text),
            };
        } catch (ModelNotFoundException) {
            $replies = $this->restart($conversation, $input['tenant_name']);
            $replies[] = 'Desculpe, não consegui continuar de onde paramos. Escolha novamente:';
        } catch (ValidationException $exception) {
            $replies = $this->restart($conversation, $input['tenant_name']);
            $replies[] = (string) (collect($exception->errors())->flatten()->first()
                ?? 'Não consegui concluir. Escolha novamente:');
        }

        $conversation->forceFill(['last_message_id' => $input['message_id']])->save();

        return ['replies' => $replies];
    }

    /**
     * @return array<int, string>
     */
    private function chooseBarber(WhatsAppConversation $conversation, string $tenantName, string $text): array
    {
        $barbers = $this->listBookableBarbers->execute([])['barbers'];

        if ($barbers === []) {
            $conversation->forceFill([
                'step' => ConversationStep::ChoosingBarber->value,
                'context' => [],
            ])->save();

            return [
                'Ainda não há barbeiros disponíveis para agendamento. Tente novamente mais tarde ou envie "menu".',
            ];
        }

        $choice = self::optionNumber($text);

        if ($choice === null || ! isset($barbers[$choice - 1])) {
            return [$this->barberMenu($barbers, $tenantName)];
        }

        $barber = $barbers[$choice - 1];
        $conversation->forceFill([
            'step' => ConversationStep::ChoosingService->value,
            'context' => ['barber_id' => $barber['id'], 'barber_name' => $barber['name']],
        ])->save();

        $services = $this->listBookableServices->execute(['barber_id' => $barber['id']])['services'];

        if ($services === []) {
            $replies = $this->restart($conversation, $tenantName);
            array_unshift($replies, 'Este barbeiro ainda não tem serviços cadastrados.');

            return $replies;
        }

        return [$this->serviceMenu($services)];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function chooseService(WhatsAppConversation $conversation, array $context, string $text): array
    {
        $services = $this->listBookableServices->execute(['barber_id' => (int) $context['barber_id']])['services'];

        if ($services === []) {
            return $this->restart($conversation, '');
        }

        $choice = self::optionNumber($text);

        if ($choice === null || ! isset($services[$choice - 1])) {
            return [$this->serviceMenu($services)];
        }

        $service = $services[$choice - 1];
        $conversation->forceFill([
            'step' => ConversationStep::ChoosingDate->value,
            'context' => array_merge($context, [
                'service_id' => $service['id'],
                'service_name' => $service['name'],
                'duration_minutes' => $service['duration_minutes'],
                'price' => $service['price'],
            ]),
        ])->save();

        return [$this->dateMenu()];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function chooseDate(WhatsAppConversation $conversation, array $context, string $text): array
    {
        $date = $this->parseDate($text);

        if ($date === null) {
            return [$this->dateMenu('Não entendi a data.')];
        }

        $slots = $this->slotsFor($context, $date);

        if ($slots === []) {
            return [$this->dateMenu(sprintf('Não há horários livres em %s.', $date->format('d/m')))];
        }

        $conversation->forceFill([
            'step' => ConversationStep::ChoosingTime->value,
            'context' => array_merge($context, ['date' => $date->toDateString(), 'slots_page' => 0]),
        ])->save();

        return [$this->slotsMenu($slots, 0, $date)];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function chooseTime(WhatsAppConversation $conversation, array $context, string $phone, string $text): array
    {
        if (! isset($context['date'], $context['barber_id'], $context['service_id'])) {
            return $this->restart($conversation, '');
        }

        $date = $this->parseStoredDate((string) $context['date']);
        $slots = $this->slotsFor($context, $date);
        $page = (int) ($context['slots_page'] ?? 0);

        if ($text === '0') {
            $totalPages = (int) ceil(count($slots) / self::SLOTS_PER_PAGE);

            if ($page + 1 < $totalPages) {
                $conversation->forceFill([
                    'context' => array_merge($context, ['slots_page' => $page + 1]),
                ])->save();

                return [$this->slotsMenu($slots, $page + 1, $date)];
            }

            return [$this->slotsMenu($slots, $page, $date, 'Não há mais horários nesta lista.')];
        }

        $choice = self::optionNumber($text);
        $pageSlots = array_slice($slots, $page * self::SLOTS_PER_PAGE, self::SLOTS_PER_PAGE);

        if ($choice === null || ! isset($pageSlots[$choice - 1])) {
            return [$this->slotsMenu($slots, $page, $date, 'Opção inválida.')];
        }

        $context = array_merge($context, ['time' => $pageSlots[$choice - 1], 'slots_page' => $page]);
        $nationalPhone = WhatsAppPhone::national($phone);
        $customer = $nationalPhone === null
            ? null
            : Customer::query()->where('phone', $nationalPhone)->first();

        if ($customer !== null) {
            $conversation->forceFill([
                'step' => ConversationStep::Confirming->value,
                'context' => array_merge($context, ['customer_name' => $customer->name]),
            ])->save();

            return [$this->summary(array_merge($context, ['customer_name' => $customer->name]))];
        }

        $conversation->forceFill([
            'step' => ConversationStep::AskingName->value,
            'context' => $context,
        ])->save();

        return ['Qual é o seu nome?'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function askName(WhatsAppConversation $conversation, array $context, string $text): array
    {
        if ($text === '' || mb_strlen($text) > 150) {
            return ['Não entendi. Envie seu nome (até 150 caracteres).'];
        }

        $conversation->forceFill([
            'step' => ConversationStep::Confirming->value,
            'context' => array_merge($context, ['customer_name' => $text]),
        ])->save();

        return [$this->summary(array_merge($context, ['customer_name' => $text]))];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function confirm(
        WhatsAppConversation $conversation,
        array $context,
        string $phone,
        string $text,
    ): array {
        if ($text === '3') {
            return $this->restart($conversation, '');
        }

        if ($text === '2') {
            $date = $this->parseStoredDate((string) $context['date']);
            $slots = $this->slotsFor($context, $date);
            $conversation->forceFill([
                'step' => ConversationStep::ChoosingTime->value,
                'context' => array_merge($context, ['time' => null, 'slots_page' => 0]),
            ])->save();

            return [$this->slotsMenu($slots, 0, $date)];
        }

        if ($text !== '1') {
            return [$this->summary($context)];
        }

        if (! isset($context['customer_name'], $context['time'], $context['date'])) {
            return $this->restart($conversation, '');
        }

        $nationalPhone = WhatsAppPhone::national($phone);

        if ($nationalPhone === null) {
            return $this->restart($conversation, '');
        }

        try {
            $appointment = $this->createPublicAppointment->execute([
                'barber_id' => (int) $context['barber_id'],
                'service_id' => (int) $context['service_id'],
                'date' => (string) $context['date'],
                'time' => (string) $context['time'],
                'customer_name' => (string) $context['customer_name'],
                'customer_phone' => $nationalPhone,
            ])['appointment'];
        } catch (ValidationException $exception) {
            $date = $this->parseStoredDate((string) $context['date']);
            $slots = $this->slotsFor($context, $date);
            $conversation->forceFill([
                'step' => ConversationStep::ChoosingTime->value,
                'context' => array_merge($context, ['time' => null, 'slots_page' => 0]),
            ])->save();

            $message = (string) (collect($exception->errors())->flatten()->first()
                ?? 'Este horário acabou de ficar indisponível.');

            return [$message."\n\n".$this->slotsMenu($slots, 0, $date)];
        }

        $conversation->forceFill([
            'step' => ConversationStep::ChoosingBarber->value,
            'context' => [],
        ])->save();

        return [sprintf(
            'Agendado! %s com %s em %s às %s. Até lá!',
            $appointment->service->name,
            $appointment->barber->name,
            $appointment->starts_at->format('d/m/Y'),
            $appointment->starts_at->format('H:i'),
        )];
    }

    /**
     * Volta ao menu inicial e devolve o menu de barbeiros.
     *
     * @return array<int, string>
     */
    private function restart(WhatsAppConversation $conversation, string $tenantName): array
    {
        $conversation->forceFill([
            'step' => ConversationStep::ChoosingBarber->value,
            'context' => [],
        ])->save();

        return [$this->barberMenu($this->listBookableBarbers->execute([])['barbers'], $tenantName)];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function slotsFor(array $context, CarbonImmutable $date): array
    {
        return $this->listAvailableSlots->execute([
            'barber_id' => (int) $context['barber_id'],
            'service_id' => (int) $context['service_id'],
            'date' => $date->toDateString(),
        ])['slots'];
    }

    private function parseStoredDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'))
            ?: CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    private function parseDate(string $text): ?CarbonImmutable
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        if ($text === '1') {
            return $today;
        }

        if ($text === '2') {
            return $today->addDay();
        }

        foreach (['!d/m' => 'd/m', '!d/m/Y' => 'd/m/Y'] as $format => $roundTrip) {
            $date = CarbonImmutable::createFromFormat($format, $text, config('app.timezone'));

            if ($date !== false && $date->format($roundTrip) === $text) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    private static function optionNumber(string $text): ?int
    {
        return ctype_digit($text) && (int) $text > 0 ? (int) $text : null;
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $barbers
     */
    private function barberMenu(array $barbers, string $tenantName): string
    {
        $lines = [
            'Olá! Aqui é o agendamento da '.($tenantName !== '' ? $tenantName : 'nossa barbearia').'.',
            'Escolha o barbeiro:',
        ];

        foreach ($barbers as $index => $barber) {
            $lines[] = sprintf('%d. %s', $index + 1, $barber['name']);
        }

        $lines[] = 'Envie "menu" para recomeçar.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{name: string, duration_minutes: int, price: string|null}>  $services
     */
    private function serviceMenu(array $services): string
    {
        $lines = ['Qual serviço você precisa?'];

        foreach ($services as $index => $service) {
            $price = $service['price'] !== null
                ? sprintf(' · R$ %s', number_format((float) $service['price'], 2, ',', '.'))
                : '';
            $lines[] = sprintf(
                '%d. %s (%d min%s)',
                $index + 1,
                $service['name'],
                $service['duration_minutes'],
                $price,
            );
        }

        return implode("\n", $lines);
    }

    private function dateMenu(?string $prefix = null): string
    {
        $lines = [];

        if ($prefix !== null) {
            $lines[] = $prefix;
        }

        $lines[] = 'Qual dia você prefere?';
        $lines[] = '1. Hoje';
        $lines[] = '2. Amanhã';
        $lines[] = '3. Outra data (responda no formato dia/mês, ex: 25/10)';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $slots
     */
    private function slotsMenu(array $slots, int $page, CarbonImmutable $date, ?string $prefix = null): string
    {
        $lines = [];

        if ($prefix !== null) {
            $lines[] = $prefix;
        }

        $lines[] = sprintf('Horários livres em %s:', $date->format('d/m'));

        foreach (array_slice($slots, $page * self::SLOTS_PER_PAGE, self::SLOTS_PER_PAGE) as $index => $slot) {
            $lines[] = sprintf('%d. %s', $index + 1, $slot);
        }

        if ((($page + 1) * self::SLOTS_PER_PAGE) < count($slots)) {
            $lines[] = '0. Mais horários';
        } else {
            $lines[] = 'Se não há horário aqui, envie "menu" e escolha outro dia.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function summary(array $context): string
    {
        $date = $this->parseStoredDate((string) $context['date']);
        $price = isset($context['price']) && $context['price'] !== null
            ? sprintf(' · R$ %s', number_format((float) $context['price'], 2, ',', '.'))
            : '';

        $lines = [
            'Confirma o agendamento?',
            sprintf('%s%s com %s', $context['service_name'], $price, $context['barber_name']),
            sprintf('%s às %s', $date->format('d/m/Y'), $context['time']),
            sprintf('Cliente: %s', $context['customer_name']),
            '',
            '1. Confirmar',
            '2. Escolher outro horário',
            '3. Recomeçar',
        ];

        return implode("\n", $lines);
    }
}
