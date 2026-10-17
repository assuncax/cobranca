<?php

namespace Tests\Feature;

use App\Mail\MensagemRecebidaMail;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use App\Models\MensagemRecebida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Configuracao::set('meta_app_secret', 'segredo');
        Configuracao::set('meta_verify_token', 'verifica');
    }

    private function postar(array $payload, string $segredo = 'segredo'): TestResponse
    {
        $corpo = json_encode($payload);

        return $this->call('POST', '/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, $segredo),
        ], $corpo);
    }

    private function payload(array $valor): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]];
    }

    private function envio(string $status = 'enviada'): Envio
    {
        return Envio::create([
            'cobranca_id' => Cobranca::factory()->create()->id,
            'etapa_regua_id' => EtapaRegua::first()->id,
            'status' => $status,
            'meta_message_id' => 'wamid.1',
        ]);
    }

    public function test_verificacao_com_token_correto_devolve_challenge(): void
    {
        $this->get('/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=verifica&hub.challenge=987')
            ->assertOk()
            ->assertContent('987');
    }

    public function test_verificacao_com_token_errado_e_recusada(): void
    {
        $this->get('/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=987')->assertForbidden();
    }

    public function test_assinatura_invalida_e_recusada(): void
    {
        $envio = $this->envio();

        $this->postar($this->payload(['statuses' => [['id' => 'wamid.1', 'status' => 'delivered']]]), 'outro')->assertForbidden();

        $this->assertSame('enviada', $envio->fresh()->status);
    }

    public function test_status_avanca_e_nao_regride(): void
    {
        $envio = $this->envio();

        $this->postar($this->payload(['statuses' => [['id' => 'wamid.1', 'status' => 'read']]]))->assertOk();
        $this->postar($this->payload(['statuses' => [['id' => 'wamid.1', 'status' => 'delivered']]]))->assertOk();

        $this->assertSame('lida', $envio->fresh()->status);
    }

    public function test_status_failed_marca_falhou_com_motivo(): void
    {
        $envio = $this->envio();

        $this->postar($this->payload(['statuses' => [[
            'id' => 'wamid.1', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]]]))->assertOk();

        $this->assertSame('falhou', $envio->fresh()->status);
        $this->assertSame('Message undeliverable', $envio->fresh()->erro);
    }

    public function test_resposta_do_cliente_e_registrada_e_avisada_por_email(): void
    {
        Mail::fake();
        Configuracao::set('email_avisos', 'dono@exemplo.com');
        $cliente = Cliente::factory()->create(['telefone' => '+5511999998888']);

        $this->postar($this->payload(['messages' => [[
            'from' => '551199998888', 'id' => 'wamid.in', 'timestamp' => '1792500000', 'type' => 'text', 'text' => ['body' => 'Já paguei'],
        ]]]))->assertOk();

        $mensagem = MensagemRecebida::sole();
        $this->assertSame($cliente->id, $mensagem->cliente_id);
        $this->assertSame('Já paguei', $mensagem->texto);
        Mail::assertSent(MensagemRecebidaMail::class, fn ($m) => $m->hasTo('dono@exemplo.com'));
    }
}
