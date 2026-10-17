<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\EtapaRegua;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelosTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_cria_regua_padrao(): void
    {
        $etapas = EtapaRegua::orderBy('ordem')->get();

        $this->assertSame([-3, 0, 3, 7, 15], $etapas->pluck('dias')->all());
        $this->assertSame('cobranca_lembrete_3dias', $etapas[0]->template_meta);
        $this->assertSame('cobranca_ultimo_aviso', $etapas[4]->template_meta);
        $this->assertTrue($etapas->every(fn ($e) => $e->ativa && $e->idioma === 'pt_BR'));
    }

    public function test_registrar_cria_cliente_e_cobranca_sem_duplicar(): void
    {
        $dados = [
            'nome' => 'Maria',
            'telefone' => '+5511999998888',
            'valor' => '150.00',
            'vencimento' => '2026-10-23',
            'link_pagamento' => 'https://pag.exemplo/1',
        ];

        $primeira = Cobranca::registrar($dados);
        $segunda = Cobranca::registrar([...$dados, 'nome' => 'Maria Silva']);

        $this->assertTrue($primeira->wasRecentlyCreated);
        $this->assertFalse($segunda->wasRecentlyCreated);
        $this->assertSame(1, Cobranca::count());
        $this->assertSame('Maria Silva', $primeira->cliente->fresh()->nome);
        $this->assertSame('aberta', $primeira->fresh()->status);
        $this->assertSame('2026-10-23', $primeira->fresh()->vencimento->toDateString());
    }

    public function test_configuracao_guarda_valor_criptografado(): void
    {
        Configuracao::set('meta_token', 'segredo-123');

        $this->assertSame('segredo-123', Configuracao::get('meta_token'));
        $this->assertNotSame('segredo-123', DB::table('configuracoes')->where('chave', 'meta_token')->value('valor'));
        $this->assertNull(Configuracao::get('numero_teste'));
        $this->assertFalse(Configuracao::modoTeste());

        Configuracao::set('modo_teste', '1');
        $this->assertTrue(Configuracao::modoTeste());
    }
}
