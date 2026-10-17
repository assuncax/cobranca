# Régua de Cobrança via WhatsApp — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** App web Laravel que envia lembretes de cobrança pelo WhatsApp (API oficial da Meta) seguindo uma régua de 5 etapas, alimentado por planilha e cadastro manual.

**Architecture:** Laravel monolítico com telas Blade (sem build de front-end), MySQL em produção / SQLite em dev e testes. Um comando agendado (`regua:executar`) decide qual etapa cada cobrança deve receber hoje (regra pura em `SeletorEtapa`) e enfileira um job por mensagem; o job chama a Meta através da interface `EnviadorWhatsApp`. Um webhook recebe status e respostas da Meta. O cron do hPanel roda `schedule:run` a cada minuto, que também processa a fila.

**Tech Stack:** PHP 8.3, Laravel 12, PHPUnit, OpenSpout 4 (leitura de .xlsx/.csv), Pico.css via CDN, Docker (apenas para o ambiente de desenvolvimento local).

**Spec:** `docs/superpowers/specs/2026-10-17-regua-cobranca-design.md`

## Global Constraints

- PHP 8.3; Laravel `^12.0`; produção na hospedagem Business da Hostinger (sem Docker, sem processos permanentes — tudo via cron).
- Banco de produção MySQL; testes em SQLite `:memory:`.
- Fuso: `America/Sao_Paulo`. Envios às **09:00 apenas em dias úteis (seg–sex)**.
- Régua padrão: dias **-3, 0, +3, +7, +15** com os templates `cobranca_lembrete_3dias`, `cobranca_vence_hoje`, `cobranca_atraso_3dias`, `cobranca_atraso_7dias`, `cobranca_ultimo_aviso`, idioma `pt_BR`.
- Parâmetros dos templates, sempre nesta ordem: `{{1}}` nome, `{{2}}` valor (`R$ 1.234,56`), `{{3}}` vencimento (`dd/mm/aaaa`), `{{4}}` link de pagamento.
- Telefones gravados em E.164 brasileiro: `+55` + DDD + número (ex.: `+5511999998888`).
- Status de cobrança: `aberta` | `paga` | `cancelada`. Status de envio: `pendente` | `enviada` | `entregue` | `lida` | `falhou` | `pulada`.
- Nunca enviar a mesma etapa duas vezes para a mesma cobrança (índice único `cobranca_id, etapa_regua_id, teste`).
- Erros temporários: até 3 novas tentativas (backoff 60s, 300s, 900s). Erros definitivos: `falhou` sem nova tentativa.
- Sem resposta automática a clientes; sem geração de boleto/Pix; sem baixa automática.
- Interface em português; código (classes, métodos, colunas) em português, seguindo os nomes deste plano.
- Todos os comandos rodam pelo script `./dev` (container Docker) a partir da raiz do projeto, num terminal **Git Bash**.

---

## File Structure

```
dev                                  # script: roda comandos no container de dev
docker/dev.Dockerfile                # imagem PHP 8.3 + Composer + ext-zip
app/Support/Telefone.php             # normaliza telefone para E.164 BR
app/Support/ParserValor.php          # "1.234,56" -> "1234.56"
app/Support/ParserData.php           # "23/10/2026", data do Excel -> CarbonImmutable
app/Support/SeletorEtapa.php         # regra pura: qual etapa enviar hoje
app/Support/LeitorPlanilha.php       # lê .xlsx/.csv em linhas associativas
app/Support/ValidadorLinhaImportacao.php
app/Models/{Cliente,Cobranca,EtapaRegua,Envio,MensagemRecebida,Configuracao}.php
app/WhatsApp/EnviadorWhatsApp.php    # interface
app/WhatsApp/MetaWhatsApp.php        # implementação HTTP (Cloud API)
app/WhatsApp/ErroTemporarioWhatsApp.php
app/WhatsApp/ErroDefinitivoWhatsApp.php
app/Jobs/EnviarMensagem.php
app/Console/Commands/{ExecutarRegua,ResumoDiario,CriarUsuario}.php
app/Mail/{MensagemRecebidaMail,ResumoDiarioMail}.php
app/Http/Controllers/{WebhookWhatsApp,Login,Cobranca,Importacao,Envio,Regua,Configuracao}Controller.php
resources/views/{layout,login,envios,regua,configuracoes}.blade.php
resources/views/cobrancas/{index,form}.blade.php
resources/views/importar/{form,previa}.blade.php
lang/pt_BR/validation.php
database/migrations/2026_10_17_000001_create_regua_tables.php
database/factories/{Cliente,Cobranca}Factory.php
tests/Fakes/FakeWhatsApp.php
tests/Unit/*, tests/Feature/*
DEPLOY.md
```

---

### Task 1: Ambiente de desenvolvimento e projeto Laravel

**Files:**
- Create: `.gitattributes`, `docker/dev.Dockerfile`, `dev`
- Create (gerado): esqueleto Laravel na raiz
- Modify: `config/app.php` (timezone), `.env`, `.env.example`, `phpunit.xml`
- Delete: `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`

**Interfaces:**
- Produces: comando `./dev <cmd>` que executa `<cmd>` em `/app` dentro da imagem `regua-dev`; projeto Laravel 12 com `openspout/openspout` instalado.

- [ ] **Step 1: Iniciar git e normalizar finais de linha**

```bash
git init -b main
printf '* text=auto eol=lf\n*.bat text eol=crlf\n' > .gitattributes
```

- [ ] **Step 2: Criar a imagem de desenvolvimento**

`docker/dev.Dockerfile`:

```dockerfile
FROM php:8.3-cli
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
```

`dev`:

```bash
#!/usr/bin/env bash
# Roda um comando dentro do container de desenvolvimento (PHP 8.3 + Composer).
# Uso: ./dev php artisan test
#      PORTA=8000 ./dev php artisan serve --host=0.0.0.0 --port=8000
set -e
RAIZ="$(cd "$(dirname "$0")" && (pwd -W 2>/dev/null || pwd))"
PORTAS=()
if [ -n "$PORTA" ]; then PORTAS=(-p "$PORTA:$PORTA"); fi
MSYS_NO_PATHCONV=1 exec docker run --rm -i "${PORTAS[@]}" -v "$RAIZ:/app" -w /app regua-dev "$@"
```

Run:
```bash
chmod +x dev
docker build -t regua-dev -f docker/dev.Dockerfile docker
./dev php -v
```
Expected: `PHP 8.3.x (cli)`.

- [ ] **Step 3: Gerar o projeto Laravel na raiz**

```bash
./dev bash -c "composer create-project --no-interaction --prefer-dist laravel/laravel:^12.0 /tmp/l && cp -a /tmp/l/. /app/"
./dev composer require openspout/openspout:^4.0
```
Expected: termina sem erro; existem `artisan`, `composer.json`, `vendor/`, `.env`, `database/database.sqlite`.

- [ ] **Step 4: Configurar fuso, idioma e testes**

Em `config/app.php`, substitua a linha que define `'timezone'` por:

```php
    'timezone' => 'America/Sao_Paulo',
```

Em `.env` **e** `.env.example`, ajuste/adicione:

```
APP_NAME="Régua de Cobrança"
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
QUEUE_CONNECTION=database
```

Em `phpunit.xml`, dentro de `<php>`, garanta que estas linhas existam **sem comentário** (descomente ou adicione):

```xml
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="MAIL_MAILER" value="array"/>
```

Apague os testes de exemplo:

```bash
rm tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php
```

- [ ] **Step 5: Verificar**

Run: `./dev php artisan test`
Expected: `No tests found` (ou 0 testes) e saída sem erros de configuração.

Run: `./dev php artisan about | grep -i -E "timezone|locale"`
Expected: `Timezone ... America/Sao_Paulo` e `Locale ... pt_BR`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: ambiente de dev em Docker e esqueleto Laravel 12"
```

---

### Task 2: Utilitários de telefone, valor e data

**Files:**
- Create: `app/Support/Telefone.php`, `app/Support/ParserValor.php`, `app/Support/ParserData.php`
- Test: `tests/Unit/TelefoneTest.php`, `tests/Unit/ParserValorTest.php`, `tests/Unit/ParserDataTest.php`

**Interfaces:**
- Produces:
  - `App\Support\Telefone::normalizar(string $entrada): ?string` → `'+5511999998888'` ou `null`
  - `App\Support\Telefone::variantes(string $e164): list<string>` → número + versão com o 9º dígito (para números de 10 dígitos de celular)
  - `App\Support\ParserValor::converter(mixed $valor): ?string` → `'1234.56'` ou `null`
  - `App\Support\ParserData::converter(mixed $valor): ?Carbon\CarbonImmutable` (início do dia) ou `null`

- [ ] **Step 1: Escrever os testes**

`tests/Unit/TelefoneTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\Telefone;
use PHPUnit\Framework\TestCase;

class TelefoneTest extends TestCase
{
    public function test_normaliza_formatos_comuns(): void
    {
        $this->assertSame('+5511999998888', Telefone::normalizar('(11) 99999-8888'));
        $this->assertSame('+5511999998888', Telefone::normalizar('11999998888'));
        $this->assertSame('+5511999998888', Telefone::normalizar('+55 11 99999-8888'));
        $this->assertSame('+5511999998888', Telefone::normalizar('5511999998888'));
        $this->assertSame('+5511999998888', Telefone::normalizar('011 99999-8888'));
        $this->assertSame('+551133334444', Telefone::normalizar('(11) 3333-4444'));
    }

    public function test_rejeita_invalidos(): void
    {
        $this->assertNull(Telefone::normalizar('99999-8888'));
        $this->assertNull(Telefone::normalizar('11899998888'));
        $this->assertNull(Telefone::normalizar('abc'));
        $this->assertNull(Telefone::normalizar('+1 415 555 2671'));
    }

    public function test_variantes_incluem_nono_digito_para_celular_antigo(): void
    {
        $this->assertSame(['+551199998888', '+5511999998888'], Telefone::variantes('+551199998888'));
        $this->assertSame(['+551133334444'], Telefone::variantes('+551133334444'));
        $this->assertSame(['+5511999998888'], Telefone::variantes('+5511999998888'));
    }
}
```

`tests/Unit/ParserValorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\ParserValor;
use PHPUnit\Framework\TestCase;

class ParserValorTest extends TestCase
{
    public function test_converte_formatos_brasileiros_e_numeros(): void
    {
        $this->assertSame('1234.56', ParserValor::converter('1.234,56'));
        $this->assertSame('150.00', ParserValor::converter('150'));
        $this->assertSame('99.90', ParserValor::converter('R$ 99,9'));
        $this->assertSame('1500.00', ParserValor::converter('1.500'));
        $this->assertSame('10.50', ParserValor::converter('10.5'));
        $this->assertSame('150.50', ParserValor::converter(150.5));
        $this->assertSame('200.00', ParserValor::converter(200));
    }

    public function test_rejeita_invalidos(): void
    {
        $this->assertNull(ParserValor::converter('abc'));
        $this->assertNull(ParserValor::converter('-5'));
        $this->assertNull(ParserValor::converter(0));
        $this->assertNull(ParserValor::converter(null));
        $this->assertNull(ParserValor::converter(''));
    }
}
```

`tests/Unit/ParserDataTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\ParserData;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ParserDataTest extends TestCase
{
    public function test_converte_formatos_aceitos(): void
    {
        $this->assertSame('2026-10-23', ParserData::converter('23/10/2026')->toDateString());
        $this->assertSame('2026-01-05', ParserData::converter('5/1/2026')->toDateString());
        $this->assertSame('2026-10-23', ParserData::converter('2026-10-23')->toDateString());
        $this->assertSame('2026-10-23', ParserData::converter(new DateTimeImmutable('2026-10-23 15:00'))->toDateString());
        $this->assertSame('2026-10-23', ParserData::converter(46318)->toDateString()); // número de série do Excel
    }

    public function test_rejeita_invalidos(): void
    {
        $this->assertNull(ParserData::converter('31/02/2026'));
        $this->assertNull(ParserData::converter('amanhã'));
        $this->assertNull(ParserData::converter('23/10/26'));
        $this->assertNull(ParserData::converter(null));
        $this->assertNull(ParserData::converter(''));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test tests/Unit`
Expected: FAIL — `Class "App\Support\Telefone" not found` (e equivalentes).

- [ ] **Step 3: Implementar**

`app/Support/Telefone.php`:

```php
<?php

namespace App\Support;

final class Telefone
{
    /** Converte um telefone brasileiro em E.164 (+55DDDNUMERO) ou retorna null se inválido. */
    public static function normalizar(string $entrada): ?string
    {
        $digitos = ltrim((string) preg_replace('/\D/', '', $entrada), '0');

        if (strlen($digitos) === 12 || strlen($digitos) === 13) {
            if (! str_starts_with($digitos, '55')) {
                return null;
            }
            $digitos = substr($digitos, 2);
        }

        if (strlen($digitos) !== 10 && strlen($digitos) !== 11) {
            return null;
        }

        if (strlen($digitos) === 11 && $digitos[2] !== '9') {
            return null;
        }

        return '+55'.$digitos;
    }

    /**
     * A Meta às vezes informa celulares antigos sem o 9º dígito.
     *
     * @return list<string>
     */
    public static function variantes(string $e164): array
    {
        $local = substr($e164, 3);

        if (str_starts_with($e164, '+55') && strlen($local) === 10 && in_array($local[2], ['6', '7', '8', '9'], true)) {
            return [$e164, '+55'.substr($local, 0, 2).'9'.substr($local, 2)];
        }

        return [$e164];
    }
}
```

`app/Support/ParserValor.php`:

```php
<?php

namespace App\Support;

final class ParserValor
{
    /** Converte valores como "1.234,56", "R$ 99,9" ou 150.5 para "1234.56". */
    public static function converter(mixed $valor): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            $numero = (float) $valor;
        } elseif (is_string($valor)) {
            $texto = str_replace([' ', "\u{00A0}"], '', trim(str_ireplace('R$', '', $valor)));

            if (str_contains($texto, ',')) {
                $texto = str_replace(['.', ','], ['', '.'], $texto);
            } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $texto)) {
                $texto = str_replace('.', '', $texto);
            }

            if (! preg_match('/^\d+(\.\d{1,2})?$/', $texto)) {
                return null;
            }
            $numero = (float) $texto;
        } else {
            return null;
        }

        return $numero > 0 ? number_format($numero, 2, '.', '') : null;
    }
}
```

`app/Support/ParserData.php`:

```php
<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;

final class ParserData
{
    /** Aceita dd/mm/aaaa, aaaa-mm-dd, DateTime e número de série do Excel. */
    public static function converter(mixed $valor): ?CarbonImmutable
    {
        if ($valor instanceof DateTimeInterface) {
            return CarbonImmutable::instance($valor)->startOfDay();
        }

        if ((is_int($valor) || is_float($valor)) && $valor > 30000 && $valor < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $valor);
        }

        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        foreach (['!d/m/Y', '!Y-m-d'] as $formato) {
            $data = DateTimeImmutable::createFromFormat($formato, trim($valor));
            $erros = DateTimeImmutable::getLastErrors();
            $semAvisos = $erros === false || ($erros['warning_count'] === 0 && $erros['error_count'] === 0);

            if ($data !== false && $semAvisos && (int) $data->format('Y') >= 2000) {
                return CarbonImmutable::instance($data)->startOfDay();
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./dev php artisan test tests/Unit`
Expected: PASS (8 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Support tests/Unit
git commit -m "feat: normalização de telefone, valor e data"
```

---

### Task 3: Banco de dados, modelos e configurações

**Files:**
- Create: `database/migrations/2026_10_17_000001_create_regua_tables.php`
- Create: `app/Models/Cliente.php`, `Cobranca.php`, `EtapaRegua.php`, `Envio.php`, `MensagemRecebida.php`, `Configuracao.php`
- Create: `database/factories/ClienteFactory.php`, `database/factories/CobrancaFactory.php`
- Test: `tests/Feature/ModelosTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - Tabelas `clientes`, `cobrancas`, `etapas_regua` (já com as 5 etapas padrão), `envios`, `mensagens_recebidas`, `configuracoes`.
  - `Cliente` (`nome`, `telefone`; `cobrancas()`), `Cobranca` (`cliente_id`, `valor` decimal:2 string, `vencimento` `CarbonImmutable`, `link_pagamento`, `status`, `pago_em`; `cliente()`, `envios()`; `static registrar(array{nome,telefone,valor,vencimento,link_pagamento}): Cobranca` — não duplica cliente+vencimento+valor; `wasRecentlyCreated` indica se criou).
  - `EtapaRegua` (`ordem`, `dias` int, `template_meta`, `idioma`, `ativa` bool).
  - `Envio` (`cobranca_id`, `etapa_regua_id`, `teste` bool, `status`, `tentativas` int, `meta_message_id`, `erro`, `enviado_em`; `cobranca()`, `etapa()`).
  - `MensagemRecebida` (`telefone`, `cliente_id`, `texto`, `recebida_em`; `cliente()`).
  - `Configuracao::get(string): ?string`, `Configuracao::set(string, ?string): void`, `Configuracao::modoTeste(): bool`, `Configuracao::CHAVES` (lista). Valores gravados criptografados.
  - Factories `Cliente::factory()`, `Cobranca::factory()`.

- [ ] **Step 1: Escrever o teste**

`tests/Feature/ModelosTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\EtapaRegua;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelosTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_cria_regua_padrao(): void
    {
        $etapas = EtapaRegua::orderBy('ordem')->get();

        $this->assertSame([-3, 0, 3, 7, 15], $etapas->pluck('dias')->all());
        $this->assertSame('cobranca_lembrete_3dias', $etapas[0]->template_meta);
        $this->assertSame('cobranca_ultimo_aviso', $etapas[4]->template_meta);
        $this->assertTrue($etapas->every(fn ($e) => $e->ativa && $e->idioma === 'pt_BR'));
    }

    public function test_registrar_cria_cliente_e_cobranca_sem_duplicar(): void
    {
        $dados = [
            'nome' => 'Maria',
            'telefone' => '+5511999998888',
            'valor' => '150.00',
            'vencimento' => '2026-10-23',
            'link_pagamento' => 'https://pag.exemplo/1',
        ];

        $primeira = Cobranca::registrar($dados);
        $segunda = Cobranca::registrar([...$dados, 'nome' => 'Maria Silva']);

        $this->assertTrue($primeira->wasRecentlyCreated);
        $this->assertFalse($segunda->wasRecentlyCreated);
        $this->assertSame(1, Cobranca::count());
        $this->assertSame('Maria Silva', $primeira->cliente->fresh()->nome);
        $this->assertSame('aberta', $primeira->fresh()->status);
        $this->assertSame('2026-10-23', $primeira->fresh()->vencimento->toDateString());
    }

    public function test_configuracao_guarda_valor_criptografado(): void
    {
        Configuracao::set('meta_token', 'segredo-123');

        $this->assertSame('segredo-123', Configuracao::get('meta_token'));
        $this->assertNotSame('segredo-123', DB::table('configuracoes')->where('chave', 'meta_token')->value('valor'));
        $this->assertNull(Configuracao::get('numero_teste'));
        $this->assertFalse(Configuracao::modoTeste());

        Configuracao::set('modo_teste', '1');
        $this->assertTrue(Configuracao::modoTeste());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=ModelosTest`
Expected: FAIL — `Class "App\Models\EtapaRegua" not found`.

- [ ] **Step 3: Criar a migration**

`database/migrations/2026_10_17_000001_create_regua_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $t) {
            $t->id();
            $t->string('nome');
            $t->string('telefone', 20)->unique();
            $t->timestamps();
        });

        Schema::create('cobrancas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained()->cascadeOnDelete();
            $t->decimal('valor', 12, 2);
            $t->date('vencimento');
            $t->string('link_pagamento', 500);
            $t->string('status', 20)->default('aberta');
            $t->timestamp('pago_em')->nullable();
            $t->timestamps();
            $t->index(['status', 'vencimento']);
        });

        Schema::create('etapas_regua', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('ordem');
            $t->integer('dias');
            $t->string('template_meta');
            $t->string('idioma', 10)->default('pt_BR');
            $t->boolean('ativa')->default(true);
            $t->timestamps();
        });

        Schema::create('envios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cobranca_id')->constrained()->cascadeOnDelete();
            $t->foreignId('etapa_regua_id')->constrained('etapas_regua')->cascadeOnDelete();
            $t->boolean('teste')->default(false);
            $t->string('status', 20);
            $t->unsignedTinyInteger('tentativas')->default(0);
            $t->string('meta_message_id')->nullable()->index();
            $t->text('erro')->nullable();
            $t->timestamp('enviado_em')->nullable();
            $t->timestamps();
            $t->unique(['cobranca_id', 'etapa_regua_id', 'teste']);
        });

        Schema::create('mensagens_recebidas', function (Blueprint $t) {
            $t->id();
            $t->string('telefone', 20);
            $t->foreignId('cliente_id')->nullable()->constrained()->nullOnDelete();
            $t->text('texto');
            $t->timestamp('recebida_em');
            $t->timestamps();
        });

        Schema::create('configuracoes', function (Blueprint $t) {
            $t->string('chave')->primary();
            $t->text('valor')->nullable();
        });

        $agora = now();
        DB::table('etapas_regua')->insert([
            ['ordem' => 1, 'dias' => -3, 'template_meta' => 'cobranca_lembrete_3dias', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['ordem' => 2, 'dias' => 0, 'template_meta' => 'cobranca_vence_hoje', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['ordem' => 3, 'dias' => 3, 'template_meta' => 'cobranca_atraso_3dias', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['ordem' => 4, 'dias' => 7, 'template_meta' => 'cobranca_atraso_7dias', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['ordem' => 5, 'dias' => 15, 'template_meta' => 'cobranca_ultimo_aviso', 'idioma' => 'pt_BR', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
        Schema::dropIfExists('mensagens_recebidas');
        Schema::dropIfExists('envios');
        Schema::dropIfExists('etapas_regua');
        Schema::dropIfExists('cobrancas');
        Schema::dropIfExists('clientes');
    }
};
```

- [ ] **Step 4: Criar os modelos**

`app/Models/Cliente.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory;

    protected $fillable = ['nome', 'telefone'];

    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class);
    }
}
```

`app/Models/Cobranca.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cobranca extends Model
{
    use HasFactory;

    protected $fillable = ['cliente_id', 'valor', 'vencimento', 'link_pagamento', 'status', 'pago_em'];

    protected $casts = [
        'valor' => 'decimal:2',
        'vencimento' => 'immutable_date',
        'pago_em' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function envios(): HasMany
    {
        return $this->hasMany(Envio::class);
    }

    /**
     * Cria (ou reaproveita) cliente pelo telefone e a cobrança, sem duplicar cliente+vencimento+valor.
     *
     * @param  array{nome: string, telefone: string, valor: string, vencimento: string, link_pagamento: string}  $dados
     */
    public static function registrar(array $dados): self
    {
        $cliente = Cliente::updateOrCreate(['telefone' => $dados['telefone']], ['nome' => $dados['nome']]);

        $existente = static::where('cliente_id', $cliente->id)
            ->whereDate('vencimento', $dados['vencimento'])
            ->where('valor', $dados['valor'])
            ->first();

        return $existente ?? static::create([
            'cliente_id' => $cliente->id,
            'valor' => $dados['valor'],
            'vencimento' => $dados['vencimento'],
            'link_pagamento' => $dados['link_pagamento'],
            'status' => 'aberta',
        ]);
    }
}
```

`app/Models/EtapaRegua.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EtapaRegua extends Model
{
    protected $table = 'etapas_regua';

    protected $fillable = ['ordem', 'dias', 'template_meta', 'idioma', 'ativa'];

    protected $casts = ['dias' => 'integer', 'ordem' => 'integer', 'ativa' => 'boolean'];
}
```

`app/Models/Envio.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Envio extends Model
{
    protected $fillable = [
        'cobranca_id', 'etapa_regua_id', 'teste', 'status', 'tentativas', 'meta_message_id', 'erro', 'enviado_em',
    ];

    protected $casts = ['teste' => 'boolean', 'tentativas' => 'integer', 'enviado_em' => 'datetime'];

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaRegua::class, 'etapa_regua_id');
    }
}
```

`app/Models/MensagemRecebida.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensagemRecebida extends Model
{
    protected $table = 'mensagens_recebidas';

    protected $fillable = ['telefone', 'cliente_id', 'texto', 'recebida_em'];

    protected $casts = ['recebida_em' => 'datetime'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
```

`app/Models/Configuracao.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracao extends Model
{
    public const CHAVES = [
        'meta_token', 'meta_phone_number_id', 'meta_app_secret', 'meta_verify_token',
        'meta_api_versao', 'email_avisos', 'modo_teste', 'numero_teste',
    ];

    protected $table = 'configuracoes';

    protected $primaryKey = 'chave';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['chave', 'valor'];

    protected $casts = ['valor' => 'encrypted'];

    public static function get(string $chave): ?string
    {
        return static::find($chave)?->valor;
    }

    public static function set(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }

    public static function modoTeste(): bool
    {
        return static::get('modo_teste') === '1';
    }
}
```

- [ ] **Step 5: Criar as factories**

`database/factories/ClienteFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ClienteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => fake()->name(),
            'telefone' => '+55119'.fake()->unique()->numerify('########'),
        ];
    }
}
```

`database/factories/CobrancaFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

class CobrancaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'valor' => '150.00',
            'vencimento' => today()->addDays(10)->toDateString(),
            'link_pagamento' => 'https://pag.exemplo/'.fake()->uuid(),
            'status' => 'aberta',
        ];
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./dev php artisan test --filter=ModelosTest`
Expected: PASS (3 testes).

Run: `./dev php artisan migrate:fresh`
Expected: todas as migrations rodam sem erro no SQLite local.

- [ ] **Step 7: Commit**

```bash
git add app/Models database tests/Feature/ModelosTest.php
git commit -m "feat: tabelas, modelos, régua padrão e configurações criptografadas"
```

---

### Task 4: Regra de seleção de etapa

**Files:**
- Create: `app/Support/SeletorEtapa.php`
- Test: `tests/Unit/SeletorEtapaTest.php`

**Interfaces:**
- Produces: `App\Support\SeletorEtapa::selecionar(CarbonImmutable $vencimento, CarbonImmutable $hoje, list<array{id:int,dias:int,ativa:bool}> $etapas, list<int> $idsRegistrados): array{enviar: ?int, pular: list<int>}`
  - Candidatas: etapas **ativas**, ainda **não registradas**, com `dias` **maior** que o maior `dias` já registrado, e `vencimento + dias <= hoje`.
  - `enviar` = candidata de maior `dias`; `pular` = demais candidatas.

- [ ] **Step 1: Escrever os testes**

`tests/Unit/SeletorEtapaTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\SeletorEtapa;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SeletorEtapaTest extends TestCase
{
    private function etapas(array $inativas = []): array
    {
        $dias = [1 => -3, 2 => 0, 3 => 3, 4 => 7, 5 => 15];

        return array_map(
            fn ($id) => ['id' => $id, 'dias' => $dias[$id], 'ativa' => ! in_array($id, $inativas, true)],
            array_keys($dias),
        );
    }

    private function selecionar(string $vencimento, string $hoje, array $registrados = [], array $inativas = []): array
    {
        return SeletorEtapa::selecionar(
            CarbonImmutable::parse($vencimento),
            CarbonImmutable::parse($hoje),
            $this->etapas($inativas),
            $registrados,
        );
    }

    public function test_nada_antes_da_primeira_etapa(): void
    {
        $this->assertSame(['enviar' => null, 'pular' => []], $this->selecionar('2026-10-23', '2026-10-19'));
    }

    public function test_envia_lembrete_tres_dias_antes(): void
    {
        $this->assertSame(['enviar' => 1, 'pular' => []], $this->selecionar('2026-10-23', '2026-10-20'));
    }

    public function test_no_vencimento_com_lembrete_ja_enviado(): void
    {
        $this->assertSame(['enviar' => 2, 'pular' => []], $this->selecionar('2026-10-23', '2026-10-23', [1]));
    }

    public function test_no_vencimento_sem_nada_enviado_pula_lembrete(): void
    {
        $this->assertSame(['enviar' => 2, 'pular' => [1]], $this->selecionar('2026-10-23', '2026-10-23'));
    }

    public function test_cadastrada_com_oito_dias_de_atraso_envia_so_a_de_sete(): void
    {
        $this->assertSame(['enviar' => 4, 'pular' => [1, 2, 3]], $this->selecionar('2026-10-12', '2026-10-20'));
    }

    public function test_vencimento_no_sabado_envia_na_segunda(): void
    {
        $this->assertSame(['enviar' => 2, 'pular' => []], $this->selecionar('2026-10-24', '2026-10-26', [1]));
    }

    public function test_etapa_desativada_nao_e_enviada(): void
    {
        $this->assertSame(['enviar' => null, 'pular' => []], $this->selecionar('2026-10-23', '2026-10-23', [1], [2]));
    }

    public function test_nao_volta_para_etapa_anterior(): void
    {
        $this->assertSame(['enviar' => null, 'pular' => []], $this->selecionar('2026-10-12', '2026-10-20', [4]));
    }

    public function test_depois_da_ultima_etapa_nao_envia_mais(): void
    {
        $this->assertSame(['enviar' => null, 'pular' => []], $this->selecionar('2026-09-01', '2026-10-20', [1, 2, 3, 4, 5]));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=SeletorEtapaTest`
Expected: FAIL — `Class "App\Support\SeletorEtapa" not found`.

- [ ] **Step 3: Implementar**

`app/Support/SeletorEtapa.php`:

```php
<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class SeletorEtapa
{
    /**
     * Decide qual etapa da régua uma cobrança deve receber hoje.
     *
     * @param  list<array{id: int, dias: int, ativa: bool}>  $etapas  todas as etapas da régua
     * @param  list<int>  $idsRegistrados  etapas que já têm registro em envios para esta cobrança
     * @return array{enviar: ?int, pular: list<int>}
     */
    public static function selecionar(CarbonImmutable $vencimento, CarbonImmutable $hoje, array $etapas, array $idsRegistrados): array
    {
        $registrados = array_map('intval', $idsRegistrados);
        $diasRegistrados = array_column(array_filter($etapas, fn ($e) => in_array($e['id'], $registrados, true)), 'dias');
        $limite = $diasRegistrados ? max($diasRegistrados) : PHP_INT_MIN;
        $hoje = $hoje->startOfDay();
        $vencimento = $vencimento->startOfDay();

        $candidatas = array_values(array_filter($etapas, fn ($e) => $e['ativa']
            && ! in_array($e['id'], $registrados, true)
            && $e['dias'] > $limite
            && $vencimento->addDays($e['dias'])->lessThanOrEqualTo($hoje)
        ));

        if (! $candidatas) {
            return ['enviar' => null, 'pular' => []];
        }

        usort($candidatas, fn ($a, $b) => $a['dias'] <=> $b['dias']);
        $ultima = array_pop($candidatas);

        return ['enviar' => $ultima['id'], 'pular' => array_column($candidatas, 'id')];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./dev php artisan test --filter=SeletorEtapaTest`
Expected: PASS (9 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Support/SeletorEtapa.php tests/Unit/SeletorEtapaTest.php
git commit -m "feat: regra de seleção da etapa da régua"
```

---

### Task 5: Cliente da WhatsApp Cloud API

**Files:**
- Create: `app/WhatsApp/EnviadorWhatsApp.php`, `MetaWhatsApp.php`, `ErroTemporarioWhatsApp.php`, `ErroDefinitivoWhatsApp.php`
- Create: `tests/Fakes/FakeWhatsApp.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/MetaWhatsAppTest.php`

**Interfaces:**
- Consumes: `Configuracao::get()` (Task 3).
- Produces:
  - `interface App\WhatsApp\EnviadorWhatsApp { enviarTemplate(string $telefone, string $template, string $idioma, list<string> $parametros): string; }` — retorna o id da mensagem na Meta; lança `ErroTemporarioWhatsApp` ou `ErroDefinitivoWhatsApp` (ambos `RuntimeException`).
  - `MetaWhatsApp(string $token, string $phoneNumberId, string $versao)`.
  - Container resolve `EnviadorWhatsApp` para `MetaWhatsApp` com as configurações salvas (versão padrão `v23.0`).
  - `Tests\Fakes\FakeWhatsApp`: propriedade pública `array $enviados` (cada item: `telefone`, `template`, `idioma`, `parametros`) e `?Throwable $erro` (se definido, é lançado). Retorna `wamid.fake1`, `wamid.fake2`…

- [ ] **Step 1: Escrever os testes**

`tests/Feature/MetaWhatsAppTest.php`:

```php
<?php

namespace Tests\Feature;

use App\WhatsApp\ErroDefinitivoWhatsApp;
use App\WhatsApp\ErroTemporarioWhatsApp;
use App\WhatsApp\MetaWhatsApp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWhatsAppTest extends TestCase
{
    private function enviar(): string
    {
        return (new MetaWhatsApp('tok', '123', 'v23.0'))
            ->enviarTemplate('+5511999998888', 'cobranca_vence_hoje', 'pt_BR', ['Maria', 'R$ 10,00', '23/10/2026', 'https://pag/1']);
    }

    public function test_envia_template_e_retorna_id(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);

        $this->assertSame('wamid.ABC', $this->enviar());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v23.0/123/messages'
            && $r->hasHeader('Authorization', 'Bearer tok')
            && $r['to'] === '5511999998888'
            && $r['type'] === 'template'
            && $r['template']['name'] === 'cobranca_vence_hoje'
            && $r['template']['language']['code'] === 'pt_BR'
            && $r['template']['components'][0]['parameters'][1] === ['type' => 'text', 'text' => 'R$ 10,00']);
    }

    public function test_erro_do_servidor_e_temporario(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 1, 'message' => 'oops']], 500)]);

        $this->expectException(ErroTemporarioWhatsApp::class);
        $this->enviar();
    }

    public function test_limite_de_taxa_e_temporario(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 130429, 'message' => 'rate']], 400)]);

        $this->expectException(ErroTemporarioWhatsApp::class);
        $this->enviar();
    }

    public function test_numero_invalido_e_definitivo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400)]);

        $this->expectException(ErroDefinitivoWhatsApp::class);
        $this->expectExceptionMessage('Message undeliverable');
        $this->enviar();
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=MetaWhatsAppTest`
Expected: FAIL — `Class "App\WhatsApp\MetaWhatsApp" not found`.

- [ ] **Step 3: Implementar**

`app/WhatsApp/EnviadorWhatsApp.php`:

```php
<?php

namespace App\WhatsApp;

interface EnviadorWhatsApp
{
    /**
     * @param  list<string>  $parametros  valores de {{1}}, {{2}}… do corpo do template
     * @return string id da mensagem na Meta
     *
     * @throws ErroTemporarioWhatsApp vale tentar de novo depois
     * @throws ErroDefinitivoWhatsApp não adianta tentar de novo
     */
    public function enviarTemplate(string $telefone, string $template, string $idioma, array $parametros): string;
}
```

`app/WhatsApp/ErroTemporarioWhatsApp.php`:

```php
<?php

namespace App\WhatsApp;

use RuntimeException;

class ErroTemporarioWhatsApp extends RuntimeException {}
```

`app/WhatsApp/ErroDefinitivoWhatsApp.php`:

```php
<?php

namespace App\WhatsApp;

use RuntimeException;

class ErroDefinitivoWhatsApp extends RuntimeException {}
```

`app/WhatsApp/MetaWhatsApp.php`:

```php
<?php

namespace App\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class MetaWhatsApp implements EnviadorWhatsApp
{
    /** Códigos da Meta que indicam limite de taxa ou indisponibilidade passageira. */
    private const CODIGOS_TEMPORARIOS = [1, 2, 4, 80007, 130429, 131016];

    public function __construct(
        private string $token,
        private string $phoneNumberId,
        private string $versao,
    ) {}

    public function enviarTemplate(string $telefone, string $template, string $idioma, array $parametros): string
    {
        try {
            $resposta = Http::withToken($this->token)->timeout(20)->post(
                "https://graph.facebook.com/{$this->versao}/{$this->phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($telefone, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $idioma],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $parametros),
                        ]],
                    ],
                ],
            );
        } catch (ConnectionException $e) {
            throw new ErroTemporarioWhatsApp('Falha de conexão com a Meta: '.$e->getMessage(), 0, $e);
        }

        if ($resposta->successful()) {
            $id = $resposta->json('messages.0.id');
            if (! $id) {
                throw new ErroTemporarioWhatsApp('Resposta da Meta sem id de mensagem.');
            }

            return $id;
        }

        $codigo = (int) $resposta->json('error.code');
        $texto = sprintf('Meta HTTP %d (código %d): %s', $resposta->status(), $codigo, $resposta->json('error.message') ?? $resposta->body());

        if ($resposta->serverError() || $resposta->status() === 429 || in_array($codigo, self::CODIGOS_TEMPORARIOS, true)) {
            throw new ErroTemporarioWhatsApp($texto);
        }

        throw new ErroDefinitivoWhatsApp($texto);
    }
}
```

`tests/Fakes/FakeWhatsApp.php`:

```php
<?php

namespace Tests\Fakes;

use App\WhatsApp\EnviadorWhatsApp;
use Throwable;

final class FakeWhatsApp implements EnviadorWhatsApp
{
    /** @var list<array{telefone: string, template: string, idioma: string, parametros: list<string>}> */
    public array $enviados = [];

    public ?Throwable $erro = null;

    public function enviarTemplate(string $telefone, string $template, string $idioma, array $parametros): string
    {
        if ($this->erro) {
            throw $this->erro;
        }

        $this->enviados[] = compact('telefone', 'template', 'idioma', 'parametros');

        return 'wamid.fake'.count($this->enviados);
    }
}
```

Em `app/Providers/AppServiceProvider.php`, adicione os imports e o corpo de `register()`:

```php
use App\Models\Configuracao;
use App\WhatsApp\EnviadorWhatsApp;
use App\WhatsApp\MetaWhatsApp;
```

```php
    public function register(): void
    {
        $this->app->bind(EnviadorWhatsApp::class, fn () => new MetaWhatsApp(
            (string) Configuracao::get('meta_token'),
            (string) Configuracao::get('meta_phone_number_id'),
            Configuracao::get('meta_api_versao') ?: 'v23.0',
        ));
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./dev php artisan test --filter=MetaWhatsAppTest`
Expected: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add app/WhatsApp app/Providers/AppServiceProvider.php tests/Fakes tests/Feature/MetaWhatsAppTest.php
git commit -m "feat: cliente da WhatsApp Cloud API com classificação de erros"
```

---

### Task 6: Job de envio, comando da régua e agendamento

**Files:**
- Create: `app/Jobs/EnviarMensagem.php`, `app/Console/Commands/ExecutarRegua.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/ExecutarReguaTest.php`

**Interfaces:**
- Consumes: `SeletorEtapa::selecionar` (Task 4); `EnviadorWhatsApp`, `ErroTemporarioWhatsApp`, `ErroDefinitivoWhatsApp`, `FakeWhatsApp` (Task 5); modelos e `Configuracao` (Task 3).
- Produces:
  - `App\Jobs\EnviarMensagem(int $envioId)` — `$tries = 4`, `backoff() = [60, 300, 900]`; `handle(EnviadorWhatsApp)`; `failed(?Throwable)` marca `falhou`.
  - Comando `php artisan regua:executar`.
  - Agendamentos: `regua:executar` seg–sex 09:00; `queue:work --stop-when-empty --max-time=50` a cada minuto.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/ExecutarReguaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensagem;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use App\WhatsApp\EnviadorWhatsApp;
use App\WhatsApp\ErroDefinitivoWhatsApp;
use App\WhatsApp\ErroTemporarioWhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeWhatsApp;
use Tests\TestCase;

class ExecutarReguaTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsApp $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whatsapp = new FakeWhatsApp;
        $this->app->instance(EnviadorWhatsApp::class, $this->whatsapp);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00')); // terça-feira
    }

    private function cobranca(string $vencimento, string $status = 'aberta'): Cobranca
    {
        $cliente = Cliente::factory()->create(['nome' => 'Maria', 'telefone' => '+5511999998888']);

        return Cobranca::factory()->create([
            'cliente_id' => $cliente->id,
            'vencimento' => $vencimento,
            'valor' => '1234.50',
            'link_pagamento' => 'https://pag.exemplo/1',
            'status' => $status,
        ]);
    }

    public function test_envia_a_etapa_do_dia_com_parametros_formatados(): void
    {
        $cobranca = $this->cobranca('2026-10-23');

        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertCount(1, $this->whatsapp->enviados);
        $this->assertSame([
            'telefone' => '+5511999998888',
            'template' => 'cobranca_lembrete_3dias',
            'idioma' => 'pt_BR',
            'parametros' => ['Maria', 'R$ 1.234,50', '23/10/2026', 'https://pag.exemplo/1'],
        ], $this->whatsapp->enviados[0]);

        $envio = Envio::sole();
        $this->assertSame($cobranca->id, $envio->cobranca_id);
        $this->assertSame('enviada', $envio->status);
        $this->assertSame('wamid.fake1', $envio->meta_message_id);
        $this->assertNotNull($envio->enviado_em);
    }

    public function test_rodar_duas_vezes_nao_duplica(): void
    {
        $this->cobranca('2026-10-23');

        $this->artisan('regua:executar')->assertSuccessful();
        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertCount(1, $this->whatsapp->enviados);
        $this->assertSame(1, Envio::count());
    }

    public function test_cobranca_paga_nao_recebe_mensagem(): void
    {
        $this->cobranca('2026-10-23', 'paga');

        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertCount(0, $this->whatsapp->enviados);
    }

    public function test_cobranca_atrasada_recebe_so_a_etapa_mais_recente(): void
    {
        $this->cobranca('2026-10-12');

        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertSame(['cobranca_atraso_7dias'], array_column($this->whatsapp->enviados, 'template'));
        $this->assertSame(3, Envio::where('status', 'pulada')->count());
    }

    public function test_modo_teste_envia_para_numero_de_teste_e_nao_trava_envio_real(): void
    {
        $this->cobranca('2026-10-23');
        Configuracao::set('modo_teste', '1');
        Configuracao::set('numero_teste', '+5511900000000');

        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertSame('+5511900000000', $this->whatsapp->enviados[0]['telefone']);
        $this->assertSame('Maria', $this->whatsapp->enviados[0]['parametros'][0]);
        $this->assertTrue(Envio::sole()->teste);

        Configuracao::set('modo_teste', '0');
        $this->artisan('regua:executar')->assertSuccessful();

        $this->assertSame('+5511999998888', $this->whatsapp->enviados[1]['telefone']);
        $this->assertSame(2, Envio::count());
    }

    public function test_erro_definitivo_marca_falhou(): void
    {
        $this->cobranca('2026-10-23');
        $this->whatsapp->erro = new ErroDefinitivoWhatsApp('número sem WhatsApp');

        $this->artisan('regua:executar')->assertSuccessful();

        $envio = Envio::sole();
        $this->assertSame('falhou', $envio->status);
        $this->assertSame('número sem WhatsApp', $envio->erro);
    }

    public function test_job_nao_envia_se_cobranca_foi_paga_depois_de_agendada(): void
    {
        $cobranca = $this->cobranca('2026-10-23');
        $envio = Envio::create(['cobranca_id' => $cobranca->id, 'etapa_regua_id' => EtapaRegua::first()->id, 'status' => 'pendente']);
        $cobranca->update(['status' => 'paga']);

        (new EnviarMensagem($envio->id))->handle($this->whatsapp);

        $this->assertCount(0, $this->whatsapp->enviados);
        $this->assertSame('pulada', $envio->fresh()->status);
    }

    public function test_erro_temporario_mantem_pendente_e_failed_marca_falhou(): void
    {
        $cobranca = $this->cobranca('2026-10-23');
        $envio = Envio::create(['cobranca_id' => $cobranca->id, 'etapa_regua_id' => EtapaRegua::first()->id, 'status' => 'pendente']);
        $this->whatsapp->erro = new ErroTemporarioWhatsApp('timeout');

        try {
            (new EnviarMensagem($envio->id))->handle($this->whatsapp);
            $this->fail('Deveria lançar ErroTemporarioWhatsApp');
        } catch (ErroTemporarioWhatsApp) {
        }

        $this->assertSame('pendente', $envio->fresh()->status);
        $this->assertSame(1, $envio->fresh()->tentativas);

        (new EnviarMensagem($envio->id))->failed(new ErroTemporarioWhatsApp('timeout'));

        $this->assertSame('falhou', $envio->fresh()->status);
        $this->assertSame('timeout', $envio->fresh()->erro);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=ExecutarReguaTest`
Expected: FAIL — `The command "regua:executar" does not exist` / `Class "App\Jobs\EnviarMensagem" not found`.

- [ ] **Step 3: Implementar o job**

`app/Jobs/EnviarMensagem.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Configuracao;
use App\Models\Envio;
use App\WhatsApp\EnviadorWhatsApp;
use App\WhatsApp\ErroDefinitivoWhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EnviarMensagem implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $envioId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EnviadorWhatsApp $enviador): void
    {
        $envio = Envio::with(['cobranca.cliente', 'etapa'])->find($this->envioId);
        if (! $envio || $envio->status !== 'pendente') {
            return;
        }

        $cobranca = $envio->cobranca;
        if ($cobranca->status !== 'aberta') {
            $envio->update(['status' => 'pulada', 'erro' => 'Cobrança não está mais aberta.']);

            return;
        }

        $telefone = $envio->teste ? Configuracao::get('numero_teste') : $cobranca->cliente->telefone;
        if (! $telefone) {
            $envio->update(['status' => 'falhou', 'erro' => 'Número de teste não configurado.']);

            return;
        }

        $envio->increment('tentativas');

        try {
            $id = $enviador->enviarTemplate($telefone, $envio->etapa->template_meta, $envio->etapa->idioma, [
                $cobranca->cliente->nome,
                'R$ '.number_format((float) $cobranca->valor, 2, ',', '.'),
                $cobranca->vencimento->format('d/m/Y'),
                $cobranca->link_pagamento,
            ]);
        } catch (ErroDefinitivoWhatsApp $e) {
            $envio->update(['status' => 'falhou', 'erro' => $e->getMessage()]);

            return;
        }

        $envio->update(['status' => 'enviada', 'meta_message_id' => $id, 'enviado_em' => now(), 'erro' => null]);
    }

    public function failed(?Throwable $erro): void
    {
        Envio::whereKey($this->envioId)
            ->where('status', 'pendente')
            ->update(['status' => 'falhou', 'erro' => $erro?->getMessage() ?? 'Falha desconhecida.']);
    }
}
```

- [ ] **Step 4: Implementar o comando**

`app/Console/Commands/ExecutarRegua.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\EnviarMensagem;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use App\Support\SeletorEtapa;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ExecutarRegua extends Command
{
    protected $signature = 'regua:executar';

    protected $description = 'Agenda as mensagens da régua de cobrança de hoje';

    public function handle(): int
    {
        $hoje = CarbonImmutable::today();
        $teste = Configuracao::modoTeste();
        $etapas = EtapaRegua::all()
            ->map(fn (EtapaRegua $e) => ['id' => $e->id, 'dias' => $e->dias, 'ativa' => $e->ativa])
            ->all();
        $agendadas = 0;

        Cobranca::where('status', 'aberta')->with('envios')->chunkById(200, function ($cobrancas) use ($hoje, $teste, $etapas, &$agendadas) {
            foreach ($cobrancas as $cobranca) {
                $registrados = $cobranca->envios->where('teste', $teste)->pluck('etapa_regua_id')->all();
                $escolha = SeletorEtapa::selecionar($cobranca->vencimento, $hoje, $etapas, $registrados);

                foreach ($escolha['pular'] as $etapaId) {
                    Envio::firstOrCreate(
                        ['cobranca_id' => $cobranca->id, 'etapa_regua_id' => $etapaId, 'teste' => $teste],
                        ['status' => 'pulada'],
                    );
                }

                if ($escolha['enviar'] === null) {
                    continue;
                }

                $envio = Envio::firstOrCreate(
                    ['cobranca_id' => $cobranca->id, 'etapa_regua_id' => $escolha['enviar'], 'teste' => $teste],
                    ['status' => 'pendente'],
                );

                if ($envio->wasRecentlyCreated) {
                    EnviarMensagem::dispatch($envio->id);
                    $agendadas++;
                }
            }
        });

        $this->info($agendadas.' mensagem(ns) agendada(s)'.($teste ? ' em MODO TESTE' : '').'.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Agendar**

Substitua o conteúdo de `routes/console.php` por:

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('regua:executar')
    ->weekdays()->at('09:00')->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// A hospedagem não mantém processos rodando: a fila é processada pelo cron a cada minuto.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./dev php artisan test --filter=ExecutarReguaTest`
Expected: PASS (8 testes).

Run: `./dev php artisan schedule:list`
Expected: duas linhas — `0 9 * * 1-5 php artisan regua:executar` e `* * * * * php artisan queue:work --stop-when-empty --max-time=50`.

- [ ] **Step 7: Commit**

```bash
git add app/Jobs app/Console/Commands/ExecutarRegua.php routes/console.php tests/Feature/ExecutarReguaTest.php
git commit -m "feat: comando diário da régua e job de envio com novas tentativas"
```

---

### Task 7: Webhook da Meta (status e respostas)

**Files:**
- Create: `app/Http/Controllers/WebhookWhatsAppController.php`, `app/Mail/MensagemRecebidaMail.php`
- Modify: `routes/web.php` (substituir), `bootstrap/app.php`
- Delete: `resources/views/welcome.blade.php`
- Test: `tests/Feature/WebhookWhatsAppTest.php`

**Interfaces:**
- Consumes: `Envio`, `Cliente`, `MensagemRecebida`, `Configuracao` (Task 3); `Telefone::normalizar`, `Telefone::variantes` (Task 2).
- Produces:
  - `GET /webhook/whatsapp` (verificação `hub.verify_token`) e `POST /webhook/whatsapp` (assinatura `X-Hub-Signature-256` com `meta_app_secret`).
  - Status nunca regridem (`enviada` → `entregue` → `lida`); `failed` → `falhou`.
  - `App\Mail\MensagemRecebidaMail(MensagemRecebida $mensagem)`.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/WebhookWhatsAppTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\MensagemRecebidaMail;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use App\Models\MensagemRecebida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Configuracao::set('meta_app_secret', 'segredo');
        Configuracao::set('meta_verify_token', 'verifica');
    }

    private function postar(array $payload, string $segredo = 'segredo'): TestResponse
    {
        $corpo = json_encode($payload);

        return $this->call('POST', '/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, $segredo),
        ], $corpo);
    }

    private function payload(array $valor): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]];
    }

    private function envio(string $status = 'enviada'): Envio
    {
        return Envio::create([
            'cobranca_id' => Cobranca::factory()->create()->id,
            'etapa_regua_id' => EtapaRegua::first()->id,
            'status' => $status,
            'meta_message_id' => 'wamid.1',
        ]);
    }

    public function test_verificacao_com_token_correto_devolve_challenge(): void
    {
        $this->get('/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=verifica&hub.challenge=987')
            ->assertOk()
            ->assertContent('987');
    }

    public function test_verificacao_com_token_errado_e_recusada(): void
    {
        $this->get('/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=987')->assertForbidden();
    }

    public function test_assinatura_invalida_e_recusada(): void
    {
        $envio = $this->envio();

        $this->postar($this->payload(['statuses' => [['id' => 'wamid.1', 'status' => 'delivered']]]), 'outro')->assertForbidden();

        $this->assertSame('enviada', $envio->fresh()->status);
    }

    public function test_status_avanca_e_nao_regride(): void
    {
        $envio = $this->envio();

        $this->postar($this->payload(['statuses' => [['id' => 'wamid.1', 'status' => 'read']]]))->assertOk();
        $this->postar($this->payload(['statuses' => [['id' => 'wamid.1', 'status' => 'delivered']]]))->assertOk();

        $this->assertSame('lida', $envio->fresh()->status);
    }

    public function test_status_failed_marca_falhou_com_motivo(): void
    {
        $envio = $this->envio();

        $this->postar($this->payload(['statuses' => [[
            'id' => 'wamid.1', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]]]))->assertOk();

        $this->assertSame('falhou', $envio->fresh()->status);
        $this->assertSame('Message undeliverable', $envio->fresh()->erro);
    }

    public function test_resposta_do_cliente_e_registrada_e_avisada_por_email(): void
    {
        Mail::fake();
        Configuracao::set('email_avisos', 'dono@exemplo.com');
        $cliente = Cliente::factory()->create(['telefone' => '+5511999998888']);

        $this->postar($this->payload(['messages' => [[
            'from' => '551199998888', 'id' => 'wamid.in', 'timestamp' => '1792500000', 'type' => 'text', 'text' => ['body' => 'Já paguei'],
        ]]]))->assertOk();

        $mensagem = MensagemRecebida::sole();
        $this->assertSame($cliente->id, $mensagem->cliente_id);
        $this->assertSame('Já paguei', $mensagem->texto);
        Mail::assertSent(MensagemRecebidaMail::class, fn ($m) => $m->hasTo('dono@exemplo.com'));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=WebhookWhatsAppTest`
Expected: FAIL — 404 nas rotas / `Class "App\Mail\MensagemRecebidaMail" not found`.

- [ ] **Step 3: Implementar o e-mail**

`app/Mail/MensagemRecebidaMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\MensagemRecebida;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MensagemRecebidaMail extends Mailable
{
    public function __construct(public MensagemRecebida $mensagem) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Resposta de cliente no WhatsApp');
    }

    public function content(): Content
    {
        $nome = $this->mensagem->cliente?->nome ?? 'Número não cadastrado';

        return new Content(htmlString: sprintf(
            '<p><strong>%s</strong> (%s) respondeu:</p><blockquote>%s</blockquote>',
            e($nome), e($this->mensagem->telefone), nl2br(e($this->mensagem->texto)),
        ));
    }
}
```

- [ ] **Step 4: Implementar o controller**

`app/Http/Controllers/WebhookWhatsAppController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Mail\MensagemRecebidaMail;
use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\MensagemRecebida;
use App\Support\Telefone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WebhookWhatsAppController extends Controller
{
    private const STATUS_META = ['sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'];

    private const ORDEM = ['pendente' => 0, 'enviada' => 1, 'entregue' => 2, 'lida' => 3];

    public function verificar(Request $request): Response
    {
        $token = (string) Configuracao::get('meta_verify_token');

        if ($request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        return response('Forbidden', 403);
    }

    public function receber(Request $request): Response
    {
        $segredo = (string) Configuracao::get('meta_app_secret');
        $esperada = 'sha256='.hash_hmac('sha256', $request->getContent(), $segredo);

        if ($segredo === '' || ! hash_equals($esperada, (string) $request->header('X-Hub-Signature-256'))) {
            Log::warning('Webhook do WhatsApp com assinatura inválida.');

            return response('Forbidden', 403);
        }

        foreach ($request->input('entry', []) as $entrada) {
            foreach ($entrada['changes'] ?? [] as $mudanca) {
                foreach ($mudanca['value']['statuses'] ?? [] as $status) {
                    $this->atualizarStatus($status);
                }
                foreach ($mudanca['value']['messages'] ?? [] as $mensagem) {
                    $this->registrarMensagem($mensagem);
                }
            }
        }

        return response('OK', 200);
    }

    private function atualizarStatus(array $status): void
    {
        $novo = self::STATUS_META[$status['status'] ?? ''] ?? null;
        $envio = $novo ? Envio::where('meta_message_id', $status['id'] ?? '')->first() : null;
        if (! $envio) {
            return;
        }

        if ($novo === 'falhou') {
            $envio->update(['status' => 'falhou', 'erro' => $status['errors'][0]['title'] ?? 'Falha informada pela Meta.']);

            return;
        }

        if (self::ORDEM[$novo] > (self::ORDEM[$envio->status] ?? PHP_INT_MAX)) {
            $envio->update(['status' => $novo]);
        }
    }

    private function registrarMensagem(array $dados): void
    {
        $telefone = Telefone::normalizar($dados['from'] ?? '') ?? '+'.($dados['from'] ?? '');
        $cliente = Cliente::whereIn('telefone', Telefone::variantes($telefone))->first();

        $mensagem = MensagemRecebida::create([
            'telefone' => $cliente?->telefone ?? $telefone,
            'cliente_id' => $cliente?->id,
            'texto' => $dados['text']['body'] ?? '['.($dados['type'] ?? 'mensagem').']',
            'recebida_em' => isset($dados['timestamp']) ? Carbon::createFromTimestamp((int) $dados['timestamp']) : now(),
        ]);

        if ($email = Configuracao::get('email_avisos')) {
            Mail::to($email)->send(new MensagemRecebidaMail($mensagem));
        }
    }
}
```

- [ ] **Step 5: Rotas e CSRF**

Substitua `routes/web.php` por:

```php
<?php

use App\Http\Controllers\WebhookWhatsAppController;
use Illuminate\Support\Facades\Route;

Route::get('/webhook/whatsapp', [WebhookWhatsAppController::class, 'verificar']);
Route::post('/webhook/whatsapp', [WebhookWhatsAppController::class, 'receber']);
```

Em `bootstrap/app.php`, dentro de `->withMiddleware(function (Middleware $middleware) ... {` substitua o `//` por:

```php
        $middleware->validateCsrfTokens(except: ['webhook/*']);
```

Apague a view padrão:

```bash
rm resources/views/welcome.blade.php
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./dev php artisan test --filter=WebhookWhatsAppTest`
Expected: PASS (6 testes).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: webhook da Meta para status de entrega e respostas de clientes"
```

---

### Task 8: Login, layout e criação do usuário

**Files:**
- Create: `app/Http/Controllers/LoginController.php`, `app/Console/Commands/CriarUsuario.php`
- Create: `resources/views/layout.blade.php`, `resources/views/login.blade.php`, `lang/pt_BR/validation.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/LoginTest.php`

**Interfaces:**
- Consumes: modelo `User` padrão do Laravel.
- Produces:
  - Rotas `login` (GET/POST), `logout` (POST), `/` (provisória; vira redirect na Task 9).
  - Layout Blade `layout` com `@yield('titulo')` e `@yield('conteudo')`; mensagens de sucesso via `session('ok')`.
  - Comando `php artisan usuario:criar {email} {--nome=Administrador}` (pede a senha, mínimo 8 caracteres).

- [ ] **Step 1: Escrever os testes**

`tests/Feature/LoginTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Entrar');
    }

    public function test_login_com_senha_correta(): void
    {
        $user = User::factory()->create(['email' => 'dono@exemplo.com', 'password' => 'senha-forte-123']);

        $this->post('/login', ['email' => 'dono@exemplo.com', 'password' => 'senha-forte-123'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_com_senha_errada(): void
    {
        User::factory()->create(['email' => 'dono@exemplo.com', 'password' => 'senha-forte-123']);

        $this->post('/login', ['email' => 'dono@exemplo.com', 'password' => 'errada'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_comando_cria_usuario(): void
    {
        $this->artisan('usuario:criar', ['email' => 'dono@exemplo.com'])
            ->expectsQuestion('Senha', 'senha-forte-123')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('senha-forte-123', User::where('email', 'dono@exemplo.com')->value('password')));
    }

    public function test_comando_recusa_senha_curta(): void
    {
        $this->artisan('usuario:criar', ['email' => 'dono@exemplo.com'])
            ->expectsQuestion('Senha', '123')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=LoginTest`
Expected: FAIL — 404 em `/` e `/login`; comando inexistente.

- [ ] **Step 3: Implementar controller e comando**

`app/Http/Controllers/LoginController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function form(): View
    {
        return view('login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $credenciais = $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (Auth::attempt($credenciais, true)) {
            $request->session()->regenerate();

            return redirect()->intended('/');
        }

        return back()->withErrors(['email' => 'E-mail ou senha incorretos.'])->onlyInput('email');
    }

    public function sair(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
```

`app/Console/Commands/CriarUsuario.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CriarUsuario extends Command
{
    protected $signature = 'usuario:criar {email} {--nome=Administrador}';

    protected $description = 'Cria ou atualiza o usuário que acessa o sistema';

    public function handle(): int
    {
        $senha = (string) $this->secret('Senha');

        if (strlen($senha) < 8) {
            $this->error('A senha precisa ter pelo menos 8 caracteres.');

            return self::FAILURE;
        }

        User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('nome'), 'password' => $senha],
        );
        $this->info('Usuário salvo.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Views e traduções**

`resources/views/layout.blade.php`:

```blade
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
```

`resources/views/login.blade.php`:

```blade
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
```

`lang/pt_BR/validation.php`:

```php
<?php

return [
    'array' => 'O campo :attribute é inválido.',
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'between' => ['numeric' => 'O campo :attribute deve estar entre :min e :max.'],
    'date' => 'O campo :attribute não é uma data válida.',
    'digits_between' => 'O campo :attribute deve ter entre :min e :max dígitos.',
    'email' => 'O campo :attribute deve ser um e-mail válido.',
    'file' => 'O campo :attribute deve ser um arquivo.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'max' => [
        'file' => 'O arquivo :attribute não pode ter mais que :max kilobytes.',
        'string' => 'O campo :attribute não pode ter mais que :max caracteres.',
    ],
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute deve ser um texto.',
    'url' => 'O campo :attribute deve ser um link válido (começando com http:// ou https://).',

    'attributes' => [
        'arquivo' => 'arquivo',
        'email' => 'e-mail',
        'email_avisos' => 'e-mail para avisos',
        'link_pagamento' => 'link de pagamento',
        'meta_api_versao' => 'versão da API',
        'meta_phone_number_id' => 'Phone number ID',
        'password' => 'senha',
        'vencimento' => 'vencimento',
    ],
];
```

- [ ] **Step 5: Rotas**

Substitua `routes/web.php` por:

```php
<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\WebhookWhatsAppController;
use Illuminate\Support\Facades\Route;

Route::get('/webhook/whatsapp', [WebhookWhatsAppController::class, 'verificar']);
Route::post('/webhook/whatsapp', [WebhookWhatsAppController::class, 'receber']);

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'form'])->name('login');
    Route::post('/login', [LoginController::class, 'entrar']);
});

Route::post('/logout', [LoginController::class, 'sair'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => view('layout'));
});
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./dev php artisan test --filter=LoginTest`
Expected: PASS (6 testes).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: login, layout e comando para criar usuário"
```

---

### Task 9: Tela de cobranças

**Files:**
- Create: `app/Http/Controllers/CobrancaController.php`
- Create: `resources/views/cobrancas/index.blade.php`, `resources/views/cobrancas/form.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CobrancaTelaTest.php`

**Interfaces:**
- Consumes: `Cobranca::registrar`, `Cliente` (Task 3); `Telefone::normalizar`, `ParserValor::converter` (Task 2); layout (Task 8).
- Produces: rotas `cobrancas.index|create|store|edit|update`, `cobrancas.pagar`, `cobrancas.cancelar`; `/` redireciona para `cobrancas.index`. Filtros `?filtro=abertas|a_vencer|vencidas|pagas|canceladas` (padrão `abertas`).

- [ ] **Step 1: Escrever os testes**

`tests/Feature/CobrancaTelaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrancaTelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $this->actingAs(User::factory()->create());
    }

    public function test_inicio_redireciona_para_cobrancas(): void
    {
        $this->get('/')->assertRedirect(route('cobrancas.index'));
    }

    public function test_lista_filtra_vencidas(): void
    {
        Cobranca::factory()->for(Cliente::factory()->state(['nome' => 'Atrasado da Silva']))->create(['vencimento' => '2026-10-10']);
        Cobranca::factory()->for(Cliente::factory()->state(['nome' => 'Em Dia Souza']))->create(['vencimento' => '2026-10-30']);

        $this->get('/cobrancas')->assertOk()->assertSee('Atrasado da Silva')->assertSee('Em Dia Souza');
        $this->get('/cobrancas?filtro=vencidas')->assertSee('Atrasado da Silva')->assertDontSee('Em Dia Souza');
    }

    public function test_cadastra_cobranca_normalizando_telefone_e_valor(): void
    {
        $this->post('/cobrancas', [
            'nome' => 'Maria',
            'telefone' => '(11) 99999-8888',
            'valor' => '1.234,56',
            'vencimento' => '2026-10-23',
            'link_pagamento' => 'https://pag.exemplo/1',
        ])->assertRedirect(route('cobrancas.index'));

        $cobranca = Cobranca::with('cliente')->sole();
        $this->assertSame('+5511999998888', $cobranca->cliente->telefone);
        $this->assertSame('1234.56', $cobranca->valor);
        $this->assertSame('aberta', $cobranca->status);
    }

    public function test_recusa_telefone_invalido(): void
    {
        $this->post('/cobrancas', [
            'nome' => 'Maria',
            'telefone' => '9999',
            'valor' => '10',
            'vencimento' => '2026-10-23',
            'link_pagamento' => 'https://pag.exemplo/1',
        ])->assertSessionHasErrors('telefone');

        $this->assertSame(0, Cobranca::count());
    }

    public function test_edita_cobranca(): void
    {
        $cobranca = Cobranca::factory()->create();

        $this->put("/cobrancas/{$cobranca->id}", [
            'nome' => 'Novo Nome',
            'telefone' => $cobranca->cliente->telefone,
            'valor' => '200,00',
            'vencimento' => '2026-11-05',
            'link_pagamento' => 'https://pag.exemplo/novo',
        ])->assertRedirect(route('cobrancas.index'));

        $cobranca->refresh();
        $this->assertSame('200.00', $cobranca->valor);
        $this->assertSame('2026-11-05', $cobranca->vencimento->toDateString());
        $this->assertSame('Novo Nome', $cobranca->cliente->nome);
    }

    public function test_marca_como_paga_e_cancela(): void
    {
        $paga = Cobranca::factory()->create();
        $cancelada = Cobranca::factory()->create();

        $this->post("/cobrancas/{$paga->id}/pagar")->assertRedirect();
        $this->post("/cobrancas/{$cancelada->id}/cancelar")->assertRedirect();

        $this->assertSame('paga', $paga->fresh()->status);
        $this->assertNotNull($paga->fresh()->pago_em);
        $this->assertSame('cancelada', $cancelada->fresh()->status);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=CobrancaTelaTest`
Expected: FAIL — 404 em `/cobrancas`.

- [ ] **Step 3: Implementar o controller**

`app/Http/Controllers/CobrancaController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Support\ParserValor;
use App\Support\Telefone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CobrancaController extends Controller
{
    public function index(Request $request): View
    {
        $filtro = $request->query('filtro', 'abertas');
        $consulta = Cobranca::with('cliente')->orderBy('vencimento');

        match ($filtro) {
            'a_vencer' => $consulta->where('status', 'aberta')->whereDate('vencimento', '>=', today()),
            'vencidas' => $consulta->where('status', 'aberta')->whereDate('vencimento', '<', today()),
            'pagas' => $consulta->where('status', 'paga'),
            'canceladas' => $consulta->where('status', 'cancelada'),
            default => $consulta->where('status', 'aberta'),
        };

        return view('cobrancas.index', [
            'cobrancas' => $consulta->paginate(50)->withQueryString(),
            'filtro' => $filtro,
        ]);
    }

    public function create(): View
    {
        return view('cobrancas.form', ['cobranca' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Cobranca::registrar($this->validar($request));

        return redirect()->route('cobrancas.index')->with('ok', 'Cobrança cadastrada.');
    }

    public function edit(Cobranca $cobranca): View
    {
        return view('cobrancas.form', ['cobranca' => $cobranca->load('cliente')]);
    }

    public function update(Request $request, Cobranca $cobranca): RedirectResponse
    {
        $dados = $this->validar($request);
        $cliente = Cliente::updateOrCreate(['telefone' => $dados['telefone']], ['nome' => $dados['nome']]);

        $cobranca->update([
            'cliente_id' => $cliente->id,
            'valor' => $dados['valor'],
            'vencimento' => $dados['vencimento'],
            'link_pagamento' => $dados['link_pagamento'],
        ]);

        return redirect()->route('cobrancas.index')->with('ok', 'Cobrança atualizada.');
    }

    public function pagar(Cobranca $cobranca): RedirectResponse
    {
        $cobranca->update(['status' => 'paga', 'pago_em' => now()]);

        return back()->with('ok', 'Cobrança marcada como paga.');
    }

    public function cancelar(Cobranca $cobranca): RedirectResponse
    {
        $cobranca->update(['status' => 'cancelada']);

        return back()->with('ok', 'Cobrança cancelada.');
    }

    /** @return array{nome: string, telefone: string, valor: string, vencimento: string, link_pagamento: string} */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'nome' => 'required|string|max:255',
            'telefone' => 'required|string',
            'valor' => 'required|string',
            'vencimento' => 'required|date',
            'link_pagamento' => 'required|url|max:500',
        ]);

        $telefone = Telefone::normalizar($dados['telefone']);
        $valor = ParserValor::converter($dados['valor']);
        $erros = array_filter([
            'telefone' => $telefone ? null : 'Telefone inválido. Use DDD + número, ex.: (11) 99999-8888.',
            'valor' => $valor ? null : 'Valor inválido. Ex.: 150,00',
        ]);

        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        return [...$dados, 'telefone' => $telefone, 'valor' => $valor];
    }
}
```

- [ ] **Step 4: Views**

`resources/views/cobrancas/index.blade.php`:

```blade
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
```

`resources/views/cobrancas/form.blade.php`:

```blade
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
```

- [ ] **Step 5: Rotas**

Em `routes/web.php`, adicione o import:

```php
use App\Http\Controllers\CobrancaController;
```

e substitua o grupo `auth` por:

```php
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/cobrancas');
    Route::resource('cobrancas', CobrancaController::class)->except(['show', 'destroy']);
    Route::post('/cobrancas/{cobranca}/pagar', [CobrancaController::class, 'pagar'])->name('cobrancas.pagar');
    Route::post('/cobrancas/{cobranca}/cancelar', [CobrancaController::class, 'cancelar'])->name('cobrancas.cancelar');
});
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./dev php artisan test --filter="CobrancaTelaTest|LoginTest"`
Expected: PASS (12 testes). O teste `test_login_com_senha_correta` continua passando (redireciona para `/`).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: tela de cobranças com cadastro, edição, baixa e cancelamento"
```

---

### Task 10: Importação de planilha

**Files:**
- Create: `app/Support/LeitorPlanilha.php`, `app/Support/ValidadorLinhaImportacao.php`, `app/Http/Controllers/ImportacaoController.php`
- Create: `resources/views/importar/form.blade.php`, `resources/views/importar/previa.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ImportacaoTest.php`

**Interfaces:**
- Consumes: `Telefone`, `ParserValor`, `ParserData` (Task 2); `Cobranca::registrar` (Task 3).
- Produces:
  - `LeitorPlanilha::ler(string $caminho, string $extensao): list<array<string, mixed>>` — primeira aba, cabeçalho convertido em `snake_case` sem acentos, linhas vazias ignoradas; CSV com `;` ou `,`, UTF-8 ou Windows-1252.
  - `ValidadorLinhaImportacao::validar(array $linha): array{dados: ?array{nome,telefone,valor,vencimento,link_pagamento}, erros: list<string>}`.
  - Rotas `importar.form` (GET), `importar.previa` (POST, campo `arquivo`), `importar.confirmar` (POST). Linhas válidas ficam na sessão `importacao` entre prévia e confirmação.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/ImportacaoTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function csv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cobrancas.csv', implode("\n", [
            'Nome;Telefone;Valor;Vencimento;Link Pagamento',
            'Maria;(11) 99999-8888;1.234,56;23/10/2026;https://pag.exemplo/1',
            'João;(21) 98888-7777;99,90;2026-10-25;https://pag.exemplo/2',
            'Sem Telefone;123;10,00;23/10/2026;https://pag.exemplo/3',
            ';;;;',
        ]));
    }

    public function test_previa_mostra_erros_e_confirmar_grava_so_validas(): void
    {
        $this->post('/importar', ['arquivo' => $this->csv()])
            ->assertOk()
            ->assertSee('2 linha(s) válida(s)')
            ->assertSee('telefone inválido')
            ->assertSessionHas('importacao', fn ($linhas) => count($linhas) === 2);

        $this->assertSame(0, Cobranca::count());

        $this->post('/importar/confirmar')
            ->assertRedirect(route('cobrancas.index'))
            ->assertSessionHas('ok', 'Importação concluída: 2 cobrança(s) nova(s), 0 já existente(s).');

        $maria = Cobranca::whereHas('cliente', fn ($q) => $q->where('telefone', '+5511999998888'))->sole();
        $this->assertSame('1234.56', $maria->valor);
        $this->assertSame('2026-10-23', $maria->vencimento->toDateString());
    }

    public function test_importar_de_novo_nao_duplica(): void
    {
        $this->post('/importar', ['arquivo' => $this->csv()]);
        $this->post('/importar/confirmar');
        $this->post('/importar', ['arquivo' => $this->csv()]);

        $this->post('/importar/confirmar')
            ->assertSessionHas('ok', 'Importação concluída: 0 cobrança(s) nova(s), 2 já existente(s).');

        $this->assertSame(2, Cobranca::count());
    }

    public function test_importa_xlsx(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($caminho);
        $writer->addRow(Row::fromValues(['nome', 'telefone', 'valor', 'vencimento', 'link_pagamento']));
        $writer->addRow(Row::fromValues(['Ana', '11977776666', 150.5, '30/10/2026', 'https://pag.exemplo/ana']));
        $writer->close();

        $this->post('/importar', ['arquivo' => new UploadedFile($caminho, 'cobrancas.xlsx', null, null, true)])
            ->assertOk()
            ->assertSee('1 linha(s) válida(s)');

        $this->post('/importar/confirmar');

        $this->assertSame('150.50', Cobranca::sole()->valor);
    }

    public function test_recusa_formato_desconhecido(): void
    {
        $this->post('/importar', ['arquivo' => UploadedFile::fake()->create('foto.png', 10)])
            ->assertSessionHasErrors('arquivo');
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=ImportacaoTest`
Expected: FAIL — 404 em `/importar`.

- [ ] **Step 3: Implementar leitor e validador**

`app/Support/LeitorPlanilha.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use SplFileObject;

final class LeitorPlanilha
{
    /** @return list<array<string, mixed>> linhas indexadas pelo cabeçalho (ex.: link_pagamento) */
    public static function ler(string $caminho, string $extensao): array
    {
        $leitor = match (strtolower($extensao)) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader(self::opcoesCsv($caminho)),
            default => throw new InvalidArgumentException('Formato não suportado. Use .xlsx ou .csv.'),
        };

        $leitor->open($caminho);
        $cabecalho = null;
        $linhas = [];

        try {
            foreach ($leitor->getSheetIterator() as $aba) {
                foreach ($aba->getRowIterator() as $row) {
                    $valores = $row->toArray();

                    if ($cabecalho === null) {
                        $cabecalho = array_map(fn ($c) => Str::slug((string) $c, '_'), $valores);

                        continue;
                    }

                    if (! array_filter($valores, fn ($v) => $v !== null && $v !== '')) {
                        continue;
                    }

                    $valores = array_pad(array_slice($valores, 0, count($cabecalho)), count($cabecalho), null);
                    $linhas[] = array_combine($cabecalho, $valores);
                }
                break; // só a primeira aba
            }
        } finally {
            $leitor->close();
        }

        return $linhas;
    }

    private static function opcoesCsv(string $caminho): CsvOptions
    {
        $primeiraLinha = (string) (new SplFileObject($caminho))->fgets();

        $opcoes = new CsvOptions;
        $opcoes->FIELD_DELIMITER = substr_count($primeiraLinha, ';') >= substr_count($primeiraLinha, ',') ? ';' : ',';
        $opcoes->ENCODING = mb_check_encoding((string) file_get_contents($caminho), 'UTF-8') ? 'UTF-8' : 'WINDOWS-1252';

        return $opcoes;
    }
}
```

`app/Support/ValidadorLinhaImportacao.php`:

```php
<?php

namespace App\Support;

final class ValidadorLinhaImportacao
{
    /**
     * @param  array<string, mixed>  $linha
     * @return array{dados: ?array{nome: string, telefone: string, valor: string, vencimento: string, link_pagamento: string}, erros: list<string>}
     */
    public static function validar(array $linha): array
    {
        $nome = trim((string) ($linha['nome'] ?? ''));
        $telefone = Telefone::normalizar((string) ($linha['telefone'] ?? ''));
        $valor = ParserValor::converter($linha['valor'] ?? null);
        $vencimento = ParserData::converter($linha['vencimento'] ?? null);
        $link = trim((string) ($linha['link_pagamento'] ?? ''));

        $erros = array_values(array_filter([
            $nome === '' ? 'nome vazio' : null,
            $telefone ? null : 'telefone inválido',
            $valor ? null : 'valor inválido',
            $vencimento ? null : 'vencimento inválido (use dd/mm/aaaa)',
            filter_var($link, FILTER_VALIDATE_URL) ? null : 'link_pagamento inválido',
        ]));

        if ($erros) {
            return ['dados' => null, 'erros' => $erros];
        }

        return [
            'dados' => [
                'nome' => $nome,
                'telefone' => $telefone,
                'valor' => $valor,
                'vencimento' => $vencimento->toDateString(),
                'link_pagamento' => $link,
            ],
            'erros' => [],
        ];
    }
}
```

- [ ] **Step 4: Controller e views**

`app/Http/Controllers/ImportacaoController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Support\LeitorPlanilha;
use App\Support\ValidadorLinhaImportacao;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ImportacaoController extends Controller
{
    public function form(): View
    {
        return view('importar.form');
    }

    public function previa(Request $request): View
    {
        $request->validate(['arquivo' => 'required|file|max:5120']);
        $arquivo = $request->file('arquivo');
        $extensao = strtolower($arquivo->getClientOriginalExtension());

        if (! in_array($extensao, ['xlsx', 'csv', 'txt'], true)) {
            throw ValidationException::withMessages(['arquivo' => 'Envie um arquivo .xlsx ou .csv.']);
        }

        $resultado = [];
        foreach (LeitorPlanilha::ler($arquivo->getRealPath(), $extensao) as $i => $linha) {
            $resultado[] = [
                'numero' => $i + 2,
                'original' => implode(' | ', array_map(
                    fn ($v) => $v instanceof DateTimeInterface ? $v->format('d/m/Y') : (string) $v,
                    $linha,
                )),
            ] + ValidadorLinhaImportacao::validar($linha);
        }

        $validas = array_values(array_filter(array_column($resultado, 'dados')));
        $request->session()->put('importacao', $validas);

        return view('importar.previa', ['resultado' => $resultado, 'validas' => count($validas)]);
    }

    public function confirmar(Request $request): RedirectResponse
    {
        $linhas = $request->session()->pull('importacao', []);
        $novas = 0;

        foreach ($linhas as $dados) {
            if (Cobranca::registrar($dados)->wasRecentlyCreated) {
                $novas++;
            }
        }

        $existentes = count($linhas) - $novas;

        return redirect()->route('cobrancas.index')
            ->with('ok', "Importação concluída: {$novas} cobrança(s) nova(s), {$existentes} já existente(s).");
    }
}
```

`resources/views/importar/form.blade.php`:

```blade
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
```

`resources/views/importar/previa.blade.php`:

```blade
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
```

- [ ] **Step 5: Rotas**

Em `routes/web.php`, adicione o import:

```php
use App\Http\Controllers\ImportacaoController;
```

e, dentro do grupo `auth`, adicione:

```php
    Route::get('/importar', [ImportacaoController::class, 'form'])->name('importar.form');
    Route::post('/importar', [ImportacaoController::class, 'previa'])->name('importar.previa');
    Route::post('/importar/confirmar', [ImportacaoController::class, 'confirmar'])->name('importar.confirmar');
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./dev php artisan test --filter=ImportacaoTest`
Expected: PASS (4 testes).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: importação de planilha xlsx/csv com prévia e sem duplicar"
```

---

### Task 11: Telas de histórico, régua e configurações

**Files:**
- Create: `app/Http/Controllers/EnvioController.php`, `ReguaController.php`, `ConfiguracaoController.php`
- Create: `resources/views/envios.blade.php`, `resources/views/regua.blade.php`, `resources/views/configuracoes.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TelasAdministrativasTest.php`

**Interfaces:**
- Consumes: `Envio`, `MensagemRecebida`, `EtapaRegua`, `Configuracao` (Task 3); `Telefone::normalizar` (Task 2).
- Produces: rotas `envios.index` (`?status=`), `regua.edit`/`regua.update` (campos `etapas[{id}][dias|template_meta|idioma|ativa]`), `configuracoes.edit`/`configuracoes.update`. Segredos (`meta_token`, `meta_app_secret`) não são exibidos e são mantidos quando enviados em branco; ligar `modo_teste` exige `numero_teste` válido.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/TelasAdministrativasTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelasAdministrativasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_historico_mostra_falhas_com_motivo(): void
    {
        Envio::create([
            'cobranca_id' => Cobranca::factory()->create()->id,
            'etapa_regua_id' => EtapaRegua::first()->id,
            'status' => 'falhou',
            'erro' => 'número sem WhatsApp',
        ]);

        $this->get('/envios')->assertOk()->assertSee('número sem WhatsApp');
        $this->get('/envios?status=enviada')->assertOk()->assertDontSee('número sem WhatsApp');
    }

    public function test_edita_regua(): void
    {
        $etapa = EtapaRegua::where('dias', 15)->first();

        $this->put('/regua', ['etapas' => [
            $etapa->id => ['dias' => 20, 'template_meta' => 'ultimo_aviso_v2', 'idioma' => 'pt_BR'],
        ]])->assertRedirect();

        $etapa->refresh();
        $this->assertSame(20, $etapa->dias);
        $this->assertSame('ultimo_aviso_v2', $etapa->template_meta);
        $this->assertFalse($etapa->ativa);
    }

    public function test_regua_recusa_nome_de_template_invalido(): void
    {
        $etapa = EtapaRegua::first();

        $this->put('/regua', ['etapas' => [
            $etapa->id => ['dias' => -3, 'template_meta' => 'Nome Com Espaço', 'idioma' => 'pt_BR', 'ativa' => '1'],
        ]])->assertSessionHasErrors("etapas.{$etapa->id}.template_meta");
    }

    public function test_salva_configuracoes_e_mantem_segredo_em_branco(): void
    {
        Configuracao::set('meta_token', 'token-antigo');

        $this->put('/configuracoes', [
            'meta_token' => '',
            'meta_phone_number_id' => '123456789',
            'meta_api_versao' => 'v23.0',
            'email_avisos' => 'dono@exemplo.com',
            'numero_teste' => '(11) 90000-0000',
            'modo_teste' => '1',
        ])->assertRedirect();

        $this->assertSame('token-antigo', Configuracao::get('meta_token'));
        $this->assertSame('123456789', Configuracao::get('meta_phone_number_id'));
        $this->assertSame('+5511900000000', Configuracao::get('numero_teste'));
        $this->assertTrue(Configuracao::modoTeste());

        $this->get('/configuracoes')->assertOk()->assertDontSee('token-antigo')->assertSee('/webhook/whatsapp');
    }

    public function test_modo_teste_exige_numero_de_teste(): void
    {
        $this->put('/configuracoes', ['modo_teste' => '1'])->assertSessionHasErrors('numero_teste');

        $this->assertFalse(Configuracao::modoTeste());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=TelasAdministrativasTest`
Expected: FAIL — 404 em `/envios`, `/regua`, `/configuracoes`.

- [ ] **Step 3: Controllers**

`app/Http/Controllers/EnvioController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Envio;
use App\Models\MensagemRecebida;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnvioController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        return view('envios', [
            'status' => $status,
            'envios' => Envio::with(['cobranca.cliente', 'etapa'])
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest()
                ->paginate(50)
                ->withQueryString(),
            'mensagens' => MensagemRecebida::with('cliente')->latest('recebida_em')->limit(20)->get(),
        ]);
    }
}
```

`app/Http/Controllers/ReguaController.php`:

```php
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
```

`app/Http/Controllers/ConfiguracaoController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Support\Telefone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfiguracaoController extends Controller
{
    private const SEGREDOS = ['meta_token', 'meta_app_secret'];

    public function edit(): View
    {
        $valores = [];
        foreach (Configuracao::CHAVES as $chave) {
            $valores[$chave] = in_array($chave, self::SEGREDOS, true) ? null : Configuracao::get($chave);
        }

        $segredosPreenchidos = [];
        foreach (self::SEGREDOS as $chave) {
            $segredosPreenchidos[$chave] = filled(Configuracao::get($chave));
        }

        return view('configuracoes', compact('valores', 'segredosPreenchidos'));
    }

    public function update(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'meta_token' => 'nullable|string',
            'meta_phone_number_id' => 'nullable|digits_between:5,30',
            'meta_app_secret' => 'nullable|string',
            'meta_verify_token' => 'nullable|string|max:255',
            'meta_api_versao' => ['nullable', 'regex:/^v\d+\.\d+$/'],
            'email_avisos' => 'nullable|email',
            'numero_teste' => 'nullable|string',
        ]);

        $modoTeste = $request->boolean('modo_teste');
        $numeroTeste = filled($dados['numero_teste'] ?? null) ? Telefone::normalizar($dados['numero_teste']) : null;

        if (filled($dados['numero_teste'] ?? null) && ! $numeroTeste) {
            throw ValidationException::withMessages(['numero_teste' => 'Número de teste inválido.']);
        }
        if ($modoTeste && ! $numeroTeste) {
            throw ValidationException::withMessages(['numero_teste' => 'Informe o número de teste para ligar o modo teste.']);
        }

        foreach ($dados as $chave => $valor) {
            if (in_array($chave, self::SEGREDOS, true) && blank($valor)) {
                continue;
            }
            Configuracao::set($chave, $chave === 'numero_teste' ? $numeroTeste : $valor);
        }
        Configuracao::set('modo_teste', $modoTeste ? '1' : '0');

        return back()->with('ok', 'Configurações salvas.');
    }
}
```

- [ ] **Step 4: Views**

`resources/views/envios.blade.php`:

```blade
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
```

`resources/views/regua.blade.php`:

```blade
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
```

`resources/views/configuracoes.blade.php`:

```blade
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
```

- [ ] **Step 5: Rotas**

Em `routes/web.php`, adicione os imports:

```php
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\EnvioController;
use App\Http\Controllers\ReguaController;
```

e, dentro do grupo `auth`, adicione:

```php
    Route::get('/envios', [EnvioController::class, 'index'])->name('envios.index');
    Route::get('/regua', [ReguaController::class, 'edit'])->name('regua.edit');
    Route::put('/regua', [ReguaController::class, 'update'])->name('regua.update');
    Route::get('/configuracoes', [ConfiguracaoController::class, 'edit'])->name('configuracoes.edit');
    Route::put('/configuracoes', [ConfiguracaoController::class, 'update'])->name('configuracoes.update');
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./dev php artisan test --filter=TelasAdministrativasTest`
Expected: PASS (5 testes).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: telas de histórico, régua e configurações"
```

---

### Task 12: Resumo diário por e-mail

**Files:**
- Create: `app/Console/Commands/ResumoDiario.php`, `app/Mail/ResumoDiarioMail.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/ResumoDiarioTest.php`

**Interfaces:**
- Consumes: `Envio`, `Configuracao` (Task 3).
- Produces: comando `php artisan regua:resumo` (envia só se há `email_avisos` e envios não-`pulada` criados hoje); `App\Mail\ResumoDiarioMail(Collection $envios)`; agendamento seg–sex 10:00.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/ResumoDiarioTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\ResumoDiarioMail;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\Envio;
use App\Models\EtapaRegua;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResumoDiarioTest extends TestCase
{
    use RefreshDatabase;

    private function envio(string $status, ?string $erro = null): void
    {
        Envio::create([
            'cobranca_id' => Cobranca::factory()->create()->id,
            'etapa_regua_id' => EtapaRegua::first()->id,
            'status' => $status,
            'erro' => $erro,
        ]);
    }

    public function test_envia_resumo_com_totais_e_falhas(): void
    {
        Mail::fake();
        Configuracao::set('email_avisos', 'dono@exemplo.com');
        $this->envio('enviada');
        $this->envio('entregue');
        $this->envio('falhou', 'número sem WhatsApp');
        $this->envio('pulada');

        $this->artisan('regua:resumo')->assertSuccessful();

        Mail::assertSent(ResumoDiarioMail::class, function (ResumoDiarioMail $mail) {
            $mail->assertSeeInHtml('Enviadas: 2');
            $mail->assertSeeInHtml('Falhas: 1');
            $mail->assertSeeInHtml('número sem WhatsApp');

            return $mail->hasTo('dono@exemplo.com') && $mail->envios->count() === 3;
        });
    }

    public function test_nao_envia_sem_movimento(): void
    {
        Mail::fake();
        Configuracao::set('email_avisos', 'dono@exemplo.com');

        $this->artisan('regua:resumo')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./dev php artisan test --filter=ResumoDiarioTest`
Expected: FAIL — `The command "regua:resumo" does not exist`.

- [ ] **Step 3: Implementar**

`app/Mail/ResumoDiarioMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class ResumoDiarioMail extends Mailable
{
    public function __construct(public Collection $envios) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Régua de cobrança — resumo de '.today()->format('d/m/Y'));
    }

    public function content(): Content
    {
        $enviadas = $this->envios->whereIn('status', ['enviada', 'entregue', 'lida'])->count();
        $pendentes = $this->envios->where('status', 'pendente')->count();
        $falhas = $this->envios->where('status', 'falhou');

        $html = "<p>Enviadas: {$enviadas}<br>Pendentes: {$pendentes}<br>Falhas: {$falhas->count()}</p>";

        if ($falhas->isNotEmpty()) {
            $html .= '<h3>Falhas</h3><ul>';
            foreach ($falhas as $envio) {
                $html .= '<li>'.e($envio->cobranca->cliente->nome).' — '.e($envio->erro).'</li>';
            }
            $html .= '</ul>';
        }

        return new Content(htmlString: $html);
    }
}
```

`app/Console/Commands/ResumoDiario.php`:

```php
<?php

namespace App\Console\Commands;

use App\Mail\ResumoDiarioMail;
use App\Models\Configuracao;
use App\Models\Envio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ResumoDiario extends Command
{
    protected $signature = 'regua:resumo';

    protected $description = 'Envia por e-mail o resumo dos envios de hoje';

    public function handle(): int
    {
        $email = Configuracao::get('email_avisos');
        $envios = Envio::with('cobranca.cliente')
            ->whereDate('created_at', today())
            ->where('status', '!=', 'pulada')
            ->get();

        if (! $email || $envios->isEmpty()) {
            $this->info('Nada a enviar.');

            return self::SUCCESS;
        }

        Mail::to($email)->send(new ResumoDiarioMail($envios));
        $this->info('Resumo enviado.');

        return self::SUCCESS;
    }
}
```

Em `routes/console.php`, adicione ao final:

```php
Schedule::command('regua:resumo')
    ->weekdays()->at('10:00')->timezone('America/Sao_Paulo');
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./dev php artisan test --filter=ResumoDiarioTest`
Expected: PASS (2 testes).

Run: `./dev php artisan schedule:list`
Expected: três agendamentos (`regua:executar` 9h, `queue:work` a cada minuto, `regua:resumo` 10h).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ResumoDiario.php app/Mail/ResumoDiarioMail.php routes/console.php tests/Feature/ResumoDiarioTest.php
git commit -m "feat: resumo diário dos envios por e-mail"
```

---

### Task 13: Guia de implantação e verificação final

**Files:**
- Create: `DEPLOY.md`

**Interfaces:**
- Consumes: tudo.
- Produces: documento com o passo a passo de Hostinger, Meta (incluindo o texto dos 5 modelos) e primeiro uso.

- [ ] **Step 1: Escrever `DEPLOY.md`**

````markdown
# Implantação — Régua de Cobrança

## 1. Hostinger (plano Business)

1. No hPanel, adicione o domínio (ou subdomínio, ex.: `cobranca.seudominio.com.br`) e ative o **SSL**.
2. **Avançado → Configuração do PHP**: escolha **PHP 8.3**.
3. **Bancos de dados → MySQL**: crie banco, usuário e senha. Anote os três.
4. **Avançado → Acesso SSH**: ative e anote host, porta e usuário.
5. Envie o projeto para `~/domains/SEU_DOMINIO/regua` (sem a pasta `vendor/` e sem `.env`):
   - Pelo Gerenciador de Arquivos (zip + extrair), **ou**
   - via `git clone` de um repositório privado seu.
6. Pelo SSH:
   ```bash
   cd ~/domains/SEU_DOMINIO/regua
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   php artisan key:generate
   ```
7. Edite `.env`:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://SEU_DOMINIO
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=...
   DB_USERNAME=...
   DB_PASSWORD=...
   QUEUE_CONNECTION=database
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.hostinger.com
   MAIL_PORT=465
   MAIL_SCHEME=smtps
   MAIL_USERNAME=avisos@SEU_DOMINIO
   MAIL_PASSWORD=...
   MAIL_FROM_ADDRESS=avisos@SEU_DOMINIO
   ```
   (Crie a caixa `avisos@SEU_DOMINIO` em **E-mails** no hPanel.)
8. Rode:
   ```bash
   php artisan migrate --force
   php artisan usuario:criar seu@email.com
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
9. Aponte o site para a pasta `public`:
   ```bash
   cd ~/domains/SEU_DOMINIO
   mv public_html public_html_antigo
   ln -s regua/public public_html
   ```
10. **Avançado → Cron Jobs**: crie um cron **a cada minuto** (`* * * * *`) com o comando:
    ```
    cd ~/domains/SEU_DOMINIO/regua && php artisan schedule:run >> /dev/null 2>&1
    ```

> Sempre que alterar o `.env`, rode `php artisan config:cache` de novo.

## 2. Meta (WhatsApp Cloud API)

1. Crie uma conta em business.facebook.com e verifique a empresa.
2. Em developers.facebook.com → **Criar app** → tipo **Empresa** → adicione o produto **WhatsApp**.
3. Adicione e verifique o **número dedicado** (não pode estar em uso no WhatsApp comum).
4. Crie um **usuário do sistema** (Configurações do negócio → Usuários do sistema), dê acesso ao app e gere um **token permanente** com as permissões `whatsapp_business_messaging` e `whatsapp_business_management`.
5. Anote o **Phone number ID** (WhatsApp → Configuração da API) e o **App secret** (Configurações do app → Básico).
6. Em **WhatsApp → Gerenciador de modelos**, crie os 5 modelos abaixo, categoria **Utilidade**, idioma **Português (BR)**. Variáveis: `{{1}}` nome, `{{2}}` valor, `{{3}}` vencimento, `{{4}}` link.

| Nome | Texto |
|---|---|
| `cobranca_lembrete_3dias` | Olá {{1}}! Passando para lembrar que sua fatura de {{2}} vence em {{3}}. Para pagar, acesse: {{4}} . Obrigado! |
| `cobranca_vence_hoje` | Olá {{1}}, sua fatura de {{2}} vence hoje ({{3}}). Pague pelo link: {{4}} . Se já pagou, desconsidere. |
| `cobranca_atraso_3dias` | Olá {{1}}, identificamos que a fatura de {{2}} com vencimento em {{3}} ainda está em aberto. Para regularizar: {{4}} . Se já pagou, desconsidere. |
| `cobranca_atraso_7dias` | Olá {{1}}, a fatura de {{2}} vencida em {{3}} continua em aberto. Pedimos que regularize o quanto antes: {{4}} . Se já pagou, desconsidere. |
| `cobranca_ultimo_aviso` | Olá {{1}}, este é o último aviso sobre a fatura de {{2}} vencida em {{3}}. Regularize pelo link {{4}} ou responda esta mensagem para conversarmos. |

7. No sistema, abra **Configurações** e preencha token, Phone number ID, App secret, um verify token inventado por você e o e-mail de avisos.
8. Na Meta, em **WhatsApp → Configuração → Webhook**, informe a URL mostrada na tela de Configurações (`https://SEU_DOMINIO/webhook/whatsapp`) e o mesmo verify token. Assine o campo **messages**.

## 3. Primeiro uso (com segurança)

1. Em **Configurações**, ligue o **modo teste** com o seu próprio número.
2. Cadastre ou importe algumas cobranças.
3. Pelo SSH, rode `php artisan regua:executar` e confira as mensagens no seu WhatsApp e em **Histórico**.
4. Estando tudo certo, desligue o modo teste. A partir daí a régua roda sozinha às 9h dos dias úteis.

## Desenvolvimento local

```bash
docker build -t regua-dev -f docker/dev.Dockerfile docker
./dev composer install
./dev php artisan test
PORTA=8000 ./dev php artisan serve --host=0.0.0.0 --port=8000
```
````

- [ ] **Step 2: Rodar a suíte completa**

Run: `./dev php artisan test`
Expected: PASS — todos os testes (aprox. 60) passando, 0 falhas.

- [ ] **Step 3: Teste manual local**

```bash
./dev php artisan migrate:fresh
./dev php artisan usuario:criar teste@exemplo.com   # digite uma senha de 8+ caracteres
PORTA=8000 ./dev php artisan serve --host=0.0.0.0 --port=8000
```

Abra `http://localhost:8000`, faça login, cadastre uma cobrança, importe um CSV, marque como paga, edite a régua e salve as configurações. Expected: todas as telas abrem sem erro e as mensagens de sucesso aparecem.

- [ ] **Step 4: Commit**

```bash
git add DEPLOY.md
git commit -m "docs: guia de implantação na Hostinger e configuração da Meta"
```
