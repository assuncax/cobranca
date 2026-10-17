@extends('layout')

@section('titulo', $cobranca ? 'Editar cobrança' : 'Nova cobrança')

@section('conteudo')
    <h2>{{ $cobranca ? 'Editar cobrança' : 'Nova cobrança' }}</h2>

    <form method="post" action="{{ $cobranca ? route('cobrancas.update', $cobranca) : route('cobrancas.store') }}">
        @csrf
        @if ($cobranca) @method('PUT') @endif

        <label>Nome do cliente
            <input name="nome" value="{{ old('nome', $cobranca?->cliente->nome) }}" required>
        </label>
        @error('nome')<small class="falhou">{{ $message }}</small>@enderror

        <label>Telefone (WhatsApp)
            <input name="telefone" value="{{ old('telefone', $cobranca?->cliente->telefone) }}" placeholder="(11) 99999-8888" required>
        </label>
        @error('telefone')<small class="falhou">{{ $message }}</small>@enderror

        <label>Valor (R$)
            <input name="valor" value="{{ old('valor', $cobranca ? number_format((float) $cobranca->valor, 2, ',', '.') : '') }}" placeholder="150,00" required>
        </label>
        @error('valor')<small class="falhou">{{ $message }}</small>@enderror

        <label>Vencimento
            <input type="date" name="vencimento" value="{{ old('vencimento', $cobranca?->vencimento->format('Y-m-d')) }}" required>
        </label>
        @error('vencimento')<small class="falhou">{{ $message }}</small>@enderror

        <label>Link de pagamento (boleto/Pix)
            <input type="url" name="link_pagamento" value="{{ old('link_pagamento', $cobranca?->link_pagamento) }}" required>
        </label>
        @error('link_pagamento')<small class="falhou">{{ $message }}</small>@enderror

        <button type="submit">Salvar</button>
    </form>
@endsection
