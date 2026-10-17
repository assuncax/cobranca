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
   **Guarde o `APP_KEY` gerado em local seguro e nunca rode `key:generate` de novo: as credenciais da Meta ficam criptografadas com ele e se tornam ilegíveis se a chave mudar.**
7. Edite `.env`:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://SEU_DOMINIO
   LOG_STACK=daily
   LOG_LEVEL=warning
   SESSION_SECURE_COOKIE=true
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
10. Pelo SSH, rode `php -v` e confirme que é a versão **8.3**. Se não for, use o caminho completo do PHP 8.3 na linha do cron (Hostinger: por exemplo `/opt/alt/php83/usr/bin/php`).
11. **Avançado → Cron Jobs**: crie um cron **a cada minuto** (`* * * * *`) com o comando:
    ```
    cd ~/domains/SEU_DOMINIO/regua && php artisan schedule:run >> /dev/null 2>&1
    ```
    (ou `/opt/alt/php83/usr/bin/php artisan schedule:run ...` se o `php` padrão não for 8.3)

> Sempre que alterar o `.env`, rode `php artisan config:cache` de novo.

## Atualizar o sistema

1. Envie o código novo (upload ou `git pull`).
2. Pelo SSH, na pasta do projeto:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```

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

```powershell
docker build -t regua-dev -f docker/dev.Dockerfile docker
.\dev.ps1 composer install
.\dev.ps1 php artisan test
$env:PORTA=8000; .\dev.ps1 php artisan serve --host=0.0.0.0 --port=8000
```
