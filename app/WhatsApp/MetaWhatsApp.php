<?php

namespace App\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class MetaWhatsApp implements EnviadorWhatsApp
{
    /** Códigos da Meta que indicam limite de taxa ou indisponibilidade passageira. */
    private const CODIGOS_TEMPORARIOS = [1, 2, 4, 80007, 130429, 131016];

    public function __construct(
        private string $token,
        private string $phoneNumberId,
        private string $versao,
    ) {}

    public function enviarTemplate(string $telefone, string $template, string $idioma, array $parametros): string
    {
        try {
            $resposta = Http::withToken($this->token)->timeout(20)->post(
                "https://graph.facebook.com/{$this->versao}/{$this->phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($telefone, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $idioma],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $parametros),
                        ]],
                    ],
                ],
            );
        } catch (ConnectionException $e) {
            throw new ErroTemporarioWhatsApp('Falha de conexão com a Meta: '.$e->getMessage(), 0, $e);
        }

        if ($resposta->successful()) {
            $id = $resposta->json('messages.0.id');
            if (! $id) {
                throw new ErroTemporarioWhatsApp('Resposta da Meta sem id de mensagem.');
            }

            return $id;
        }

        $codigo = (int) $resposta->json('error.code');
        $texto = sprintf('Meta HTTP %d (código %d): %s', $resposta->status(), $codigo, $resposta->json('error.message') ?? $resposta->body());

        if ($resposta->serverError() || $resposta->status() === 429 || in_array($codigo, self::CODIGOS_TEMPORARIOS, true)) {
            throw new ErroTemporarioWhatsApp($texto);
        }

        throw new ErroDefinitivoWhatsApp($texto);
    }
}
