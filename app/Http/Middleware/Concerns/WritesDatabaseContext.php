<?php

namespace App\Http\Middleware\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Escrita da *customized option* que as policies de RLS leem.
 *
 * ## Por que `set_config` e não `SET`
 *
 * `SET app.current_tenant = ...` emite um `DISCARD ALL` implícito em alguns
 * caminhos do driver e, principalmente, persiste na sessão. `set_config(..., true)`
 * equivale a `SET LOCAL`: vale só até o fim da transação corrente.
 *
 * ## Por que a transação é obrigatória
 *
 * Foi medido em PG 16 que `set_config(..., true)` **fora** de transação tem
 * efeito e persiste na sessão. A transação não é economia de round-trip — é o
 * mecanismo de limpeza. É ela que torna a aplicação segura com connection pool:
 * uma conexão devolvida ao pool nunca carrega a barbearia de outra requisição.
 *
 * ## Transactions aninhadas
 *
 * Quando dois middlewares deste conjunto abrem transação, a segunda vira um
 * `SAVEPOINT`. Medido: `SET LOCAL` feito dentro do savepoint **sobrevive** ao
 * `RELEASE` e só é descartado no `COMMIT` da transação externa — que é
 * exatamente o que este código quer.
 */
trait WritesDatabaseContext
{
    /**
     * `null` limpa a opção em vez de escrever string vazia, para que
     * `current_setting(..., true)` devolva NULL e a policy trate como "sem
     * contexto" — que é o mesmo que "sem acesso".
     */
    private function setLocal(string $name, int|string|null $value): void
    {
        DB::selectOne('SELECT set_config(?, ?, true)', [
            $name,
            $value === null ? '' : (string) $value,
        ]);
    }
}
