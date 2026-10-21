<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | Assinaturas de recorrência vencidas viram `expired` sozinhas: sem isso a
 | lista do painel mostraria planos "ativos" já fora da vigência e a cota do
 | mês liberaria cortes fora do contrato.
 */
Schedule::command('recurrence:expire')->dailyAt('00:10');
