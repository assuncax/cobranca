<?php

namespace Tests\Fakes;

use App\WhatsApp\EnviadorWhatsApp;
use Throwable;

final class FakeWhatsApp implements EnviadorWhatsApp
{
    /** @var list<array{telefone: string, template: string, idioma: string, parametros: list<string>}> */
    public array $enviados = [];

    public ?Throwable $erro = null;

    public function enviarTemplate(string $telefone, string $template, string $idioma, array $parametros): string
    {
        if ($this->erro) {
            throw $this->erro;
        }

        $this->enviados[] = compact('telefone', 'template', 'idioma', 'parametros');

        return 'wamid.fake'.count($this->enviados);
    }
}
