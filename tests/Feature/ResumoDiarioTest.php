<?php

namespace Tests\Feature;

use App\Mail\ResumoDiarioMail;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResumoDiarioTest extends TestCase
{
    use RefreshDatabase;

    private function envio(string $status, ?string $erro = null): void
    {
        Envio::create([
            'cobranca_id' => Cobranca::factory()->create()->id,
            'etapa_regua_id' => EtapaRegua::first()->id,
            'status' => $status,
            'erro' => $erro,
        ]);
    }

    public function test_envia_resumo_com_totais_e_falhas(): void
    {
        Mail::fake();
        Configuracao::set('email_avisos', 'dono@exemplo.com');
        $this->envio('enviada');
        $this->envio('entregue');
        $this->envio('falhou', 'número sem WhatsApp');
        $this->envio('pulada');

        $this->artisan('regua:resumo')->assertSuccessful();

        Mail::assertSent(ResumoDiarioMail::class, function (ResumoDiarioMail $mail) {
            $mail->assertSeeInHtml('Enviadas: 2');
            $mail->assertSeeInHtml('Falhas: 1');
            $mail->assertSeeInHtml('número sem WhatsApp');

            return $mail->hasTo('dono@exemplo.com') && $mail->envios->count() === 3;
        });
    }

    public function test_nao_envia_sem_movimento(): void
    {
        Mail::fake();
        Configuracao::set('email_avisos', 'dono@exemplo.com');

        $this->artisan('regua:resumo')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
