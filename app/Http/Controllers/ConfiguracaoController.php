<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Support\Telefone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfiguracaoController extends Controller
{
    private const SEGREDOS = ['meta_token', 'meta_app_secret'];

    public function edit(): View
    {
        $valores = [];
        foreach (Configuracao::CHAVES as $chave) {
            $valores[$chave] = in_array($chave, self::SEGREDOS, true) ? null : Configuracao::get($chave);
        }

        $segredosPreenchidos = [];
        foreach (self::SEGREDOS as $chave) {
            $segredosPreenchidos[$chave] = filled(Configuracao::get($chave));
        }

        return view('configuracoes', compact('valores', 'segredosPreenchidos'));
    }

    public function update(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'meta_token' => 'nullable|string',
            'meta_phone_number_id' => 'nullable|digits_between:5,30',
            'meta_app_secret' => 'nullable|string',
            'meta_verify_token' => 'nullable|string|max:255',
            'meta_api_versao' => ['nullable', 'regex:/^v\d+\.\d+$/'],
            'email_avisos' => 'nullable|email',
            'numero_teste' => 'nullable|string',
        ]);

        $modoTeste = $request->boolean('modo_teste');
        $numeroTeste = filled($dados['numero_teste'] ?? null) ? Telefone::normalizar($dados['numero_teste']) : null;

        if (filled($dados['numero_teste'] ?? null) && ! $numeroTeste) {
            throw ValidationException::withMessages(['numero_teste' => 'Número de teste inválido.']);
        }
        if ($modoTeste && ! $numeroTeste) {
            throw ValidationException::withMessages(['numero_teste' => 'Informe o número de teste para ligar o modo teste.']);
        }

        foreach ($dados as $chave => $valor) {
            if (in_array($chave, self::SEGREDOS, true) && blank($valor)) {
                continue;
            }
            Configuracao::set($chave, $chave === 'numero_teste' ? $numeroTeste : $valor);
        }
        Configuracao::set('modo_teste', $modoTeste ? '1' : '0');

        return back()->with('ok', 'Configurações salvas.');
    }
}
