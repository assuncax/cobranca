<?php

namespace Tests\Feature;

use App\WhatsApp\ErroDefinitivoWhatsApp;
use App\WhatsApp\ErroTemporarioWhatsApp;
use App\WhatsApp\MetaWhatsApp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWhatsAppTest extends TestCase
{
    private function enviar(): string
    {
        return (new MetaWhatsApp('tok', '123', 'v23.0'))
            ->enviarTemplate('+5511999998888', 'cobranca_vence_hoje', 'pt_BR', ['Maria', 'R$ 10,00', '23/10/2026', 'https://pag/1']);
    }

    public function test_envia_template_e_retorna_id(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);

        $this->assertSame('wamid.ABC', $this->enviar());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v23.0/123/messages'
            && $r->hasHeader('Authorization', 'Bearer tok')
            && $r['to'] === '5511999998888'
            && $r['type'] === 'template'
            && $r['template']['name'] === 'cobranca_vence_hoje'
            && $r['template']['language']['code'] === 'pt_BR'
            && $r['template']['components'][0]['parameters'][1] === ['type' => 'text', 'text' => 'R$ 10,00']);
    }

    public function test_erro_do_servidor_e_temporario(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 1, 'message' => 'oops']], 500)]);

        $this->expectException(ErroTemporarioWhatsApp::class);
        $this->enviar();
    }

    public function test_limite_de_taxa_e_temporario(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 130429, 'message' => 'rate']], 400)]);

        $this->expectException(ErroTemporarioWhatsApp::class);
        $this->enviar();
    }

    public function test_numero_invalido_e_definitivo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400)]);

        $this->expectException(ErroDefinitivoWhatsApp::class);
        $this->expectExceptionMessage('Message undeliverable');
        $this->enviar();
    }
}
