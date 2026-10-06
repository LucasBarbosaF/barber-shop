<?php

namespace App\Application\Shared\Tenancy;

/**
 * O que a resolução do tenant corrente produziu.
 *
 * Três desfechos, e a diferença entre eles é o que a interface precisa mostrar:
 *
 *  - `Resolved`: há um tenant no contexto, a requisição pode trabalhar;
 *  - `SelectionRequired`: a pessoa pertence a mais de uma barbearia e nenhuma
 *    foi escolhida — escolher arbitrariamente seria inventar autorização;
 *  - `None`: a pessoa não pertence a nenhuma barbearia ativa.
 */
enum ResolutionStatus
{
    case Resolved;
    case SelectionRequired;
    case None;
}
