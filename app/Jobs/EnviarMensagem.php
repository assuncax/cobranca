<?php

namespace App\Jobs;

use App\Models\Configuracao;
use App\Models\Envio;
use App\WhatsApp\EnviadorWhatsApp;
use App\WhatsApp\ErroDefinitivoWhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EnviarMensagem implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $envioId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EnviadorWhatsApp $enviador): void
    {
        $envio = Envio::with(['cobranca.cliente', 'etapa'])->find($this->envioId);
        if (! $envio || $envio->status !== 'pendente') {
            return;
        }

        $cobranca = $envio->cobranca;
        if ($cobranca->status !== 'aberta') {
            $envio->update(['status' => 'pulada', 'erro' => 'Cobrança não está mais aberta.']);

            return;
        }

        $telefone = $envio->teste ? Configuracao::get('numero_teste') : $cobranca->cliente->telefone;
        if (! $telefone) {
            $envio->update(['status' => 'falhou', 'erro' => 'Número de teste não configurado.']);

            return;
        }

        $envio->increment('tentativas');

        try {
            $id = $enviador->enviarTemplate($telefone, $envio->etapa->template_meta, $envio->etapa->idioma, [
                $cobranca->cliente->nome,
                'R$ '.number_format((float) $cobranca->valor, 2, ',', '.'),
                $cobranca->vencimento->format('d/m/Y'),
                $cobranca->link_pagamento,
            ]);
        } catch (ErroDefinitivoWhatsApp $e) {
            $envio->update(['status' => 'falhou', 'erro' => $e->getMessage()]);

            return;
        }

        $envio->update(['status' => 'enviada', 'meta_message_id' => $id, 'enviado_em' => now(), 'erro' => null]);
    }

    public function failed(?Throwable $erro): void
    {
        Envio::whereKey($this->envioId)
            ->where('status', 'pendente')
            ->update(['status' => 'falhou', 'erro' => $erro?->getMessage() ?? 'Falha desconhecida.']);
    }
}
