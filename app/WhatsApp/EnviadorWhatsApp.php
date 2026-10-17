<?php

namespace App\WhatsApp;

interface EnviadorWhatsApp
{
    /**
     * @param  list<string>  $parametros  valores de {{1}}, {{2}}… do corpo do template
     * @return string id da mensagem na Meta
     *
     * @throws ErroTemporarioWhatsApp vale tentar de novo depois
     * @throws ErroDefinitivoWhatsApp não adianta tentar de novo
     */
    public function enviarTemplate(string $telefone, string $template, string $idioma, array $parametros): string;
}
