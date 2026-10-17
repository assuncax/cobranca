<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class SeletorEtapa
{
    /**
     * Decide qual etapa da régua uma cobrança deve receber hoje.
     *
     * @param  list<array{id: int, dias: int, ativa: bool}>  $etapas  todas as etapas da régua
     * @param  list<int>  $idsRegistrados  etapas que já têm registro em envios para esta cobrança
     * @return array{enviar: ?int, pular: list<int>}
     */
    public static function selecionar(CarbonImmutable $vencimento, CarbonImmutable $hoje, array $etapas, array $idsRegistrados): array
    {
        $registrados = array_map('intval', $idsRegistrados);
        $diasRegistrados = array_column(array_filter($etapas, fn ($e) => in_array($e['id'], $registrados, true)), 'dias');
        $limite = $diasRegistrados ? max($diasRegistrados) : PHP_INT_MIN;
        $hoje = $hoje->startOfDay();
        $vencimento = $vencimento->startOfDay();

        $candidatas = array_values(array_filter($etapas, fn ($e) => $e['ativa']
            && ! in_array($e['id'], $registrados, true)
            && $e['dias'] > $limite
            && $vencimento->addDays($e['dias'])->lessThanOrEqualTo($hoje)
        ));

        if (! $candidatas) {
            return ['enviar' => null, 'pular' => []];
        }

        usort($candidatas, fn ($a, $b) => $a['dias'] <=> $b['dias']);
        $ultima = array_pop($candidatas);

        return ['enviar' => $ultima['id'], 'pular' => array_column($candidatas, 'id')];
    }
}
