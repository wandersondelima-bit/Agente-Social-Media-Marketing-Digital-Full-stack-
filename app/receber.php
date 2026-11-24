<?php
/*
 * Porta de entrada dos leads do site: recebe o formulário das landings ("Agende sua visita") e das
 * iscas (materiais gratuitos) e cria o lead no CRM, em "A distribuir", status Novo.
 *
 * Recebe o mesmo JSON que as landings já mandam para a planilha (texto puro, por sendBeacon ou fetch):
 *   {tipo:'formulario', nome, whatsapp:'+5511…', consentimento:true, consentimento_versao,
 *    imovel_codigo, imovel, interesse, pagina, utm_*, ref, referrer, aceita_ofertas, empresa}
 * Isca: os mesmos campos, sem imóvel, com isca:'{slug}' e isca_titulo:'{nome do material}'.
 *
 * Público de propósito (sem login e sem CSRF): o que protege é a origem (só o próprio site),
 * o campo invisível "empresa" (robô), o limite por IP e a validação de nome e telefone.
 * Nada é lido daqui: a resposta é só um código e {"ok":…}.
 */
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

const SITE_LIMITE_IP = 10;        // envios aceitos por IP por hora
const SITE_JANELA = 3600;
const SITE_REPETIDO = 86400;      // mesmo telefone no mesmo imóvel (ou isca) em 24 h = não cria outro lead
const SITE_ORIGENS = [SITE_URL, 'https://www.marceloosawaimoveis.com.br'];

function responder(int $codigo, array $extra = []): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header_remove('X-Powered-By');
    exit(json_encode(['ok' => $codigo < 300] + $extra, JSON_UNESCAPED_UNICODE));
}
/* Texto curto, sem quebra de linha e sem caractere de controle. */
function texto($v, int $max): string
{
    if (!is_scalar($v)) return '';
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v) ?? '';
    return mb_substr(trim($s), 0, $max);
}
/* Só aceita o envio feito de uma página do próprio site. */
function origem_do_site(): bool
{
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o !== '') return in_array(rtrim($o, '/'), SITE_ORIGENS, true);
    $r = (string)($_SERVER['HTTP_REFERER'] ?? '');
    foreach (SITE_ORIGENS as $s) if (str_starts_with($r, $s . '/')) return true;
    return false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') responder(200, ['servico' => 'entrada de leads do site']);   // teste rápido no navegador
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(405);
if (!origem_do_site()) responder(403);

$bruto = (string)file_get_contents('php://input', false, null, 0, 20001);
if ($bruto === '' || strlen($bruto) > 20000) responder(400);
$d = json_decode($bruto, true);
if (!is_array($d)) responder(400);

// Só o formulário com consentimento vira lead. Clique no WhatsApp e outros avisos são ignorados sem erro.
if (($d['tipo'] ?? '') !== 'formulario' || ($d['consentimento'] ?? null) !== true) responder(200, ['ignorado' => true]);
// Campo invisível preenchido = robô. Responde "ok" para não ensinar o robô a contornar.
if (texto($d['empresa'] ?? '', 10) !== '') responder(200);

try {
    if (!instalado()) responder(503);

    // Limite por IP: conta os envios da última hora e limpa os registros com mais de 1 dia.
    q('DELETE FROM entradas_site WHERE quando < ?', [date('Y-m-d H:i:s', time() - SITE_REPETIDO)]);
    $n = (int)valor('SELECT COUNT(*) FROM entradas_site WHERE ip = ? AND quando >= ?', [ip(), date('Y-m-d H:i:s', time() - SITE_JANELA)]);
    if ($n >= SITE_LIMITE_IP) responder(429);
    q('INSERT INTO entradas_site (ip, quando) VALUES (?,?)', [ip(), agora()]);

    $nome = texto($d['nome'] ?? '', 160);
    $tel = telefone_limpo(texto($d['whatsapp'] ?? '', 30));
    if ($nome === '' || $tel === null) responder(400);

    // Isca (material gratuito) ou imóvel (landing)
    $isca = strtolower(texto($d['isca'] ?? '', 60));
    if ($isca !== '' && !preg_match('/^[a-z0-9-]{2,60}$/', $isca)) responder(400);
    if ($isca !== '') {
        $origem = 'Isca do site';
        $interesse = 'Isca: ' . (texto($d['isca_titulo'] ?? '', 72) ?: $isca);
        $im = [null, null, null];
        $chave_dup = ['interesse = ?', mb_substr($interesse, 0, 80)];
    } else {
        $origem = 'Formulário do site';
        $interesse = texto($d['interesse'] ?? '', 80) ?: null;
        $cod = preg_match('/^\d{5,9}$/', texto($d['imovel_codigo'] ?? '', 9)) ? texto($d['imovel_codigo'], 9) : '';
        $site = imoveis_site();
        $pagina = texto($d['pagina'] ?? '', 255);
        $link = preg_match('~^' . preg_quote(SITE_URL, '~') . '/\d{5,9}/[a-z0-9-]+/~', $pagina) ? $pagina : null;
        if ($cod !== '' && isset($site[$cod])) $im = [$cod, $site[$cod]['titulo'], $site[$cod]['url']];
        elseif ($cod !== '') $im = [$cod, texto($d['imovel'] ?? '', 255) ?: "Imóvel $cod", $link];
        else $im = [null, texto($d['imovel'] ?? '', 255) ?: null, $link];
        $chave_dup = $cod !== '' ? ['imovel_codigo = ?', $cod] : ['imovel_codigo IS NULL AND origem = ?', $origem];
    }

    $utm = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'ref'] as $c) {
        $v = texto($d[$c] ?? '', 80);
        if ($v !== '') $utm[$c] = $v;
    }
    $utm_json = $utm ? mb_substr(json_encode($utm, JSON_UNESCAPED_UNICODE), 0, 500) : null;
    $detalhe = 'pelo site · consentimento ' . (texto($d['consentimento_versao'] ?? '', 30) ?: 'sim')
        . ' · aceita outras opções: ' . (($d['aceita_ofertas'] ?? null) === true ? 'sim' : 'não');

    // Mesma pessoa, mesmo imóvel (ou isca), nas últimas 24 h: só registra no histórico do lead que já existe.
    $dup = um('SELECT id FROM leads WHERE telefone = ? AND excluido_em IS NULL AND criado_em >= ? AND ' . $chave_dup[0] . ' ORDER BY id DESC LIMIT 1',
        [$tel, date('Y-m-d H:i:s', time() - SITE_REPETIDO), $chave_dup[1]]);
    if ($dup) {
        evento((int)$dup['id'], 'site_repetido', 'enviou o formulário de novo · ' . ($interesse ?? ''));
        responder(200, ['repetido' => true]);
    }

    q("INSERT INTO leads (entrada, nome, telefone, imovel_codigo, imovel_titulo, imovel_url, interesse, origem, utm, status, corretor_id, observacoes, criado_em, atualizado_em)
       VALUES (?,?,?,?,?,?,?,?,?,'novo',NULL,'',?,?)",
        [date('Y-m-d'), $nome, $tel, $im[0], $im[1], $im[2], $interesse, $origem, $utm_json, agora(), agora()]);
    evento((int)db()->lastInsertId(), 'criar', $detalhe);
    responder(201);
} catch (Throwable $t) {
    error_log('[crm receber] ' . get_class($t) . ': ' . $t->getMessage());
    responder(500);
}
