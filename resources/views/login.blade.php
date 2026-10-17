@extends('layout')

@section('titulo', 'Entrar')

@section('conteudo')
    <article style="max-width:420px;margin:4rem auto">
        <h2>Entrar</h2>
        <form method="post" action="{{ url('/login') }}">
            @csrf
            <label>E-mail
                <input type="email" name="email" value="{{ old('email') }}" required autofocus>
            </label>
            @error('email')<small class="falhou">{{ $message }}</small>@enderror
            <label>Senha
                <input type="password" name="password" required>
            </label>
            <button type="submit">Entrar</button>
        </form>
    </article>
@endsection
