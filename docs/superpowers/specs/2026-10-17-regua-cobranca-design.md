# Régua de Cobrança via WhatsApp — Design

**Data:** 2026-10-17
**Status:** aprovado pelo usuário, aguardando revisão do documento

## 1. Objetivo

Enviar automaticamente lembretes de cobrança pelo WhatsApp para clientes, antes e depois do vencimento.
O sistema **não** emite boletos/Pix nem identifica pagamentos sozinho: o link de pagamento é informado no cadastro e o usuário marca a cobrança como paga manualmente.

## 2. Decisões

| Tema | Decisão |
|---|---|
| Hospedagem | Hostinger, plano de hospedagem **Business** (a contratar) |
| Linguagem / framework | PHP 8 + Laravel |
| Banco | MySQL da hospedagem |
| Agendamento | Cron do hPanel executando `php artisan schedule:run` a cada minuto |
| Canal | WhatsApp Cloud API oficial (Meta), via HTTP direto |
| Entrada de dados | Importação de planilha (`.xlsx`/`.csv`) + cadastro manual na tela |
| Baixa de pagamento | Manual (botão "marcar como pago") |
| Usuários | Apenas o dono do sistema (login único) |

## 3. Régua padrão

Envios às **09:00, somente em dias úteis (seg–sex)**. Dias e modelos editáveis na tela.

| Etapa | Dias relativos ao vencimento | Conteúdo (modelo aprovado na Meta) |
|---|---|---|
| 1 | -3 | Fatura de R$ {valor} vence em {data}. {link} |
| 2 | 0 | Fatura vence hoje. {link} |
| 3 | +3 | Fatura de {data} está em aberto. {link} |
| 4 | +7 | Lembrete mais firme. {link} |
| 5 | +15 | Último aviso. {link} |

Mensagens iniciadas pela empresa exigem **modelos (templates) aprovados** na Meta, categoria *utility*. As variáveis são: nome, valor, data de vencimento e link.

## 4. Telas (autenticadas)

1. **Cobranças**: lista com filtros (a vencer / vencidas / pagas / canceladas), cadastrar, editar, marcar como paga, cancelar.
2. **Importar planilha**: upload com colunas `nome, telefone, valor, vencimento, link_pagamento`; prévia com erros por linha antes de gravar; cliente reaproveitado se o telefone já existir.
3. **Histórico de envios**: data/hora, cliente, etapa, status, motivo de falha, respostas recebidas.
4. **Régua**: editar dias, nome do modelo na Meta e ativar/desativar cada etapa.
5. **Configurações**: credenciais da Meta (token, phone number ID, verify token do webhook), e-mail para avisos, **modo teste** (número de teste).

## 5. Modelo de dados

- `users`: login do dono.
- `clientes`: id, nome, telefone (E.164, ex. `+5511999999999`, único).
- `cobrancas`: id, cliente_id, valor (decimal), vencimento (date), link_pagamento, status (`aberta`|`paga`|`cancelada`), pago_em.
- `etapas_regua`: id, ordem, dias (int, pode ser negativo), template_meta, idioma (`pt_BR`), ativa (bool).
- `envios`: id, cobranca_id, etapa_id, status (`pendente`|`enviada`|`entregue`|`lida`|`falhou`|`pulada`), tentativas, meta_message_id, erro, enviado_em. **Índice único (cobranca_id, etapa_id).**
- `mensagens_recebidas`: id, telefone, cliente_id (nullable), texto, recebida_em.

## 6. Rotina diária

Executada às 09:00 em dias úteis (fuso `America/Sao_Paulo`):

1. Seleciona cobranças `aberta`.
2. Para cada uma, determina as etapas ativas com `vencimento + dias <= hoje` que ainda não têm registro em `envios`.
3. Envia somente a **mais recente** dessas etapas; as anteriores são registradas como `pulada`.
   - Exemplo: cobrança cadastrada já com 8 dias de atraso → envia só a etapa +7.
   - Efeito: etapas que cairiam no fim de semana saem na segunda-feira.
4. O índice único em `envios` garante que nenhuma etapa seja enviada duas vezes, mesmo se a rotina rodar mais de uma vez.
5. Após a última etapa, nada mais é enviado; a cobrança permanece `aberta`.
6. Ao final, envia e-mail de resumo ao dono (enviadas, falhas e motivos).

Cobranças `paga` ou `cancelada` nunca recebem mensagens.

## 7. Integração com a Meta

- **Envio:** `POST https://graph.facebook.com/{versão}/{phone_number_id}/messages` com `type: template`.
- **Webhook:** rota pública `/webhook/whatsapp`
  - `GET`: verificação do `hub.verify_token`.
  - `POST`: valida a assinatura `X-Hub-Signature-256` com o app secret; atualiza status de `envios` pelo `meta_message_id`; grava mensagens recebidas em `mensagens_recebidas` e avisa o dono por e-mail. Não há resposta automática.
- O cliente da Meta fica atrás de uma interface (`EnviadorWhatsApp`), com implementação real e implementação falsa para testes.

## 8. Tratamento de erros

| Situação | Comportamento |
|---|---|
| Telefone inválido | Rejeitado no cadastro/importação |
| Erro temporário (5xx, timeout, limite de taxa) | Até 3 novas tentativas com intervalo crescente; depois `falhou` |
| Erro definitivo (número sem WhatsApp, template inválido, token expirado) | `falhou` com motivo, destacado na tela e no resumo diário |
| Webhook com assinatura inválida | Ignorado (HTTP 403) e registrado no log |

## 9. Modo teste

Com o modo teste ligado, todo envio vai para o número de teste configurado, mantendo o conteúdo real (nome/valor/link do cliente). Os envios são gravados normalmente no histórico, marcados como teste, e **não** contam para a trava de "já enviado".

## 10. Testes

- Testes unitários da regra de seleção de etapa: antes do vencimento, no dia, atrasado, fim de semana, cadastro tardio, cobrança paga/cancelada, etapa desativada.
- Teste de idempotência: rodar a rotina duas vezes no mesmo dia não duplica envios.
- Testes de importação: planilha válida, linhas com erro, telefone repetido.
- Testes do webhook: verificação, assinatura inválida, atualização de status.
- Envio à Meta substituído pela implementação falsa em todos os testes.

## 11. Fora do escopo

- Geração de boleto/Pix e baixa automática de pagamento.
- Réguas diferentes por cliente.
- Responder clientes pelo sistema.
- Múltiplos usuários/permissões.
- Outros canais (e-mail/SMS) para o cliente.

## 12. Pré-requisitos do usuário

1. Contratar o plano Business da Hostinger e um domínio (HTTPS é exigido pelo webhook da Meta).
2. Criar conta Meta Business, app no Meta for Developers e registrar um número de WhatsApp dedicado.
3. Cadastrar e aprovar os 5 modelos de mensagem.
