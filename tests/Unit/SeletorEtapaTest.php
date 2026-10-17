<?php

namespace Tests\Unit;

use App\Support\SeletorEtapa;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SeletorEtapaTest extends TestCase
{
    private function etapas(array $inativas = []): array
    {
        $dias = [1 => -3, 2 => 0, 3 => 3, 4 => 7, 5 => 15];

        return array_map(
            fn ($id) => ['id' => $id, 'dias' => $dias[$id], 'ativa' => ! in_array($id, $inativas, true)],
            array_keys($dias),
        );
    }

    private function selecionar(string $vencimento, string $hoje, array $registrados = [], array $inativas = []): array
    {
        return SeletorEtapa::selecionar(
            CarbonImmutable::parse($vencimento),
            CarbonImmutable::parse($hoje),
            $this->etapas($inativas),
            $registrados,
        );
    }

    public function test_nada_antes_da_primeira_etapa(): void
    {
        $this->assertSame(['enviar' => null, 'pular' => []], $this->selecionar('2026-10-23', '2026-10-19'));
    }

    public function test_envia_lembrete_tres_dias_antes(): void
    {
        $this->assertSame(['enviar' => 1, 'pular' => []], $this->selecionar('2026-10-23', '2026-10-20'));
    }

    public function test_no_vencimento_com_lembrete_ja_enviado(): void
    {
        $this->assertSame(['enviar' => 2, 'pular' => []], $this->selecionar('2026-10-23', '2026-10-23', [1]));
    }

    public function test_no_vencimento_sem_nada_enviado_pula_lembrete(): void
    {
        $this->assertSame(['enviar' => 2, 'pular' => [1]], $this->selecionar('2026-10-23', '2026-10-23'));
    }

    public function test_cadastrada_com_oito_dias_de_atraso_envia_so_a_de_sete(): void
    {
        $this->assertSame(['enviar' => 4, 'pular' => [1, 2, 3]], $this->selecionar('2026-10-12', '2026-10-20'));
    }

    public function test_vencimento_no_sabado_envia_na_segunda(): void
    {
        $this->assertSame(['enviar' => 2, 'pular' => []], $this->selecionar('2026-10-24', '2026-10-26', [1]));
    }

    public function test_etapa_desativada_nao_e_enviada(): void
    {
        $this->assertSame(['enviar' => null, 'pular' => []], $this->selecionar('2026-10-23', '2026-10-23', [1], [2]));
    }

    public function test_nao_volta_para_etapa_anterior(): void
    {
        $this->assertSame(['enviar' => null, 'pular' => []], $this->selecionar('2026-10-12', '2026-10-20', [4]));
    }

    public function test_depois_da_ultima_etapa_nao_envia_mais(): void
    {
        $this->assertSame(['enviar' => null, 'pular' => []], $this->selecionar('2026-09-01', '2026-10-20', [1, 2, 3, 4, 5]));
    }
}
