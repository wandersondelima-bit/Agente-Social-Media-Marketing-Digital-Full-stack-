#!/usr/bin/env python3
"""Tira um imóvel vendido (ou retirado) do site, de uma vez.

Uso:
  python3 scripts/remover-imovel.py <codigo>                 # faz tudo no repositório
  python3 scripts/remover-imovel.py <codigo> --dry-run       # só mostra o que faria
  python3 scripts/remover-imovel.py <codigo> --remoto        # também apaga a pasta no servidor e sobe o redirecionamento

O que faz no repositório (sempre):
  1. apaga o cartão do imóvel na lista IMOVEIS de site/index.html e confere a sintaxe da lista (node);
  2. apaga a pasta site/<codigo>/<bairro>/ e deixa só um .htaccess com o redirecionamento 301 para a
     página inicial (quem abrir o link antigo do Reel cai na lista de imóveis à venda);
  3. imprime os passos que faltam: commit/PR, publicar o index.html da main, limpar o cache, conferir.

O que faz no servidor (--remoto), com as credenciais do File Browser da Hostinger nas variáveis de ambiente
HOSTINGER_FILES_URL, HOSTINGER_AUTH_KEY e HOSTINGER_REST_KEY (saem de hosting_files_generate-upload-url;
a URL é a `url` devolvida, com ou sem o final /api/tus/public_html):
  4. DELETE da pasta public_html/<codigo>/<bairro>/ pela API do File Browser;
  5. upload TUS do .htaccess de redirecionamento e do site/index.html novo.
O cache da CDN (hosting_cache_clear, scope all) continua sendo limpo pelo conector, depois.

Nunca apaga nada fora de site/<codigo>/ e nunca mexe em outro cartão da lista.
"""
import argparse, json, os, re, shutil, subprocess, sys, urllib.request, urllib.error
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
SITE = RAIZ / "site"
INDEX = SITE / "index.html"
DOMINIO = "https://marceloosawaimoveis.com.br/"


def falha(msg):
    sys.exit(f"ERRO: {msg}")


def achar_pasta(cod):
    pastas = [p for p in (SITE / cod).glob("*/") if p.is_dir()] if (SITE / cod).is_dir() else []
    if len(pastas) > 1:
        falha(f"mais de uma pasta em site/{cod}/: {[p.name for p in pastas]}")
    return pastas[0] if pastas else None


def tirar_do_index(html, cod):
    # tolera qualquer indentação do cartão (o 2669878 entrou com 3 espaços em 07/11/2026)
    padrao = re.compile(r"^[ \t]*\{codigo:'%s',.*?\},[ \t]*\n" % re.escape(cod), re.S | re.M)
    achados = padrao.findall(html)
    if len(achados) != 1:
        falha(f"esperava 1 cartão com codigo:'{cod}' em site/index.html, achei {len(achados)}")
    return padrao.sub("", html, count=1), achados[0]


def lista_imoveis(html):
    m = re.search(r"const IMOVEIS = \[\n(.*?)\n[ \t]*\];", html, re.S)
    if not m:
        falha("não achei a lista IMOVEIS em site/index.html")
    return m.group(1)


def conferir_sintaxe(html):
    """Avalia a lista com node (mesma linguagem do navegador). Sem node, confere só o balanço de chaves."""
    itens = lista_imoveis(html)
    if shutil.which("node"):
        r = subprocess.run(["node", "-e", "const a=new Function('return ['+process.argv[1]+']')();console.log(a.length)", itens],
                           capture_output=True, text=True)
        if r.returncode != 0:
            falha("a lista IMOVEIS ficou com erro de sintaxe:\n" + r.stderr.strip())
        return int(r.stdout.strip())
    if itens.count("{") != itens.count("}"):
        falha("a lista IMOVEIS ficou com chaves desbalanceadas")
    return itens.count("codigo:'")




def htaccess_redirect(cod, bairro):
    return (f"# Imóvel {cod} vendido/retirado: qualquer endereço antigo desta pasta leva para a página inicial.\n"
            f"RedirectMatch 301 ^/{cod}/{bairro}/.* {DOMINIO}\n")


# ---------- servidor (File Browser da Hostinger) ----------
def creds():
    url = os.environ.get("HOSTINGER_FILES_URL", "").rstrip("/")
    a, r = os.environ.get("HOSTINGER_AUTH_KEY", ""), os.environ.get("HOSTINGER_REST_KEY", "")
    if not (url and a and r):
        falha("--remoto precisa de HOSTINGER_FILES_URL, HOSTINGER_AUTH_KEY e HOSTINGER_REST_KEY no ambiente "
              "(valores de hosting_files_generate-upload-url)")
    base = re.sub(r"/api/tus(/public_html)?$", "", url)
    return base, {"X-Auth": a, "X-Auth-Rest": r}


def req(metodo, url, cab, dados=None, ok=(200, 201, 204)):
    r = urllib.request.Request(url, data=dados, method=metodo, headers=cab)
    try:
        with urllib.request.urlopen(r, timeout=60) as resp:
            return resp.status, dict(resp.headers)
    except urllib.error.HTTPError as e:
        if e.code in ok:
            return e.code, dict(e.headers)
        falha(f"{metodo} {url} → HTTP {e.code} {e.reason}")


def apagar_remoto(base, cab, cod, bairro):
    st, _ = req("DELETE", f"{base}/api/resources/public_html/{cod}/{bairro}/", cab, ok=(204, 404))
    return st


def subir_tus(base, cab, destino, conteudo: bytes):
    """Upload TUS em duas etapas (POST cria, PATCH envia), como o conector da Hostinger faz."""
    import base64
    nome = destino.rsplit("/", 1)[-1]
    meta = "filename " + base64.b64encode(nome.encode()).decode()
    url = f"{base}/api/tus/public_html/{destino}?override=true"   # sem o parâmetro, arquivo existente (index.html) dá 409
    cab1 = dict(cab, **{"Tus-Resumable": "1.0.0", "Upload-Length": str(len(conteudo)), "Upload-Metadata": meta, "Override": "true"})
    req("POST", url, cab1, ok=(201, 200, 204))
    cab2 = dict(cab, **{"Tus-Resumable": "1.0.0", "Upload-Offset": "0", "Content-Type": "application/offset+octet-stream"})
    st, h = req("PATCH", url, cab2, dados=conteudo, ok=(204, 200))
    off = int(h.get("Upload-Offset", h.get("upload-offset", -1)))
    if off != len(conteudo):
        falha(f"upload de {destino}: Upload-Offset {off} ≠ tamanho {len(conteudo)}")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("codigo", help="código do imóvel no QuintoAndar (o mesmo da pasta e do cartão)")
    ap.add_argument("--dry-run", action="store_true", help="mostra o que faria, sem alterar nada")
    ap.add_argument("--remoto", action="store_true", help="também apaga a pasta no servidor e sobe o redirecionamento e o index.html")
    ap.add_argument("--manter-pasta", action="store_true", help="não apaga a pasta local (só tira o cartão)")
    a = ap.parse_args()
    cod = a.codigo.strip()
    if not cod.isdigit():
        falha("o código do imóvel é só números")

    html = INDEX.read_text(encoding="utf-8")
    pasta = achar_pasta(cod)
    bairro = pasta.name if pasta else None
    novo_html, cartao = tirar_do_index(html, cod)
    n = conferir_sintaxe(novo_html)

    print(f"Imóvel {cod}" + (f" · pasta site/{cod}/{bairro}/" if pasta else " · sem pasta local (só o cartão)"))
    print("Cartão a remover:", re.sub(r"\s+", " ", cartao.strip())[:140] + "…")
    print(f"Lista IMOVEIS depois: {n} imóveis (sintaxe OK)")
    if a.dry_run:
        print("\n--dry-run: nada foi alterado.")
        return

    INDEX.write_text(novo_html, encoding="utf-8")
    print("✓ site/index.html sem o cartão")

    if pasta and not a.manter_pasta:
        for filho in pasta.iterdir():
            shutil.rmtree(filho) if filho.is_dir() else filho.unlink()
        (pasta / ".htaccess").write_text(htaccess_redirect(cod, bairro), encoding="utf-8")
        print(f"✓ site/{cod}/{bairro}/ ficou só com o .htaccess de redirecionamento 301")

    if a.remoto:
        if not bairro:
            falha("--remoto precisa da pasta local para saber o bairro do endereço")
        base, cab = creds()
        st = apagar_remoto(base, cab, cod, bairro)
        print("✓ pasta apagada no servidor" if st == 204 else "· pasta já não existia no servidor (404)")
        subir_tus(base, cab, f"{cod}/{bairro}/.htaccess", htaccess_redirect(cod, bairro).encode())
        print("✓ .htaccess de redirecionamento no servidor")
        subir_tus(base, cab, "index.html", novo_html.encode())
        print("✓ index.html novo no servidor")

    print("\nFalta (regras da skill, seção 'Imóvel vendido ou retirado'):")
    print(f"  1. git checkout -b imovel-vendido-{cod} && git add -A site && git commit -m 'Imóvel {cod} vendido: sai do site' && push + PR + merge")
    if not a.remoto:
        print("  2. publicar site/index.html (da main) e o .htaccess da pasta; apagar a pasta no servidor "
              "(ou rodar de novo com --remoto e as credenciais no ambiente)")
    print("  3. hosting_cache_clear (scope all) no domínio")
    print(f"  4. conferir no ar: {DOMINIO} sem o cartão e {DOMINIO}{cod}/{bairro or '<bairro>'}/ redirecionando para a página inicial")
    print("  5. retirar o Reel do Instagram/TikTok e pausar a automação Zernio do post")


if __name__ == "__main__":
    main()
