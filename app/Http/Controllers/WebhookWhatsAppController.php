<?php

namespace App\Http\Controllers;

use App\Mail\MensagemRecebidaMail;
use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\MensagemRecebida;
use App\Support\Telefone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WebhookWhatsAppController extends Controller
{
    private const STATUS_META = ['sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'];

    private const ORDEM = ['pendente' => 0, 'enviada' => 1, 'entregue' => 2, 'lida' => 3];

    public function verificar(Request $request): Response
    {
        $token = (string) Configuracao::get('meta_verify_token');

        if ($request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        return response('Forbidden', 403);
    }

    public function receber(Request $request): Response
    {
        $segredo = (string) Configuracao::get('meta_app_secret');
        $esperada = 'sha256='.hash_hmac('sha256', $request->getContent(), $segredo);

        if ($segredo === '' || ! hash_equals($esperada, (string) $request->header('X-Hub-Signature-256'))) {
            Log::warning('Webhook do WhatsApp com assinatura inválida.');

            return response('Forbidden', 403);
        }

        foreach ($request->input('entry', []) as $entrada) {
            foreach ($entrada['changes'] ?? [] as $mudanca) {
                foreach ($mudanca['value']['statuses'] ?? [] as $status) {
                    $this->atualizarStatus($status);
                }
                foreach ($mudanca['value']['messages'] ?? [] as $mensagem) {
                    $this->registrarMensagem($mensagem);
                }
            }
        }

        return response('OK', 200);
    }

    private function atualizarStatus(array $status): void
    {
        $novo = self::STATUS_META[$status['status'] ?? ''] ?? null;
        $envio = $novo ? Envio::where('meta_message_id', $status['id'] ?? '')->first() : null;
        if (! $envio) {
            return;
        }

        if ($novo === 'falhou') {
            $envio->update(['status' => 'falhou', 'erro' => $status['errors'][0]['title'] ?? 'Falha informada pela Meta.']);

            return;
        }

        if (self::ORDEM[$novo] > (self::ORDEM[$envio->status] ?? PHP_INT_MAX)) {
            $envio->update(['status' => $novo]);
        }
    }

    private function registrarMensagem(array $dados): void
    {
        $telefone = Telefone::normalizar($dados['from'] ?? '') ?? '+'.($dados['from'] ?? '');
        $cliente = Cliente::whereIn('telefone', Telefone::variantes($telefone))->first();

        $campos = [
            'telefone' => $cliente?->telefone ?? $telefone,
            'cliente_id' => $cliente?->id,
            'texto' => $dados['text']['body'] ?? '['.($dados['type'] ?? 'mensagem').']',
            'recebida_em' => isset($dados['timestamp']) ? Carbon::createFromTimestamp((int) $dados['timestamp'], config('app.timezone')) : now(),
        ];

        // A Meta reenvia o webhook se não receber 200 a tempo; o id da mensagem evita duplicar.
        $mensagem = isset($dados['id'])
            ? MensagemRecebida::firstOrCreate(['meta_message_id' => $dados['id']], $campos)
            : MensagemRecebida::create($campos);

        if ($mensagem->wasRecentlyCreated && $email = Configuracao::get('email_avisos')) {
            Mail::to($email)->queue(new MensagemRecebidaMail($mensagem));
        }
    }
}
