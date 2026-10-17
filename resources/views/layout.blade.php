<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo', 'Régua de Cobrança')</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2.0.6/css/pico.min.css">
    <style>
        .falhou { color: #c62828; font-weight: 600; }
        .ok { color: #2e7d32; }
        td form { display: inline; margin: 0; }
        td button { padding: .2rem .6rem; margin: 0; width: auto; }
    </style>
</head>
<body>
<main class="container">
    @auth
        <nav>
            <ul><li><strong>Régua de Cobrança</strong></li></ul>
            <ul>
                <li><a href="{{ url('/cobrancas') }}">Cobranças</a></li>
                <li><a href="{{ url('/importar') }}">Importar</a></li>
                <li><a href="{{ url('/envios') }}">Histórico</a></li>
                <li><a href="{{ url('/regua') }}">Régua</a></li>
                <li><a href="{{ url('/configuracoes') }}">Configurações</a></li>
                <li>
                    <form method="post" action="{{ route('logout') }}" style="margin:0">
                        @csrf
                        <button class="secondary outline" style="padding:.3rem .8rem">Sair</button>
                    </form>
                </li>
            </ul>
        </nav>
    @endauth

    @if (session('ok'))
        <article class="ok">{{ session('ok') }}</article>
    @endif

    @yield('conteudo')
</main>
</body>
</html>
