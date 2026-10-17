<?php

namespace App\Console\Commands;

use App\Jobs\EnviarMensagem;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use App\Support\SeletorEtapa;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExecutarRegua extends Command
{
    protected $signature = 'regua:executar';

    protected $description = 'Agenda as mensagens da régua de cobrança de hoje';

    public function handle(): int
    {
        $hoje = CarbonImmutable::today();
        $teste = Configuracao::modoTeste();
        $etapas = EtapaRegua::all()
            ->map(fn (EtapaRegua $e) => ['id' => $e->id, 'dias' => $e->dias, 'ativa' => $e->ativa])
            ->all();
        $agendadas = 0;

        Cobranca::where('status', 'aberta')->with('envios')->chunkById(200, function ($cobrancas) use ($hoje, $teste, $etapas, &$agendadas) {
            foreach ($cobrancas as $cobranca) {
                $registrados = $cobranca->envios->where('teste', $teste)->pluck('etapa_regua_id')->all();
                $escolha = SeletorEtapa::selecionar($cobranca->vencimento, $hoje, $etapas, $registrados);

                foreach ($escolha['pular'] as $etapaId) {
                    Envio::firstOrCreate(
                        ['cobranca_id' => $cobranca->id, 'etapa_regua_id' => $etapaId, 'teste' => $teste],
                        ['status' => 'pulada'],
                    );
                }

                if ($escolha['enviar'] === null) {
                    continue;
                }

                // Transação: o envio 'pendente' e o job entram juntos na fila, ou nenhum dos dois.
                DB::transaction(function () use ($cobranca, $escolha, $teste, &$agendadas) {
                    $envio = Envio::firstOrCreate(
                        ['cobranca_id' => $cobranca->id, 'etapa_regua_id' => $escolha['enviar'], 'teste' => $teste],
                        ['status' => 'pendente'],
                    );

                    if ($envio->wasRecentlyCreated) {
                        EnviarMensagem::dispatch($envio->id);
                        $agendadas++;
                    }
                });
            }
        });

        $this->info($agendadas.' mensagem(ns) agendada(s)'.($teste ? ' em MODO TESTE' : '').'.');

        return self::SUCCESS;
    }
}
