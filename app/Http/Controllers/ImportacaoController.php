<?php

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Support\LeitorPlanilha;
use App\Support\ValidadorLinhaImportacao;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ImportacaoController extends Controller
{
    public function form(): View
    {
        return view('importar.form');
    }

    public function previa(Request $request): View
    {
        $request->validate(['arquivo' => 'required|file|max:5120']);
        $arquivo = $request->file('arquivo');
        $extensao = strtolower($arquivo->getClientOriginalExtension());

        if (! in_array($extensao, ['xlsx', 'csv', 'txt'], true)) {
            throw ValidationException::withMessages(['arquivo' => 'Envie um arquivo .xlsx ou .csv.']);
        }

        $resultado = [];
        foreach (LeitorPlanilha::ler($arquivo->getRealPath(), $extensao) as $i => $linha) {
            $resultado[] = [
                'numero' => $i + 2,
                'original' => implode(' | ', array_map(
                    fn ($v) => $v instanceof DateTimeInterface ? $v->format('d/m/Y') : (string) $v,
                    $linha,
                )),
            ] + ValidadorLinhaImportacao::validar($linha);
        }

        $validas = array_values(array_filter(array_column($resultado, 'dados')));
        $request->session()->put('importacao', $validas);

        return view('importar.previa', ['resultado' => $resultado, 'validas' => count($validas)]);
    }

    public function confirmar(Request $request): RedirectResponse
    {
        $linhas = $request->session()->pull('importacao', []);
        $novas = 0;

        foreach ($linhas as $dados) {
            if (Cobranca::registrar($dados)->wasRecentlyCreated) {
                $novas++;
            }
        }

        $existentes = count($linhas) - $novas;

        return redirect()->route('cobrancas.index')
            ->with('ok', "Importação concluída: {$novas} cobrança(s) nova(s), {$existentes} já existente(s).");
    }
}
