<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensagem;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use App\WhatsApp\EnviadorWhatsApp;
use App\WhatsApp\ErroDefinitivoWhatsApp;
use App\WhatsApp\ErroTemporarioWhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeWhatsApp;
use Tests\TestCase;

class ExecutarReguaTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsApp $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whatsapp = new FakeWhatsApp;
        $this->app->instance(EnviadorWhatsApp::class, $this->whatsapp);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00')); // terça-feira
    }

    private function cobranca(string $vencimento, string $status = 'aberta'): Cobranca
    {
        $cliente = Cliente::factory()->create(['nome' => 'Maria', 'telefone' => '+5511999998888']);

        return Cobranca::factory()->create([
            'cliente_id' => $cliente->id,
            'vencimento' => $vencimento,
            'valor' => '1234.50',
            'link_pagamento' => 'https://pag.exemplo/1',
            'status' => $status,
        ]);
    }

    public function test_envia_a_etapa_do_dia_com_parametros_formatados(): void
    {
        $cobranca = $this->cobranca('2026-10-23');

        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertCount(1, $this->whatsapp->enviados);
        $this->assertSame([
            'telefone' => '+5511999998888',
            'template' => 'cobranca_lembrete_3dias',
            'idioma' => 'pt_BR',
            'parametros' => ['Maria', 'R$ 1.234,50', '23/10/2026', 'https://pag.exemplo/1'],
        ], $this->whatsapp->enviados[0]);

        $envio = Envio::sole();
        $this->assertSame($cobranca->id, $envio->cobranca_id);
        $this->assertSame('enviada', $envio->status);
        $this->assertSame('wamid.fake1', $envio->meta_message_id);
        $this->assertNotNull($envio->enviado_em);
    }

    public function test_rodar_duas_vezes_nao_duplica(): void
    {
        $this->cobranca('2026-10-23');

        $this->artisan('regua:executar')->assertSuccessful();
        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertCount(1, $this->whatsapp->enviados);
        $this->assertSame(1, Envio::count());
    }

    public function test_cobranca_paga_nao_recebe_mensagem(): void
    {
        $this->cobranca('2026-10-23', 'paga');

        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertCount(0, $this->whatsapp->enviados);
    }

    public function test_cobranca_atrasada_recebe_so_a_etapa_mais_recente(): void
    {
        $this->cobranca('2026-10-12');

        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertSame(['cobranca_atraso_7dias'], array_column($this->whatsapp->enviados, 'template'));
        $this->assertSame(3, Envio::where('status', 'pulada')->count());
    }

    public function test_modo_teste_envia_para_numero_de_teste_e_nao_trava_envio_real(): void
    {
        $this->cobranca('2026-10-23');
        Configuracao::set('modo_teste', '1');
        Configuracao::set('numero_teste', '+5511900000000');

        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertSame('+5511900000000', $this->whatsapp->enviados[0]['telefone']);
        $this->assertSame('Maria', $this->whatsapp->enviados[0]['parametros'][0]);
        $this->assertTrue(Envio::sole()->teste);

        Configuracao::set('modo_teste', '0');
        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertSame('+5511999998888', $this->whatsapp->enviados[1]['telefone']);
        $this->assertSame(2, Envio::count());
    }

    public function test_erro_definitivo_marca_falhou(): void
    {
        $this->cobranca('2026-10-23');
        $this->whatsapp->erro = new ErroDefinitivoWhatsApp('número sem WhatsApp');

        $this->artisan('regua:executar')->assertSuccessful();

        $envio = Envio::sole();
        $this->assertSame('falhou', $envio->status);
        $this->assertSame('número sem WhatsApp', $envio->erro);
    }

    public function test_job_nao_envia_se_cobranca_foi_paga_depois_de_agendada(): void
    {
        $cobranca = $this->cobranca('2026-10-23');
        $envio = Envio::create(['cobranca_id' => $cobranca->id, 'etapa_regua_id' => EtapaRegua::first()->id, 'status' => 'pendente']);
        $cobranca->update(['status' => 'paga']);

        (new EnviarMensagem($envio->id))->handle($this->whatsapp);

        $this->assertCount(0, $this->whatsapp->enviados);
        $this->assertSame('pulada', $envio->fresh()->status);
    }

    public function test_erro_temporario_mantem_pendente_e_failed_marca_falhou(): void
    {
        $cobranca = $this->cobranca('2026-10-23');
        $envio = Envio::create(['cobranca_id' => $cobranca->id, 'etapa_regua_id' => EtapaRegua::first()->id, 'status' => 'pendente']);
        $this->whatsapp->erro = new ErroTemporarioWhatsApp('timeout');

        try {
            (new EnviarMensagem($envio->id))->handle($this->whatsapp);
            $this->fail('Deveria lançar ErroTemporarioWhatsApp');
        } catch (ErroTemporarioWhatsApp) {
        }

        $this->assertSame('pendente', $envio->fresh()->status);
        $this->assertSame(1, $envio->fresh()->tentativas);

        (new EnviarMensagem($envio->id))->failed(new ErroTemporarioWhatsApp('timeout'));

        $this->assertSame('falhou', $envio->fresh()->status);
        $this->assertSame('timeout', $envio->fresh()->erro);
    }
}
