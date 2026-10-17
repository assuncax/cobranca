<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Support\ParserValor;
use App\Support\Telefone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CobrancaController extends Controller
{
    public function index(Request $request): View
    {
        $filtro = $request->query('filtro', 'abertas');
        $consulta = Cobranca::with('cliente')->orderBy('vencimento');

        match ($filtro) {
            'a_vencer' => $consulta->where('status', 'aberta')->whereDate('vencimento', '>=', today()),
            'vencidas' => $consulta->where('status', 'aberta')->whereDate('vencimento', '<', today()),
            'pagas' => $consulta->where('status', 'paga'),
            'canceladas' => $consulta->where('status', 'cancelada'),
            default => $consulta->where('status', 'aberta'),
        };

        return view('cobrancas.index', [
            'cobrancas' => $consulta->paginate(50)->withQueryString(),
            'filtro' => $filtro,
        ]);
    }

    public function create(): View
    {
        return view('cobrancas.form', ['cobranca' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Cobranca::registrar($this->validar($request));

        return redirect()->route('cobrancas.index')->with('ok', 'Cobrança cadastrada.');
    }

    public function edit(Cobranca $cobranca): View
    {
        return view('cobrancas.form', ['cobranca' => $cobranca->load('cliente')]);
    }

    public function update(Request $request, Cobranca $cobranca): RedirectResponse
    {
        $dados = $this->validar($request);
        $cliente = Cliente::updateOrCreate(['telefone' => $dados['telefone']], ['nome' => $dados['nome']]);

        $cobranca->update([
            'cliente_id' => $cliente->id,
            'valor' => $dados['valor'],
            'vencimento' => $dados['vencimento'],
            'link_pagamento' => $dados['link_pagamento'],
        ]);

        return redirect()->route('cobrancas.index')->with('ok', 'Cobrança atualizada.');
    }

    public function pagar(Cobranca $cobranca): RedirectResponse
    {
        $cobranca->update(['status' => 'paga', 'pago_em' => now()]);

        return back()->with('ok', 'Cobrança marcada como paga.');
    }

    public function cancelar(Cobranca $cobranca): RedirectResponse
    {
        $cobranca->update(['status' => 'cancelada']);

        return back()->with('ok', 'Cobrança cancelada.');
    }

    /** @return array{nome: string, telefone: string, valor: string, vencimento: string, link_pagamento: string} */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'nome' => 'required|string|max:255',
            'telefone' => 'required|string',
            'valor' => 'required|string',
            'vencimento' => 'required|date',
            'link_pagamento' => 'required|url|max:500',
        ]);

        $telefone = Telefone::normalizar($dados['telefone']);
        $valor = ParserValor::converter($dados['valor']);
        $erros = array_filter([
            'telefone' => $telefone ? null : 'Telefone inválido. Use DDD + número, ex.: (11) 99999-8888.',
            'valor' => $valor ? null : 'Valor inválido. Ex.: 150,00',
        ]);

        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        return [...$dados, 'telefone' => $telefone, 'valor' => $valor];
    }
}
