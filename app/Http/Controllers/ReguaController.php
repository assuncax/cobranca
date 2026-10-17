<?php

namespace App\Http\Controllers;

use App\Models\EtapaRegua;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReguaController extends Controller
{
    public function edit(): View
    {
        return view('regua', ['etapas' => EtapaRegua::orderBy('ordem')->get()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'etapas' => 'required|array',
            'etapas.*.dias' => 'required|integer|between:-60,365',
            'etapas.*.template_meta' => ['required', 'regex:/^[a-z0-9_]+$/'],
            'etapas.*.idioma' => 'required|string|max:10',
        ]);

        foreach (EtapaRegua::all() as $etapa) {
            $campos = $dados['etapas'][$etapa->id] ?? null;
            if ($campos) {
                $etapa->update([...$campos, 'ativa' => $request->boolean("etapas.{$etapa->id}.ativa")]);
            }
        }

        return back()->with('ok', 'Régua salva.');
    }
}
