<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates users table with expected columns', function () {
    expect(Schema::hasTable('users'))->toBeTrue();
    expect(Schema::hasColumns('users', ['id', 'name', 'email', 'password', 'created_at', 'updated_at']))->toBeTrue();
});

it('runs feature tests on a driver that can enforce rls', function () {
    /*
     * Antes este arquivo afirmava `sqlite :memory:`. A Sprint 5 trocou: as
     * policies de RLS são sintaxe de PostgreSQL, e `set_config()` — o caminho
     * pelo qual o tenant chega à policy — não existe em SQLite.
     *
     * O que importa agora é o contrário do que se costuma checar: não basta o
     * driver ser PostgreSQL, ele precisa ser um papel que *sofre* a policy. Um
     * SUPERUSER não falha em nada, o que torna a suíte inteira verde sem provar
     * nada. `RowLevelSecurityTest` verifica essa parte.
     */
    expect(config('database.default'))->toBe('pgsql');
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('has queue jobs table for async work', function () {
    expect(Schema::hasTable('jobs'))->toBeTrue();
});

it('has cache table for rate limiting and sessions', function () {
    expect(Schema::hasTable('cache'))->toBeTrue();
});
