# 🚀 Scripts de Otimização MySQL - Sistema Integrar

Este conjunto de scripts foi criado para otimizar a performance do MySQL no servidor de produção do Sistema Integrar.

## 📋 Scripts Disponíveis

### 1. **otimizar_mysql_buffer_pool.sh**
- **Função**: Configura `innodb_buffer_pool_size` para 4GB
- **Uso**: `sudo bash otimizar_mysql_buffer_pool.sh`
- **O que faz**:
  - Ajusta buffer pool para 4GB (otimizado para 8GB RAM)
  - Configura `innodb_buffer_pool_instances = 4`
  - Adiciona otimizações complementares
  - Faz backup da configuração atual

### 2. **criar_swapfile_2gb.sh**
- **Função**: Cria swapfile de 2GB para resolver picos de OOM
- **Uso**: `sudo bash criar_swapfile_2gb.sh`
- **O que faz**:
  - Cria swapfile de 2GB
  - Configura swappiness otimizada (10)
  - Ativa automaticamente no boot
  - Configura `vfs_cache_pressure = 50`

### 3. **configurar_tmpfs_mysql.sh**
- **Função**: Configura tmpfs para /tmp do MySQL e caches
- **Uso**: `sudo bash configurar_tmpfs_mysql.sh`
- **O que faz**:
  - Cria tmpfs de 1GB para `/var/lib/mysql-tmp`
  - Cria tmpfs de 512MB para `/var/lib/mysql-cache`
  - Configura MySQL para usar tmpfs
  - Adiciona otimizações de I/O

### 4. **instalar_mysqltuner.sh**
- **Função**: Instala e configura MySQLTuner para monitoramento
- **Uso**: `sudo bash instalar_mysqltuner.sh`
- **O que faz**:
  - Instala MySQLTuner e dependências
  - Cria scripts wrapper (`mysqltuner`, `mysql-status`)
  - Configura monitoramento automático via cron
  - Cria relatórios em `/opt/mysql_reports`

### 5. **monitorar_mysql_performance.sh**
- **Função**: Monitora performance e executa testes
- **Uso**: `bash monitorar_mysql_performance.sh`
- **O que faz**:
  - Verifica status do MySQL
  - Analisa configurações atuais
  - Monitora conexões, queries, memória, I/O
  - Gera relatórios de performance
  - Identifica problemas críticos

### 6. **otimizar_mysql_completo.sh** (MASTER)
- **Função**: Executa todas as otimizações com menu interativo
- **Uso**: `sudo bash otimizar_mysql_completo.sh`
- **O que faz**:
  - Menu para escolher otimizações
  - Executa scripts na ordem correta
  - Verifica status após otimizações
  - Mostra resumo final

## 🚀 Instruções de Uso

### **Opção 1: Execução Completa (Recomendada)**
```bash
# No servidor de produção
cd /ico/fabiano/ft/integrar/script-manutencao
sudo bash otimizar_mysql_completo.sh
```

### **Opção 2: Execução Individual**
```bash
# 1. Buffer Pool
sudo bash otimizar_mysql_buffer_pool.sh

# 2. Swapfile
sudo bash criar_swapfile_2gb.sh

# 3. tmpfs
sudo bash configurar_tmpfs_mysql.sh

# 4. MySQLTuner
sudo bash instalar_mysqltuner.sh

# 5. Monitoramento
bash monitorar_mysql_performance.sh
```

## 📊 Comandos de Monitoramento

Após a instalação, você terá estes comandos disponíveis:

```bash
# Análise completa do MySQL
mysqltuner

# Status rápido
mysql-status

# Monitoramento contínuo
/opt/mysqltuner/monitor_mysql.sh

# Análise de performance
/ico/fabiano/ft/integrar/script-manutencao/monitorar_mysql_performance.sh
```

## 📁 Estrutura de Arquivos

```
/ico/fabiano/ft/integrar/script-manutencao/
├── otimizar_mysql_buffer_pool.sh      # Buffer Pool 4GB
├── criar_swapfile_2gb.sh              # Swapfile 2GB
├── configurar_tmpfs_mysql.sh          # tmpfs para MySQL
├── instalar_mysqltuner.sh             # MySQLTuner
├── monitorar_mysql_performance.sh     # Monitoramento
├── otimizar_mysql_completo.sh         # Script Master
└── README_OTIMIZACAO_MYSQL.md         # Este arquivo

/opt/mysql_reports/                    # Relatórios gerados
├── mysqltuner_YYYYMMDD_HHMMSS.txt
├── performance_report_YYYYMMDD_HHMMSS.txt
└── innodb_status_YYYYMMDD_HHMMSS.txt

/opt/mysql_backups/                    # Backups de configuração
├── mysqld.cnf.backup.YYYYMMDD_HHMMSS
└── mysqld.cnf.backup.tmpfs.YYYYMMDD_HHMMSS
```

## ⚠️ Pré-requisitos

- **Sistema**: Ubuntu 24.04
- **MySQL**: 8.0+ (ou 5.7+)
- **RAM**: Mínimo 8GB (recomendado)
- **Permissões**: root (sudo)
- **Espaço**: Mínimo 5GB livres

## 🔧 Configurações Aplicadas

### **Buffer Pool**
- `innodb_buffer_pool_size = 4G`
- `innodb_buffer_pool_instances = 4`
- `innodb_log_file_size = 256M`
- `innodb_log_buffer_size = 16M`
- `innodb_flush_log_at_trx_commit = 2`

### **tmpfs**
- `/var/lib/mysql-tmp` (1GB)
- `/var/lib/mysql-cache` (512MB)
- `tmpdir = /var/lib/mysql-tmp`
- `innodb_tmpdir = /var/lib/mysql-tmp`

### **Swap**
- Swapfile de 2GB
- `vm.swappiness = 10`
- `vm.vfs_cache_pressure = 50`

### **I/O Otimizações**
- `innodb_use_native_aio = 1`
- `innodb_read_io_threads = 4`
- `innodb_write_io_threads = 4`

## 📈 Monitoramento Automático

### **Cron Jobs Configurados**
```bash
# Análise diária às 2:00 AM
0 2 * * * root /opt/mysqltuner/monitor_mysql.sh

# Análise semanal aos domingos às 3:00 AM
0 3 * * 0 root /usr/local/bin/mysqltuner
```

### **Logs**
- **MySQL**: `journalctl -u mysql`
- **Monitoramento**: `/var/log/mysql_monitor.log`
- **Performance**: `/var/log/mysql_performance.log`

## 🚨 Troubleshooting

### **MySQL não inicia após otimizações**
```bash
# Verificar logs
journalctl -u mysql -f

# Restaurar configuração
sudo cp /opt/mysql_backups/mysqld.cnf.backup.* /etc/mysql/mysql.conf.d/mysqld.cnf
sudo systemctl restart mysql
```

### **Problemas de permissão**
```bash
# Corrigir permissões
sudo chown -R mysql:mysql /var/lib/mysql-tmp
sudo chown -R mysql:mysql /var/lib/mysql-cache
```

### **Swap não ativa**
```bash
# Verificar swap
sudo swapon --show
sudo free -h

# Ativar manualmente
sudo swapon /swapfile
```

## 📞 Suporte

Para problemas ou dúvidas:
1. Verifique os logs em `/var/log/`
2. Execute `mysql-status` para status rápido
3. Execute `mysqltuner` para análise completa
4. Consulte os relatórios em `/opt/mysql_reports/`

## 🔄 Atualizações

Para atualizar os scripts:
```bash
cd /ico/fabiano/ft/integrar/script-manutencao
git pull origin master
```

---

**Desenvolvido para o Sistema Integrar**  
**Data**: $(date +%Y-%m-%d)  
**Versão**: 1.0


