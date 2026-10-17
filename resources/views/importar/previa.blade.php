@extends('layout')

@section('titulo', 'Prévia da importação')

@section('conteudo')
    <h2>Prévia da importação</h2>
    <p><strong>{{ $validas }} linha(s) válida(s)</strong> de {{ count($resultado) }}. Linhas com erro serão ignoradas.</p>

    <table>
        <thead><tr><th>Linha</th><th>Conteúdo</th><th>Situação</th></tr></thead>
        <tbody>
            @foreach ($resultado as $linha)
                <tr>
                    <td>{{ $linha['numero'] }}</td>
                    <td>{{ $linha['original'] }}</td>
                    <td @class(['falhou' => $linha['erros'], 'ok' => ! $linha['erros']])>
                        {{ $linha['erros'] ? implode(', ', $linha['erros']) : 'ok' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($validas > 0)
        <form method="post" action="{{ route('importar.confirmar') }}">
            @csrf
            <button type="submit">Importar {{ $validas }} cobrança(s)</button>
        </form>
    @endif
    <a href="{{ route('importar.form') }}">Enviar outro arquivo</a>
@endsection
