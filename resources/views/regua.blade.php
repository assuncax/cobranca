@extends('layout')

@section('titulo', 'Régua')

@section('conteudo')
    <h2>Régua de cobrança</h2>
    <p>Dias relativos ao vencimento (negativo = antes). O nome do modelo deve ser idêntico ao aprovado na Meta.</p>

    <form method="post" action="{{ route('regua.update') }}">
        @csrf
        @method('PUT')
        <table>
            <thead><tr><th>Ativa</th><th>Dias</th><th>Modelo na Meta</th><th>Idioma</th></tr></thead>
            <tbody>
                @foreach ($etapas as $etapa)
                    <tr>
                        <td><input type="checkbox" name="etapas[{{ $etapa->id }}][ativa]" value="1" @checked(old("etapas.{$etapa->id}.ativa", $etapa->ativa))></td>
                        <td><input type="number" name="etapas[{{ $etapa->id }}][dias]" value="{{ old("etapas.{$etapa->id}.dias", $etapa->dias) }}" required></td>
                        <td>
                            <input name="etapas[{{ $etapa->id }}][template_meta]" value="{{ old("etapas.{$etapa->id}.template_meta", $etapa->template_meta) }}" required>
                            @error("etapas.{$etapa->id}.template_meta")<small class="falhou">Use apenas letras minúsculas, números e _</small>@enderror
                        </td>
                        <td><input name="etapas[{{ $etapa->id }}][idioma]" value="{{ old("etapas.{$etapa->id}.idioma", $etapa->idioma) }}" required></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <button type="submit">Salvar régua</button>
    </form>
@endsection
