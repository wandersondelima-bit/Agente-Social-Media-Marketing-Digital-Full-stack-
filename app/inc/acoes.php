<?php
/* Ações (pedidos POST). Cada uma confere a permissão antes de tocar no banco. */
declare(strict_types=1);

function senha_valida(string $s): bool { return mb_strlen($s) >= 8 && mb_strlen($s) <= 100; }
function login_valido(string $s): bool { return (bool)preg_match('/^[a-z0-9._-]{3,60}$/', $s); }

/* ---------- Instalação: só funciona enquanto não existe nenhum usuário, e exige a chave do arquivo de configuração ---------- */
function acao_instalar(): void
{
    if (instalado()) falha(404, 'Página não encontrada.');
    $chave = (string)config('install_key', '');
    if ($chave === '' || !hash_equals($chave, (string)($_POST['chave'] ?? ''))) falha(403, 'Chave de instalação incorreta.');
    $nome = trim((string)($_POST['nome'] ?? ''));
    $login = mb_strtolower(trim((string)($_POST['login'] ?? '')));
    $senha = (string)($_POST['senha'] ?? '');
    if ($nome === '' || !login_valido($login) || !senha_valida($senha)) falha(422, 'Preencha o nome, um login (letras minúsculas, números, ponto ou hífen) e uma senha de pelo menos 8 caracteres.');
    require_once __DIR__ . '/schema.php';
    criar_tabelas();
    q("INSERT INTO usuarios (nome, login, senha_hash, papel, ativo, criado_em) VALUES (?,?,?,'admin',1,?)",
        [$nome, $login, password_hash($senha, PASSWORD_DEFAULT), agora()]);
    aviso('Sistema instalado. Entre com o login e a senha que você acabou de criar.');
    redirecionar(url('login'));
}

/* ---------- Login e saída ---------- */
function acao_entrar(): void
{
    $login = mb_strtolower(trim((string)($_POST['login'] ?? '')));
    $senha = (string)($_POST['senha'] ?? '');
    $desde = date('Y-m-d H:i:s', time() - LOGIN_JANELA);
    q('DELETE FROM tentativas_login WHERE quando < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    // Limite por login (8) e, mais folgado, por IP (30): o servidor pode ver vários usuários atrás do mesmo IP.
    $por_login = (int)valor('SELECT COUNT(*) FROM tentativas_login WHERE quando > ? AND login = ?', [$desde, mb_substr($login, 0, 60)]);
    $por_ip = (int)valor('SELECT COUNT(*) FROM tentativas_login WHERE quando > ? AND ip = ?', [$desde, ip()]);
    if ($por_login >= LOGIN_MAX || $por_ip >= LOGIN_MAX_IP) {
        aviso('Muitas tentativas erradas. Aguarde 15 minutos e tente de novo.', 'erro');
        redirecionar(url('login'));
    }
    $u = um('SELECT * FROM usuarios WHERE login = ?', [$login]);
    // Confere um hash mesmo quando o login não existe, para o tempo de resposta não revelar quais logins existem.
    if ($u && $u['senha_hash'] !== '') {
        $ok = password_verify($senha, $u['senha_hash']) && (int)$u['ativo'] === 1;
    } else {
        password_hash($senha, PASSWORD_DEFAULT);
        $ok = false;
    }
    if (!$ok) {
        q('INSERT INTO tentativas_login (login, ip, quando) VALUES (?,?,?)', [mb_substr($login, 0, 60), ip(), agora()]);
        aviso('Login ou senha incorretos.', 'erro');
        redirecionar(url('login'));
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE usuarios SET ultimo_acesso = ? WHERE id = ?', [agora(), $u['id']]);
    q('DELETE FROM tentativas_login WHERE login = ?', [$login]);
    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
    }
    redirecionar(url('leads'));
}
function acao_sair(): void
{
    encerrar_sessao();
    redirecionar(url('login'));
}

/* ---------- Leads: corretor e admin ---------- */
function acao_lead_status(): void
{
    $l = lead_permitido((int)($_POST['id'] ?? 0));
    $st = (string)($_POST['valor'] ?? '');
    if (!isset(STATUS[$st])) falha(422, 'Status inválido.');
    $motivo = $st === 'perdido' ? $l['motivo_perda'] : null;
    q('UPDATE leads SET status = ?, motivo_perda = ?, atualizado_em = ? WHERE id = ?', [$st, $motivo, agora(), $l['id']]);
    if ($st !== $l['status']) evento((int)$l['id'], 'status', STATUS[$l['status']] . ' → ' . STATUS[$st]);
    concluir($st === 'perdido' && !$motivo ? 'Escolha o motivo da perda' : 'Status: ' . STATUS[$st], ['status' => $st, 'antes' => $l['status']]);
}
function acao_lead_motivo(): void
{
    $l = lead_permitido((int)($_POST['id'] ?? 0));
    $m = (string)($_POST['valor'] ?? '');
    if ($l['status'] !== 'perdido' || !in_array($m, MOTIVOS, true)) falha(422, 'Escolha um motivo da lista.');
    q('UPDATE leads SET motivo_perda = ?, atualizado_em = ? WHERE id = ?', [$m, agora(), $l['id']]);
    evento((int)$l['id'], 'motivo', $m);
    concluir('Motivo salvo');
}
function acao_lead_obs(): void
{
    $l = lead_permitido((int)($_POST['id'] ?? 0));
    $obs = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['valor'] ?? '')));
    if (mb_strlen($obs) > OBS_MAX) falha(422, 'As observações aceitam até ' . OBS_MAX . ' caracteres.');
    q('UPDATE leads SET observacoes = ?, atualizado_em = ? WHERE id = ?', [$obs, agora(), $l['id']]);
    evento((int)$l['id'], 'observacoes');
    concluir('Observações salvas');
}

/* ---------- Leads: só admin ---------- */
function corretor_valido($id): ?int
{
    if ($id === '' || $id === null) return null;
    $c = um("SELECT id FROM usuarios WHERE id = ? AND papel = 'corretor' AND ativo = 1", [(int)$id]);
    if (!$c) falha(422, 'Corretor inválido ou desativado.');
    return (int)$c['id'];
}
function acao_lead_atribuir(): void
{
    exigir_admin();
    $l = lead_permitido((int)($_POST['id'] ?? 0));
    $cid = corretor_valido($_POST['valor'] ?? '');
    q('UPDATE leads SET corretor_id = ?, atualizado_em = ? WHERE id = ?', [$cid, agora(), $l['id']]);
    $nome = $cid ? (string)valor('SELECT nome FROM usuarios WHERE id = ?', [$cid]) : '';
    evento((int)$l['id'], 'atribuir', $nome !== '' ? $nome : 'A distribuir');
    concluir($nome !== '' ? "Lead atribuído a $nome" : 'Lead voltou para A distribuir', ['corretor' => $nome]);
}
function acao_lead_salvar(): void
{
    exigir_admin();
    $id = (int)($_POST['id'] ?? 0);
    $atual = $id ? lead_permitido($id) : null;
    $nome = trim((string)($_POST['nome'] ?? ''));
    $tel = telefone_limpo((string)($_POST['telefone'] ?? ''));
    $entrada = data_iso((string)($_POST['entrada'] ?? '')) ?? date('Y-m-d');
    $origem = in_array($_POST['origem'] ?? '', ORIGENS, true) ? $_POST['origem'] : 'Outro';
    $cid = corretor_valido($_POST['corretor_id'] ?? '');
    $erros = [];
    if ($nome === '' || mb_strlen($nome) > 160) $erros[] = 'Informe o nome (até 160 caracteres).';
    if ($tel === null) $erros[] = 'Informe o WhatsApp com DDD (10 ou 11 dígitos).';
    // Imóvel: um campo só. Aceita o link da página do site, o código do imóvel ou um texto livre (região, outro imóvel).
    $ref = mb_substr(trim((string)($_POST['imovel_ref'] ?? '')), 0, 255);
    $site = imoveis_site();
    $cod = '';
    $link = null;
    if (preg_match('~marceloosawaimoveis\.com\.br/(\d{5,9})(/[a-z0-9-]+)?~i', $ref, $m)) {
        $cod = $m[1];
        $link = SITE_URL . '/' . $m[1] . (isset($m[2]) ? strtolower($m[2]) . '/' : '/');
    } elseif (preg_match('/^(?:c[oó]d(?:igo)?\.?\s*)?(\d{5,9})$/iu', $ref, $m)) {
        $cod = $m[1];
    }
    if ($cod !== '' && isset($site[$cod])) {
        $im = [$cod, $site[$cod]['titulo'], $site[$cod]['url']];
    } elseif ($cod !== '' && $atual && $cod === (string)$atual['imovel_codigo']) {
        $im = [$atual['imovel_codigo'], $atual['imovel_titulo'], $atual['imovel_url']];   // imóvel que já saiu do site
    } elseif ($cod !== '' && $link !== null) {
        $im = [$cod, "Imóvel $cod", $link];                                              // link do site ainda fora da página inicial
    } elseif ($cod !== '') {
        $im = [null, null, null];
        $erros[] = "Não achei o código $cod entre os imóveis do site. Confira o número ou cole o link da página do imóvel.";
    } else {
        $im = [null, $ref !== '' ? $ref : null, null];
    }
    if (!$erros && !isset($_POST['confirmar'])) {
        $dup = um('SELECT id, nome FROM leads WHERE telefone = ? AND excluido_em IS NULL AND id <> ?', [$tel, $id]);
        if ($dup) { $_SESSION['form_dup'] = $dup['nome']; $erros[] = 'duplicado'; }
    }
    if ($erros) {
        $_SESSION['form_lead'] = $_POST;
        $_SESSION['form_erros'] = array_values(array_diff($erros, ['duplicado']));
        redirecionar(url('lead_form', $id ? ['id' => $id] : []));
    }
    if ($atual) {
        q('UPDATE leads SET entrada=?, nome=?, telefone=?, imovel_codigo=?, imovel_titulo=?, imovel_url=?, origem=?, corretor_id=?, atualizado_em=? WHERE id=?',
            [$entrada, $nome, $tel, $im[0], $im[1], $im[2], $origem, $cid, agora(), $id]);
        evento($id, 'editar');
        aviso('Lead atualizado');
    } else {
        q("INSERT INTO leads (entrada, nome, telefone, imovel_codigo, imovel_titulo, imovel_url, origem, status, corretor_id, observacoes, criado_em, atualizado_em)
           VALUES (?,?,?,?,?,?,?,'novo',?,'',?,?)", [$entrada, $nome, $tel, $im[0], $im[1], $im[2], $origem, $cid, agora(), agora()]);
        evento((int)db()->lastInsertId(), 'criar');
        aviso('Lead salvo');
    }
    redirecionar(voltar_lista());
}
function acao_lead_excluir(): void
{
    exigir_admin();
    $l = lead_permitido((int)($_POST['id'] ?? 0));
    q('UPDATE leads SET excluido_em = ? WHERE id = ?', [agora(), $l['id']]);
    evento((int)$l['id'], 'excluir');
    aviso('Lead excluído. Ele fica em Excluídos e pode ser restaurado.');
    redirecionar(voltar_lista());
}
function acao_lead_restaurar(): void
{
    exigir_admin();
    $l = lead_permitido((int)($_POST['id'] ?? 0), true);
    q('UPDATE leads SET excluido_em = NULL, atualizado_em = ? WHERE id = ?', [agora(), $l['id']]);
    evento((int)$l['id'], 'restaurar');
    aviso('Lead restaurado');
    redirecionar(voltar_lista());
}
/* Exclusão definitiva: só para lead que já está em Excluídos. Apaga o lead e o histórico dele; não tem volta. */
function acao_lead_apagar(): void
{
    exigir_admin();
    $l = lead_permitido((int)($_POST['id'] ?? 0), true);
    if ($l['excluido_em'] === null) falha(422, 'Só é possível apagar de vez um lead que já está em Excluídos.');
    db()->beginTransaction();
    q('DELETE FROM lead_eventos WHERE lead_id = ?', [$l['id']]);
    q('DELETE FROM leads WHERE id = ? AND excluido_em IS NOT NULL', [$l['id']]);
    db()->commit();
    aviso('Lead apagado definitivamente.');
    redirecionar(voltar_lista());
}

/* ---------- Usuários: só admin ---------- */
function usuario_alvo(): array
{
    $u = um('SELECT * FROM usuarios WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    if (!$u) falha(404, 'Usuário não encontrado.');
    return $u;
}
function acao_usuario_criar(): void
{
    exigir_admin();
    $nome = trim((string)($_POST['nome'] ?? ''));
    $login = mb_strtolower(trim((string)($_POST['login'] ?? '')));
    $senha = (string)($_POST['senha'] ?? '');
    $papel = ($_POST['papel'] ?? '') === 'admin' ? 'admin' : 'corretor';
    $erro = '';
    if ($nome === '' || mb_strlen($nome) > 120) $erro = 'Informe o nome.';
    elseif (!login_valido($login)) $erro = 'O login usa de 3 a 60 letras minúsculas, números, ponto, hífen ou sublinhado.';
    elseif (!senha_valida($senha)) $erro = 'A senha precisa de pelo menos 8 caracteres.';
    elseif (um('SELECT id FROM usuarios WHERE login = ?', [$login])) $erro = 'Já existe um usuário com esse login.';
    if ($erro !== '') { aviso($erro, 'erro'); redirecionar(url('usuarios')); }
    q('INSERT INTO usuarios (nome, login, senha_hash, papel, ativo, criado_em) VALUES (?,?,?,?,1,?)',
        [$nome, $login, password_hash($senha, PASSWORD_DEFAULT), $papel, agora()]);
    aviso("Usuário $nome criado. Passe o login e a senha a ele.");
    redirecionar(url('usuarios'));
}
function acao_usuario_senha(): void
{
    exigir_admin();
    $u = usuario_alvo();
    $senha = (string)($_POST['senha'] ?? '');
    if (!senha_valida($senha)) { aviso('A senha precisa de pelo menos 8 caracteres.', 'erro'); redirecionar(url('usuarios')); }
    q('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
    aviso('Senha de ' . $u['nome'] . ' redefinida.');
    redirecionar(url('usuarios'));
}
function admins_ativos(): int { return (int)valor("SELECT COUNT(*) FROM usuarios WHERE papel = 'admin' AND ativo = 1"); }
function acao_usuario_ativo(): void
{
    $eu = exigir_admin();
    $u = usuario_alvo();
    $novo = (int)$u['ativo'] === 1 ? 0 : 1;
    if ($novo === 0 && (int)$u['id'] === (int)$eu['id']) { aviso('Você não pode desativar o próprio usuário.', 'erro'); redirecionar(url('usuarios')); }
    if ($novo === 1 && $u['senha_hash'] === '') { aviso('Defina uma senha para ' . $u['nome'] . ' antes de reativar.', 'erro'); redirecionar(url('usuarios')); }
    q('UPDATE usuarios SET ativo = ? WHERE id = ?', [$novo, $u['id']]);
    aviso($novo ? 'Usuário reativado' : 'Usuário desativado. Ele não consegue mais entrar; os leads continuam com ele.');
    redirecionar(url('usuarios'));
}
function acao_usuario_excluir(): void
{
    $eu = exigir_admin();
    $u = usuario_alvo();
    if ((int)$u['id'] === (int)$eu['id']) { aviso('Você não pode excluir o próprio usuário.', 'erro'); redirecionar(url('usuarios')); }
    if ($u['papel'] === 'admin' && (int)$u['ativo'] === 1 && admins_ativos() <= 1) { aviso('O sistema precisa de pelo menos um administrador.', 'erro'); redirecionar(url('usuarios')); }
    $n = (int)valor('SELECT COUNT(*) FROM leads WHERE corretor_id = ?', [$u['id']]);
    db()->beginTransaction();
    q('UPDATE leads SET corretor_id = NULL, atualizado_em = ? WHERE corretor_id = ?', [agora(), $u['id']]);
    q('DELETE FROM usuarios WHERE id = ?', [$u['id']]);
    db()->commit();
    aviso('Usuário excluído.' . ($n ? " Os $n leads dele voltaram para A distribuir." : ''));
    redirecionar(url('usuarios'));
}

/* ---------- Importação da planilha (CSV exportado do Google Planilhas) ---------- */
function chave_coluna(string $s): string
{
    $s = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s));
    return strtr($s, ['á' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);
}
function origem_da_planilha(string $o, bool $tem_utm): string
{
    $k = chave_coluna($o);
    if (str_contains($k, 'whats')) return 'WhatsApp Business';
    if (str_contains($k, 'insta') || str_contains($k, 'direct') || $k === 'dm') return 'Direct do Instagram';
    if ($tem_utm || str_contains($k, 'site') || str_contains($k, 'form') || str_contains($k, 'landing') || str_contains($k, 'acesso') || str_contains($k, 'reel')) return 'Formulário do site';
    return 'Outro';
}
function acao_importar(): void
{
    exigir_admin();
    $f = $_FILES['arquivo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 2 * 1024 * 1024) {
        aviso('Envie o arquivo CSV da planilha (até 2 MB).', 'erro'); redirecionar(url('importar'));
    }
    $h = fopen($f['tmp_name'], 'r');
    $cab = fgetcsv($h, 0, ',', '"', '');
    $col = [];
    foreach ((array)$cab as $i => $nome) $col[chave_coluna((string)$nome)] = $i;
    foreach (['nome', 'whatsapp'] as $obrig) {
        if (!isset($col[$obrig])) { fclose($h); aviso('O arquivo precisa das colunas Nome e WhatsApp na primeira linha.', 'erro'); redirecionar(url('importar')); }
    }
    $pega = fn(array $r, string $c): string => isset($col[$c]) ? trim((string)($r[$col[$c]] ?? '')) : '';
    $status_por_nome = [];
    foreach (STATUS as $k => $v) $status_por_nome[chave_coluna($v)] = $k;
    $usuarios = [];
    foreach (q('SELECT id, nome FROM usuarios')->fetchAll() as $u) $usuarios[chave_coluna($u['nome'])] = (int)$u['id'];
    $site = imoveis_site();
    $rel = ['importados' => 0, 'repetidos' => 0, 'ignorados' => [], 'cortados' => [], 'corretores' => []];
    $linha = 1;
    db()->beginTransaction();
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $linha++;
        $nome = mb_substr($pega($r, 'nome'), 0, 160);
        if ($nome === '' && $pega($r, 'whatsapp') === '') continue;
        $tel = telefone_limpo($pega($r, 'whatsapp'));
        if ($nome === '' || $tel === null) { $rel['ignorados'][] = "linha $linha (" . ($nome !== '' ? $nome : 'sem nome') . ': telefone ausente ou inválido)'; continue; }
        $entrada = data_iso($pega($r, 'recebido em')) ?? date('Y-m-d');
        if (um('SELECT id FROM leads WHERE telefone = ? AND entrada = ? AND nome = ?', [$tel, $entrada, $nome])) { $rel['repetidos']++; continue; }
        $cid = null;
        $cor = $pega($r, 'corretor');
        if ($cor !== '') {
            $k = chave_coluna($cor);
            if (!isset($usuarios[$k])) {
                $base = trim(preg_replace('/[^a-z0-9]+/', '.', $k) ?? '', '.') ?: 'corretor';
                $login = $base; $n = 1;
                while (um('SELECT id FROM usuarios WHERE login = ?', [$login])) $login = $base . (++$n);
                q("INSERT INTO usuarios (nome, login, senha_hash, papel, ativo, criado_em) VALUES (?,?,'','corretor',0,?)", [mb_substr($cor, 0, 120), mb_substr($login, 0, 60), agora()]);
                $usuarios[$k] = (int)db()->lastInsertId();
                $rel['corretores'][] = "$cor (login $login)";
            }
            $cid = $usuarios[$k];
        }
        $cod = so_digitos($pega($r, 'codigo do imovel'));
        $imtxt = $pega($r, 'imovel');
        if ($cod !== '' && isset($site[$cod])) $im = [$cod, $site[$cod]['titulo'], $site[$cod]['url']];
        elseif (preg_match('~^https://marceloosawaimoveis\.com\.br/~', $imtxt)) $im = [$cod ?: null, $cod !== '' ? "Imóvel $cod" : 'Imóvel do site', mb_substr($imtxt, 0, 255)];
        else $im = [$cod ?: null, $imtxt !== '' ? mb_substr($imtxt, 0, 255) : ($cod !== '' ? "Imóvel $cod" : null), null];
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $c) { $v = $pega($r, $c); if ($v !== '') $utm[$c] = $v; }
        $obs = $pega($r, 'observacoes');
        if (mb_strlen($obs) > OBS_MAX) { $rel['cortados'][] = "$nome (linha $linha)"; $obs = mb_substr($obs, 0, OBS_MAX); }
        $st = $status_por_nome[chave_coluna($pega($r, 'status'))] ?? 'novo';
        q('INSERT INTO leads (entrada, nome, telefone, imovel_codigo, imovel_titulo, imovel_url, interesse, origem, utm, status, corretor_id, observacoes, criado_em, atualizado_em)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$entrada, $nome, $tel, $im[0], $im[1], $im[2], mb_substr($pega($r, 'interesse'), 0, 80) ?: null, origem_da_planilha($pega($r, 'origem'), (bool)$utm),
             $utm ? mb_substr(json_encode($utm, JSON_UNESCAPED_UNICODE), 0, 500) : null, $st, $cid, $obs, agora(), agora()]);
        evento((int)db()->lastInsertId(), 'importar');
        $rel['importados']++;
    }
    db()->commit();
    fclose($h);
    $_SESSION['relatorio'] = $rel;
    redirecionar(url('importar'));
}

/* ---------- Exportação (CSV com todos os leads não excluídos) ---------- */
function exportar_csv(): void
{
    exigir_admin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    $seguro = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;   // impede fórmula ao abrir na planilha
    fputcsv($o, ['Recebido em', 'Status', 'Motivo da perda', 'Corretor', 'Nome', 'WhatsApp', 'Código do imóvel', 'Imóvel', 'Link do imóvel', 'Origem', 'Observações'], ',', '"', '');
    $rs = q('SELECT l.*, u.nome AS corretor FROM leads l LEFT JOIN usuarios u ON u.id = l.corretor_id WHERE l.excluido_em IS NULL ORDER BY l.entrada DESC, l.id DESC');
    foreach ($rs as $l) {
        fputcsv($o, array_map($seguro, [data_br($l['entrada']), STATUS[$l['status']] ?? $l['status'], $l['motivo_perda'], $l['corretor'], $l['nome'],
            telefone_fmt($l['telefone']), $l['imovel_codigo'], $l['imovel_titulo'], $l['imovel_url'], $l['origem'], $l['observacoes']]), ',', '"', '');
    }
    exit;
}
