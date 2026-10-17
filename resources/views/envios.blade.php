@extends('layout')

@section('titulo', 'Histórico de envios')

@section('conteudo')
    <h2>Histórico de envios</h2>

    <p>
        <a href="{{ route('envios.index') }}">Todos</a>
        @foreach (['pendente', 'enviada', 'entregue', 'lida', 'falhou', 'pulada'] as $s)
            · <a href="{{ route('envios.index', ['status' => $s]) }}" @if ($status === $s) aria-current="page" @endif>{{ $s }}</a>
        @endforeach
    </p>

    <table>
        <thead><tr><th>Data</th><th>Cliente</th><th>Etapa</th><th>Status</th><th>Detalhe</th></tr></thead>
        <tbody>
            @forelse ($envios as $envio)
                <tr>
                    <td>{{ $envio->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $envio->cobranca->cliente->nome }}</td>
                    <td>{{ $envio->etapa->template_meta }} ({{ $envio->etapa->dias >= 0 ? '+' : '' }}{{ $envio->etapa->dias }} dias)</td>
                    <td @class(['falhou' => $envio->status === 'falhou'])>{{ $envio->status }}@if ($envio->teste) (teste)@endif</td>
                    <td @class(['falhou' => $envio->status === 'falhou'])>{{ $envio->erro }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Nenhum envio.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{ $envios->links('pagination::simple-default') }}

    <h3>Respostas recebidas</h3>
    <table>
        <thead><tr><th>Data</th><th>De</th><th>Mensagem</th></tr></thead>
        <tbody>
            @forelse ($mensagens as $mensagem)
                <tr>
                    <td>{{ $mensagem->recebida_em->format('d/m/Y H:i') }}</td>
                    <td>{{ $mensagem->cliente?->nome ?? $mensagem->telefone }}</td>
                    <td>{{ $mensagem->texto }}</td>
                </tr>
            @empty
                <tr><td colspan="3">Nenhuma resposta.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
