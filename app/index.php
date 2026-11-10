<?php
/* Entrada única do CRM de leads. Toda página e toda ação passam por aqui. */
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/telas.php';

set_exception_handler(function (Throwable $t): void {
    error_log('[crm] ' . get_class($t) . ': ' . $t->getMessage() . ' em ' . $t->getFile() . ':' . $t->getLine());
    if (!headers_sent()) http_response_code(500);
    if (config('debug')) { header('Content-Type: text/plain; charset=utf-8'); echo $t; exit; }
    try { falha(500, 'Ocorreu um erro inesperado. Tente de novo em instantes.'); } catch (Throwable $x) { echo 'Erro inesperado.'; }
});

cabecalhos();
iniciar_sessao();

$rota = preg_replace('/[^a-z_]/', '', (string)($_GET['r'] ?? 'leads'));
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!instalado()) {
    if ($post && $rota === 'instalar') { exigir_csrf(); require __DIR__ . '/inc/acoes.php'; acao_instalar(); }
    tela_instalar();
    exit;
}

if ($post) {
    exigir_csrf();
    require __DIR__ . '/inc/acoes.php';
    $acoes = [
        'entrar' => false, 'sair' => false,                      // false: não exige login
        'lead_status' => true, 'lead_motivo' => true, 'lead_obs' => true,
        'lead_atribuir' => true, 'lead_salvar' => true, 'lead_excluir' => true, 'lead_restaurar' => true, 'lead_apagar' => true,
        'usuario_criar' => true, 'usuario_senha' => true, 'usuario_ativo' => true, 'usuario_excluir' => true,
        'importar' => true,
    ];
    if (!isset($acoes[$rota])) falha(404, 'Página não encontrada.');
    if ($acoes[$rota]) exigir_login();
    ('acao_' . $rota)();
    exit;
}

switch ($rota) {
    case 'login':
        if (usuario()) redirecionar(url('leads'));
        tela_login();
        break;
    case 'leads':
        tela_leads(exigir_login());
        break;
    case 'lead_form':
        tela_lead_form(exigir_admin());
        break;
    case 'usuarios':
        tela_usuarios(exigir_admin());
        break;
    case 'importar':
        tela_importar(exigir_admin());
        break;
    case 'exportar':
        require __DIR__ . '/inc/acoes.php';
        exportar_csv();
        break;
    default:
        exigir_login();
        falha(404, 'Página não encontrada.');
}
