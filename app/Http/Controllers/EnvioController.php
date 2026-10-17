<?php

namespace App\Http\Controllers;

use App\Models\Envio;
use App\Models\MensagemRecebida;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnvioController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        return view('envios', [
            'status' => $status,
            'envios' => Envio::with(['cobranca.cliente', 'etapa'])
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest()
                ->paginate(50)
                ->withQueryString(),
            'mensagens' => MensagemRecebida::with('cliente')->latest('recebida_em')->limit(20)->get(),
        ]);
    }
}
