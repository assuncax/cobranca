<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class ResumoDiarioMail extends Mailable
{
    public function __construct(public Collection $envios) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Régua de cobrança — resumo de '.today()->format('d/m/Y'));
    }

    public function content(): Content
    {
        $enviadas = $this->envios->whereIn('status', ['enviada', 'entregue', 'lida'])->count();
        $pendentes = $this->envios->where('status', 'pendente')->count();
        $falhas = $this->envios->where('status', 'falhou');

        $html = "<p>Enviadas: {$enviadas}<br>Pendentes: {$pendentes}<br>Falhas: {$falhas->count()}</p>";

        if ($falhas->isNotEmpty()) {
            $html .= '<h3>Falhas</h3><ul>';
            foreach ($falhas as $envio) {
                $html .= '<li>'.e($envio->cobranca->cliente->nome).' — '.e($envio->erro).'</li>';
            }
            $html .= '</ul>';
        }

        return new Content(htmlString: $html);
    }
}
