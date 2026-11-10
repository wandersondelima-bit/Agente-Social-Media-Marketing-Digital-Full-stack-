<?php
/* Telas. Todo valor vindo do banco ou do usuário passa por e() antes de ir para o HTML. */
declare(strict_types=1);

const SPRITE = '<svg class="sprite" width="0" height="0" aria-hidden="true">
<symbol id="i-marca" viewBox="0 0 48 48"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="24" cy="24" r="22" stroke-width="1.15"/><circle cx="24" cy="24" r="18.4" stroke-width=".6" opacity=".55"/><path d="M15.5 31.5V16.8l8.5 10.4 8.5-10.4v14.7" stroke-width="1.75"/></g></symbol>
<symbol id="i-wa" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 20.5l1.3-4.2A8.5 8.5 0 1 1 8 19.4z"/><path d="M9 8.6c0 3.3 2.9 6.2 6.2 6.2l1.1-1.5-2-1.1-.9.8c-.9-.4-1.9-1.4-2.3-2.3l.8-.9-1.1-2z" stroke-width="1.4"/></g></symbol>
</svg>';
const MARCA = '<span class="mark" aria-hidden="true"><svg><use href="#i-marca"/></svg></span><span class="t"><strong>Marcelo Osawa</strong><span>Imóveis</span></span>';

function pagina(string $titulo, string $corpo, string $classe = ''): void
{
    cabecalhos();
    header('Content-Type: text/html; charset=utf-8');
    $b = e(base());
    $v = (string)@filemtime(dirname(__DIR__) . '/assets/app.css');
    $aviso = $_SESSION['aviso'] ?? null;
    unset($_SESSION['aviso']);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">',
        '<meta name="robots" content="noindex, nofollow"><meta name="csrf" content="', e(csrf()), '">',
        '<title>', e($titulo), ' · Leads · Marcelo Osawa Imóveis</title>',
        '<link rel="icon" href="', $b, '/assets/favicon.svg">',
        '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>',
        '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&amp;display=swap">',
        '<link rel="stylesheet" href="', $b, '/assets/app.css?v=', $v, '">',
        '</head><body class="', e($classe), '" data-base="', $b, '">', SPRITE, $corpo,
        '<div class="toast', ($aviso && $aviso[0] === 'erro' ? ' erro' : ''), '" id="toast" role="status"', ($aviso ? '' : ' hidden'), '>', e($aviso[1] ?? ''), '</div>',
        '<script src="', $b, '/assets/app.js?v=', $v, '" defer></script></body></html>';
}

function topo(array $u, string $atual): string
{
    $menu = '';
    if ($u['papel'] === 'admin') {
        $itens = ['leads' => 'Leads', 'usuarios' => 'Usuários', 'importar' => 'Importar'];
        $menu = '<nav class="menu" aria-label="Seções"><div class="wrap">';
        foreach ($itens as $r => $t) $menu .= '<a href="' . e(url($r)) . '"' . ($r === $atual ? ' aria-current="page"' : '') . '>' . $t . '</a>';
        $menu .= '</div></nav>';
    }
    return '<header class="top"><div class="wrap"><a class="brand" href="' . e(url('leads')) . '" title="Lista de leads">' . MARCA . '</a>'
        . '<form class="who" method="post" action="' . e(url('sair')) . '">' . campo_csrf() . '<span>' . e($u['nome']) . '</span><button type="submit">Sair</button></form>'
        . '</div></header>' . $menu;
}

function tela_mensagem(string $titulo, string $msg): void
{
    $u = null;
    try { $u = usuario(); } catch (Throwable $t) {}
    pagina($titulo, ($u ? topo($u, '') : '') . '<main><div class="wrap"><div class="head"><div><h1>' . e($titulo) . '</h1><p class="muted">' . e($msg) . '</p></div></div>'
        . '<a class="btn btn-ghost" href="' . e(url('leads')) . '">Voltar para os leads</a></div></main>');
}

function tela_login(): void
{
    $corpo = '<div class="login"><div class="wrap"><div class="cx"><div class="brand">' . MARCA . '</div>'
        . '<form method="post" action="' . e(url('entrar')) . '">' . campo_csrf() . '<h1>Gestão de leads</h1>'
        . '<div><label for="l-user">Login</label><input id="l-user" name="login" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></div>'
        . '<div><label for="l-pass">Senha</label><input id="l-pass" name="senha" type="password" autocomplete="current-password" required></div>'
        . '<button class="btn btn-gold" type="submit">Entrar</button>'
        . '<p class="ajuda">Esqueceu a senha? Peça uma nova ao administrador.</p></form></div></div></div>';
    pagina('Entrar', $corpo, 'p-login');
}

function tela_instalar(): void
{
    $corpo = '<div class="login"><div class="wrap"><div class="cx"><div class="brand">' . MARCA . '</div>'
        . '<form method="post" action="' . e(url('instalar')) . '">' . campo_csrf() . '<h1>Instalação</h1>'
        . '<div><label for="i-chave">Chave de instalação</label><input id="i-chave" name="chave" type="password" autocomplete="off" required></div>'
        . '<div><label for="i-nome">Nome do primeiro administrador</label><input id="i-nome" name="nome" required></div>'
        . '<div><label for="i-login">Login</label><input id="i-login" name="login" autocapitalize="none" spellcheck="false" required></div>'
        . '<div><label for="i-senha">Senha (8 caracteres ou mais)</label><input id="i-senha" name="senha" type="password" autocomplete="new-password" required></div>'
        . '<button class="btn btn-gold" type="submit">Instalar</button>'
        . '<p class="ajuda">A chave está no arquivo de configuração do servidor.</p></form></div></div></div>';
    pagina('Instalação', $corpo, 'p-login');
}

function opcoes(array $lista, $sel, ?string $vazio = null): string
{
    $h = $vazio !== null ? '<option value="">' . e($vazio) . '</option>' : '';
    $simples = array_is_list($lista);   // lista simples: o texto é o próprio valor; com chaves: a chave é o valor
    foreach ($lista as $v => $t) {
        if ($simples) $v = $t;
        $h .= '<option value="' . e($v) . '"' . ((string)$v === (string)$sel ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    return $h;
}

function linha_lead(array $l, bool $adm, array $cors, bool $lixeira): string
{
    $id = (int)$l['id'];
    $sub = $adm ? '<span class="sub" data-cor>' . e($l['corretor'] ?? '' ?: 'A distribuir') . '</span>' : '';
    $h = '<li class="lead" data-id="' . $id . '"><div class="linha">'
        . '<button type="button" class="abrir" aria-expanded="false" aria-controls="det-' . $id . '">'
        . '<span class="dt num">' . e(substr(data_br($l['entrada']), 0, 5)) . '</span><span class="nm"><span class="n1">' . e($l['nome']) . '</span>' . $sub . '</span></button>';
    if ($lixeira) {
        $h .= '<span class="st fixo" data-s="' . e($l['status']) . '"><span>' . e(STATUS[$l['status']] ?? '') . '</span></span>';
    } else {
        $h .= '<span class="st" data-s="' . e($l['status']) . '"><select aria-label="Status de ' . e($l['nome']) . '" data-acao="lead_status">' . opcoes(STATUS, $l['status']) . '</select></span>';
    }
    $h .= '</div><div class="det" id="det-' . $id . '" hidden><dl class="dados">'
        . '<div><dt>WhatsApp</dt><dd class="num">' . e(telefone_fmt($l['telefone'])) . '</dd></div>'
        . '<div><dt>Imóvel de referência</dt><dd>';
    $rot = trim((string)$l['imovel_titulo']) . ($l['imovel_codigo'] ? ' (cód. ' . $l['imovel_codigo'] . ')' : '');
    if ($l['imovel_url']) $h .= '<a href="' . e($l['imovel_url']) . '" target="_blank" rel="noopener">' . e($rot) . '</a>';
    else $h .= $rot !== '' ? e($rot) : '<span class="muted">Não informado</span>';
    $h .= '</dd></div><div><dt>Origem</dt><dd>' . e($l['origem']) . ($l['interesse'] ? ' · ' . e($l['interesse']) : '') . '</dd></div>'
        . '<div><dt>Entrada</dt><dd class="num">' . e(data_br($l['entrada'])) . '</dd></div></dl>'
        . '<div class="acoes"><a class="btn btn-gold" href="https://wa.me/55' . e($l['telefone']) . '" target="_blank" rel="noopener"><svg aria-hidden="true"><use href="#i-wa"/></svg>Abrir WhatsApp</a></div>';
    if ($lixeira) {
        $h .= ($l['observacoes'] !== '' ? '<p class="obs-lida">' . nl2br(e($l['observacoes'])) . '</p>' : '')
            . '<form method="post" action="' . e(url('lead_restaurar')) . '" class="acoes">' . campo_csrf() . '<input type="hidden" name="id" value="' . $id . '"><button class="btn btn-primary btn-small" type="submit">Restaurar lead</button></form>';
        return $h . '</div></li>';
    }
    $h .= '<div class="motivo" data-motivo' . ($l['status'] === 'perdido' ? '' : ' hidden') . '><label for="mot-' . $id . '">Motivo da perda</label>'
        . '<select class="campo" id="mot-' . $id . '" data-acao="lead_motivo">' . opcoes(MOTIVOS, $l['motivo_perda'], 'Escolha o motivo') . '</select></div>';
    if ($adm) {
        $lista = $cors;
        if ($l['corretor_id'] && !isset($lista[(int)$l['corretor_id']])) $lista[(int)$l['corretor_id']] = ($l['corretor'] ?? 'Corretor') . ' (desativado)';
        $h .= '<div class="motivo"><label for="cor-' . $id . '">Corretor responsável</label><select class="campo" id="cor-' . $id . '" data-acao="lead_atribuir">'
            . opcoes($lista, $l['corretor_id'], 'A distribuir') . '</select></div>';
    }
    $h .= '<div class="obs"><label for="obs-' . $id . '">Observações</label>'
        . '<textarea class="campo" id="obs-' . $id . '" maxlength="' . OBS_MAX . '" data-obs>' . e($l['observacoes']) . '</textarea>'
        . '<div class="pe"><span class="conta num" data-conta>' . mb_strlen($l['observacoes']) . ' de ' . OBS_MAX . ' caracteres</span>'
        . '<button type="button" class="btn btn-primary btn-small" data-salvar-obs>Salvar observações</button></div></div>';
    if ($adm) {
        $h .= '<div class="acoes"><a class="btn btn-ghost btn-small" href="' . e(url('lead_form', ['id' => $id])) . '">Editar dados</a>'
            . '<form method="post" action="' . e(url('lead_excluir')) . '" class="confirma">' . campo_csrf() . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button class="btn btn-ghost btn-small btn-danger" type="submit" data-confirma="Confirmar exclusão">Excluir lead</button></form></div>';
    }
    return $h . '</div></li>';
}

function tela_leads(array $u): void
{
    $adm = $u['papel'] === 'admin';
    $f = (string)($_GET['f'] ?? 'todos');
    $busca = trim((string)($_GET['q'] ?? ''));
    $fc = $adm ? (int)($_GET['c'] ?? 0) : 0;
    $validos = array_merge(['todos'], array_keys(STATUS), $adm ? ['distribuir', 'excluidos'] : []);
    if (!in_array($f, $validos, true)) $f = 'todos';
    $lixeira = $f === 'excluidos';
    $_SESSION['lista_qs'] = http_build_query(array_filter(['r' => 'leads', 'f' => $f === 'todos' ? '' : $f, 'q' => $busca, 'c' => $fc ?: '']));

    // Escopo: o corretor só enxerga os leads atribuídos a ele.
    $onde = ['l.excluido_em IS ' . ($lixeira ? 'NOT NULL' : 'NULL')];
    $p = [];
    if (!$adm) { $onde[] = 'l.corretor_id = ?'; $p[] = (int)$u['id']; }
    $escopo = implode(' AND ', $onde);
    $pe = $p;
    $cont = ['todos' => 0, 'distribuir' => 0] + array_fill_keys(array_keys(STATUS), 0);
    foreach (q("SELECT l.status, COUNT(*) AS n, SUM(CASE WHEN l.corretor_id IS NULL THEN 1 ELSE 0 END) AS sem FROM leads l WHERE " . ($adm ? 'l.excluido_em IS NULL' : $escopo) . " GROUP BY l.status", $adm ? [] : $pe) as $r) {
        $cont[$r['status']] = (int)$r['n']; $cont['todos'] += (int)$r['n']; $cont['distribuir'] += (int)$r['sem'];
    }
    if (isset(STATUS[$f])) { $onde[] = 'l.status = ?'; $p[] = $f; }
    if ($f === 'distribuir') $onde[] = 'l.corretor_id IS NULL';
    if ($fc) { $onde[] = 'l.corretor_id = ?'; $p[] = $fc; }
    if ($busca !== '') {
        $d = so_digitos($busca);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($busca)) . '%';
        if ($d !== '') { $onde[] = "(LOWER(l.nome) LIKE ? ESCAPE '\\' OR l.telefone LIKE ?)"; $p[] = $like; $p[] = "%$d%"; }
        else { $onde[] = "LOWER(l.nome) LIKE ? ESCAPE '\\'"; $p[] = $like; }
    }
    $leads = q('SELECT l.*, u.nome AS corretor FROM leads l LEFT JOIN usuarios u ON u.id = l.corretor_id WHERE ' . implode(' AND ', $onde) . ' ORDER BY l.entrada DESC, l.id DESC LIMIT 500', $p)->fetchAll();

    $cors = [];
    if ($adm) foreach (corretores() as $c) $cors[(int)$c['id']] = $c['nome'];
    $chip = function (string $k, string $t, ?int $n, string $ponto = '') use ($f, $busca, $fc): string {
        $href = url('leads', array_filter(['f' => $k === 'todos' ? '' : $k, 'q' => $busca, 'c' => $fc ?: '']));
        return '<a class="chip" href="' . e($href) . '"' . ($f === $k ? ' aria-current="true"' : '') . '>' . ($ponto ? '<span class="ponto ' . $ponto . '"></span>' : '')
            . $t . ($n !== null ? ' <b class="num" data-n="' . $k . '">' . $n . '</b>' : '') . '</a>';
    };
    $h = topo($u, 'leads') . '<main><div class="wrap"><div class="head"><div><h1>' . ($adm ? 'Leads' : 'Meus leads') . '</h1><p class="muted">'
        . ($adm ? 'Todos os leads da equipe. Os sem corretor ficam em A distribuir.' : 'Toque em um nome para ver o contato e as observações.') . '</p></div>'
        . ($adm ? '<a class="btn btn-primary" href="' . e(url('lead_form')) . '">Novo lead</a>' : '') . '</div>'
        . '<div class="filtros">' . $chip('todos', 'Todos', $cont['todos']) . ($adm ? $chip('distribuir', 'A distribuir', $cont['distribuir']) : '')
        . $chip('novo', 'Novo', $cont['novo'], 'novo') . $chip('andamento', 'Em andamento', $cont['andamento'], 'andamento')
        . $chip('concluido', 'Concluído', $cont['concluido'], 'concluido') . $chip('perdido', 'Perdido', $cont['perdido'], 'perdido')
        . ($adm ? $chip('excluidos', 'Excluídos', null) : '') . '</div>'
        . '<form class="busca' . ($adm ? ' dupla' : '') . '" method="get" action="' . e(base()) . '/"><input type="hidden" name="r" value="leads">'
        . ($f !== 'todos' ? '<input type="hidden" name="f" value="' . e($f) . '">' : '')
        . '<input class="campo" type="search" name="q" value="' . e($busca) . '" placeholder="Buscar por nome ou telefone" aria-label="Buscar por nome ou telefone">'
        . ($adm ? '<select class="campo" name="c" aria-label="Filtrar por corretor" data-envia>' . opcoes($cors, $fc ?: '', 'Todos os corretores') . '</select>' : '')
        . '<button class="btn btn-ghost" type="submit">Buscar</button></form>'
        . '<ul class="lista"><li class="cab" aria-hidden="true"><span>Entrada</span><span>Nome</span><span>Status</span></li>';
    if (!$leads) {
        $vazio = $busca !== '' || $f !== 'todos' || $fc ? 'Nenhum lead neste filtro.' : ($adm ? 'Ainda não há leads. Use Novo lead ou Importar para começar.' : 'Você ainda não tem leads atribuídos.');
        $h .= '<li class="vazio">' . $vazio . '</li>';
    }
    foreach ($leads as $l) $h .= linha_lead($l, $adm, $cors, $lixeira);
    $h .= '</ul>' . (count($leads) === 500 ? '<p class="muted nota-lista">Mostrando os 500 mais recentes. Use a busca ou os filtros para ver os demais.</p>' : '')
        . ($adm && !$lixeira ? '<p class="nota-lista"><a href="' . e(url('exportar')) . '">Baixar todos os leads em CSV</a></p>' : '') . '</div></main>';
    pagina($adm ? 'Leads' : 'Meus leads', $h);
}

function tela_lead_form(array $u): void
{
    $id = (int)($_GET['id'] ?? 0);
    $l = $id ? lead_permitido($id) : ['entrada' => date('Y-m-d'), 'nome' => '', 'telefone' => '', 'imovel_codigo' => '', 'imovel_titulo' => '', 'origem' => ORIGENS[0], 'corretor_id' => ''];
    $l['imovel_texto'] = $l['imovel_codigo'] ? '' : (string)($l['imovel_titulo'] ?? '');
    $dup = $_SESSION['form_dup'] ?? null;
    $erros = $_SESSION['form_erros'] ?? [];
    if (isset($_SESSION['form_lead'])) {
        foreach (['entrada', 'nome', 'telefone', 'imovel_codigo', 'imovel_texto', 'origem', 'corretor_id'] as $c) $l[$c] = (string)($_SESSION['form_lead'][$c] ?? '');
    }
    unset($_SESSION['form_dup'], $_SESSION['form_erros'], $_SESSION['form_lead']);
    $im = [];
    foreach (imoveis_site() as $c => $x) $im[$c] = $x['bairro'] . ' · ' . mb_strimwidth($x['titulo'], 0, 70, '…') . ' (cód. ' . $c . ')';
    if (!empty($l['imovel_codigo']) && !isset($im[$l['imovel_codigo']])) $im[$l['imovel_codigo']] = ($l['imovel_titulo'] ?? 'Imóvel') . ' (cód. ' . $l['imovel_codigo'] . ', fora do site)';
    $cors = [];
    foreach (corretores() as $c) $cors[(int)$c['id']] = $c['nome'];
    $h = topo($u, 'leads') . '<main><div class="wrap"><div class="head"><div><h1>' . ($id ? 'Editar lead' : 'Novo lead') . '</h1></div></div>'
        . '<form class="form" method="post" action="' . e(url('lead_salvar')) . '">' . campo_csrf() . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : '');
    foreach ($erros as $er) $h .= '<p class="alerta">' . e($er) . '</p>';
    if ($dup) $h .= '<p class="alerta">Já existe um lead com esse WhatsApp: <b>' . e($dup) . '</b>. Para salvar mesmo assim, toque em Salvar de novo.</p><input type="hidden" name="confirmar" value="1">';
    $h .= '<div class="grade">'
        . '<div><label for="n-nome">Nome</label><input class="campo" id="n-nome" name="nome" maxlength="160" value="' . e($l['nome']) . '" required></div>'
        . '<div><label for="n-tel">WhatsApp</label><input class="campo" id="n-tel" name="telefone" inputmode="tel" placeholder="DDD + número" value="' . e($l['telefone']) . '" required></div>'
        . '<div><label for="n-im">Imóvel do site</label><select class="campo" id="n-im" name="imovel_codigo">' . opcoes($im, $l['imovel_codigo'], 'Nenhum da lista') . '</select></div>'
        . '<div><label for="n-imt">Outro imóvel ou região</label><input class="campo" id="n-imt" name="imovel_texto" maxlength="255" placeholder="Use só se não estiver na lista" value="' . e($l['imovel_texto']) . '"></div>'
        . '<div><label for="n-or">Origem</label><select class="campo" id="n-or" name="origem">' . opcoes(ORIGENS, $l['origem']) . '</select></div>'
        . '<div><label for="n-cor">Corretor</label><select class="campo" id="n-cor" name="corretor_id">' . opcoes($cors, $l['corretor_id'], 'A distribuir') . '</select></div>'
        . '<div><label for="n-dt">Data de entrada</label><input class="campo" id="n-dt" name="entrada" type="date" value="' . e($l['entrada']) . '"></div>'
        . '</div><p class="nota">A lista de imóveis vem dos imóveis publicados no site.</p>'
        . '<div class="acoes"><button class="btn btn-primary" type="submit">Salvar lead</button><a class="btn btn-ghost" href="' . e(voltar_lista()) . '">Cancelar</a></div></form></div></main>';
    pagina($id ? 'Editar lead' : 'Novo lead', $h);
}

function tela_usuarios(array $eu): void
{
    $us = q("SELECT u.*, (SELECT COUNT(*) FROM leads l WHERE l.corretor_id = u.id AND l.excluido_em IS NULL) AS n FROM usuarios u ORDER BY u.papel, u.nome")->fetchAll();
    $h = topo($eu, 'usuarios') . '<main><div class="wrap"><div class="head"><div><h1>Usuários</h1><p class="muted">Quem pode entrar no sistema e o que cada um vê.</p></div></div>'
        . '<details class="form"><summary>Novo usuário</summary><form method="post" action="' . e(url('usuario_criar')) . '" autocomplete="off">' . campo_csrf() . '<div class="grade">'
        . '<div><label for="u-nome">Nome</label><input class="campo" id="u-nome" name="nome" maxlength="120" required></div>'
        . '<div><label for="u-login">Login</label><input class="campo" id="u-login" name="login" autocapitalize="none" spellcheck="false" required></div>'
        . '<div><label for="u-senha">Senha inicial (8 caracteres ou mais)</label><input class="campo" id="u-senha" name="senha" type="text" minlength="8" autocomplete="off" required></div>'
        . '<div><label for="u-papel">Perfil</label><select class="campo" id="u-papel" name="papel"><option value="corretor">Corretor</option><option value="admin">Admin</option></select></div>'
        . '</div><p class="nota">Só o admin define e redefine senhas. Passe o login e a senha ao corretor.</p>'
        . '<div class="acoes"><button class="btn btn-primary" type="submit">Criar usuário</button></div></form></details><ul class="usuarios">';
    foreach ($us as $x) {
        $id = (int)$x['id'];
        $eu_mesmo = $id === (int)$eu['id'];
        $oculto = campo_csrf() . '<input type="hidden" name="id" value="' . $id . '">';
        $h .= '<li class="' . ((int)$x['ativo'] ? '' : 'inativo') . '"><div><span class="nm">' . e($x['nome']) . '</span><span class="tag' . ($x['papel'] === 'admin' ? ' adm' : '') . '">'
            . ($x['papel'] === 'admin' ? 'Admin' : 'Corretor') . '</span>' . ((int)$x['ativo'] ? '' : '<span class="tag">' . ($x['senha_hash'] === '' ? 'Sem senha' : 'Desativado') . '</span>')
            . '<div class="inf">Login: ' . e($x['login']) . ' · Último acesso: ' . ($x['ultimo_acesso'] ? e(date('d/m/Y H:i', strtotime($x['ultimo_acesso']))) : 'nunca')
            . ($x['papel'] === 'corretor' ? ' · ' . (int)$x['n'] . ' leads' : '') . '</div></div><div class="acoes">'
            . '<details class="senha"><summary class="btn btn-ghost btn-small">Redefinir senha</summary><form method="post" action="' . e(url('usuario_senha')) . '" autocomplete="off">' . $oculto
            . '<label for="s-' . $id . '">Nova senha de ' . e($x['nome']) . '</label><input class="campo" id="s-' . $id . '" name="senha" type="text" minlength="8" autocomplete="off" required>'
            . '<button class="btn btn-primary btn-small" type="submit">Salvar senha</button></form></details>';
        if (!$eu_mesmo) {
            $h .= '<form method="post" action="' . e(url('usuario_ativo')) . '">' . $oculto . '<button class="btn btn-ghost btn-small" type="submit">' . ((int)$x['ativo'] ? 'Desativar' : 'Reativar') . '</button></form>'
                . '<form method="post" action="' . e(url('usuario_excluir')) . '" class="confirma">' . $oculto . '<button class="btn btn-ghost btn-small btn-danger" type="submit" data-confirma="Confirmar exclusão">Excluir</button></form>';
        }
        $h .= '</div></li>';
    }
    $h .= '</ul><p class="muted nota-lista">Desativar impede o acesso e mantém os leads com a pessoa. Excluir devolve os leads dela para A distribuir.</p></div></main>';
    pagina('Usuários', $h);
}

function tela_importar(array $u): void
{
    $rel = $_SESSION['relatorio'] ?? null;
    unset($_SESSION['relatorio']);
    $h = topo($u, 'importar') . '<main><div class="wrap"><div class="head"><div><h1>Importar planilha</h1><p class="muted">Traz os leads da planilha do Google para o sistema, com o corretor e o status que estão nela.</p></div></div>';
    if ($rel) {
        $h .= '<div class="form"><h2>Resultado da importação</h2><p class="nota-rel"><b>' . (int)$rel['importados'] . '</b> leads importados' . ($rel['repetidos'] ? ' · ' . (int)$rel['repetidos'] . ' já existiam e foram pulados' : '') . '.</p>';
        foreach (['corretores' => 'Corretores criados sem senha (defina a senha e reative em Usuários):', 'cortados' => 'Observações cortadas em ' . OBS_MAX . ' caracteres:', 'ignorados' => 'Linhas não importadas:'] as $k => $t) {
            if ($rel[$k]) { $h .= '<p class="nota-rel">' . $t . '</p><ul class="rel">'; foreach ($rel[$k] as $x) $h .= '<li>' . e($x) . '</li>'; $h .= '</ul>'; }
        }
        $h .= '<div class="acoes"><a class="btn btn-primary" href="' . e(url('leads')) . '">Ver os leads</a></div></div>';
    }
    $h .= '<form class="form" method="post" action="' . e(url('importar')) . '" enctype="multipart/form-data">' . campo_csrf()
        . '<ol class="passos"><li>Na planilha, abra a aba <b>Leads</b>.</li><li>Use <b>Arquivo → Fazer download → Valores separados por vírgula (.csv)</b>.</li><li>Escolha o arquivo baixado aqui e toque em Importar.</li></ol>'
        . '<div><label for="arq">Arquivo CSV</label><input class="campo" id="arq" name="arquivo" type="file" accept=".csv,text/csv" required></div>'
        . '<p class="nota">Importar duas vezes o mesmo arquivo não duplica: o lead com o mesmo nome, telefone e data é pulado.</p>'
        . '<div class="acoes"><button class="btn btn-primary" type="submit">Importar</button></div></form></div></main>';
    pagina('Importar', $h);
}
