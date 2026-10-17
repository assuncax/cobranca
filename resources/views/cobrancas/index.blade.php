@extends('layout')

@section('titulo', 'Cobranças')

@section('conteudo')
    <h2>Cobranças</h2>

    <p>
        @foreach (['abertas' => 'Abertas', 'a_vencer' => 'A vencer', 'vencidas' => 'Vencidas', 'pagas' => 'Pagas', 'canceladas' => 'Canceladas'] as $chave => $rotulo)
            <a href="{{ route('cobrancas.index', ['filtro' => $chave]) }}" @if ($filtro === $chave) aria-current="page" @endif>{{ $rotulo }}</a>@if (! $loop->last) · @endif
        @endforeach
        &nbsp;&nbsp;<a href="{{ route('cobrancas.create') }}" role="button">Nova cobrança</a>
    </p>

    <table>
        <thead>
            <tr><th>Cliente</th><th>Telefone</th><th>Valor</th><th>Vencimento</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($cobrancas as $cobranca)
                @php($vencida = $cobranca->status === 'aberta' && $cobranca->vencimento->isPast() && ! $cobranca->vencimento->isToday())
                <tr>
                    <td>{{ $cobranca->cliente->nome }}</td>
                    <td>{{ $cobranca->cliente->telefone }}</td>
                    <td>R$ {{ number_format((float) $cobranca->valor, 2, ',', '.') }}</td>
                    <td>{{ $cobranca->vencimento->format('d/m/Y') }}</td>
                    <td @class(['falhou' => $vencida])>{{ $vencida ? 'vencida' : $cobranca->status }}</td>
                    <td>
                        <a href="{{ route('cobrancas.edit', $cobranca) }}">Editar</a>
                        @if ($cobranca->status === 'aberta')
                            <form method="post" action="{{ route('cobrancas.pagar', $cobranca) }}">@csrf<button>Pago</button></form>
                            <form method="post" action="{{ route('cobrancas.cancelar', $cobranca) }}" onsubmit="return confirm('Cancelar esta cobrança?')">@csrf<button class="secondary">Cancelar</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">Nenhuma cobrança encontrada.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{ $cobrancas->links('pagination::simple-default') }}
@endsection
