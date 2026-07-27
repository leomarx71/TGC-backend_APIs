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
         "message_id": 1,
         "from": { "id": 12345, "first_name": "Piloto", "username": "piloto_tg" },
         "chat": { "id": 12345, "type": "private" },
         "text": "/ajuda"
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
| `/resultado`| `POST` | Informa o vencedor de uma partida. | `/resultado 10` |
| `/audit` | `POST` | Exibe o histórico de ações de uma partida específica. | `/audit 10` |
| `/links` | `POST` | Exibe links importantes do campeonato. | `/links` |

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
| `/audit ID` | Retorna o histórico da partida. |
| `/ajuda`, `/links` | Retorna informações de ajuda e links úteis. |

*Nota: Comandos interativos como `/agendar` e fluxos complexos de `/resultado` devem ser realizados preferencialmente via interface do Telegram, mas a API fornece feedback adequado.*

### Fluxo de Agendamento (`/agendar`)

O comando `/agendar ID` na API retorna um campo `state` dentro do objeto `data`. Este estado deve ser utilizado pela aplicação cliente para decidir qual interface ou ação apresentar ao usuário.

| Estado (`state`) | Descrição | Próxima Ação Esperada |
| :--- | :--- | :--- |
| `REQUIRE_PROPOSAL` | Nenhuma proposta ativa ou última recusada. | Usuário deve enviar uma data/hora (Ex: `25/07 19:00`). |
| `WAITING_OPPONENT` | Proposta feita pelo usuário atual, aguardando oponente. | Aguardar ou enviar nova data para alterar a proposta. |
| `REQUIRE_DECISION_PROPOSAL` | Proposta recebida do oponente, aguardando decisão. | Usuário deve escolher: [1] Confirmar, [2] Contra-proposta ou [3] Recusar. |
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

## Saída (Chamadas para o Telegram)
O bot utiliza a função `apiRequest($method, $parameters)` para enviar dados de volta ao Telegram via `POST` para `https://api.telegram.org/bot[TOKEN]/[MÉTODO]`.
- **Autenticação:** Token do Bot (`TELEGRAM_BOT_TOKEN`) na URL.
- **Corpo:** JSON enviado via cURL.
