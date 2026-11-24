<?php
/* Aba Painel: os números dos leads num lugar só (admin e gerente). Ideia tirada do CRM do Sistema de Iscas
   (Vórtex IA), adaptada ao nosso fluxo: entradas por dia, origem, campanha, imóvel ou material, status e corretor.
   Os gráficos são SVG com atributos (a política de segurança do app não aceita estilo em linha). */
declare(strict_types=1);

const PERIODOS_PAINEL = ['7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', 'mes' => 'Este mês', 'mes_passado' => 'Mês passado', 'tudo' => 'Todo o período'];

/* Devolve [chave, de, até] do período (datas de entrada, AAAA-MM-DD). */
function periodo_painel(string $p): array
{
    $hoje = date('Y-m-d');
    if (!isset(PERIODOS_PAINEL[$p])) $p = '30d';
    if ($p === '7d') return [$p, date('Y-m-d', strtotime('-6 days')), $hoje];
    if ($p === 'mes') return [$p, date('Y-m-01'), $hoje];
    if ($p === 'mes_passado') { $t = strtotime('first day of last month'); return [$p, date('Y-m-01', $t), date('Y-m-t', $t)]; }
    if ($p === 'tudo') {
        $min = (string)(valor('SELECT MIN(entrada) FROM leads WHERE excluido_em IS NULL') ?: $hoje);
        return [$p, substr($min, 0, 10), $hoje];
    }
    return [$p, date('Y-m-d', strtotime('-29 days')), $hoje];
}

function pct_txt(int $n, int $total): string { return $total ? round(100 * $n / $total) . '%' : '–'; }

/* Barra horizontal: rótulo, número e a barra proporcional ao maior valor da lista. */
function barra_h(string $rotulo, int $n, int $max, string $extra = '', string $classe = ''): string
{
    $w = $max ? max(1.5, round(100 * $n / $max, 1)) : 0;
    return '<li class="hb' . ($classe ? ' ' . $classe : '') . '"><div class="hb-t"><span class="hb-r">' . e($rotulo) . '</span><span class="hb-n num"><b>' . $n . '</b>'
        . ($extra !== '' ? ' <span class="muted">' . e($extra) . '</span>' : '') . '</span></div>'
        . '<svg class="hb-b" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect class="fundo" width="100" height="6" rx="3"/>'
        . ($n ? '<rect class="valor" width="' . $w . '" height="6" rx="3"/>' : '') . '</svg></li>';
}

/* Agrupa e ordena do maior para o menor; o que passar de $limite vira "Outros". */
function ranking(array $contagem, int $limite): array
{
    arsort($contagem);
    if (count($contagem) <= $limite) return $contagem;
    $top = array_slice($contagem, 0, $limite - 1, true);
    $top['Outros'] = array_sum(array_slice($contagem, $limite - 1));
    return $top;
}

function rotulo_material(array $l): string
{
    if ($l['imovel_codigo']) {
        $t = trim((string)$l['imovel_titulo']);
        return 'Cód. ' . $l['imovel_codigo'] . ($t !== '' ? ' · ' . (mb_strlen($t) > 46 ? mb_substr($t, 0, 45) . '…' : $t) : '');
    }
    $i = (string)$l['interesse'];
    if (str_starts_with($i, 'Isca: ')) return 'Material: ' . mb_substr($i, 6);
    $t = trim((string)$l['imovel_titulo']);
    return $t !== '' ? (mb_strlen($t) > 56 ? mb_substr($t, 0, 55) . '…' : $t) : 'Sem imóvel informado';
}

function rotulo_campanha(?string $utm): string
{
    $u = $utm ? json_decode($utm, true) : null;
    if (!is_array($u) || !$u) return 'Sem marcação (acesso direto)';
    if (!empty($u['utm_campaign'])) return (string)$u['utm_campaign'];
    $partes = array_filter([(string)($u['utm_source'] ?? ''), (string)($u['utm_medium'] ?? '')]);
    return $partes ? implode(' · ', $partes) : 'Sem marcação (acesso direto)';
}

function tela_painel(array $u): void
{
    [$per, $de, $ate] = periodo_painel((string)($_GET['p'] ?? '30d'));
    $leads = q('SELECT l.entrada, l.status, l.origem, l.interesse, l.imovel_codigo, l.imovel_titulo, l.utm, l.corretor_id, u.nome AS corretor
                FROM leads l LEFT JOIN usuarios u ON u.id = l.corretor_id
                WHERE l.excluido_em IS NULL AND l.entrada >= ? AND l.entrada <= ?', [$de, $ate])->fetchAll();
    $total = count($leads);
    $st = array_fill_keys(array_keys(STATUS), 0);
    $origem = $campanha = $material = $porDia = $conta_material = $nomes_material = [];
    $cor = [];   // nome => [abertos, concluidos, perdidos, total]
    foreach ($leads as $l) {
        $st[$l['status']] = ($st[$l['status']] ?? 0) + 1;
        $origem[$l['origem']] = ($origem[$l['origem']] ?? 0) + 1;
        if (in_array($l['origem'], ['Formulário do site', 'Isca do site'], true)) {
            $c = rotulo_campanha($l['utm']);
            $campanha[$c] = ($campanha[$c] ?? 0) + 1;
        }
        // Mesmo código = mesmo imóvel, ainda que o endereço tenha sido escrito de outro jeito em algum lead.
        $km = $l['imovel_codigo'] ? 'c:' . $l['imovel_codigo'] : 'r:' . rotulo_material($l);
        $nomes_material[$km] ??= rotulo_material($l);
        $conta_material[$km] = ($conta_material[$km] ?? 0) + 1;
        $d = substr((string)$l['entrada'], 0, 10);
        $porDia[$d] = ($porDia[$d] ?? 0) + 1;
        $n = $l['corretor_id'] ? ((string)($l['corretor'] ?? '') ?: 'Usuário removido') : 'A distribuir';
        $cor[$n] ??= [0, 0, 0, 0];
        $cor[$n][$l['status'] === 'concluido' ? 1 : ($l['status'] === 'perdido' ? 2 : 0)]++;
        $cor[$n][3]++;
    }
    foreach ($conta_material as $k => $n) { $r = $nomes_material[$k]; $material[$r] = ($material[$r] ?? 0) + $n; }
    $abertos = $st['novo'] + $st['andamento'];

    // "Agora", fora do período: o que pede ação hoje.
    $distribuir = (int)valor("SELECT COUNT(*) FROM leads WHERE excluido_em IS NULL AND corretor_id IS NULL AND status IN ('novo', 'andamento')");
    $parados = (int)valor("SELECT COUNT(*) FROM leads WHERE excluido_em IS NULL AND status IN ('novo', 'andamento') AND atualizado_em < ?", [date('Y-m-d H:i:s', time() - DIAS_PARADO * 86400)]);

    $chips = '';
    foreach (PERIODOS_PAINEL as $k => $t) {
        $chips .= '<a class="chip" href="' . e(url('painel', $k === '30d' ? [] : ['p' => $k])) . '"' . ($k === $per ? ' aria-current="true"' : '') . '>' . e($t) . '</a>';
    }
    $faixa = data_br($de) . ' a ' . data_br($ate);
    $h = topo($u, 'painel') . '<main><div class="wrap"><div class="head"><div><h1>Painel</h1>'
        . '<p class="muted">Os números dos leads pela data de entrada. ' . e(PERIODOS_PAINEL[$per]) . ': <span class="num">' . e($faixa) . '</span>.</p></div></div>'
        . '<div class="filtros">' . $chips . '</div>';

    // Agora
    $h .= '<div class="kpis agora">'
        . '<a class="kpi' . ($distribuir ? ' atencao' : '') . '" href="' . e(url('leads', ['f' => 'distribuir'])) . '"><span class="kpi-r">A distribuir agora</span><b class="num">' . $distribuir . '</b><span class="kpi-d">leads em aberto sem corretor · ver lista</span></a>'
        . '<a class="kpi' . ($parados ? ' atencao' : '') . '" href="' . e(url('leads', ['f' => 'parados'])) . '"><span class="kpi-r">Parados</span><b class="num">' . $parados . '</b><span class="kpi-d">em aberto sem atualização há ' . DIAS_PARADO . ' dias ou mais · ver lista</span></a>'
        . '</div>';

    // Período
    $h .= '<div class="kpis">'
        . '<div class="kpi"><span class="kpi-r">Entraram</span><b class="num">' . $total . '</b><span class="kpi-d">leads novos no período</span></div>'
        . '<div class="kpi"><span class="kpi-r">Em aberto</span><b class="num">' . $abertos . '</b><span class="kpi-d">Novo ou Em andamento</span></div>'
        . '<div class="kpi"><span class="kpi-r">Concluídos</span><b class="num">' . $st['concluido'] . '</b><span class="kpi-d">' . pct_txt($st['concluido'], $total) . ' dos que entraram</span></div>'
        . '<div class="kpi"><span class="kpi-r">Perdidos</span><b class="num">' . $st['perdido'] . '</b><span class="kpi-d">' . pct_txt($st['perdido'], $total) . ' dos que entraram</span></div>'
        . '</div>';

    if (!$total) {
        $h .= '<section class="form painel-cx"><p class="vazio">Nenhum lead entrou neste período.</p></section></div></main>';
        pagina('Painel', $h);
        return;
    }

    // Entradas no tempo: por dia até ~2 meses; acima disso, por mês.
    $dias = (int)((strtotime($ate) - strtotime($de)) / 86400) + 1;
    $serie = [];
    if ($dias <= 62) {
        for ($t = strtotime($de); $t <= strtotime($ate); $t += 86400) { $k = date('Y-m-d', $t); $serie[date('d/m', $t)] = $porDia[$k] ?? 0; }
        $titulo_serie = 'Leads por dia';
    } else {
        $pm = [];
        foreach ($porDia as $d => $n) { $k = substr($d, 0, 7); $pm[$k] = ($pm[$k] ?? 0) + $n; }
        for ($t = strtotime(substr($de, 0, 7) . '-01'); $t <= strtotime($ate); $t = strtotime('+1 month', $t)) { $k = date('Y-m', $t); $serie[date('m/y', $t)] = $pm[$k] ?? 0; }
        $titulo_serie = 'Leads por mês';
    }
    $max = max($serie) ?: 1;
    $nb = count($serie);
    $bw = 100 / $nb;
    $svg = '';
    $i = 0;
    foreach ($serie as $rot => $n) {
        $alt = $n ? max(2, round(96 * $n / $max, 1)) : 0.8;
        $svg .= '<rect class="' . ($n ? 'valor' : 'zero') . '" x="' . round($i * $bw + $bw * .14, 2) . '" y="' . round(100 - $alt, 2) . '" width="' . round($bw * .72, 2) . '" height="' . $alt . '"><title>' . e($rot) . ': ' . $n . ($n === 1 ? ' lead' : ' leads') . '</title></rect>';
        $i++;
    }
    $rots = array_keys($serie);
    $passo = max(1, (int)ceil($nb / 7));
    $eixo = '';
    foreach ($rots as $j => $r) if ($j % $passo === 0 || $j === $nb - 1) $eixo .= '<span>' . e($r) . '</span>';
    $maior = array_search($max, $serie, true);
    $h .= '<section class="form painel-cx"><div class="cx-t"><h2>' . $titulo_serie . '</h2><p class="muted">Maior: <b class="num">' . (int)$max . '</b> em <span class="num">' . e((string)$maior) . '</span></p></div>'
        . '<svg class="colunas" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="' . e($titulo_serie . ': ' . $total . ' leads no período, no máximo ' . $max . ' num ' . ($dias <= 62 ? 'dia' : 'mês')) . '">' . $svg . '</svg>'
        . ($nb <= 14
            ? '<div class="eixo cheio num">' . implode('', array_map(fn($r, $n) => '<span><b>' . $n . '</b>' . e($r) . '</span>', $rots, $serie)) . '</div>'
            : '<div class="eixo num">' . $eixo . '</div><p class="nota">Toque ou passe o mouse numa coluna para ver o número do dia.</p>')
        . '</section>';

    // Status do período: uma barra empilhada nas cores de status.
    $x = 0; $pilha = '';
    foreach (STATUS as $k => $t) {
        if (!$st[$k]) continue;
        $w = round(100 * $st[$k] / $total, 2);
        $pilha .= '<rect class="s-' . $k . '" x="' . $x . '" width="' . $w . '" height="10"><title>' . e($t) . ': ' . $st[$k] . '</title></rect>';
        $x += $w;
    }
    $leg = '';
    foreach (STATUS as $k => $t) $leg .= '<li><span class="ponto ' . $k . '"></span>' . e($t) . ' <b class="num">' . $st[$k] . '</b> <span class="muted num">' . pct_txt($st[$k], $total) . '</span></li>';
    $h .= '<section class="form painel-cx"><div class="cx-t"><h2>Como estão os leads do período</h2></div>'
        . '<svg class="pilha" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">' . $pilha . '</svg><ul class="legenda">' . $leg . '</ul></section>';

    $lista = function (string $titulo, string $sub, array $dados, int $limite) {
        $r = ranking($dados, $limite);
        $m = max($r ?: [0]);
        $li = '';
        foreach ($r as $rot => $n) $li .= barra_h((string)$rot, (int)$n, (int)$m, '', $rot === 'Outros' ? 'outros' : '');
        return '<section class="form painel-cx"><div class="cx-t"><h2>' . e($titulo) . '</h2>' . ($sub !== '' ? '<p class="muted">' . e($sub) . '</p>' : '') . '</div><ul class="hbs">' . $li . '</ul></section>';
    };
    $h .= '<div class="painel-2">' . $lista('De onde vieram', 'Origem de cada lead', $origem, 8);
    $h .= $campanha ? $lista('Campanhas dos leads do site', 'Pela marcação do link (UTM): Reel, direct, anúncio', $campanha, 8)
        : '<section class="form painel-cx"><div class="cx-t"><h2>Campanhas dos leads do site</h2></div><p class="vazio">Nenhum lead do site no período.</p></section>';
    $h .= '</div>' . $lista('Imóvel ou material que trouxe o lead', 'Os que mais geraram contato no período', $material, 10);

    // Corretores: o andamento de cada um com os leads do período.
    uksort($cor, fn($a, $b) => $a === 'A distribuir' ? -1 : ($b === 'A distribuir' ? 1 : strcoll($a, $b)));
    $tb = '';
    foreach ($cor as $n => $c) {
        $tb .= '<tr><td>' . e($n) . '</td><td class="num">' . $c[3] . '</td><td class="num">' . $c[0] . '</td><td class="num">' . $c[1] . '</td><td class="num">' . $c[2] . '</td><td class="num">' . pct_txt($c[1], $c[3]) . '</td></tr>';
    }
    $h .= '<section class="form painel-cx"><div class="cx-t"><h2>Por corretor</h2><p class="muted">Leads que entraram no período, pelo corretor de hoje</p></div>'
        . '<div class="tabela"><table><thead><tr><th>Corretor</th><th>Leads</th><th>Em aberto</th><th>Concluídos</th><th>Perdidos</th><th>Conversão</th></tr></thead><tbody>' . $tb . '</tbody></table></div>'
        . '<p class="nota">Conversão = concluídos ÷ leads do corretor no período.</p></section>';

    $h .= '</div></main>';
    pagina('Painel', $h);
}
