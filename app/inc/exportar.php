<?php
/* Aba Exportar: filtros, resumo do mês e download da planilha (.xlsx ou .csv). Admin e gerente usam. */
declare(strict_types=1);

const MESES = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const DIAS_PARADO = 14;   // lead em aberto sem atualização há este tempo conta como parado

function nome_mes(string $ym): string
{
    [$a, $m] = array_map('intval', explode('-', $ym));
    return (MESES[$m] ?? $ym) . ' de ' . $a;
}

/* Lê os filtros do pedido (GET) e devolve as condições SQL, os parâmetros, o resumo em palavras e o sufixo do arquivo. */
function filtros_exportacao(array $g): array
{
    $onde = [];
    $p = [];
    $txt = [];
    $hoje = date('Y-m-d');
    $per = (string)($g['periodo'] ?? 'tudo');
    $de = $ate = null;
    if ($per === 'mes_atual') { $de = date('Y-m-01'); $ate = date('Y-m-t'); }
    elseif ($per === 'mes_passado') { $t = strtotime('first day of last month'); $de = date('Y-m-01', $t); $ate = date('Y-m-t', $t); }
    elseif ($per === '30d') { $de = date('Y-m-d', strtotime('-29 days')); $ate = $hoje; }
    elseif (preg_match('/^m:(\d{4})-(\d{2})$/', $per, $m) && checkdate((int)$m[2], 1, (int)$m[1])) { $de = "$m[1]-$m[2]-01"; $ate = date('Y-m-t', strtotime($de)); }
    elseif ($per === 'datas') { $de = data_iso((string)($g['de'] ?? '')); $ate = data_iso((string)($g['ate'] ?? '')); }
    else $per = 'tudo';
    if ($de && $ate && $de > $ate) [$de, $ate] = [$ate, $de];
    if ($de) { $onde[] = 'l.entrada >= ?'; $p[] = $de; }
    if ($ate) { $onde[] = 'l.entrada <= ?'; $p[] = $ate; }
    $txt[] = $de || $ate ? 'entrada ' . ($de ? 'de ' . data_br($de) : 'até') . ($ate ? ($de ? ' a ' : ' ') . data_br($ate) : ' em diante') : 'todo o período';

    // Status: na primeira visita todos vêm marcados; depois vale o que foi marcado.
    $sts = isset($g['enviado']) ? array_values(array_intersect(array_keys(STATUS), (array)($g['s'] ?? []))) : array_keys(STATUS);
    if (!$sts) $onde[] = '1 = 0';
    elseif (count($sts) < count(STATUS)) {
        $onde[] = 'l.status IN (' . implode(',', array_fill(0, count($sts), '?')) . ')';
        array_push($p, ...$sts);
        $txt[] = implode(', ', array_map(fn($k) => STATUS[$k], $sts));
    }

    $c = (string)($g['c'] ?? '');
    if ($c === 'sem') { $onde[] = 'l.corretor_id IS NULL'; $txt[] = 'A distribuir'; }
    elseif (ctype_digit($c) && $c !== '0') {
        $onde[] = 'l.corretor_id = ?'; $p[] = (int)$c;
        $txt[] = (string)valor('SELECT nome FROM usuarios WHERE id = ?', [(int)$c]);
    } else $c = '';

    $o = (string)($g['o'] ?? '');
    if (in_array($o, ORIGENS, true)) { $onde[] = 'l.origem = ?'; $p[] = $o; $txt[] = $o; } else $o = '';

    $x = ($g['x'] ?? '') === '1';
    if (!$x) $onde[] = 'l.excluido_em IS NULL'; else $txt[] = 'com excluídos';

    $suf = $de || $ate ? ($de ?? 'inicio') . '-a-' . ($ate ?? $hoje) : 'tudo';
    return ['onde' => $onde ?: ['1 = 1'], 'p' => $p, 'texto' => implode(' · ', $txt), 'sufixo' => $suf,
            'v' => ['periodo' => $per, 'de' => $de, 'ate' => $ate, 's' => $sts, 'c' => $c, 'o' => $o, 'x' => $x]];
}

function contar_exportacao(array $flt): int
{
    return (int)valor('SELECT COUNT(*) FROM leads l WHERE ' . implode(' AND ', $flt['onde']), $flt['p']);
}

/* Resumo pela data de entrada: os 6 meses mais recentes com leads. */
function resumo_mensal(): array
{
    return q("SELECT SUBSTR(l.entrada, 1, 7) AS mes, COUNT(*) AS total,
                SUM(CASE WHEN l.status IN ('novo', 'andamento') THEN 1 ELSE 0 END) AS abertos,
                SUM(CASE WHEN l.status = 'concluido' THEN 1 ELSE 0 END) AS concluidos,
                SUM(CASE WHEN l.status = 'perdido' THEN 1 ELSE 0 END) AS perdidos
              FROM leads l WHERE l.excluido_em IS NULL GROUP BY SUBSTR(l.entrada, 1, 7) ORDER BY mes DESC LIMIT 6")->fetchAll();
}

function tela_exportar(array $u): void
{
    $flt = filtros_exportacao($_GET);
    $v = $flt['v'];
    $n = contar_exportacao($flt);
    $parados = (int)valor("SELECT COUNT(*) FROM leads WHERE excluido_em IS NULL AND status IN ('novo', 'andamento') AND atualizado_em < ?", [date('Y-m-d H:i:s', time() - DIAS_PARADO * 86400)]);

    $meses = q("SELECT DISTINCT SUBSTR(entrada, 1, 7) AS m FROM leads ORDER BY m DESC LIMIT 36")->fetchAll(PDO::FETCH_COLUMN);
    $per = ['tudo' => 'Todo o período', 'mes_atual' => 'Este mês (' . nome_mes(date('Y-m')) . ')', 'mes_passado' => 'Mês passado (' . nome_mes(date('Y-m', strtotime('first day of last month'))) . ')', '30d' => 'Últimos 30 dias'];
    foreach ($meses as $m) if ($m !== date('Y-m') && $m !== date('Y-m', strtotime('first day of last month'))) $per['m:' . $m] = nome_mes($m);
    $per['datas'] = 'Escolher datas…';
    $cors = ['sem' => 'A distribuir'];
    foreach (corretores(false) as $c) $cors[(string)$c['id']] = $c['nome'] . ((int)$c['ativo'] ? '' : ' (desativado)');

    $h = topo($u, 'exportar') . '<main><div class="wrap"><div class="head"><div><h1>Exportar planilha</h1>'
        . '<p class="muted">Escolha os filtros e baixe os leads em planilha. O período é contado pela data de entrada do lead.</p></div></div>';

    // Resumo: o que entrou e como terminou, mês a mês.
    $rs = resumo_mensal();
    if ($rs) {
        $h .= '<section class="form resumo"><h2>Resumo pela data de entrada</h2><div class="tabela"><table><thead><tr><th>Mês</th><th>Entraram</th><th>Em aberto</th><th>Concluídos</th><th>Perdidos</th><th>Conversão</th></tr></thead><tbody>';
        foreach ($rs as $r) {
            $conv = (int)$r['total'] ? round(100 * (int)$r['concluidos'] / (int)$r['total']) . '%' : '–';
            $h .= '<tr><td>' . e(nome_mes((string)$r['mes'])) . '</td><td class="num">' . (int)$r['total'] . '</td><td class="num">' . (int)$r['abertos'] . '</td><td class="num">'
                . (int)$r['concluidos'] . '</td><td class="num">' . (int)$r['perdidos'] . '</td><td class="num">' . $conv . '</td></tr>';
        }
        $h .= '</tbody></table></div><p class="nota">Conversão = concluídos ÷ leads que entraram no mês.'
            . ($parados ? ' <b>' . $parados . ' ' . ($parados === 1 ? 'lead em aberto está' : 'leads em aberto estão') . ' sem atualização há ' . DIAS_PARADO . ' dias ou mais.</b>' : '') . '</p></section>';
    }

    $h .= '<form class="form exportar" method="get" action="' . e(base()) . '/"><input type="hidden" name="enviado" value="1"><div class="grade">'
        . '<div><label for="x-per">Período</label><select class="campo" id="x-per" name="periodo" data-periodo>' . opcoes($per, $v['periodo']) . '</select></div>'
        . '<div class="datas" data-datas><div><label for="x-de">De</label><input class="campo" id="x-de" name="de" type="date" value="' . e($v['periodo'] === 'datas' ? (string)$v['de'] : '') . '"></div>'
        . '<div><label for="x-ate">Até</label><input class="campo" id="x-ate" name="ate" type="date" value="' . e($v['periodo'] === 'datas' ? (string)$v['ate'] : '') . '"></div></div>'
        . '<div><label for="x-cor">Corretor</label><select class="campo" id="x-cor" name="c">' . opcoes($cors, $v['c'], 'Todos os corretores') . '</select></div>'
        . '<div><label for="x-or">Origem</label><select class="campo" id="x-or" name="o">' . opcoes(ORIGENS, $v['o'], 'Todas as origens') . '</select></div>'
        . '</div><fieldset class="marcas"><legend>Status</legend>';
    foreach (STATUS as $k => $t) {
        $h .= '<label class="marca"><input type="checkbox" name="s[]" value="' . e($k) . '"' . (in_array($k, $v['s'], true) ? ' checked' : '') . '><span class="ponto ' . e($k) . '"></span>' . e($t) . '</label>';
    }
    $h .= '<label class="marca"><input type="checkbox" name="x" value="1"' . ($v['x'] ? ' checked' : '') . '>Incluir excluídos</label></fieldset>'
        . '<fieldset class="marcas"><legend>Formato</legend>'
        . '<label class="marca"><input type="radio" name="fmt" value="xlsx"' . (($_GET['fmt'] ?? 'xlsx') !== 'csv' ? ' checked' : '') . '>Excel (.xlsx), abre também no Google Planilhas</label>'
        . '<label class="marca"><input type="radio" name="fmt" value="csv"' . (($_GET['fmt'] ?? '') === 'csv' ? ' checked' : '') . '>CSV, para outros sistemas</label></fieldset>'
        . '<p class="contagem" aria-live="polite"><b class="num">' . $n . '</b> ' . ($n === 1 ? 'lead' : 'leads') . ' com estes filtros · ' . e($flt['texto']) . '</p>'
        . '<div class="acoes"><button class="btn btn-primary" type="submit" name="r" value="exportar_arquivo"' . ($n ? '' : ' disabled') . '>Baixar planilha</button>'
        . '<button class="btn btn-ghost" type="submit" name="r" value="exportar">Atualizar contagem</button></div></form>'
        . '<p class="muted nota-lista">A planilha traz contato, imóvel, origem, campanha (UTM), status, motivo da perda, corretor, observações e há quantos dias o lead entrou. Guarde o arquivo com cuidado: ele tem nome e telefone de clientes.</p>'
        . '</div></main>';
    pagina('Exportar', $h);
}

/* ---------- Download ---------- */
function linhas_exportacao(array $flt): array
{
    $rs = q('SELECT l.*, u.nome AS corretor FROM leads l LEFT JOIN usuarios u ON u.id = l.corretor_id WHERE ' . implode(' AND ', $flt['onde']) . ' ORDER BY l.entrada DESC, l.id DESC', $flt['p'])->fetchAll();
    $hoje = new DateTimeImmutable('today');
    $out = [];
    foreach ($rs as $l) {
        $utm = json_decode((string)$l['utm'], true) ?: [];
        $dias = $hoje->diff(new DateTimeImmutable((string)$l['entrada']))->days;
        $out[] = [
            ['d', (string)$l['entrada']], ['s', nome_mes(substr((string)$l['entrada'], 0, 7))], ['s', STATUS[$l['status']] ?? $l['status']], ['s', (string)$l['motivo_perda']],
            ['s', (string)($l['corretor'] ?? '') !== '' ? (string)$l['corretor'] : 'A distribuir'], ['s', (string)$l['nome']], ['s', telefone_fmt((string)$l['telefone'])],
            ['s', (string)$l['imovel_codigo']], ['s', (string)$l['imovel_titulo']], ['s', (string)$l['imovel_url']], ['s', (string)$l['interesse']], ['s', (string)$l['origem']],
            ['s', (string)($utm['utm_source'] ?? '')], ['s', (string)($utm['utm_medium'] ?? '')], ['s', (string)($utm['utm_campaign'] ?? '')], ['s', (string)($utm['utm_content'] ?? '')],
            ['s', (string)$l['observacoes']], ['d', substr((string)$l['atualizado_em'], 0, 10)], ['n', $dias],
            ['d', $l['excluido_em'] ? substr((string)$l['excluido_em'], 0, 10) : ''],
        ];
    }
    return $out;
}

const COLUNAS_EXPORT = [['Recebido em', 12], ['Mês de entrada', 18], ['Status', 14], ['Motivo da perda', 20], ['Corretor', 22], ['Nome', 28], ['WhatsApp', 17],
    ['Código do imóvel', 12], ['Imóvel', 40], ['Link do imóvel', 44], ['Interesse', 12], ['Origem', 20], ['utm_source', 14], ['utm_medium', 14], ['utm_campaign', 18],
    ['utm_content', 18], ['Observações', 60], ['Última atualização', 14], ['Dias desde a entrada', 10], ['Excluído em', 12]];

function exportar_arquivo(array $u): void
{
    $flt = filtros_exportacao($_GET);
    $linhas = linhas_exportacao($flt);
    $nome = 'leads-' . $flt['sufixo'];
    if (($_GET['fmt'] ?? 'xlsx') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        $seguro = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;   // impede fórmula ao abrir na planilha
        fputcsv($o, array_column(COLUNAS_EXPORT, 0), ',', '"', '');
        foreach ($linhas as $l) fputcsv($o, array_map(fn($c) => $seguro($c[0] === 'd' ? data_br($c[1]) : $c[1]), $l), ',', '"', '');
        exit;
    }
    $arq = xlsx_montar($linhas);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nome . '.xlsx"');
    header('Content-Length: ' . filesize($arq));
    readfile($arq);
    @unlink($arq);
    exit;
}

/* ---------- XLSX mínimo (sem biblioteca): uma aba, cabeçalho em negrito e congelado, filtro, datas de verdade ---------- */
function xlsx_col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function xlsx_txt(string $s): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function xlsx_montar(array $linhas): string
{
    $ncol = count(COLUNAS_EXPORT);
    $ult = xlsx_col($ncol - 1);
    $nlin = count($linhas) + 1;
    $cols = '';
    foreach (COLUNAS_EXPORT as $i => [, $w]) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $x = '<row r="1">';
    foreach (COLUNAS_EXPORT as $i => [$t]) $x .= '<c r="' . xlsx_col($i) . '1" t="inlineStr" s="1"><is><t>' . xlsx_txt($t) . '</t></is></c>';
    $x .= '</row>';
    foreach ($linhas as $r => $l) {
        $n = $r + 2;
        $x .= '<row r="' . $n . '">';
        foreach ($l as $i => [$tipo, $val]) {
            $ref = xlsx_col($i) . $n;
            if ($val === '' || $val === null) continue;
            if ($tipo === 'n') $x .= '<c r="' . $ref . '"><v>' . (int)$val . '</v></c>';
            elseif ($tipo === 'd' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$val, $m)) $x .= '<c r="' . $ref . '" s="2"><v>' . (intdiv(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]), 86400) + 25569) . '</v></c>';
            else $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_txt((string)$val) . '</t></is></c>';
        }
        $x .= '</row>';
    }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $partes = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Leads!$A$1:$' . $ult . '$' . $nlin . '</definedName></definedNames></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' . $ns . '"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>' . $cols . '</cols><sheetData>' . $x . '</sheetData><autoFilter ref="A1:' . $ult . $nlin . '"/></worksheet>',
    ];
    $dir = sys_get_temp_dir();
    if (!is_writable($dir)) { $dir = __DIR__ . '/_dados'; @mkdir($dir, 0700, true); }
    $arq = tempnam($dir, 'crm');
    $z = new ZipArchive();
    if ($z->open($arq, ZipArchive::OVERWRITE) !== true) falha(500, 'Não foi possível montar a planilha. Tente o formato CSV.');
    foreach ($partes as $nome => $conteudo) $z->addFromString($nome, $conteudo);
    $z->close();
    return $arq;
}
