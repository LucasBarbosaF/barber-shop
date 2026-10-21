<?php

namespace App\Application\WhatsApp\Enums;

/**
 * Passos do menu numerado do bot de WhatsApp.
 *
 * A conversa avança em linha: barbeiro → serviço → dia → horário → (nome, se
 * o telefone for novo) → confirmação. `0`/`menu` reinicia para
 * `ChoosingBarber` em qualquer ponto.
 */
enum ConversationStep: string
{
    case ChoosingBarber = 'choosing_barber';

    case ChoosingService = 'choosing_service';

    case ChoosingDate = 'choosing_date';

    case ChoosingTime = 'choosing_time';

    case AskingName = 'asking_name';

    case Confirming = 'confirming';
}
