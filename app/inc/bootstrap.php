<?php
/* Base do CRM de leads: configuração, sessão, banco, segurança e funções de apoio. */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

const STATUS = ['novo' => 'Novo', 'andamento' => 'Em andamento', 'concluido' => 'Concluído', 'perdido' => 'Perdido'];
const MOTIVOS = ['Sem retorno', 'Comprou outro imóvel', 'Fora do orçamento', 'Desistiu', 'Outro'];
const ORIGENS = ['WhatsApp Business', 'Direct do Instagram', 'Formulário do site', 'Outro'];
const OBS_MAX = 200;
const SENHA_MIN = 6;              // tamanho mínimo da senha (decisão do administrador em 11/11/2026)
const SESSAO_OCIOSA = 86400;      // 24 horas sem uso encerram a sessão (era 12; pedido de 12/11/2026)
const LOGIN_JANELA = 900;         // 15 minutos
const LOGIN_MAX = 8;              // tentativas erradas na janela, por login
const LOGIN_MAX_IP = 30;          // tentativas erradas na janela, por IP
const SITE_URL = 'https://marceloosawaimoveis.com.br';

/* ---------- Configuração: fora da pasta pública quando existir, senão em inc/config.php (protegido pelo .htaccess) ---------- */
function config(?string $chave = null, $padrao = null)
{
    static $c = null;
    if ($c === null) {
        $c = [];
        foreach ([dirname(__DIR__, 3) . '/crm-config.php', __DIR__ . '/config.php'] as $arq) {
            if (is_file($arq)) { $c = require $arq; break; }
        }
        if (!is_array($c) || empty($c['db_dsn'])) {
            http_response_code(500);
            exit('Sistema sem configuração. Crie o arquivo de configuração a partir de inc/config.exemplo.php.');
        }
    }
    return $chave === null ? $c : ($c[$chave] ?? $padrao);
}

function base(): string
{
    static $b = null;
    if ($b === null) $b = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $b;
}
function url(string $rota = 'leads', array $q = []): string
{
    return base() . '/?' . http_build_query(['r' => $rota] + $q);
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function agora(): string { return date('Y-m-d H:i:s'); }
function https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/* ---------- Banco ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(config('db_dsn'), config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function um(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function valor(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

function instalado(): bool
{
    try { return (int)valor('SELECT COUNT(*) FROM usuarios') > 0; } catch (Throwable $t) { return false; }
}

/* ---------- Cabeçalhos de segurança ---------- */
function cabecalhos(): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, max-age=0');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header_remove('X-Powered-By');
}

/* ---------- Sessão ---------- */
function iniciar_sessao(): void
{
    $dir = __DIR__ . '/_dados/sess';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) ini_set('session.save_path', $dir);
    ini_set('session.gc_maxlifetime', (string)SESSAO_OCIOSA);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('crm_sessao');
    session_set_cookie_params(['lifetime' => 0, 'path' => base() . '/', 'secure' => https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['visto']) && time() - (int)$_SESSION['visto'] > SESSAO_OCIOSA) encerrar_sessao();
    $_SESSION['visto'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function encerrar_sessao(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
        session_start();
    }
}
function csrf(): string { return $_SESSION['csrf'] ?? ''; }
function campo_csrf(): string { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function exigir_csrf(): void
{
    $t = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($t === '' || !hash_equals(csrf(), $t)) falha(403, 'Sessão expirada. Recarregue a página e tente de novo.');
}

/* ---------- Usuário logado: relido do banco a cada pedido, para que desativar valha na hora ---------- */
function usuario(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = um('SELECT id, nome, login, papel, ativo FROM usuarios WHERE id = ?', [(int)$_SESSION['uid']]);
            if (!$u || !(int)$u['ativo']) { $u = null; encerrar_sessao(); }
        }
    }
    return $u;
}
function admin(): bool { $u = usuario(); return $u !== null && $u['papel'] === 'admin'; }
function exigir_login(): array
{
    $u = usuario();
    if (!$u) {
        if (pedido_fetch()) falha(401, 'Sua sessão terminou. Entre de novo.');
        redirecionar(url('login'));
    }
    return $u;
}
function exigir_admin(): array
{
    $u = exigir_login();
    if ($u['papel'] !== 'admin') falha(403, 'Esta área é só do administrador.');
    return $u;
}

/* ---------- Respostas ---------- */
function pedido_fetch(): bool { return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'; }
function redirecionar(string $para): void { header('Location: ' . $para, true, 303); exit; }
function aviso(string $msg, string $tipo = 'ok'): void { $_SESSION['aviso'] = [$tipo, $msg]; }
function falha(int $codigo, string $msg): void
{
    http_response_code($codigo);
    if (pedido_fetch()) { header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE)); }
    require_once __DIR__ . '/telas.php';
    tela_mensagem('Não foi possível concluir', $msg);
    exit;
}
/* Resposta de uma ação: JSON para o JavaScript, ou aviso e volta para a lista. */
function concluir(string $msg, array $extra = [], ?string $para = null): void
{
    if (pedido_fetch()) { header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['ok' => true, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE)); }
    aviso($msg);
    redirecionar($para ?? voltar_lista());
}
function voltar_lista(): string { return base() . '/?' . ($_SESSION['lista_qs'] ?? 'r=leads'); }

/* ---------- Telefone e datas ---------- */
function so_digitos(string $s): string { return preg_replace('/\D+/', '', $s) ?? ''; }
/* Guarda DDD + número (10 ou 11 dígitos); aceita o número digitado com 55 na frente. */
function telefone_limpo(string $s): ?string
{
    $d = so_digitos($s);
    if (strlen($d) >= 12 && str_starts_with($d, '55')) $d = substr($d, 2);
    $d = ltrim($d, '0');
    return (strlen($d) === 10 || strlen($d) === 11) ? $d : null;
}
function telefone_fmt(string $d): string
{
    if (strlen($d) === 11) return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
    if (strlen($d) === 10) return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
    return $d;
}
function data_br(?string $iso): string
{
    if (!$iso) return '';
    $t = strtotime($iso);
    return $t ? date('d/m/Y', $t) : '';
}
/* Aceita 2026-11-10 ou 10/11/2026 (com ou sem hora) e devolve 2026-11-10. */
function data_iso(string $s): ?string
{
    $s = trim($s);
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})~', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

/* ---------- Imóveis publicados: lidos da página inicial do site (lista IMOVEIS) ---------- */
function imoveis_site(): array
{
    static $l = null;
    if ($l !== null) return $l;
    $l = [];
    $arq = rtrim((string)config('site_dir', dirname(__DIR__, 2)), '/') . '/index.html';
    $html = is_file($arq) ? (string)@file_get_contents($arq) : '';
    if ($html !== '' && preg_match_all("~\\{codigo:'(\\d+)',\\s*url:'([^']+)',\\s*bairro:'((?:[^'\\\\]|\\\\.)*)',\\s*titulo:'((?:[^'\\\\]|\\\\.)*)'~u", $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $l[$x[1]] = ['codigo' => $x[1], 'url' => SITE_URL . $x[2], 'bairro' => stripslashes($x[3]), 'titulo' => stripslashes($x[4])];
        }
    }
    return $l;
}

/* ---------- Leads: toda leitura passa por aqui, para o corretor só alcançar os dele ---------- */
function lead_permitido(int $id, bool $com_excluidos = false): array
{
    $u = exigir_login();
    $l = um('SELECT * FROM leads WHERE id = ?', [$id]);
    if (!$l) falha(404, 'Lead não encontrado.');
    if ($u['papel'] !== 'admin' && (int)$l['corretor_id'] !== (int)$u['id']) falha(404, 'Lead não encontrado.');
    if ($l['excluido_em'] !== null && !($com_excluidos && $u['papel'] === 'admin')) falha(404, 'Lead não encontrado.');
    return $l;
}
function evento(int $lead_id, string $acao, string $detalhe = ''): void
{
    $u = usuario();
    q('INSERT INTO lead_eventos (lead_id, usuario_id, acao, detalhe, quando) VALUES (?,?,?,?,?)',
        [$lead_id, $u ? (int)$u['id'] : null, $acao, mb_substr($detalhe, 0, 250), agora()]);
}
function corretores(bool $so_ativos = true): array
{
    return q("SELECT id, nome, ativo FROM usuarios WHERE papel = 'corretor'" . ($so_ativos ? ' AND ativo = 1' : '') . ' ORDER BY nome')->fetchAll();
}
