# 📚 GUIA DE MIGRAÇÃO - PASSO A PASSO

## 🎯 Objetivo
Migrar do projeto legado para a nova arquitetura sem perder dados.

## ⏱️ Tempo Estimado
- **Leitura:** 5-10 minutos
- **Implementação:** 15-20 minutos
- **Testes:** 10-15 minutos
- **Total:** ~45 minutos

---

## ✅ PRÉ-REQUISITOS

Antes de começar, certifique-se que tem:
- [ ] Acesso SSH ao servidor
- [ ] Backup atual do projeto (`/bot/`)
- [ ] Cópia dos novos arquivos
- [ ] Conhecimento básico de Terminal/SSH

---

## 📋 CHECKLIST DE MIGRAÇÃO

### FASE 1: PREPARAÇÃO (5 min)

```bash
# 1. Fazer backup do projeto atual
cd /home/u391950094/domains/topgearchampionships.com/public_html/
cp -r bot bot_backup_$(date +%Y%m%d_%H%M%S)
echo "✓ Backup criado"

# 2. Navegar para o diretório bot
cd bot/
pwd  # Deve mostrar: /home/u391950094/domains/topgearchampionships.com/public_html/bot/
```

### FASE 2: CRIAR ESTRUTURA (5 min)

```bash
# 1. Criar pasta public/ (se não existir)
mkdir -p public/
echo "✓ Pasta public/ criada"

# 2. Verificar pastas existentes
ls -la config/    # Deve ter environment.php
ls -la src/       # Deve ter Auth/AdminAuth.php
ls -la storage/   # Deve ter json/, logs/, backups/
```

### FASE 3: SUBSTITUIR ARQUIVOS CORRIGIDOS (10 min)

```bash
# 1. Substituir config/environment.php (CORRIGIDO)
# Copie o arquivo environment_corrigido.php para:
cp environment_corrigido.php config/environment.php
echo "✓ config/environment.php atualizado"

# 2. Substituir src/Auth/AdminAuth.php (CORRIGIDO)
# Copie o arquivo AdminAuth_corrigido.php para:
cp AdminAuth_corrigido.php src/Auth/AdminAuth.php
echo "✓ src/Auth/AdminAuth.php atualizado"

# 3. Copiar novas classes utilitárias
cp LogHandler.php src/Utils/LogHandler.php
echo "✓ src/Utils/LogHandler.php criado"

cp BackupManager.php src/Utils/BackupManager.php
echo "✓ src/Utils/BackupManager.php criado"

# 4. Mover arquivos para pasta public/
mv admin.php public/admin.php
echo "✓ admin.php movido para public/"

mv botMain.php public/webhook.php
echo "✓ botMain.php movido para public/webhook.php"

# 5. Copiar arquivo .htaccess
cp htaccess-protecao.txt public/.htaccess
echo "✓ public/.htaccess criado"
```

### FASE 4: CRIAR ARQUIVO .ENV (5 min)

```bash
# 1. Copiar exemplo para .env
cp env-example.txt .env
echo "✓ .env criado (edite com seus valores)"

# 2. Abrir arquivo para editar
# Use seu editor: nano, vi, ou editor gráfico
nano .env

# 3. Alterar principalmente:
#    - APP_ENV=production
#    - DEBUG_MODE=false
#    - ADMIN_PASSWORD=sua_senha_forte
#    - TELEGRAM_BOT_TOKEN=seu_token
#    - TELEGRAM_GROUP_ID=seu_id

# 4. Salvar permissões restritivas
chmod 600 .env
echo "✓ Permissões de .env configuradas"
```

### FASE 5: CONFIGURAR PERMISSÕES (5 min)

```bash
# 1. Diretórios com permissão 755 (leitura/escrita/execução)
chmod 755 storage/
chmod 755 storage/json/
chmod 755 storage/logs/
chmod 755 storage/backups/
echo "✓ Permissões de diretórios configuradas"

# 2. Arquivos com permissão 644 (leitura/escrita)
chmod 644 .env
chmod 644 config/environment.php
chmod 644 config/*.php
echo "✓ Permissões de arquivos configuradas"

# 3. Arquivos PHP executáveis
chmod 755 public/admin.php
chmod 755 public/webhook.php
echo "✓ Permissões de PHP configuradas"

# 4. Definir owner (se tiver acesso sudo)
# chown -R www-data:www-data /home/u391950094/domains/topgearchampionships.com/public_html/bot/storage/
# echo "✓ Owner configurado"
```

### FASE 6: VALIDAR ESTRUTURA (5 min)

```bash
# 1. Verificar árvore de diretórios
echo "Estrutura atual:"
tree -L 2 . -I 'node_modules'

# Saída esperada:
# .
# ├── config/
# │   └── environment.php          ✓
# ├── src/
# │   ├── Auth/
# │   │   └── AdminAuth.php        ✓
# │   └── Utils/
# │       ├── LogHandler.php       ✓
# │       └── BackupManager.php    ✓
# ├── public/
# │   ├── admin.php                ✓
# │   ├── webhook.php              ✓
# │   └── .htaccess                ✓
# ├── storage/
# │   ├── json/
# │   ├── logs/
# │   └── backups/
# └── .env                         ✓

# 2. Listar arquivos críticos
echo "Arquivos críticos:"
ls -la config/environment.php
ls -la src/Auth/AdminAuth.php
ls -la src/Utils/LogHandler.php
ls -la src/Utils/BackupManager.php
ls -la public/admin.php
ls -la public/webhook.php
ls -la .env

# Todos devem existir ✓
```

### FASE 7: TESTAR AMBIENTE (10 min)

#### Teste 1: Verificar Carregamento

```bash
# Criar arquivo de teste temporário
cat > test.php << 'EOF'
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/environment.php';

echo "✓ Ambiente carregado!\n";
echo "✓ CONFIG_LOADED: " . (defined('CONFIG_LOADED') ? 'SIM' : 'NÃO') . "\n";
echo "✓ DATA_DIR: " . DATA_DIR . "\n";
echo "✓ LOG_DIR: " . LOG_DIR . "\n";
echo "✓ BACKUP_DIR: " . BACKUP_DIR . "\n";

// Testar LogHandler
echo "✓ LogHandler disponível: " . (class_exists('LogHandler') ? 'SIM' : 'NÃO') . "\n";

// Testar BackupManager
echo "✓ BackupManager disponível: " . (class_exists('BackupManager') ? 'SIM' : 'NÃO') . "\n";

// Testar AdminAuth
echo "✓ AdminAuth disponível: " . (class_exists('AdminAuth') ? 'SIM' : 'NÃO') . "\n";

echo "\n✅ Todos os testes passaram!\n";
?>
EOF

# Executar teste
php test.php

# Remover arquivo de teste
rm test.php
```

#### Teste 2: Acessar Painel Admin

```bash
# Abrir navegador em:
# https://topgearchampionships.com/bot/public/admin.php
#
# Esperado:
# - Tela de login do painel
# - SEM warnings de constantes duplicadas
# - Campo de password visível
# - Botões do painel após login
```

#### Teste 3: Verificar Logs

```bash
# Verificar se arquivo de log foi criado
ls -la storage/logs/botMain.log

# Deve retornar algo como:
# -rw-r--r-- 1 www-data www-data 1024 Jan 13 23:00 storage/logs/botMain.log

# Se não existir, criar manualmente
touch storage/logs/botMain.log
chmod 644 storage/logs/botMain.log
```

---

## 🔍 VERIFICAÇÃO PÓS-MIGRAÇÃO

### Checklist Final

```bash
# 1. Verificar se não há warnings
echo "Testando warnings..."
php -l config/environment.php
php -l src/Auth/AdminAuth.php
php -l public/admin.php
php -l public/webhook.php

# Todos devem retornar:
# No syntax errors detected in...

# 2. Verificar dados antigos preservados
echo "Dados preservados:"
ls -la storage/json/

# Deve conter:
# - pilots.json ✓
# - matches.json ✓
# - schedules.json ✓
# - auditSchedules.json ✓

# 3. Verificar permissões
echo "Permissões:"
ls -la storage/logs/
ls -la storage/json/
ls -la .env

# Esperado:
# - logs: 755
# - json: 755
# - .env: 600
```

---

## 🆘 TROUBLESHOOTING

### Problema 1: "Constant already defined"

**Solução:**
```bash
# 1. Verificar se config/environment.php foi realmente substituído
cat config/environment.php | head -20
# Deve conter: if (defined('CONFIG_LOADED')) { return; }

# 2. Limpar cache PHP (se usar OPcache)
# Reiniciar PHP-FPM:
sudo systemctl restart php-fpm
# OU (para Apache):
sudo systemctl restart apache2
```

### Problema 2: "Permission denied"

**Solução:**
```bash
# 1. Verificar permissões
ls -la public/admin.php
ls -la public/webhook.php

# 2. Corrigir se necessário
chmod 755 public/admin.php
chmod 755 public/webhook.php
chmod 755 storage/

# 3. Verificar owner
ls -la storage/ | head
# Deve mostrar: www-data ou nobody
```

### Problema 3: "File not found" ao acessar admin.php

**Solução:**
```bash
# 1. Verificar se arquivo existe
ls -la public/admin.php

# 2. Verificar permissões do .htaccess
cat public/.htaccess | head -20

# 3. Testar URL correta
# Não faça: https://topgearchampionships.com/bot/admin.php ❌
# Faça: https://topgearchampionships.com/bot/public/admin.php ✓

# 4. Se usar alias no vhost, ajustar
# No vhost do Apache, apontar para /bot/public/
```

### Problema 4: "Class not found"

**Solução:**
```bash
# 1. Verificar se arquivos existem
ls -la src/Utils/LogHandler.php
ls -la src/Utils/BackupManager.php

# 2. Verificar se estão no diretório correto
find . -name "LogHandler.php" -type f
find . -name "BackupManager.php" -type f

# 3. Verificar se o autoload está funcionando
php -r "require_once 'config/environment.php'; echo class_exists('LogHandler') ? 'OK' : 'FAIL';"
```

---

## 📊 VALIDAÇÃO DE DADOS

Após migração, validar que todos os dados foram preservados:

```bash
# 1. Verificar se pilotos ainda existem
cat storage/json/pilots.json | jq 'length'
# Deve retornar número de pilotos

# 2. Verificar se partidas ainda existem
cat storage/json/matches.json | jq 'length'
# Deve retornar número de partidas

# 3. Verificar se agendamentos ainda existem
cat storage/json/schedules.json | jq 'length'
# Deve retornar número de agendamentos

# 4. Verificar auditoria ainda existe
cat storage/json/auditSchedules.json | jq 'length'
# Deve retornar número de registros de auditoria
```

---

## ✨ PRÓXIMOS PASSOS

Após migração bem-sucedida:

- [ ] Atualizar referências de URL no Telegram Bot (webhook)
- [ ] Testar login no painel admin
- [ ] Testar botões: "Rotacionar Tudo", "Limpar Logs", "Ver Logs"
- [ ] Testar novos comandos do bot: `/sairDoTorneio`, `/tutorial`
- [ ] Monitorar logs por 24 horas
- [ ] Arquivar backup antigo após confirmação

---

## 🔐 SEGURANÇA

Após migração, verificar:

```bash
# 1. .env não está exposto
curl https://topgearchampionships.com/bot/.env
# Deve retornar 403 Forbidden

# 2. Arquivos JSON não estão expostos
curl https://topgearchampionships.com/bot/storage/json/pilots.json
# Deve retornar 403 Forbidden

# 3. Logs não estão expostos
curl https://topgearchampionships.com/bot/storage/logs/botMain.log
# Deve retornar 403 Forbidden

# 4. Testar HTTPS
curl -I https://topgearchampionships.com/bot/public/admin.php
# Deve retornar 200 OK (sem redirect para HTTP)
```

---

## 📞 SUPORTE

Se encontrar problemas:

1. **Verificar logs do servidor:**
   ```bash
   tail -f /var/log/apache2/error.log
   tail -f /var/log/php-fpm.log
   ```

2. **Testar PHP:**
   ```bash
   php -v
   php -m | grep json
   ```

3. **Testar permissões:**
   ```bash
   namei -l /home/u391950094/domains/topgearchampionships.com/public_html/bot/storage/
   ```

---

**Migração desenvolvida em 14/01/2026**  
**Versão:** 2.0
