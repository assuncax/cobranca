<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('regua:executar')
    ->weekdays()->at('09:00')->timezone('America/Sao_Paulo')
    ->withoutOverlapping(10);

// A hospedagem não mantém processos rodando: a fila é processada pelo cron a cada minuto.
// A trava expira em 2 minutos para não travar a fila se a hospedagem matar o processo.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('regua:resumo')
    ->weekdays()->at('10:00')->timezone('America/Sao_Paulo');
