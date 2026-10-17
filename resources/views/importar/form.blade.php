@extends('layout')

@section('titulo', 'Importar planilha')

@section('conteudo')
    <h2>Importar planilha</h2>
    <p>
        Envie um arquivo <strong>.xlsx</strong> ou <strong>.csv</strong> com a primeira linha contendo exatamente estas colunas:
        <code>nome</code>, <code>telefone</code>, <code>valor</code>, <code>vencimento</code>, <code>link_pagamento</code>.
        Datas no formato dd/mm/aaaa. Cobranças já existentes (mesmo cliente, vencimento e valor) não são duplicadas.
    </p>
    <form method="post" action="{{ route('importar.previa') }}" enctype="multipart/form-data">
        @csrf
        <input type="file" name="arquivo" accept=".xlsx,.csv" required>
        @error('arquivo')<small class="falhou">{{ $message }}</small>@enderror
        <button type="submit">Ver prévia</button>
    </form>
@endsection
