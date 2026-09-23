# Documentação das APIs do TGC

Este documento descreve as APIs e integrações expostas pelo backend. A partir da migração do front-end, o fluxo de botões inline do Telegram deixou de ser a interface oficial do sistema. O backend continua aceitando comandos do Telegram, mas a integração principal para o produto atual usa payloads em JSON via API HTTP e não depende mais de `callback_query`.

## Visão geral

O projeto expõe duas integrações relevantes:

- `public/botMain.php`: webhook legado do Telegram que processa mensagens/commands do bot.
- `public/agendamentosAPI.php`: API pública principal usada pelo front-end para consultar e alterar o fluxo de partidas/agendamentos.
- `public/admin.php`: painel administrativo interno e não é uma API de integração da aplicação cliente.

## Autenticação

A API recebe o token de segurança no header `X-Telegram-Bot-API-Secret-Token`.

- Header: `X-Telegram-Bot-API-Secret-Token`
- Validação: o valor é comparado com `WEBHOOK_SECRET` do `.env`.
- Sem token ou token inválido: `HTTP 403 Forbidden`.

---

## 1. Webhook do Telegram (`public/botMain.php`)

Esse endpoint continua sendo o ponto de entrada do bot do Telegram quando o Telegram envia `Update`s para o webhook.

- URL: `https://[dominio]/public/botMain.php`
- Método: `POST`
- Conteúdo: payload do Telegram em formato JSON
- Uso principal: receber comandos de texto, mensagens e eventos do bot.

Observação importante: os callbacks de botão inline (`callback_query`) não são mais o fluxo preferencial no front-end atual e não devem ser usados como contrato da API do produto.

### Exemplo de payload

```bash
curl -X POST "https://seu-dominio.com/public/botMain.php" \
  -H "Content-Type: application/json" \
  -H "X-Telegram-Bot-API-Secret-Token: SEU_TOKEN_AQUI" \
  -d '{
   "update_id": 123456789,
   "message": {
     "from": {
       "pilotID": 123,
       "pilotName": "Piloto 1"
     },
     "function": "/agendar 10"
   }
  }'
```

### Comandos suportados

Os comandos abaixo são interpretados pelo backend e podem ser usados por mensagem no Telegram ou por chamadas programáticas via API genérica.

#### Públicos
- `/inscrever`
- `/ajuda`
- `/ayuda`
- `/links`

#### Pilotos
- `/meuNick`
- `/partidas`
- `/agendar`
- `/play`
- `/audit`

#### Administradores
- `/resultado ID`
- `/resultado ID [nickname]`
- `/resultado ID empate`
- `/resultado ID woduplo`

A partir da nova arquitetura, os botões inline e `callback_query` foram removidos do contrato do front-end. O cliente atual envia ações por comandos/texto ou por payloads de API estruturados.

---

## 2. API externa de agendamentos (`public/agendamentosAPI.php`)

Essa é a API principal utilizada pelo front-end externo. Ela abstrai os comandos do bot em respostas JSON limpas e padronizadas.

- URL: `https://[dominio]/public/agendamentosAPI.php`
- Método: `POST`
- Header obrigatório: `X-Telegram-Bot-API-Secret-Token`
- Corpo esperado:

```json
{
  "message": {
   "from": {
     "pilotID": 12345,
     "pilotName": "Piloto 1"
   },
   "function": "/agendar 10",
   "bookDate": "27/08",
   "bookTime": "19:00"
  }
}
```

### Estrutura da resposta

Toda resposta da API tem o formato base:

```json
{
  "ok": true,
  "response": "Mensagem legível para o cliente",
  "data": {
   "matchID": 10,
   "state": "REQUER_PROPOSTA"
  }
}
```

Campos importantes:
- `ok`: indica sucesso da operação.
- `response`: texto legível retornado ao cliente.
- `data`: objeto com informações estruturadas para o frontend decidir a tela/ação.
- `data.state`: estado do fluxo atual, usado pelo cliente para controlar a UI.

### Comandos suportados

A API aceita os mesmos comandos textuais do bot, com o mesmo comportamento sem depender de callbacks do Telegram.

- `/inscrever`
- `/partidas`
- `/meuNick`
- `/agendar`
- `/proposal`
- `/proposal_confirm`
- `/play`
- `/resultado`
- `/audit`
- `/ajuda`
- `/links`
- `/poleposition`
- `/polepositionsendtimes`
- `/polerounds`
- `/polefinalstandings`

### Fluxo principal do agendamento

A seguir está a descrição prática do fluxo e dos estados possíveis.

#### 1) Início do agendamento

Comando:
- `/agendar ID`

Possíveis estados:
- `ERRO_NAO_ENCONTRADO`: partida não existe.
- `ERRO_PARTIDA_COMPUTADOR`: jogo contra computador/Pole Position, sem agendamento.
- `ERRO_NAO_PERTENCE`: piloto não pertence à partida.
- `REQUER_PROPOSTA`: ainda não há proposta ativa; frontend deve solicitar a data/hora.
- `AGUARDANDO_OPONENTE`: o usuário enviou uma proposta e está aguardando resposta do adversário.
- `REQUER_DECISAO_PROPOSTA`: existe um convite recebido e o usuário precisa aceitar ou contra-propor.
- `CONFIRMADO_PODE_EDITAR`: agendamento já confirmado e a partida pode ser reagendada.

Fluxo:
- Usuário chama `/agendar 10`
- Frontend recebe `state`
- Se `REQUER_PROPOSTA`, coleta data/hora e chama `/proposal 10`
- Se `AGUARDANDO_OPONENTE`, aguarda o adversário
- Se `REQUER_DECISAO_PROPOSTA`, mostra opções de aceitar/recusar ou propor nova data
- Se `CONFIRMADO_PODE_EDITAR`, partida agendada e aceita novo agendamento

#### 2) Proposta de horário

Comando:
- `/proposal ID` com `bookDate` e `bookTime`

Validações:
- `FORA_DO_PRAZO`: data oferecida passou do deadline da partida
- `MENOS_DE_2_HORAS`: proposta muito próxima do momento atual

Estado de sucesso:
- `AGUARDANDO_OPONENTE`

Fluxo:
- Usuário envia data/hora válida
- API salva a proposta em `schedules` com status `PROPOSTO`
- Partida passa para status `PROPOSTO`
- Frontend apenas exibe que a proposta foi enviada e aguarda confirmação

#### 3) Confirmação da proposta

Comando:
- `/proposal_confirm ID`

Possíveis estados:
- `ERRO_NENHUMA_PROPOSTA`: não existe proposta ativa para confirmar
- `CONFIRMADO`: confirmação concluída com sucesso

Fluxo:
- O oponente aceita a proposta
- API atualiza `status` para `CONFIRMADO`
- Partida passa para `AGENDADO`
- Frontend pode mostrar confirmação e permitir que o usuário use `/play ID`

#### 4) Notificação de presença no horário (`/play`)

Comando:
- `/play ID`

Possíveis estados:
- `PLAY_MUITO_CEDO`: tentativa antes da janela válida do jogo
- `JOGADOR_ATRASADO`: feriado/atraso fora do limite de janela
- `JOGADOR_PRONTO`: agendamento confirmado e jogador compareceu no horário
- `JOGADOR_PRONTO_SEM_AGENDAMENTO`: jogador marcou presença mesmo sem confirmação do outro lado
- `STATUS_PLAY_DESCONHECIDO`: status da partida não permite uso do `/play`

Fluxo:
- Há uma janela de ±30 minutos em torno do horário agendado
- Se o status do agendamento está `CONFIRMADO`, o `/play` aceita normalmente
- Se o status está `PROPOSTO`, ainda registra presença, mas sem confirmação completa

#### 5) Resultado da partida

Comando:
- `/resultado ID`
- `/resultado ID nickname`
- `/resultado ID empate`
- `/resultado ID woduplo`

Possíveis estados:
- `ERRO_FALTA_ID`: faltou o número da partida
- `ERRO_APENAS_ADMIN`: ação executada por usuário sem permissão
- `ERRO_NAO_ENCONTRADO`: partida inexistente
- `ERRO_PARTIDA_COMPUTADOR`: partida de Pole Position / computador
- `REQUER_RESULTADO_ADMIN`: admin consultou a partida e precisa informar o vencedor
- `ERRO_OPCAO_INVALIDA`: nickname/opção inválida
- `FINALIZADO_ADMIN`: resultado registrado com sucesso

Fluxo:
- Admin chama `/resultado 10`
- API retorna os pilotos e lista as opções válidas
- O admin informa vencedor, empate ou W.O. duplo
- API atualiza `winnerID` e concede status `CONCLUIDO` na partida

---

## 3. APIs de Pole Position e classificação

Além do fluxo normal de agendamento, a API também expõe o conjunto de endpoints específicos do modo Pole Position / T6, usados para envio de tempos e leitura de classificações por rodada e geral.

### 3.1. `/poleposition ID`

- Objetivo: listar as pistas da rodada e preparar o envio de tempos.
- Resposta esperada: texto com pistas + estado `PENDENTE_TEMPOS` em `data.state`.
- Estado de sucesso: `PENDENTE_TEMPOS`
- Erros possíveis:
  - `ERRO_NAO_ENCONTRADO`
  - `RODADA_FINALIZADA`
  - `ERRO_NAO_PERTENCE`

Fluxo:
- Cliente chama `/poleposition 42`
- API valida se a partida existe, participa do confronto e não está encerrada
- Retorna as pistas da partida e orienta o envio dos tempos

### 3.2. `/polepositionsendtimes ID`

- Objetivo: registrar os tempos e, opcionalmente, o link do vídeo/prova.
- Payload esperado: `times` como lista com `pista` e `tempo`, além de `videoLink` quando houver.
- Estados possíveis:
  - `CONFIRMADO_PODE_EDITAR`: tempos enviados com sucesso e link disponível.
  - `CONFIRMADO_SEM_VIDEO`: tempos enviados com sucesso, sem vídeo/prova.
  - `ERRO_DADOS`: formato inválido de tempo ou pista.
  - `ERRO_NAO_ENCONTRADO`
  - `RODADA_FINALIZADA`

Fluxo:
- Piloto envia os tempos da rodada
- API valida o formato de cada tempo (`MM:SS:MMM`)
- Salva o resultado em `FILE_RESULTS_T08`
- Atualiza o status da partida para `CONFIRMADO_PODE_EDITAR` ou `CONFIRMADO_SEM_VIDEO`

### 3.3. `/polerounds X`

- Objetivo: consultar a classificação da rodada `X` do modo Pole Position.
- Exemplo: `/polerounds 2`
- Estados possíveis:
  - `ERRO_PARAMETRO_INVALIDO`: ausência de rodada
  - `ERRO_NAO_ENCONTRADO`: rodada inexistente
  - `ERRO_STATUS_INCONSISTENTE`: algumas partidas da rodada já terminaram e outras não
  - `CLASSIFICACAO_RODADA_FINALIZADA`: a rodada já foi finalizada e os resultados salvos
  - `CLASSIFICACAO_RODADA`: classificação atual da rodada calculada

Fluxo:
- API identifica todas as partidas da rodada `T6`
- Agrupa os resultados por piloto
- Ordena pela menor soma de tempos
- Aplica regras de pontuação do torneio
- Retorna a tabela final ou parcial, conforme o estado da rodada

### 3.4. `/polefinalstandings`

- Objetivo: consultar a classificação geral final acumulada do torneio Pole Position.
- Estados possíveis:
  - `ERRO_STANDINGS_VAZIO`: ainda não há standings salvos
  - `CLASSIFICACAO_GERAL_FINAL`: lista final consolidada do campeonato

Fluxo:
- API lê os `standings` salvos por rodada
- Consolida pontos e tempos por piloto
- Aplica desempates por contagem de melhores posições, rodadas válidas, tempo total e empate absoluto
- Retorna a tabela geral final

---

## 4. Estados gerais da API (`data.state`)

Os estados abaixo são os mais importantes para o frontend decidir a próxima tela ou ação.

### Estados de erro

| State | Descrição |
| --- | --- |
| `ERRO_NAO_ENCONTRADO` | Partida não encontrada. |
| `ERRO_PARTIDA_COMPUTADOR` | Partida de Pole Position / computador. |
| `ERRO_NAO_PERTENCE` | Piloto não participa da partida. |
| `ERRO_NENHUMA_PROPOSTA` | Foi solicitada confirmação sem proposta ativa. |
| `ERRO_APENAS_ADMIN` | Comando restrito a administradores. |
| `ERRO_OPCAO_INVALIDA` | Opção inválida para resultado. |
| `ERRO_FALTA_ID` | ID da partida não informado. |
| `ERRO_DADOS` | Dados inválidos no payload ou formato de tempo/pista incorreto. |
| `ERRO_PARAMETRO_INVALIDO` | Parâmetro obrigatório ausente ou inválido. |
| `ERRO_STATUS_INCONSISTENTE` | A rodada tem status inconsistente entre partidas. |
| `ERRO_STANDINGS_VAZIO` | Não há standings salvos para a competição. |
| `FORA_DO_PRAZO` | A data proposta excedeu o deadline da partida. |
| `MENOS_DE_2_HORAS` | Proposta muito próxima do horário atual. |

### Estados do fluxo

| State | Descrição |
| --- | --- |
| `REQUER_PROPOSTA` | Nenhuma proposta ativa. Solicitar nova data/hora. |
| `AGUARDANDO_OPONENTE` | Proposta criada e aguardando resposta. |
| `REQUER_DECISAO_PROPOSTA` | Existe proposta recebida e o usuário precisa decidir. |
| `CONFIRMADO_PODE_EDITAR` | Partida confirmada mas pode ser reagendada. |
| `CONFIRMADO` | Operação confirmada com sucesso. |
| `PENDENTE_TEMPOS` | O piloto deve enviar os tempos da rodada de Pole Position. |
| `CONFIRMADO_SEM_VIDEO` | Tempos enviados com sucesso sem link de prova. |
| `REQUER_RESULTADO_ADMIN` | Consulta de admin para registrar resultado. |
| `FINALIZADO_ADMIN` | Resultado oficial registrado. |
| `CLASSIFICACAO_RODADA` | Classificação atual de uma rodada de Pole Position. |
| `CLASSIFICACAO_RODADA_FINALIZADA` | Classificação final de rodada já salva. |
| `CLASSIFICACAO_GERAL_FINAL` | Classificação geral consolidada do campeonato. |
| `JOGADOR_PRONTO` | O piloto marcou presença no horário. |
| `JOGADOR_ATRASADO` | O piloto tentou entrar fora da janela permitida. |
| `PLAY_MUITO_CEDO` | Tentativa de play antes da janela válida. |

---

## 4. Observações finais

- O webhook do agora é pelo WhatsApp e ele é o frontEnd funcionando para integração com o BOT, mas a lógica do produto moderno usa `public/agendamentosAPI.php` como contrato principal.
- `public/admin.php` é uma interface administrativa local e não faz parte da API pública consumida pelo front-end

### Resumo do fluxo

```text
PENDENTE
  -> /agendar -> REQUER_PROPOSTA
  -> /proposal -> PROPOSTO -> AGUARDANDO_OPONENTE
  -> /proposal_confirm -> CONFIRMADO -> AGENDADO
  -> /play -> JOGADOR_PRONTO
  -> /resultado -> FINALIZADO_ADMIN -> CONCLUIDO

POLE POSITION
  -> /poleposition -> PENDENTE_TEMPOS
  -> /polepositionsendtimes -> CONFIRMADO_PODE_EDITAR / CONFIRMADO_SEM_VIDEO
  -> /polerounds -> CLASSIFICACAO_RODADA
  -> /polefinalstandings -> CLASSIFICACAO_GERAL_FINAL
```

