<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelasAdministrativasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_historico_mostra_falhas_com_motivo(): void
    {
        Envio::create([
            'cobranca_id' => Cobranca::factory()->create()->id,
            'etapa_regua_id' => EtapaRegua::first()->id,
            'status' => 'falhou',
            'erro' => 'número sem WhatsApp',
        ]);

        $this->get('/envios')->assertOk()->assertSee('número sem WhatsApp');
        $this->get('/envios?status=enviada')->assertOk()->assertDontSee('número sem WhatsApp');
    }

    public function test_edita_regua(): void
    {
        $etapa = EtapaRegua::where('dias', 15)->first();

        $this->put('/regua', ['etapas' => [
            $etapa->id => ['dias' => 20, 'template_meta' => 'ultimo_aviso_v2', 'idioma' => 'pt_BR'],
        ]])->assertRedirect();

        $etapa->refresh();
        $this->assertSame(20, $etapa->dias);
        $this->assertSame('ultimo_aviso_v2', $etapa->template_meta);
        $this->assertFalse($etapa->ativa);
    }

    public function test_regua_recusa_nome_de_template_invalido(): void
    {
        $etapa = EtapaRegua::first();

        $this->put('/regua', ['etapas' => [
            $etapa->id => ['dias' => -3, 'template_meta' => 'Nome Com Espaço', 'idioma' => 'pt_BR', 'ativa' => '1'],
        ]])->assertSessionHasErrors("etapas.{$etapa->id}.template_meta");
    }

    public function test_salva_configuracoes_e_mantem_segredo_em_branco(): void
    {
        Configuracao::set('meta_token', 'token-antigo');

        $this->put('/configuracoes', [
            'meta_token' => '',
            'meta_phone_number_id' => '123456789',
            'meta_api_versao' => 'v23.0',
            'email_avisos' => 'dono@exemplo.com',
            'numero_teste' => '(11) 90000-0000',
            'modo_teste' => '1',
        ])->assertRedirect();

        $this->assertSame('token-antigo', Configuracao::get('meta_token'));
        $this->assertSame('123456789', Configuracao::get('meta_phone_number_id'));
        $this->assertSame('+5511900000000', Configuracao::get('numero_teste'));
        $this->assertTrue(Configuracao::modoTeste());

        $this->get('/configuracoes')->assertOk()->assertDontSee('token-antigo')->assertSee('/webhook/whatsapp');
    }

    public function test_modo_teste_exige_numero_de_teste(): void
    {
        $this->put('/configuracoes', ['modo_teste' => '1'])->assertSessionHasErrors('numero_teste');

        $this->assertFalse(Configuracao::modoTeste());
    }
}
