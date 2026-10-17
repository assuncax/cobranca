<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('regua:executar')
    ->weekdays()->at('09:00')->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// A hospedagem não mantém processos rodando: a fila é processada pelo cron a cada minuto.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
