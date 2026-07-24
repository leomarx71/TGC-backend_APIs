# 📅 Documentação do Fluxo de Agendamento - TGC Bot

Este documento detalha o funcionamento técnico e lógico do sistema de agendamento de partidas do bot **Top Gear Championship (TGC)**.

---

## 🛠 1. Arquitetura do Sistema

O sistema é baseado em uma arquitetura procedural em PHP, utilizando arquivos JSON para persistência de dados.

### Estrutura de Arquivos Relacionados
- `public/botMain.php`: Ponto de entrada (Webhook). Contém toda a lógica de processamento de comandos, callbacks e notificações.
- `storage/json/matches.json`: Armazena os dados das partidas (jogadores, prazos, pistas, status da partida).
- `storage/json/schedules.json`: Armazena os agendamentos específicos (propostas de data/hora, status do agendamento, quem propôs).
- `storage/json/auditSchedules.json`: Histórico completo de todas as ações tomadas em cada partida (Log de auditoria).
- `storage/json/pilots.json`: Cadastro de pilotos (ID Telegram, Nickname, etc).

### Classes e Funções Principais (em `botMain.php`)
- `getMatchSchedule($matchId)`: Recupera o agendamento atual de uma partida.
- `saveAudit($matchId, $pilotId, $action, $details)`: Registra uma ação na auditoria.
- `getActionCount($matchId, $action)`: Conta quantas vezes uma ação (ex: Reagendamento) ocorreu.
- `hasAuditAction($matchId, $action, $timeThreshold)`: Verifica se uma ação ocorreu após um tempo específico.
- `formatLocal($localData)`: Formata a exibição da pista e condições climáticas.

---

## 🔄 2. Fluxo de Estados da Partida

Uma partida percorre os seguintes estados no campo `status` de `matches.json`:
1.  **PENDENTE**: Partida criada, aguardando primeiro agendamento.
2.  **AGENDADO**: Agendamento confirmado por ambos os pilotos.
3.  **FINALIZADA**: Resultado registrado e confirmado.

No arquivo `schedules.json`, os estados do agendamento são:
- `PROPOSTO`: Um piloto sugeriu data/hora.
- `CONFIRMADO`: O adversário aceitou a proposta.
- `RECUSADO`: A proposta foi rejeitada (abre espaço para nova proposta ou contra-proposta).
- `RESULTADO_PROPOSTO`: Partida jogada, um piloto informou o vencedor.
- `RESULTADO_EM_DISPUTA`: Divergência entre o que o P1 e P2 informaram.

---

## 💬 3. Fluxo de Possibilidades de Mensagens

O usuário interage com o bot através de comandos de texto e botões (Inline Keyboards).

### A. Início: `/agendar [ID]`
1.  **Usuário envia `/agendar 10`**:
    - O bot verifica se o usuário é participante da partida #10.
    - Se a partida já estiver confirmada, oferece opção de Reagendar.
    - Se estiver pendente, inicia o fluxo de calendário.
2.  **Seleção de Data (Callback: `calendar` / `sel_date`)**:
    - Bot apresenta os próximos 7 dias.
3.  **Seleção de Horário (Callback: `sel_time`)**:
    - Bot apresenta horários disponíveis (09:00 às 23:45).
    - **Regra de Negócio**: Deve haver no mínimo **2 horas de antecedência** em relação ao horário atual.
    - **Regra de Negócio**: A data não pode ultrapassar o `deadline` da partida.

### B. Resposta do Adversário
Ao receber uma proposta, o adversário recebe uma notificação privada e pode usar `/agendar [ID]` para:
1.  **Aceitar (Callback: `btn_conf`)**:
    - Altera status para `CONFIRMADO`.
    - **Regra de Segurança**: Não pode confirmar se faltar menos de 30 minutos para o horário proposto.
    - Notifica o grupo oficial.
2.  **Recusar/Contra-proposta (Callback: `btn_rec`)**:
    - Altera status para `RECUSADO`.
    - O bot oferece a opção de sugerir um novo horário imediatamente (Contra-proposta).
    - Registra na auditoria se o número de contra-propostas exceder 7 (alerta para admin).

### C. No Dia do Jogo: `/play [ID]`
Comando usado para declarar disponibilidade para jogar.
1.  **Janela de Tempo**: Válida de 30 min antes até 30 min depois do horário agendado.
2.  **No horário**: Notifica o adversário no privado e o grupo oficial que o piloto está pronto.
3.  **Antes do horário**: Avisa quantos minutos faltam para abrir a janela.
4.  **Atrasado (> 30min)**: Registra `JOGO_NAO_REALIZADO` na auditoria e solicita reagendamento.
5.  **Pendente (+24h)**: Se o oponente não confirmou a proposta após 24h, o proponente pode dar um "Nudge" (alerta) via `/play`.

### D. Registro de Resultado: `/resultado [ID]`
1.  **Proposta**: Um dos pilotos informa o vencedor (ou empate).
2.  **Confirmação**: O adversário deve confirmar o resultado enviado.
3.  **Divergência**: Se o adversário informar um resultado diferente, a partida entra em `RESULTADO_EM_DISPUTA` e um administrador deve intervir.

---

## 🛡 4. Regras e Validações

- **Auditoria**: Todas as mensagens de sistema importantes são salvas em `auditSchedules.json`.
- **Nickname**: Pilotos só podem alterar o nickname uma vez a cada 90 dias.
- **Segurança**: O bot valida o `X-Telegram-Bot-Api-Secret-Token` em todas as requisições para garantir que venham do Telegram.
- **Admin Force**: Administradores podem usar o comando `/resultado` para definir um vencedor em qualquer momento, ignorando as travas de jogadores.