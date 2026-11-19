<?php
/* Aba Registros: acessos ao sistema e pedidos de exportação. Só os administradores abrem (rota com exigir_admin). */
declare(strict_types=1);

const REGISTROS_LIMITE = 200;   // linhas mostradas por vez, das mais recentes para as mais antigas
const FALHAS_ACESSO = ['senha_errada', 'login_inexistente', 'desativado', 'bloqueado'];

/* Filtro comum à tela e ao download: tipo (acessos ou exportações), usuário e resultado. */
function filtro_registros(array $g): array
{
    $t = ($g['t'] ?? '') === 'exportacoes' ? 'exportacoes' : 'acessos';
    $fu = ctype_digit((string)($g['u'] ?? '')) ? (int)$g['u'] : 0;
    $fr = $t === 'acessos' && in_array($g['res'] ?? '', ['entradas', 'recusadas'], true) ? (string)$g['res'] : '';
    $al = $t === 'acessos' ? 'a' : 'x';
    $onde = ['1 = 1']; $p = [];
    if ($fu) { $onde[] = "$al.usuario_id = ?"; $p[] = $fu; }
    if ($fr === 'entradas') $onde[] = "a.resultado = 'entrou'";
    if ($fr === 'recusadas') $onde[] = "a.resultado IN ('" . implode("', '", FALHAS_ACESSO) . "')";
    $sql = 'FROM ' . ($t === 'acessos' ? 'log_acessos a' : 'log_exportacoes x') . " LEFT JOIN usuarios us ON us.id = $al.usuario_id WHERE " . implode(' AND ', $onde);
    return ['t' => $t, 'u' => $fu, 'res' => $fr, 'al' => $al, 'sql' => $sql, 'p' => $p];
}

function tela_registros(array $u): void
{
    $f = filtro_registros($_GET);
    [$t, $fu, $fr, $al] = [$f['t'], $f['u'], $f['res'], $f['al']];
    $rs = q("SELECT $al.*, us.nome {$f['sql']} ORDER BY $al.id DESC LIMIT " . (REGISTROS_LIMITE + 1), $f['p'])->fetchAll();
    $total = (int)valor("SELECT COUNT(*) {$f['sql']}", $f['p']);
    $baixar = $total ? '<a class="btn btn-ghost btn-small" href="' . e(url('registros_arquivo', array_filter(['t' => $t === 'exportacoes' ? $t : '', 'u' => $fu ?: '', 'res' => $fr]))) . '">'
        . 'Baixar planilha CSV · ' . $total . ($total === 1 ? ' registro' : ' registros') . '</a>' : '';
    $desde = date('Y-m-d H:i:s', strtotime('-30 days'));
    $usuarios = [];
    foreach (q('SELECT id, nome, ativo FROM usuarios ORDER BY nome')->fetchAll() as $x) $usuarios[(string)$x['id']] = $x['nome'] . ((int)$x['ativo'] ? '' : ' (desativado)');
    $quem = fn(array $r): string => $r['nome'] !== null ? e($r['nome'])
        : '<span class="muted" title="Login digitado">' . e($r['login'] !== '' ? $r['login'] : '—') . '</span>';
    $quando = fn(string $d): string => '<span class="num">' . e(date('d/m/Y H:i', strtotime($d))) . '</span>';
    $falhas = "'" . implode("', '", FALHAS_ACESSO) . "'";

    $h = topo($u, 'registros') . '<main><div class="wrap"><div class="head"><div><h1>Registros</h1>'
        . '<p class="muted">Quem entrou no sistema e quem baixou planilhas. Só os administradores veem esta página.</p></div></div>'
        . '<div class="filtros"><a class="chip" href="' . e(url('registros')) . '"' . ($t === 'acessos' ? ' aria-current="true"' : '') . '>Acessos</a>'
        . '<a class="chip" href="' . e(url('registros', ['t' => 'exportacoes'])) . '"' . ($t === 'exportacoes' ? ' aria-current="true"' : '') . '>Exportações</a></div>'
        . '<form class="busca' . ($t === 'acessos' ? ' dupla' : '') . '" method="get" action="' . e(base()) . '/"><input type="hidden" name="r" value="registros">'
        . ($t === 'exportacoes' ? '<input type="hidden" name="t" value="exportacoes">' : '')
        . '<select class="campo" name="u" aria-label="Filtrar por usuário" data-envia>' . opcoes($usuarios, $fu ?: '', 'Todos os usuários') . '</select>'
        . ($t === 'acessos' ? '<select class="campo" name="res" aria-label="Filtrar por resultado" data-envia>' . opcoes(['entradas' => 'Só entradas', 'recusadas' => 'Só tentativas recusadas'], $fr, 'Todos os resultados') . '</select>' : '')
        . '<noscript><button class="btn btn-ghost" type="submit">Filtrar</button></noscript></form>';

    if ($t === 'acessos') {
        $c = q("SELECT SUM(CASE WHEN resultado = 'entrou' THEN 1 ELSE 0 END) AS entradas,
                       SUM(CASE WHEN resultado IN ($falhas) AND resultado <> 'bloqueado' THEN 1 ELSE 0 END) AS recusadas,
                       SUM(CASE WHEN resultado = 'bloqueado' THEN 1 ELSE 0 END) AS bloqueios
                FROM log_acessos WHERE quando >= ?", [$desde])->fetch();
        $h .= '<div class="barra-reg"><p class="muted resumo-reg">Últimos 30 dias: <b class="num">' . (int)$c['entradas'] . '</b> ' . ((int)$c['entradas'] === 1 ? 'entrada' : 'entradas')
            . ' · <b class="num">' . (int)$c['recusadas'] . '</b> ' . ((int)$c['recusadas'] === 1 ? 'tentativa recusada' : 'tentativas recusadas')
            . ' · <b class="num">' . (int)$c['bloqueios'] . '</b> ' . ((int)$c['bloqueios'] === 1 ? 'bloqueio' : 'bloqueios') . '</p>' . $baixar . '</div>'
            . '<section class="form"><div class="tabela registros"><table class="t-acessos"><thead><tr><th>Quando</th><th>Usuário</th><th>Resultado</th><th>IP</th><th>Aparelho</th></tr></thead><tbody>';
        foreach (array_slice($rs, 0, REGISTROS_LIMITE) as $r) {
            $cls = $r['resultado'] === 'entrou' ? 'ok' : (in_array($r['resultado'], FALHAS_ACESSO, true) ? 'erro' : 'neutro');
            $h .= '<tr><td class="qd">' . $quando((string)$r['quando']) . '</td><td class="us">' . $quem($r) . '</td><td class="rs"><span class="res ' . $cls . '">' . e(RESULTADOS_ACESSO[$r['resultado']] ?? $r['resultado']) . '</span></td>'
                . '<td class="ip num">' . e($r['ip']) . '</td><td class="ap">' . e($r['aparelho']) . '</td></tr>';
        }
    } else {
        $n = (int)valor('SELECT COUNT(*) FROM log_exportacoes WHERE quando >= ?', [$desde]);
        $h .= '<div class="barra-reg"><p class="muted resumo-reg">Últimos 30 dias: <b class="num">' . $n . '</b> ' . ($n === 1 ? 'planilha baixada' : 'planilhas baixadas') . '</p>' . $baixar . '</div>'
            . '<section class="form"><div class="tabela registros"><table class="t-exp"><thead><tr><th>Quando</th><th>Usuário</th><th>Filtro usado</th></tr></thead><tbody>';
        foreach (array_slice($rs, 0, REGISTROS_LIMITE) as $r) {
            $h .= '<tr><td class="qd">' . $quando((string)$r['quando']) . '</td><td class="us">' . $quem($r) . '</td><td class="filtro">'
                . implode('<br>', array_map('e', explode(' · ', (string)$r['filtro']))) . '</td></tr>';
        }
    }
    if (!$rs) $h .= '<tr><td colspan="' . ($t === 'acessos' ? 5 : 3) . '" class="muted vazio">' . ($fu || $fr ? 'Nenhum registro neste filtro.' : ($t === 'acessos' ? 'Ainda não há acessos registrados.' : 'Ainda não há planilhas baixadas.')) . '</td></tr>';
    $h .= '</tbody></table></div></section>'
        . (count($rs) > REGISTROS_LIMITE ? '<p class="muted nota-lista">Mostrando os ' . REGISTROS_LIMITE . ' registros mais recentes. Use os filtros ou baixe a planilha para ver os demais.</p>' : '')
        . '<p class="muted nota-lista">' . ($t === 'acessos'
            ? 'Cada entrada, saída e tentativa recusada fica registrada com o IP (o endereço de internet de onde veio o acesso) e o aparelho. Quando o login digitado não existe, ele aparece em cinza.'
            : 'Cada planilha baixada fica registrada com quem baixou, quando e o filtro usado. Nenhum dado dos leads é guardado aqui.')
        . ' Os registros ficam guardados por ' . LOG_MESES . ' meses.</p></div></main>';
    pagina('Registros', $h);
}

/* Download dos registros em CSV, com o mesmo filtro da tela e sem o limite de 200 linhas. */
function registros_arquivo(array $u): void
{
    $f = filtro_registros($_GET);
    $rs = q("SELECT {$f['al']}.*, us.nome {$f['sql']} ORDER BY {$f['al']}.id DESC", $f['p'])->fetchAll();
    $dh = fn(string $d): array => [date('d/m/Y', strtotime($d)), date('H:i:s', strtotime($d))];
    $linhas = [];
    if ($f['t'] === 'acessos') {
        $cab = ['Data', 'Hora', 'Usuário', 'Login', 'Resultado', 'IP', 'Aparelho'];
        foreach ($rs as $r) $linhas[] = [...$dh((string)$r['quando']), (string)$r['nome'], (string)$r['login'], RESULTADOS_ACESSO[$r['resultado']] ?? (string)$r['resultado'], (string)$r['ip'], (string)$r['aparelho']];
    } else {
        // O filtro gravado ("Período: … · Status: … · …") vira uma coluna por parte, para filtrar na planilha.
        $cab = ['Data', 'Hora', 'Usuário', 'Login', 'Período', 'Status', 'Corretor', 'Origem', 'Excluídos'];
        foreach ($rs as $r) {
            $pt = [];
            foreach (explode(' · ', (string)$r['filtro']) as $x) { [$k, $v] = array_pad(explode(': ', $x, 2), 2, ''); $pt[$k] = $v; }
            $linhas[] = [...$dh((string)$r['quando']), (string)$r['nome'], (string)$r['login'], $pt['Período'] ?? (string)$r['filtro'], $pt['Status'] ?? '', $pt['Corretor'] ?? '', $pt['Origem'] ?? '', $pt['Excluídos'] ?? ''];
        }
    }
    enviar_csv('registros-' . $f['t'] . '-' . date('Y-m-d'), $cab, $linhas);
}
