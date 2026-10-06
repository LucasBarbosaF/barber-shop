<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * `$this->authorize()` em qualquer controller.
 *
 * `AuthorizesRequests` é o que transforma a Policy em ponto de decisão dentro
 * do controller, em vez de um middleware que a pessoa pode esquecer na rota.
 * Sem ele, `$this->authorize()` não existe e uma rota sem `permission:*` fica
 * sem nenhuma checagem de recurso.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
