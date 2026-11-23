#!/usr/bin/env bash
# Publica o CRM de leads (app/) na Hostinger, com conferência antes e depois.
#
# As credenciais do envio valem algumas horas e vêm do conector da Hostinger (operação
# hosting_files_generate-upload-url, usuário e domínio em .hostinger/site.json). Entram só por
# variável de ambiente, nunca em arquivo:
#   HOSTINGER_FILES_URL  o "url" devolvido (termina em /api/tus/public_html)
#   HOSTINGER_AUTH_KEY   o "auth_key"
#   HOSTINGER_REST_KEY   o "rest_auth_key"
#
# Uso (na raiz do repositório ou de qualquer lugar):
#   bash scripts/crm/publicar.sh verificar    compara cada arquivo local com a main e com o servidor; não envia nada
#   bash scripts/crm/publicar.sh publicar     testes, envio só do que mudou, migração do banco e conferência no ar
#   bash scripts/crm/publicar.sh pr <ramo> <mensagem-do-commit.txt> <corpo-do-pr.md> [arquivos ou pastas extras...]
#                                             commit sem trocar de branch, PR, merge e main local atualizada
#
# Regra de segurança: um arquivo só é enviado se o servidor tem a versão da main, a última versão que este Mac
# publicou (registro em .git/crm-publicado.txt, fora do commit) ou não tem o arquivo. Se o servidor tem uma
# versão que não é nenhuma dessas, alguém publicou por fora: o comando para antes de enviar qualquer coisa.
#
# CRM_ORIGEM e CRM_DESTINO (padrão: app e app) existem só para o teste do próprio comando numa pasta
# descartável; com destino diferente de app, testes, migração e conferência do sistema são pulados.

cd "$(dirname "$0")/../.." || exit 1
# Nomes únicos das credenciais do File Browser da Hostinger (os mesmos de scripts/remover-imovel.py).
# Os nomes antigos CRM_UPLOAD_URL, CRM_AUTH e CRM_REST ainda valem como reserva (obs. 0061, 23/11/2026).
HOSTINGER_FILES_URL=${HOSTINGER_FILES_URL:-${CRM_UPLOAD_URL:-}}
HOSTINGER_AUTH_KEY=${HOSTINGER_AUTH_KEY:-${CRM_AUTH:-}}
HOSTINGER_REST_KEY=${HOSTINGER_REST_KEY:-${CRM_REST:-}}
ORIGEM=${CRM_ORIGEM:-app}
DESTINO=${CRM_DESTINO:-app}
SITE="https://marceloosawaimoveis.com.br/$DESTINO"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
REGISTRO="$(git rev-parse --git-dir)/crm-publicado.txt"   # destino → hash do que este Mac publicou por último

credenciais(){
    if [ -z "${HOSTINGER_FILES_URL:-}" ] || [ -z "${HOSTINGER_AUTH_KEY:-}" ] || [ -z "${HOSTINGER_REST_KEY:-}" ]; then
        echo "Faltam HOSTINGER_FILES_URL, HOSTINGER_AUTH_KEY e HOSTINGER_REST_KEY. Gere com o conector da Hostinger (hosting_files_generate-upload-url)."; exit 1
    fi
    BASE=${HOSTINGER_FILES_URL%/api/tus/public_html}
}

# Arquivos publicáveis: tudo da origem, menos configuração, dados locais, prévia e lixo do macOS.
arquivos(){
    find "$ORIGEM" -type f ! -name '.DS_Store' ! -path '*/_previa/*' ! -path '*/inc/_dados/*' ! -path '*/inc/config.php' | sort
}
destino_de(){ echo "public_html/$DESTINO/${1#"$ORIGEM"/}"; }
hash_local(){ shasum < "$1" | cut -c1-40; }
hash_main(){ git cat-file -e "origin/main:$1" 2>/dev/null && git show "origin/main:$1" | shasum | cut -c1-40 || echo ausente; }
hash_servidor(){
    local c
    c=$(curl -s -o "$TMP/srv" -w '%{http_code}' "$BASE/api/raw/$(destino_de "$1")" -H "X-Auth: $HOSTINGER_AUTH_KEY" -H "X-Auth-Rest: $HOSTINGER_REST_KEY")
    case "$c" in
        200) shasum < "$TMP/srv" | cut -c1-40 ;;
        404) echo ausente ;;
        *) echo "ERRO-$c" ;;
    esac
}
hash_publicado(){ awk -v p="$1" '$1==p{h=$2} END{print (h=="" ? "nenhum" : h)}' "$REGISTRO" 2>/dev/null || echo nenhum; }
registrar(){ echo "$1 $2" >> "$REGISTRO"; }
enviar(){   # $1 arquivo local, $2 caminho no servidor (a partir da raiz do File Browser)
    local size c off
    size=$(stat -f%z "$1")
    c=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/tus/$2?override=true" -H "X-Auth: $HOSTINGER_AUTH_KEY" -H "X-Auth-Rest: $HOSTINGER_REST_KEY" \
        -H 'Tus-Resumable: 1.0.0' -H "Upload-Length: $size" -H 'Upload-Offset: 0')
    off=$(curl -s -D - -o /dev/null -X PATCH "$BASE/api/tus/$2?override=true" -H "X-Auth: $HOSTINGER_AUTH_KEY" -H "X-Auth-Rest: $HOSTINGER_REST_KEY" \
        -H 'Tus-Resumable: 1.0.0' -H 'Content-Type: application/offset+octet-stream' -H 'Upload-Offset: 0' --data-binary "@$1" \
        | tr -d '\r' | awk 'tolower($1)=="upload-offset:"{print $2}')
    [ "$off" = "$size" ] && { echo "enviado   $2 ($size bytes)"; return 0; }
    echo "FALHOU    $2: POST $c, gravou '$off' de $size bytes"; return 1
}
apagar_remoto(){ curl -s -o /dev/null -w '%{http_code}' -X DELETE "$BASE/api/resources/$1" -H "X-Auth: $HOSTINGER_AUTH_KEY" -H "X-Auth-Rest: $HOSTINGER_REST_KEY"; }

# Classifica cada arquivo em: em-dia | enviar | conflito | erro. Grava a lista em $TMP/plano.
classificar(){
    git fetch -q origin || { echo "Não consegui atualizar a origin/main."; exit 1; }
    : > "$TMP/plano"
    local f l m r estado
    while IFS= read -r f; do
        l=$(hash_local "$f"); m=$(hash_main "$f"); r=$(hash_servidor "$f")
        case "$r" in ERRO-401|ERRO-403) echo "O servidor recusou as credenciais ($r): gere um novo link de envio."; exit 1 ;; esac
        if [ "${r#ERRO-}" != "$r" ]; then estado=erro
        elif [ "$l" = "$r" ]; then estado=em-dia
        elif [ "$r" = ausente ] || [ "$r" = "$m" ] || [ "$r" = "$(hash_publicado "$(destino_de "$f")")" ]; then estado=enviar
        else estado=conflito
        fi
        echo "$estado $f" >> "$TMP/plano"
    done < <(arquivos)
    awk '$1!="em-dia"' "$TMP/plano" | while read -r e f; do
        case "$e" in
            enviar) echo "enviar    $f" ;;
            conflito) echo "CONFLITO  $f  (o servidor tem uma versão que não é a da main nem a última publicada por este Mac)" ;;
            erro) echo "ERRO      $f  (não consegui ler a versão do servidor)" ;;
        esac
    done
    echo "$(grep -c '^em-dia' "$TMP/plano") em dia · $(grep -c '^enviar' "$TMP/plano") a enviar · $(grep -c '^conflito' "$TMP/plano") em conflito · $(grep -c '^erro' "$TMP/plano") com erro"
}

# Sobe um PHP de diagnóstico com nome aleatório, lê a resposta e apaga.
diagnostico(){
    local nome="diag-$(openssl rand -hex 8).php" c d
    enviar "$1" "public_html/$DESTINO/$nome" >/dev/null || { echo "Falhou o envio do diagnóstico."; return 1; }
    curl -s "$SITE/$nome" > "$TMP/diag.out"; cat "$TMP/diag.out"
    c=$(apagar_remoto "public_html/$DESTINO/$nome"); d=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/$nome")
    [ "$c" = 204 ] && [ "$d" = 404 ] || echo "ATENÇÃO: o diagnóstico $nome não foi apagado (DELETE $c, depois $d). Apague à mão."
}

cmd_verificar(){ credenciais; classificar; }

cmd_publicar(){
    credenciais
    classificar || exit 1
    if grep -q -E '^(conflito|erro) ' "$TMP/plano"; then echo; echo "Nada foi enviado: resolva os conflitos ou erros acima."; exit 2; fi
    grep '^enviar ' "$TMP/plano" | cut -d' ' -f2- > "$TMP/enviar"
    local n; n=$(wc -l < "$TMP/enviar" | tr -d ' ')
    local sistema=1; [ "$DESTINO" = app ] && [ "$ORIGEM" = app ] || sistema=0

    if [ "$n" -gt 0 ]; then
        if [ "$sistema" = 1 ] && [ "${SEM_TESTES:-}" != 1 ]; then
            echo "== testes"
            bash scripts/crm/testes.sh > "$TMP/testes.log" 2>&1 || { grep -E '^FALHOU|passaram' "$TMP/testes.log"; echo "Testes falharam: nada foi enviado."; exit 3; }
            grep passaram "$TMP/testes.log"
        fi
        while IFS= read -r f; do case "$f" in *.php) php -l "$f" >/dev/null || { echo "Erro de sintaxe em $f: nada foi enviado."; exit 3; } ;; esac; done < "$TMP/enviar"

        echo "== envio"
        # Esquema e base primeiro, depois a migração do banco, depois o resto: o código novo nunca roda sobre o banco velho.
        grep -E '/inc/(schema|bootstrap)\.php$' "$TMP/enviar" > "$TMP/fase1"
        grep -v -E '/inc/(schema|bootstrap)\.php$' "$TMP/enviar" > "$TMP/fase2"
        while IFS= read -r f; do enviar "$f" "$(destino_de "$f")" || exit 4; done < "$TMP/fase1"
        if [ "$sistema" = 1 ]; then
            printf '%s\n' '<?php' 'require __DIR__ . "/inc/bootstrap.php"; require __DIR__ . "/inc/schema.php";' \
                'header("Content-Type: text/plain; charset=utf-8");' \
                '$f = function_exists("migrar") ? migrar() : null;' \
                'echo "migração do banco: ", $f === null ? "sem migrar()" : ($f ? implode("; ", $f) : "nada a fazer"), "\n";' > "$TMP/migrar.php"
            diagnostico "$TMP/migrar.php"
        fi
        while IFS= read -r f; do enviar "$f" "$(destino_de "$f")" || exit 4; done < "$TMP/fase2"

        echo "== conferência dos arquivos"
        local ruins=0
        while IFS= read -r f; do
            if [ "$(hash_servidor "$f")" = "$(hash_local "$f")" ]; then registrar "$(destino_de "$f")" "$(hash_local "$f")"; else echo "DIFERENTE no servidor: $f"; ruins=1; fi
        done < "$TMP/enviar"
        [ "$ruins" = 0 ] && echo "os $n arquivos no servidor são idênticos aos locais" || exit 5
    else
        echo "Nada a enviar: o servidor já está igual a este Mac."
    fi

    [ "$sistema" = 1 ] || { echo "(destino de teste: conferência do sistema pulada)"; return 0; }
    echo "== conferência do sistema no ar"
    local c1 c2 c3
    c1=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/?r=login"); c2=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/inc/config.php"); c3=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/inc/bootstrap.php")
    echo "login: $c1 · config.php pela web: $c2 · bootstrap.php pela web: $c3"
    [ "$c1" = 200 ] && [ "$c2" != 200 ] && [ "$c3" != 200 ] || { echo "FALHOU a conferência básica."; exit 6; }
    cat > "$TMP/fumaca.php" <<'PHP'
<?php
/* Conferência pós-publicação. Só contagens: nenhum nome, telefone ou login sai daqui. */
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/telas.php';
if (is_file(__DIR__ . '/inc/exportar.php')) require __DIR__ . '/inc/exportar.php';
if (is_file(__DIR__ . '/inc/registros.php')) require __DIR__ . '/inc/registros.php';
header('Content-Type: text/plain; charset=utf-8');
iniciar_sessao();
echo 'banco: ok · leads ativos: ', valor('SELECT COUNT(*) FROM leads WHERE excluido_em IS NULL'), ' · usuários ativos por perfil: ',
    json_encode(q('SELECT papel, COUNT(*) AS n FROM usuarios WHERE ativo = 1 GROUP BY papel')->fetchAll(PDO::FETCH_KEY_PAIR)), "\n";
$tela = function (array $u, array $g) { $_GET = $g; ob_start(); tela_leads($u); return ob_get_clean(); };
$adm = um("SELECT id, nome, login, papel, ativo FROM usuarios WHERE papel = 'admin' AND ativo = 1 ORDER BY id LIMIT 1");
if ($adm) { $h = $tela($adm, ['f' => 'todos']); echo 'tela do admin: ', substr_count($h, 'class="lead"'), " leads\n"; }
$ger = um("SELECT id, nome, login, papel, ativo FROM usuarios WHERE papel = 'gerente' ORDER BY id LIMIT 1");
if ($ger) { $h = $tela($ger, ['f' => 'todos']); echo 'tela do gerente: ', substr_count($h, 'class="lead"'), ' leads · controles de alteração: ', substr_count($h, 'data-acao=') + substr_count($h, 'Salvar observações'), "\n"; }
if ($adm && function_exists('tela_exportar')) { $_GET = []; ob_start(); tela_exportar($adm); $h = ob_get_clean(); echo 'aba Exportar: ', strpos($h, 'com estes filtros') !== false ? 'ok' : 'FALHOU', "\n"; }
if ($adm && function_exists('tela_registros')) { $_GET = []; ob_start(); tela_registros($adm); $h = ob_get_clean();
    echo 'aba Registros: ', strpos($h, '<h1>Registros</h1>') !== false ? 'ok' : 'FALHOU', ' · registros de acesso: ', valor('SELECT COUNT(*) FROM log_acessos'), ' · de exportação: ', valor('SELECT COUNT(*) FROM log_exportacoes'), "\n"; }
PHP
    diagnostico "$TMP/fumaca.php"
    grep -q '^banco: ok' "$TMP/diag.out" || { echo "FALHOU: o sistema não respondeu ao diagnóstico."; exit 6; }
    if grep -q 'controles de alteração:' "$TMP/diag.out" && ! grep -q 'controles de alteração: 0$' "$TMP/diag.out"; then echo "FALHOU: o gerente está vendo controles de alteração."; exit 6; fi
    grep -q 'FALHOU' "$TMP/diag.out" && exit 6
    echo "publicação conferida"
}

cmd_pr(){
    local ramo=$1 msg=$2 corpo=$3; shift 3
    [ -n "$ramo" ] && [ -f "$msg" ] && [ -f "$corpo" ] || { echo "Uso: publicar.sh pr <ramo> <mensagem-do-commit.txt> <corpo-do-pr.md> [extras...]"; exit 1; }
    git fetch -q origin || exit 1
    local idx; idx="$(git rev-parse --git-dir)/index.publicar-crm"
    GIT_INDEX_FILE=$idx git read-tree origin/main
    local f
    while IFS= read -r f; do [ "$(hash_local "$f")" = "$(hash_main "$f")" ] || GIT_INDEX_FILE=$idx git add -- "$f"; done < <(arquivos)
    for f in "$@"; do GIT_INDEX_FILE=$idx git add -- "$f" || { rm -f "$idx"; exit 1; }; done
    if GIT_INDEX_FILE=$idx git diff --cached --name-only origin/main | grep -q -E '(^|/)inc/config\.php$|/_dados/'; then rm -f "$idx"; echo "Recusado: configuração ou dados locais no commit."; exit 1; fi
    if GIT_INDEX_FILE=$idx git diff --cached --quiet origin/main; then rm -f "$idx"; echo "Nada para commitar: a main já tem tudo."; return 0; fi
    echo "== commit"; GIT_INDEX_FILE=$idx git diff --cached --stat origin/main | tail -1
    local tree commit url
    tree=$(GIT_INDEX_FILE=$idx git write-tree); rm -f "$idx"
    commit=$(git commit-tree "$tree" -p origin/main -F "$msg")
    git branch -f "crm-$ramo" "$commit" && git push -q -u origin "crm-$ramo" 2>&1 | grep -v '^remote: *$' || true
    url=$(gh pr create --base main --head "crm-$ramo" --title "$(head -1 "$msg")" --body-file "$corpo" 2>&1 | tail -1)
    echo "PR: $url"
    gh pr merge "$url" --merge >/dev/null 2>&1; echo "estado: $(gh pr view "$url" --json state -q .state)"
    # Main local: só avança se a branch ativa for a main e as cópias locais forem iguais às da main nova.
    git fetch -q origin
    if [ "$(git branch --show-current)" != main ]; then echo "A branch ativa não é a main: atualize com git pull quando voltar a ela."; return 0; fi
    local dif=0
    while IFS= read -r f; do
        [ -e "$f" ] || continue
        [ "$(hash_local "$f")" = "$(hash_main "$f")" ] || { echo "Cópia local diferente da main nova: $f (não mexi)"; dif=1; }
    done < <(git diff --name-only HEAD origin/main)
    if [ "$dif" = 1 ]; then echo "Main local não atualizada."; return 0; fi
    while IFS= read -r f; do
        [ -e "$f" ] || continue
        if git ls-files --error-unmatch -- "$f" >/dev/null 2>&1; then git checkout -- "$f"; else rm -f -- "$f"; fi   # idênticos à main nova: o pull traz de volta
    done < <(git diff --name-only HEAD origin/main)
    git pull --ff-only -q origin main && echo "main local: $(git log --oneline -1)"
}

case "${1:-}" in
    verificar) cmd_verificar ;;
    publicar) cmd_publicar ;;
    pr) shift; cmd_pr "$@" ;;
    *) sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
