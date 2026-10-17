<?php

namespace App\Mail;

use App\Models\MensagemRecebida;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MensagemRecebidaMail extends Mailable
{
    public function __construct(public MensagemRecebida $mensagem) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Resposta de cliente no WhatsApp');
    }

    public function content(): Content
    {
        $nome = $this->mensagem->cliente?->nome ?? 'Número não cadastrado';

        return new Content(htmlString: sprintf(
            '<p><strong>%s</strong> (%s) respondeu:</p><blockquote>%s</blockquote>',
            e($nome), e($this->mensagem->telefone), nl2br(e($this->mensagem->texto)),
        ));
    }
}
