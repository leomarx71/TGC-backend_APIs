# Documentação da API Externa (Telegram Webhook)

Este documento descreve as APIs expostas para consumo externo, especificamente as utilizadas pelo Webhook do Telegram.

## Classe PHP Principal
A lógica de recebimento e processamento das chamadas do Telegram não está encapsulada em uma classe, mas sim no arquivo:
- `public/botMain.php`

## Autenticação
O bot utiliza um mecanismo de segurança baseado no header `X-Telegram-Bot-API-Secret-Token`, conforme recomendado pela documentação oficial do Telegram.

- **Header:** `X-Telegram-Bot-API-Secret-Token`
- **Validação:** O valor recebido no header é comparado com a variável de ambiente `TELEGRAM_WEBHOOK_SECRET` definida no arquivo `.env`.
- **Falha:** Caso o token seja inválido ou ausente, o servidor retorna `HTTP 403 Forbidden` e encerra a execução.

---

## Endpoints e Funcionalidades

### 1. Webhook Principal (Telegram Update)
Este é o único ponto de entrada para todas as interações vindas do Telegram (mensagens, botões, comandos).

- **URL:** `https://[dominio]/public/botMain.php`
- **Método:** `POST`
- **Corpo (JSON):** Objeto `Update` do Telegram.
- **Exemplo de Chamada (Simulada):**
```bash
curl -X POST "https://seu-dominio.com/public/botMain.php" \
     -H "Content-Type: application/json" \
     -H "X-Telegram-Bot-API-Secret-Token: SEU_TOKEN_AQUI" \
     -d '{
       "update_id": 123456789,
       "message": {
          "from": {
            "pilotID": Number,
            "pilotName": "Name"
        },
        "function": "/proposal_confirm 3"
       }
     }'
```

---

## Comandos Disponíveis (Invocados via Mensagem)

Abaixo estão os comandos processados pelo `botMain.php`. Todos exigem que o usuário esteja cadastrado como piloto (exceto `/inscrever` e comandos administrativos).

### Comandos Públicos / Iniciais
| Comando | Método | Descrição |
| :--- | :--- | :--- |
| `/inscrever` | `POST` | Realiza o cadastro do piloto no sistema. |
| `/ajuda` | `POST` | Exibe a lista de comandos e guia em Português. |
| `/ayuda` | `POST` | Exibe a lista de comandos e guia em Espanhol. |

### Comandos de Piloto (Requerem Cadastro)
| Comando | Método | Descrição | Exemplo |
| :--- | :--- | :--- | :--- |
| `/meuNick` | `POST` | Consulta ou altera o nickname do piloto (limite de 90 dias). | `/meuNick NovoNome` |
| `/partidas` | `POST` | Lista as partidas pendentes do piloto. | `/partidas` |
| `/agendar` | `POST` | Inicia o fluxo de agendamento de uma partida. | `/agendar 10` |
| `/play` | `POST` | Notifica que o piloto está pronto para jogar. | `/play 10` |
| `/audit` | `POST` | Exibe o histórico de ações de uma partida específica. | `/audit 10` |
| `/links` | `POST` | Exibe links importantes do campeonato. | `/links` |

### Comandos de Administrador
| Comando | Método | Descrição | Exemplo |
| :--- | :--- | :--- | :--- |
| `/resultado ID` | `POST` | Consulta os nicknames e `telegram_id` dos pilotos da partida. | `/resultado 10` |
| `/resultado ID [nickname]` | `POST` | Define o piloto vencedor da partida. | `/resultado 10 Senna` |
| `/resultado ID empate` | `POST` | Define o resultado da partida como Empate. | `/resultado 10 empate` |
| `/resultado ID woduplo` | `POST` | Define o resultado da partida como W.O. Duplo. | `/resultado 10 woduplo` |

### Tratamento de Callback Queries (Botões Inline)
O bot também processa interações de botões (`callback_query`), utilizados nos fluxos de agendamento:
- `calendar`: Abre o seletor de datas.
- `sel_date`: Seleciona uma data.
- `sel_time`: Seleciona um horário.
- `confirm_sched`: Confirma a proposta de horário.
- `accept_sched`: Aceita uma proposta recebida.
- `cancel_op`: Cancela a operação atual.


---

## 2. API de Agendamentos (Genérica)
Esta API permite a invocação de comandos do bot de forma programática, sem depender diretamente da interface do Telegram, retornando a resposta em formato JSON.

- **URL:** `https://[dominio]/public/agendamentosAPI.php`
- **Método:** `POST`
- **Header:** `X-Telegram-Bot-API-Secret-Token` (Mesmo valor do Webhook)
- **Corpo (JSON):**
```json
{
  "update_id": 123456789,
  "message": {
    "from": { "pilotID": 123456789 },
    "function": "/ajuda"
  }
}
```

### Funcionalidades Suportadas via API
A API suporta a maioria dos comandos de texto do bot. O campo `function` ignora o caso (case-insensitive).

| Comando | Descrição |
| :--- | :--- |
| `/inscrever` | Realiza o cadastro do piloto (usa `pilotID` como ID do Telegram). |
| `/partidas` | Retorna as partidas pendentes do piloto. |
| `/meuNick` | Altera o nickname do piloto. |
| `/play ID` | Notifica que o piloto está pronto para a partida. |
| `/resultado ID` | (Admin) Consulta pilotos e define vencedor (`/resultado ID nick`), empate (`/resultado ID empate`) ou W.O. duplo (`/resultado ID woduplo`). |
| `/audit ID` | Retorna o histórico da partida. |
| `/ajuda`, `/links` | Retorna informações de ajuda e links úteis. |

*Nota: O comando `/resultado` é exclusivo para administradores. Pilotos comuns receberão erro ao tentar utilizá-lo.*

### Fluxo de Resultado (`/resultado` - Exclusivo Administradores)

O comando `/resultado` permite que os administradores consultem e definam oficialmente o resultado de qualquer partida.

1. **Consulta de Dados dos Pilotos (`/resultado ID`)**:
   - Retorna os nomes/nicknames de ambos os pilotos e seus respectivos `telegram_id`.
   - Estado retornado: `REQUER_RESULTADO_ADMIN`.

2. **Definição de Vencedor / Empate / W.O. Duplo**:
   - `/resultado ID [nickname]`: Define o piloto correspondente como vencedor.
   - `/resultado ID empate`: Define o resultado como Empate (`winner_id = 0`).
   - `/resultado ID woduplo`: Define o resultado como W.O. Duplo (`winner_id = -1`).
   - Estado retornado: `FINALIZADO_ADMIN`.

3. **Tentativa de Execução por Piloto Não-Administrador**:
   - Retorna mensagem de erro de negócio informando que apenas administradores podem executar.
   - Estado retornado: `ERRO_APENAS_ADMIN`.

#### Exemplo de Consulta de Resultado (JSON):
```json
{
  "ok": true,
  "response": "🏆 *Definir Resultado - Partida #10*...",
  "data": {
    "match_id": 10,
    "player_1": {
      "id": 1,
      "name": "Senna",
      "telegram_id": 1001
    },
    "player_2": {
      "id": 2,
      "name": "Prost",
      "telegram_id": 2002
    },
    "state": "REQUER_RESULTADO_ADMIN"
  }
}
```

### Fluxo de Agendamento (`/agendar`)

O comando `/agendar ID` na API retorna um campo `state` dentro do objeto `data`. Este estado deve ser utilizado pela aplicação cliente para decidir qual interface ou ação apresentar ao usuário.

| Estado (`state`) | Descrição | Próxima Ação Esperada |
| :--- | :--- | :--- |
| `REQUIRE_PROPOSAL` | Nenhuma proposta ativa. | Usuário deve enviar uma data/hora (Ex: `25/07 19:00`). |
| `WAITING_OPPONENT` | Proposta feita pelo usuário atual, aguardando oponente. | Aguardar ou enviar nova data para alterar a proposta. |
| `REQUIRE_DECISION_PROPOSAL` | Proposta recebida do oponente, aguardando decisão. | Usuário deve escolher: [1] Confirmar ou [2] Contra-proposta. |
| `CONFIRMED_CAN_EDIT` | Agendamento já confirmado pelas duas partes. | Nenhuma ação necessária, mas permite enviar nova data para reagendar. |
| `ERROR_MISSING_ID` | ID da partida não foi fornecido no comando. | Fornecer o ID (Ex: `/agendar 123`). |
| `ERROR_NOT_FOUND` | Partida com o ID informado não existe. | Verificar o ID informado. |
| `ERROR_COMPUTER_MATCH` | Partida de Pole Position (contra o computador). | Não requer agendamento. |
| `ERROR_NOT_OWNER` | O piloto autenticado não faz parte desta partida. | Verificar se o `pilotID` está correto. |

#### Exemplo de Resposta (JSON):
```json
{
  "ok": true,
  "response": "📅 *Agendamento #123*\n\nNenhuma proposta ativa...",
  "data": {
    "match_id": 123,
    "state": "REQUIRE_PROPOSAL"
  }
}
```

---

# Documentação de Status do Fluxo

Este documento descreve todos os status e estados utilizados pelo sistema de agendamento de partidas, incluindo o fluxo de partidas, agendamentos, respostas da API e ações de auditoria.

---

# 1. Status da Partida (`matches.json`)

Os status da partida representam o estado geral da disputa dentro do campeonato.

| Status | Descrição |
|---------|-----------|
| `PENDENTE` | A partida foi criada pelo administrador, mas ainda não existe nenhum agendamento iniciado entre os jogadores. |
| `PROPOSTO` | Um dos jogadores enviou uma proposta de data e horário, porém ela ainda depende da aprovação do adversário. |
| `AGENDADO` | Os dois jogadores concordaram com a proposta e a partida possui data e horário oficialmente marcados. |
| `CONCLUIDO` | A partida foi encerrada pelo administrador, independentemente do resultado (vitória, empate ou W.O.). |

---

# 2. Status do Agendamento (`schedules.json`)

Os status do agendamento representam apenas o processo de negociação entre os jogadores.

| Status | Descrição |
|---------|-----------|
| `PROPOSTO` | Existe uma proposta de data e horário aguardando resposta do adversário. |
| `CONFIRMADO` | O adversário aceitou a proposta. Neste momento a partida passa para o status `AGENDADO`. |
| `PARTIDA_FINALIZADA` | Definido pelo painel administrativo após o registro oficial do resultado da partida. |
| `RESULTADO_PROPOSTO` | Utilizado pelo bot do Telegram durante o envio do resultado e da captura de tela (print). |
| `RESULTADO_EM_DISPUTA` | Utilizado quando existe divergência entre os jogadores sobre o resultado informado. |

---

# 3. Estados Retornados pela API (`data.state`)

Os estados retornados pela API informam à interface qual ação ou tela deve ser apresentada ao usuário.

## Estados de erro

| State | Descrição |
|-------|-----------|
| `ERRO_NAO_ENCONTRADO` | O `matchId` informado não existe. |
| `ERRO_PARTIDA_COMPUTADOR` | Tentativa de agendar ou registrar resultado para partida do tipo Pole Position (jogo solo). |
| `ERRO_NAO_PERTENCE` | O usuário autenticado não participa da partida solicitada. |
| `ERRO_NENHUMA_PROPOSTA` | Foi solicitada uma confirmação, porém não existe proposta cadastrada. |
| `ERRO_APENAS_ADMIN` | Comando ou ação restrita exclusivamente a administradores. |
| `ERRO_OPCAO_INVALIDA` | Opção ou nickname inválido ao registrar resultado. |
| `ERRO_FALTA_ID` | ID da partida não informado no comando. |

## Estados do fluxo

| State | Descrição |
|-------|-----------|
| `REQUER_PROPOSTA` | Não existe agendamento. A interface deve solicitar ao usuário uma nova proposta de data e horário. |
| `AGUARDANDO_OPONENTE` | A proposta foi enviada com sucesso. A interface deve apenas aguardar a resposta do adversário. |
| `REQUER_DECISAO_PROPOSTA` | Existe uma proposta pendente. A interface deve oferecer as opções de **Aceitar** ou **Contra-proposta**. |
| `REQUER_CONFIRMACAO_PROPOSTA` | A interface deve solicitar uma confirmação ("OK") antes de enviar definitivamente a proposta. |
| `CONFIRMADO_PODE_EDITAR` | A partida já está agendada, porém ainda é permitido solicitar um reagendamento. |
| `CONFIRMADO` | Operação realizada com sucesso. |
| `REQUER_RESULTADO_ADMIN` | Consulta de partida para envio de resultado por administrador, aguardando definição de vencedor, empate ou W.O. duplo. |
| `FINALIZADO_ADMIN` | Resultado oficialmente definido pelo administrador e partida finalizada. |

---

# 4. Ações de Auditoria (`audit/log`)

As ações de auditoria registram os eventos relevantes ocorridos durante o fluxo de agendamento.

| Ação | Descrição |
|------|-----------|
| `INICIO_NOVA_PROPOSTA` | O jogador informou uma data e horário, mas a API ainda aguarda a confirmação final ("OK"). |
| `PROPOSTO` | Uma proposta de agendamento foi enviada ao adversário. |
| `REAGENDADO` | Foi realizada uma contra-proposta para um agendamento existente. |
| `CONFIRMADO` | O agendamento foi aceito pelo adversário. |
| `JOGADOR_PRONTO` | O jogador utilizou o comando `/play` para informar que está pronto para iniciar a partida, dentro da janela permitida de 30 minutos antes do horário agendado. |

---

# Resumo do Fluxo

```text
PENDENTE
    │
    ▼
REQUER_PROPOSTA
    │
    ▼
PROPOSTO
    │
    ▼
CONFIRMADO
    │
    ▼
AGENDADO
    │
    ▼
JOGADOR_PRONTO (/play)
    │
    ▼
PARTIDA_FINALIZADA
    │
    ▼
CONCLUIDO
```

## Saída (Chamadas para o Telegram)
O bot utiliza a função `apiRequest($method, $parameters)` para enviar dados de volta ao Telegram via `POST` para `https://api.telegram.org/bot[TOKEN]/[MÉTODO]`.
- **Autenticação:** Token do Bot (`TELEGRAM_BOT_TOKEN`) na URL.
- **Corpo:** JSON enviado via cURL.

