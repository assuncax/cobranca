<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrancaTelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $this->actingAs(User::factory()->create());
    }

    public function test_inicio_redireciona_para_cobrancas(): void
    {
        $this->get('/')->assertRedirect(route('cobrancas.index'));
    }

    public function test_lista_filtra_vencidas(): void
    {
        Cobranca::factory()->for(Cliente::factory()->state(['nome' => 'Atrasado da Silva']))->create(['vencimento' => '2026-10-10']);
        Cobranca::factory()->for(Cliente::factory()->state(['nome' => 'Em Dia Souza']))->create(['vencimento' => '2026-10-30']);

        $this->get('/cobrancas')->assertOk()->assertSee('Atrasado da Silva')->assertSee('Em Dia Souza');
        $this->get('/cobrancas?filtro=vencidas')->assertSee('Atrasado da Silva')->assertDontSee('Em Dia Souza');
    }

    public function test_cadastra_cobranca_normalizando_telefone_e_valor(): void
    {
        $this->post('/cobrancas', [
            'nome' => 'Maria',
            'telefone' => '(11) 99999-8888',
            'valor' => '1.234,56',
            'vencimento' => '2026-10-23',
            'link_pagamento' => 'https://pag.exemplo/1',
        ])->assertRedirect(route('cobrancas.index'));

        $cobranca = Cobranca::with('cliente')->sole();
        $this->assertSame('+5511999998888', $cobranca->cliente->telefone);
        $this->assertSame('1234.56', $cobranca->valor);
        $this->assertSame('aberta', $cobranca->status);
    }

    public function test_recusa_telefone_invalido(): void
    {
        $this->post('/cobrancas', [
            'nome' => 'Maria',
            'telefone' => '9999',
            'valor' => '10',
            'vencimento' => '2026-10-23',
            'link_pagamento' => 'https://pag.exemplo/1',
        ])->assertSessionHasErrors('telefone');

        $this->assertSame(0, Cobranca::count());
    }

    public function test_edita_cobranca(): void
    {
        $cobranca = Cobranca::factory()->create();

        $this->put("/cobrancas/{$cobranca->id}", [
            'nome' => 'Novo Nome',
            'telefone' => $cobranca->cliente->telefone,
            'valor' => '200,00',
            'vencimento' => '2026-11-05',
            'link_pagamento' => 'https://pag.exemplo/novo',
        ])->assertRedirect(route('cobrancas.index'));

        $cobranca->refresh();
        $this->assertSame('200.00', $cobranca->valor);
        $this->assertSame('2026-11-05', $cobranca->vencimento->toDateString());
        $this->assertSame('Novo Nome', $cobranca->cliente->nome);
    }

    public function test_marca_como_paga_e_cancela(): void
    {
        $paga = Cobranca::factory()->create();
        $cancelada = Cobranca::factory()->create();

        $this->post("/cobrancas/{$paga->id}/pagar")->assertRedirect();
        $this->post("/cobrancas/{$cancelada->id}/cancelar")->assertRedirect();

        $this->assertSame('paga', $paga->fresh()->status);
        $this->assertNotNull($paga->fresh()->pago_em);
        $this->assertSame('cancelada', $cancelada->fresh()->status);
    }
}
