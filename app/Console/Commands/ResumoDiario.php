<?php

namespace App\Console\Commands;

use App\Mail\ResumoDiarioMail;
use App\Models\Configuracao;
use App\Models\Envio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ResumoDiario extends Command
{
    protected $signature = 'regua:resumo';

    protected $description = 'Envia por e-mail o resumo dos envios de hoje';

    public function handle(): int
    {
        $email = Configuracao::get('email_avisos');
        $envios = Envio::with('cobranca.cliente')
            ->whereDate('created_at', today())
            ->where('status', '!=', 'pulada')
            ->get();

        if (! $email || $envios->isEmpty()) {
            $this->info('Nada a enviar.');

            return self::SUCCESS;
        }

        Mail::to($email)->send(new ResumoDiarioMail($envios));
        $this->info('Resumo enviado.');

        return self::SUCCESS;
    }
}
