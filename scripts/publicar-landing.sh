#!/bin/bash
# Publica a landing de um imóvel (ou a página inicial) na Hostinger e confere o index.html no servidor.
#
# Uso (de qualquer pasta):
#   bash scripts/publicar-landing.sh <codigo> <bairro>     site/<codigo>/<bairro>/  →  public_html/<codigo>/<bairro>/
#   bash scripts/publicar-landing.sh --home                site/index.html          →  public_html/index.html
#   acrescente --dry-run para só listar "local → servidor" e a conferência, sem nenhuma chamada de rede
#
# Credenciais: as mesmas de scripts/crm/publicar.sh, só por variável de ambiente, nunca em arquivo. Saem da
# operação hosting_files_generate-upload-url do conector da Hostinger (usuário e domínio em .hostinger/site.json):
#   HOSTINGER_FILES_URL  o "url" devolvido (termina em /api/tus/public_html)
#   HOSTINGER_AUTH_KEY   o "auth_key"
#   HOSTINGER_REST_KEY   o "rest_auth_key"
#
# O destino sai só dos argumentos (código numérico; bairro minúsculo, sem acento, como no Passo 0) e todo caminho
# é conferido antes do primeiro envio. Cada arquivo vai por TUS com ?override=true e o Upload-Offset final tem de
# ser igual ao tamanho; o primeiro erro para tudo. O index.html sobe por último e é lido de volta do servidor
# (api/raw) e comparado byte a byte com o local; fotos e vídeos não (basta o tamanho conferido no envio).
# Arquivos ignorados pelo git (.DS_Store, .gerado-por-landing) não sobem.
# Depois: limpar o cache (hosting_cache_clear, scope all) e conferir no navegador (Passos 9 e 10 da skill).
#
# Saída: 0 ok · 1 uso ou pasta recusada · 2 sem credenciais · 3 credenciais recusadas · 4 envio falhou
#        5 o index.html do servidor não confere com o local

set -euo pipefail
# Nomes únicos das credenciais do File Browser da Hostinger (os mesmos de scripts/remover-imovel.py).
# Os nomes antigos CRM_UPLOAD_URL, CRM_AUTH e CRM_REST ainda valem como reserva (obs. 0061, 23/11/2026).
HOSTINGER_FILES_URL=${HOSTINGER_FILES_URL:-${CRM_UPLOAD_URL:-}}
HOSTINGER_AUTH_KEY=${HOSTINGER_AUTH_KEY:-${CRM_AUTH:-}}
HOSTINGER_REST_KEY=${HOSTINGER_REST_KEY:-${CRM_REST:-}}

RAIZ=$(cd "$(dirname "$0")/.." && pwd)
cd "$RAIZ"
SITE_URL="https://marceloosawaimoveis.com.br"

uso(){ sed -n '2,23p' "$0" | sed 's/^# \{0,1\}//'; exit 1; }
recusar(){ echo "Recusado: $*" >&2; exit 1; }

# ---- argumentos ----------------------------------------------------------------------------------------------
SECO=0; INICIAL=0; CODIGO=""; BAIRRO=""; N=0
for a in "$@"; do
    case "$a" in
        --dry-run) SECO=1 ;;
        --home) INICIAL=1 ;;
        -h|--help) uso ;;
        -*) recusar "opção desconhecida: $a" ;;
        *) N=$((N + 1))
           case "$N" in 1) CODIGO=$a ;; 2) BAIRRO=$a ;; *) recusar "argumento a mais: $a" ;; esac ;;
    esac
done
if [ "$INICIAL" = 1 ]; then
    [ "$N" = 0 ] || recusar "--home não leva código nem bairro."
elif [ "$N" != 2 ]; then
    uso
fi

TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
SEGURO='^[A-Za-z0-9._/-]+$'   # caracteres aceitos num caminho: nada de $, espaço, aspas, % ou acento

# ---- plano: uma linha "local<TAB>servidor" por arquivo ------------------------------------------------------
if [ "$INICIAL" = 1 ]; then
    ORIGEM=site
    PREFIXO=public_html/
    INDEX_LOCAL=site/index.html
    [ -f "$INDEX_LOCAL" ] || recusar "$INDEX_LOCAL não existe."
    printf '%s\t%s\n' "$INDEX_LOCAL" "${PREFIXO}index.html" > "$TMP/plano"
else
    re='^[0-9]+$';                [[ $CODIGO =~ $re ]] || recusar "o código '$CODIGO' tem de ser só dígitos."
    re='^[a-z0-9]+(-[a-z0-9]+)*$'; [[ $BAIRRO =~ $re ]] || recusar "o bairro '$BAIRRO' tem de ser minúsculo, sem acento, palavras ligadas por hífen."
    ORIGEM="site/$CODIGO/$BAIRRO"
    PREFIXO="public_html/$CODIGO/$BAIRRO/"
    INDEX_LOCAL="$ORIGEM/index.html"
    [ -d "$ORIGEM" ] && [ ! -L "$ORIGEM" ] || recusar "a pasta $ORIGEM/ não existe (veja o nome certo com: ls site/$CODIGO/)."
    [ -f "$INDEX_LOCAL" ] || recusar "$ORIGEM/ não tem index.html (imóvel vendido, só com o .htaccess? pasta errada?)."
    # Rastreados + novos não ignorados: o mesmo conjunto que o commit levaria.
    git ls-files --cached --others --exclude-standard -- "$ORIGEM/" > "$TMP/lista" || recusar "o git ls-files falhou."
    : > "$TMP/resto"; : > "$TMP/index"
    while IFS= read -r f; do
        [[ $f =~ $SEGURO ]] || recusar "nome de arquivo fora do padrão: $f"
        [ -L "$f" ] && recusar "$f é um link simbólico."
        [ -f "$f" ] || continue                              # apagado da pasta mas ainda no índice do git
        linha=$(printf '%s\t%s%s' "$f" "$PREFIXO" "${f#"$ORIGEM"/}")
        if [ "$f" = "$INDEX_LOCAL" ]; then echo "$linha" > "$TMP/index"; else echo "$linha" >> "$TMP/resto"; fi
    done < <(sort -u "$TMP/lista")
    cat "$TMP/resto" "$TMP/index" > "$TMP/plano"             # index.html por último
fi
INDEX_REMOTO="${PREFIXO}index.html"

# Conferência de todo destino antes de qualquer envio: dentro do prefixo, caracteres seguros, sem "..", sem "//".
while IFS=$'\t' read -r l r; do
    [[ $r =~ $SEGURO ]] || recusar "destino com caractere proibido: $r"
    case "$r" in *..*|*//*) recusar "destino com '..' ou '//': $r" ;; esac
    case "$r" in "$PREFIXO"?*) ;; *) recusar "destino fora de $PREFIXO: $r" ;; esac
    [ "$r" = "$PREFIXO${l#"$ORIGEM"/}" ] || recusar "destino não corresponde ao arquivo local: $l → $r"
    if [ "$INICIAL" = 1 ] && [ "$r" != public_html/index.html ]; then recusar "--home só envia public_html/index.html."; fi
done < "$TMP/plano"
tail -1 "$TMP/plano" | cut -f2 | grep -qxF "$INDEX_REMOTO" || recusar "o index.html não está no fim do plano."

N_ARQ=$(wc -l < "$TMP/plano" | tr -d ' ')
BYTES=0
while IFS=$'\t' read -r l r; do BYTES=$((BYTES + $(wc -c < "$l"))); done < "$TMP/plano"

# ---- resumo (sempre, antes de enviar) ------------------------------------------------------------------------
if [ "$INICIAL" = 1 ]; then
    echo "Destino: public_html/index.html  ($SITE_URL/)"
    ramo=$(git branch --show-current 2>/dev/null || echo '?')
    if [ "$ramo" != main ]; then
        echo "ATENÇÃO: a página inicial só sai da main (Passo 8); a branch ativa é '$ramo'."
        echo "         Antes do envio real, confira que o index.html do ar é igual ao da main e que a diferença é só o cartão novo."
    fi
else
    echo "Destino: $PREFIXO  ($SITE_URL/$CODIGO/$BAIRRO/)"
fi
echo "Arquivos: $N_ARQ ($BYTES bytes)"
while IFS=$'\t' read -r l r; do echo "  $l → $r"; done < "$TMP/plano"
if [ "$INICIAL" = 0 ]; then
    find "$ORIGEM" -type f | sort > "$TMP/todos"
    cut -f1 "$TMP/plano" | sort > "$TMP/enviados"
    if comm -23 "$TMP/todos" "$TMP/enviados" | grep -q .; then
        echo "Não sobem (ignorados pelo git):"; comm -23 "$TMP/todos" "$TMP/enviados" | sed 's/^/  /'
    fi
fi
echo "Conferência depois do envio: GET {base}/api/raw/$INDEX_REMOTO e cmp com $INDEX_LOCAL"

if [ "$SECO" = 1 ]; then
    for v in HOSTINGER_FILES_URL HOSTINGER_AUTH_KEY HOSTINGER_REST_KEY; do
        if [ -n "${!v:-}" ]; then echo "credencial $v: definida"; else echo "credencial $v: FALTA"; fi
    done
    echo "--dry-run: nada foi enviado, nenhuma chamada de rede."
    exit 0
fi

# ---- envio ---------------------------------------------------------------------------------------------------
if [ -z "${HOSTINGER_FILES_URL:-}" ] || [ -z "${HOSTINGER_AUTH_KEY:-}" ] || [ -z "${HOSTINGER_REST_KEY:-}" ]; then
    echo "Faltam HOSTINGER_FILES_URL, HOSTINGER_AUTH_KEY e HOSTINGER_REST_KEY (as mesmas de scripts/crm/publicar.sh)." >&2
    echo "Gere com o conector da Hostinger (hosting_files_generate-upload-url). Nada foi enviado." >&2
    exit 2
fi
BASE=${HOSTINGER_FILES_URL%/}
BASE=${BASE%/api/tus/public_html}
BASE=${BASE%/api/tus}
case "$BASE" in https://?*) ;; *) echo "HOSTINGER_FILES_URL não começa com https://. Nada foi enviado." >&2; exit 2 ;; esac
command -v curl >/dev/null || { echo "curl não encontrado. Nada foi enviado." >&2; exit 2; }

enviar(){   # $1 arquivo local, $2 caminho no servidor (a partir da raiz do File Browser); mesmo TUS de publicar.sh
    local size c off
    size=$(wc -c < "$1" | tr -d ' ')
    c=$(curl -s --connect-timeout 20 --max-time 120 -o /dev/null -w '%{http_code}' -X POST "$BASE/api/tus/$2?override=true" \
        -H "X-Auth: $HOSTINGER_AUTH_KEY" -H "X-Auth-Rest: $HOSTINGER_REST_KEY" \
        -H 'Tus-Resumable: 1.0.0' -H "Upload-Length: $size" -H 'Upload-Offset: 0') || c="sem-resposta"
    case "$c" in 401|403) echo "O servidor recusou as credenciais ($c) em $2: gere um novo link de envio." >&2; exit 3 ;; esac
    off=$(curl -s --connect-timeout 20 --max-time 600 -D - -o /dev/null -X PATCH "$BASE/api/tus/$2?override=true" \
        -H "X-Auth: $HOSTINGER_AUTH_KEY" -H "X-Auth-Rest: $HOSTINGER_REST_KEY" \
        -H 'Tus-Resumable: 1.0.0' -H 'Content-Type: application/offset+octet-stream' -H 'Upload-Offset: 0' --data-binary "@$1" \
        | tr -d '\r' | awk 'tolower($1)=="upload-offset:"{v=$2} END{print v}') || off=""
    [ "$off" = "$size" ] && { echo "enviado   $2 ($size bytes)"; return 0; }
    echo "FALHOU    $2: POST $c, gravou '$off' de $size bytes"; return 1
}

echo "== envio"
i=0
while IFS=$'\t' read -r l r; do
    enviar "$l" "$r" || { echo "Parei no primeiro erro: $i de $N_ARQ arquivos enviados antes dele." >&2; exit 4; }
    i=$((i + 1))
done < "$TMP/plano"

echo "== conferência do index.html no servidor"
c=$(curl -s --connect-timeout 20 --max-time 120 -o "$TMP/lido" -w '%{http_code}' "$BASE/api/raw/$INDEX_REMOTO" \
    -H "X-Auth: $HOSTINGER_AUTH_KEY" -H "X-Auth-Rest: $HOSTINGER_REST_KEY") || c="sem-resposta"
if [ "$c" = 200 ] && cmp -s "$TMP/lido" "$INDEX_LOCAL"; then
    echo "OK    $INDEX_REMOTO é idêntico a $INDEX_LOCAL ($(wc -c < "$INDEX_LOCAL" | tr -d ' ') bytes)"
else
    lidos=$( [ -f "$TMP/lido" ] && wc -c < "$TMP/lido" | tr -d ' ' || echo 0 )
    echo "FAIL  $INDEX_REMOTO: HTTP $c, $lidos bytes lidos; o local tem $(wc -c < "$INDEX_LOCAL" | tr -d ' ') bytes e não confere." >&2
    exit 5
fi
echo "$i arquivos publicados em $PREFIXO."
echo "Falta: limpar o cache (hosting_cache_clear, scope all, pelo conector) e conferir no navegador (Passos 9 e 10)."
