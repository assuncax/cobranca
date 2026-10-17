@extends('layout')

@section('titulo', 'Configurações')

@section('conteudo')
    <h2>Configurações</h2>

    <form method="post" action="{{ route('configuracoes.update') }}">
        @csrf
        @method('PUT')

        <h3>WhatsApp (Meta)</h3>
        <label>Token de acesso permanente
            <input type="password" name="meta_token" placeholder="{{ $segredosPreenchidos['meta_token'] ? '(mantido — preencha só para trocar)' : '' }}">
        </label>
        <label>Phone number ID
            <input name="meta_phone_number_id" value="{{ old('meta_phone_number_id', $valores['meta_phone_number_id']) }}">
        </label>
        @error('meta_phone_number_id')<small class="falhou">{{ $message }}</small>@enderror
        <label>App secret (para validar o webhook)
            <input type="password" name="meta_app_secret" placeholder="{{ $segredosPreenchidos['meta_app_secret'] ? '(mantido — preencha só para trocar)' : '' }}">
        </label>
        <label>Verify token do webhook (invente uma senha e use a mesma na Meta)
            <input name="meta_verify_token" value="{{ old('meta_verify_token', $valores['meta_verify_token']) }}">
        </label>
        <label>Versão da API
            <input name="meta_api_versao" value="{{ old('meta_api_versao', $valores['meta_api_versao'] ?? 'v23.0') }}">
        </label>
        @error('meta_api_versao')<small class="falhou">{{ $message }}</small>@enderror
        <p>URL do webhook para cadastrar na Meta: <code>{{ url('/webhook/whatsapp') }}</code></p>

        <h3>Avisos</h3>
        <label>E-mail para resumo diário e respostas de clientes
            <input type="email" name="email_avisos" value="{{ old('email_avisos', $valores['email_avisos']) }}">
        </label>
        @error('email_avisos')<small class="falhou">{{ $message }}</small>@enderror

        <h3>Modo teste</h3>
        <label>
            <input type="checkbox" role="switch" name="modo_teste" value="1" @checked(old('modo_teste', $valores['modo_teste'] === '1'))>
            Ligado — todas as mensagens vão para o número de teste
        </label>
        <label>Número de teste
            <input name="numero_teste" value="{{ old('numero_teste', $valores['numero_teste']) }}" placeholder="(11) 99999-8888">
        </label>
        @error('numero_teste')<small class="falhou">{{ $message }}</small>@enderror

        <button type="submit">Salvar</button>
    </form>
@endsection
