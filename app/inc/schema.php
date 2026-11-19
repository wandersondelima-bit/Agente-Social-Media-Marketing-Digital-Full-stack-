<?php
/* Estrutura do banco. O mesmo texto serve ao MySQL (produção) e ao SQLite (testes locais). */
declare(strict_types=1);

function criar_tabelas(): void
{
    $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pk = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fim = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tabelas = [
        "CREATE TABLE IF NOT EXISTS usuarios (
            id $pk,
            nome VARCHAR(120) NOT NULL,
            login VARCHAR(60) NOT NULL UNIQUE,
            senha_hash VARCHAR(255) NOT NULL DEFAULT '',
            papel VARCHAR(10) NOT NULL DEFAULT 'corretor',
            ativo TINYINT NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL,
            ultimo_acesso DATETIME NULL
        )$fim",
        "CREATE TABLE IF NOT EXISTS leads (
            id $pk,
            entrada DATE NOT NULL,
            nome VARCHAR(160) NOT NULL,
            telefone VARCHAR(11) NOT NULL,
            imovel_codigo VARCHAR(20) NULL,
            imovel_titulo VARCHAR(255) NULL,
            imovel_url VARCHAR(255) NULL,
            interesse VARCHAR(80) NULL,
            origem VARCHAR(40) NOT NULL DEFAULT 'Outro',
            utm VARCHAR(500) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'novo',
            motivo_perda VARCHAR(40) NULL,
            corretor_id INT NULL,
            observacoes VARCHAR(300) NOT NULL DEFAULT '',
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL,
            excluido_em DATETIME NULL
        )$fim",
        "CREATE TABLE IF NOT EXISTS lead_eventos (
            id $pk,
            lead_id INT NOT NULL,
            usuario_id INT NULL,
            acao VARCHAR(30) NOT NULL,
            detalhe VARCHAR(255) NOT NULL DEFAULT '',
            quando DATETIME NOT NULL
        )$fim",
        "CREATE TABLE IF NOT EXISTS tentativas_login (
            id $pk,
            login VARCHAR(60) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            quando DATETIME NOT NULL
        )$fim",
        // Registro de acessos: cada entrada, saída e tentativa recusada. Só o admin vê (aba Registros).
        "CREATE TABLE IF NOT EXISTS log_acessos (
            id $pk,
            quando DATETIME NOT NULL,
            usuario_id INT NULL,
            login VARCHAR(60) NOT NULL DEFAULT '',
            resultado VARCHAR(20) NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            aparelho VARCHAR(60) NOT NULL DEFAULT ''
        )$fim",
        // Registro de exportações: quem, quando e o filtro usado. Nenhum dado dos leads exportados entra aqui.
        "CREATE TABLE IF NOT EXISTS log_exportacoes (
            id $pk,
            quando DATETIME NOT NULL,
            usuario_id INT NULL,
            login VARCHAR(60) NOT NULL DEFAULT '',
            filtro VARCHAR(500) NOT NULL
        )$fim",
    ];
    foreach ($tabelas as $sql) db()->exec($sql);
    $indices = [
        'CREATE INDEX ix_leads_corretor ON leads (corretor_id)',
        'CREATE INDEX ix_leads_status ON leads (status)',
        'CREATE INDEX ix_leads_telefone ON leads (telefone)',
        'CREATE INDEX ix_eventos_lead ON lead_eventos (lead_id)',
        'CREATE INDEX ix_tentativas ON tentativas_login (quando)',
        'CREATE INDEX ix_log_acessos ON log_acessos (quando)',
        'CREATE INDEX ix_log_exportacoes ON log_exportacoes (quando)',
    ];
    foreach ($indices as $sql) { try { db()->exec($sql); } catch (Throwable $t) { /* índice já existe */ } }
}

function tabelas_existentes(): array
{
    $sql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        : "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
    return q($sql)->fetchAll(PDO::FETCH_COLUMN);
}

/* Ajustes em bancos já instalados. Idempotente: pode rodar quantas vezes for preciso. */
function migrar(): array
{
    $feito = [];
    // Tabelas novas (CREATE TABLE IF NOT EXISTS): toda tabela acrescentada em criar_tabelas() nasce aqui.
    $antes = tabelas_existentes();
    criar_tabelas();
    foreach (array_diff(tabelas_existentes(), $antes) as $t) $feito[] = "tabela nova: $t";
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return $feito;   // SQLite não limita o tamanho do texto
    $tam = (int)valor("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'observacoes'");
    if ($tam > 0 && $tam < OBS_MAX) {
        db()->exec("ALTER TABLE leads MODIFY observacoes VARCHAR(" . OBS_MAX . ") NOT NULL DEFAULT ''");
        $feito[] = "observacoes: $tam -> " . OBS_MAX;
    }
    return $feito;
}
