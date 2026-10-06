<?php

namespace Tests\Fixtures;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;

/**
 * Modelo descartável que existe só para exercitar a base tenant-scoped.
 *
 * Nenhum modelo do produto estende `TenantScopedModel` ainda: `Membership` mora
 * em `App\Domain` e não pode depender de `App\Infrastructure`. Sem esta fixture
 * a automação do escopo global e do preenchimento de `tenant_id` ficaria sem
 * nenhuma prova — e automação sem prova é a que se quebra em silêncio quando
 * ninguém está olhando.
 */
class TenantScopedWidget extends TenantScopedModel
{
    protected $table = 'tenant_scoped_widgets';

    protected $fillable = ['name'];
}
