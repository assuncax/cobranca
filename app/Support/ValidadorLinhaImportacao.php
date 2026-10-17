<?php

namespace App\Support;

final class ValidadorLinhaImportacao
{
    /**
     * @param  array<string, mixed>  $linha
     * @return array{dados: ?array{nome: string, telefone: string, valor: string, vencimento: string, link_pagamento: string}, erros: list<string>}
     */
    public static function validar(array $linha): array
    {
        $nome = trim((string) ($linha['nome'] ?? ''));
        $telefone = Telefone::normalizar((string) ($linha['telefone'] ?? ''));
        $valor = ParserValor::converter($linha['valor'] ?? null);
        $vencimento = ParserData::converter($linha['vencimento'] ?? null);
        $link = trim((string) ($linha['link_pagamento'] ?? ''));

        $erros = array_values(array_filter([
            $nome === '' ? 'nome vazio' : null,
            $telefone ? null : 'telefone inválido',
            $valor ? null : 'valor inválido',
            $vencimento ? null : 'vencimento inválido (use dd/mm/aaaa)',
            filter_var($link, FILTER_VALIDATE_URL) ? null : 'link_pagamento inválido',
        ]));

        if ($erros) {
            return ['dados' => null, 'erros' => $erros];
        }

        return [
            'dados' => [
                'nome' => $nome,
                'telefone' => $telefone,
                'valor' => $valor,
                'vencimento' => $vencimento->toDateString(),
                'link_pagamento' => $link,
            ],
            'erros' => [],
        ];
    }
}
