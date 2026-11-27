#!/usr/bin/env python3
"""Link do QuintoAndar -> landing page pronta de marceloosawaimoveis.com.br.

Só biblioteca padrão + ffmpeg/ffprobe (Homebrew) + o script de IPTU do repositório. Sem Kling, sem créditos.

  landing.py prep  <link|codigo>   puxa a ficha, o IPTU e o bairro; baixa miniaturas; gera as folhas de contato
                                   e o rascunho _trabalho/<codigo>/imovel.json (campos "TROCAR" = julgamento)
  landing.py build <codigo>        lê o imovel.json curado, baixa as fotos escolhidas na proporção real (3:2, 4:3 ou 16:9),
                                   gera foto/poster/thumb/vídeo (pan digital com ffmpeg) e monta
                                   site/<codigo>/<bairro>/index.html
  landing.py check <codigo>        confere arquivos, tamanhos, TROCAR esquecido e IDs de cômodo

Detalhes e fluxo por lote: scripts/landing/README.md
"""
import argparse, html, json, math, os, re, shutil, struct, subprocess, sys, time, unicodedata, urllib.request, zlib
from concurrent.futures import ThreadPoolExecutor
from datetime import date

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
SITE = os.path.join(RAIZ, "site")
TRAB = os.path.join(RAIZ, "_trabalho")
IPTU_PY = os.path.abspath(os.path.join(RAIZ, "..", "iptu", "consulta_iptu.py"))
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"
IMG = "https://www.quintoandar.com.br/img/{}/{}"
MESES = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"]
VIDEO_SEG = 4          # duração de cada clipe
VIDEO_MAX = 1_500_000  # bytes por clipe
# O servidor de imagens do QuintoAndar devolve o tamanho pedido cortando pelo centro o que sobra (altura máxima 2160).
# Os anúncios vêm em 3:2, 4:3 e 16:9: a largura pedida é 2160 × proporção real da foto, senão ela chega cortada.
ASPECTOS = {"3:2": 3 / 2, "4:3": 4 / 3, "16:9": 16 / 9}
FFMPEG = shutil.which("ffmpeg") or "/opt/homebrew/bin/ffmpeg"
FFPROBE = shutil.which("ffprobe") or "/opt/homebrew/bin/ffprobe"


# ---------- utilidades ----------
def get(url, binario=False, tent=3, pausa=0):
    for i in range(tent):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept-Language": "pt-BR"})
            with urllib.request.urlopen(req, timeout=40) as r:
                d = r.read()
            return d if binario else d.decode("utf-8", "replace")
        except Exception as e:
            if i == tent - 1:
                raise
            if pausa:
                time.sleep(pausa * (i + 1))   # recuo crescente: o CDN responde 503 sob rajada
    return None


def baixar_foto(url, destino, tamanhos=("3240x2160", "1440x960"), tent=3, pausa=0):
    """Baixa a foto para um arquivo temporário e só então troca pelo destino. Uma falha (ex.: 503 do CDN) tenta o
    próximo tamanho e nunca deixa arquivo vazio ou parcial no cache. Um arquivo vazio de rodada anterior é refeito."""
    if os.path.exists(destino) and os.path.getsize(destino) > 0:
        return destino
    tmp, ultimo = destino + ".tmp", None
    for t in tamanhos:
        try:
            dados = get(IMG.format(t, url), True, tent, pausa)
            if not dados:
                raise ValueError("resposta vazia")
            with open(tmp, "wb") as fh:
                fh.write(dados)
            os.replace(tmp, destino)
            return destino
        except Exception as e:
            ultimo = e
            if os.path.exists(tmp):
                os.remove(tmp)
    raise RuntimeError(f"não consegui baixar {url} em {', '.join(tamanhos)}: {ultimo}")


def aspecto_do_texto(v):
    """foto_aspecto do imovel.json ("3:2", "4:3", "16:9" ou número como 1.333) -> largura/altura."""
    if isinstance(v, (int, float)) and not isinstance(v, bool) and v > 0:
        return float(v)
    m = re.fullmatch(r"\s*(\d+(?:[.,]\d+)?)\s*(?:[:x/]\s*(\d+(?:[.,]\d+)?)\s*)?", str(v))
    if m:
        a, b = float(m.group(1).replace(",", ".")), float((m.group(2) or "1").replace(",", "."))
        if a > 0 and b > 0:
            return a / b
    sys.exit(f'foto_aspecto inválido: {v!r} (use "3:2", "4:3", "16:9" ou um número como 1.5)')


def medir_aspecto(url):
    """Proporção real da foto, medida no quadro /img/xxl/ (1152x768), onde o servidor põe a foto inteira sobre uma
    moldura de cor lisa: faixas em cima e embaixo = foto mais larga que 3:2; nas laterais = mais estreita; sem faixa = 3:2.
    Só biblioteca padrão: o ffmpeg decodifica em cinza e a moldura é contada linha a linha a partir das bordas."""
    dados = get(IMG.format("xxl", url), True, 5, 1.5)
    pr = subprocess.run([FFPROBE, "-v", "error", "-show_entries", "stream=width,height", "-of", "csv=p=0", "-i", "pipe:0"],
                        input=dados, capture_output=True)
    w, h = (int(v) for v in pr.stdout.decode().strip().split(",")[:2])
    g = subprocess.run([FFMPEG, "-v", "error", "-i", "pipe:0", "-f", "rawvideo", "-pix_fmt", "gray", "pipe:1"],
                       input=dados, capture_output=True).stdout
    if len(g) != w * h:
        raise RuntimeError(f"quadro xxl ilegível para medir a proporção de {url}")
    linha = lambda y: g[y * w:(y + 1) * w]
    coluna = lambda x: g[x::w]
    lisa = lambda b: max(b) - min(b) <= 3

    def faixa(linhas, cor):   # linhas da borda para dentro que são só moldura (tolerando o ruído do JPEG na divisa)
        n = 0
        for b in linhas:
            if max(b) - cor > 24 or cor - min(b) > 24:
                break
            n += 1
        return n
    tb = lr = 0
    # moldura só conta se as duas bordas opostas forem lisas e da mesma cor; a foto vem centrada, então a faixa é a menor
    # das duas (uma área escura lisa na borda de um lado da foto não engana a medida)
    if lisa(linha(0)) and lisa(linha(h - 1)) and abs(g[0] - g[(h - 1) * w]) <= 3:
        tb = min(faixa(map(linha, range(h)), g[0]), faixa(map(linha, range(h - 1, -1, -1)), g[0]))
    if lisa(coluna(0)) and lisa(coluna(w - 1)) and abs(g[0] - g[w - 1]) <= 3:
        lr = min(faixa(map(coluna, range(w)), g[0]), faixa(map(coluna, range(w - 1, -1, -1)), g[0]))
    if 2 * tb >= h or 2 * lr >= w:
        raise RuntimeError(f"quadro xxl sem foto para medir a proporção de {url}")
    return (w - 2 * lr) / (h - 2 * tb)


def tamanhos_da_foto(aspecto):
    """Tamanhos a pedir: o grande (altura 2160) e a reserva para 503 (altura 960), na proporção da foto.
    3:2 -> 3240x2160 e 1440x960 (os de sempre); 4:3 -> 2880x2160; 16:9 -> 3840x2160. Fora dos três (ex.: foto em
    pé), a largura exata 2160 × proporção."""
    nome = min(ASPECTOS, key=lambda k: abs(math.log(aspecto / ASPECTOS[k])))
    if abs(math.log(aspecto / ASPECTOS[nome])) <= 0.03:
        a, rot = ASPECTOS[nome], nome
    else:
        a, rot = aspecto, f"{aspecto:.3f}".replace(".", ",") + " (fora de 3:2, 4:3 e 16:9)"
    return (f"{round(2160 * a)}x2160", f"{round(960 * a)}x960"), rot


def sem_acento(s):
    return "".join(c for c in unicodedata.normalize("NFD", s) if unicodedata.category(c) != "Mn")


def slug(s):
    return re.sub(r"[^a-z0-9]+", "-", sem_acento(s).lower()).strip("-")


def brl(v):
    return "{:,.0f}".format(v).replace(",", ".")


def brl_valor(v):
    """Condomínio e IPTU: com centavos só quando o valor guardado tem (2032.84 -> 2.032,84); inteiro sai como antes (1.800)."""
    c = round(float(v) * 100)
    if c % 100 == 0:
        return brl(v)
    return "{:,.2f}".format(c / 100).replace(",", "_").replace(".", ",").replace("_", ".")


def run(cmd, **kw):
    r = subprocess.run(cmd, capture_output=True, text=True, **kw)
    if r.returncode:
        sys.exit(f"erro em {cmd[0]}: {r.stderr[-600:]}")
    return r


def dims(path):
    o = run([FFPROBE, "-v", "error", "-show_entries", "stream=width,height", "-of", "csv=p=0", path]).stdout.strip()
    w, h = o.split(",")[:2]
    return int(w), int(h)


def pasta_trab(cod):
    return os.path.join(TRAB, str(cod))


# ---------- prep ----------
def extrair_ficha(alvo):
    m = re.search(r"/imovel/(\d+)", alvo) or re.fullmatch(r"\s*(\d{6,9})\s*", alvo)
    if not m:
        sys.exit("passe o link do QuintoAndar (…/imovel/<codigo>/comprar) ou o código do imóvel")
    url = f"https://www.quintoandar.com.br/imovel/{m.group(1)}/comprar"
    h = get(url)
    nd = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', h, re.S)
    if not nd:
        sys.exit("a página não trouxe os dados (anúncio fora do ar?)")
    hi = json.loads(nd.group(1))["props"]["pageProps"]["initialState"]["house"]["houseInfo"]
    if not hi.get("forSale") or hi.get("status") != "publicado":
        print(f"AVISO: status={hi.get('status')} forSale={hi.get('forSale')}: confira se o anúncio ainda está à venda")
    sim = lambda lst: [a["text"] for a in lst or [] if a.get("value") == "SIM" and a.get("text")]
    cond = hi.get("condominium") or {}
    ad = hi["address"]
    fotos = [{"sub": p.get("subtitle") or "", "url": p["url"], "origem": "apto"} for p in hi["photos"]]
    fotos += [{"sub": p.get("subtitle") or "", "url": p["url"], "origem": "condominio"} for p in cond.get("images", [])]
    for i, f in enumerate(fotos):
        f["i"] = i
    iptu12 = hi.get("iptu") or 0
    return {
        "codigo": str(hi["displayId"]), "url": url, "id_url": str(hi["id"]),
        "preco": hi.get("salePrice"), "condominio": hi.get("condoPrice"), "iptu_anuncio_12x": iptu12,
        "iptu_10x": int(round(iptu12 * 12 / 10 / 10.0) * 10) if iptu12 else None,
        "area": hi.get("area"), "quartos": hi.get("bedrooms"), "suites": hi.get("suites"),
        "banheiros": hi.get("bathrooms"), "vagas": hi.get("parkingSpaces"),
        "andar": hi.get("rangeFloor"), "tipo": hi.get("type"), "mobiliado": hi.get("hasFurniture"),
        "rua": ad.get("street"), "numero": cond.get("number") or "", "bairro_qa": ad.get("neighborhood"),
        "cep": ad.get("zipCode"), "lat": ad.get("lat"), "lng": ad.get("lng"),
        "condominio_nome": cond.get("name"), "perto_metro": hi.get("isNearSubway"),
        "itens_apto": sim(hi.get("amenities")),
        "itens_condominio": sim(hi.get("installations")) + sim((cond.get("features") or {}).get("installations")),
        "portaria": (cond.get("features") or {}).get("doorman"),
        "proximidades": [{"nome": p["name"], "tipo": p["type"]} for p in hi.get("placesNearby", [])],
        "descricao_anunciante": hi.get("remarks"),
        "publicado_em": hi.get("lastPublishedDate"),
        "fotos": fotos,
    }


def bairro_postal(f):
    cep = re.sub(r"\D", "", f.get("cep") or "")
    if len(cep) == 8:
        try:
            d = json.loads(get(f"https://viacep.com.br/ws/{cep}/json/"))
            if d.get("bairro"):
                return d["bairro"], d.get("logradouro")
        except Exception:
            pass
    return None, None


def consulta_iptu(f, apto=None):
    if not os.path.exists(IPTU_PY):
        return {"status": "SEM_SCRIPT"}
    cmd = [sys.executable, IPTU_PY, "buscar", "--rua", f["rua"], "--numero", str(f["numero"]), "--iptu-anuncio",
           str(f["iptu_anuncio_12x"]), "--area", str(f["area"]), "--bairro", f["bairro_qa"] or "", "--limite", "300", "--json"]
    if apto:
        cmd += ["--apto", str(apto)]   # sem o nº da unidade a checagem compara com outra unidade do prédio
    if f.get("cep"):
        cmd += ["--cep", str(f["cep"])]   # nome do anúncio x cadastro abreviado (PRSA, COMEN...): busca por CEP + número
    r = subprocess.run(cmd, capture_output=True, text=True)
    try:
        d = json.loads(r.stdout)
    except Exception:
        return {"status": "ERRO", "detalhe": (r.stderr or r.stdout)[-300:]}
    un = d.get("unidades") or []
    if not un:
        return {"status": "NAO_ENCONTRADO", "total_no_endereco": d.get("total_no_endereco")}
    # só unidades com o mesmo nº de vagas do anúncio ("AP 271 E 4VG"); depois, a de razão mais próxima de 1
    vg = [u for u in un if re.search(rf"E\s*{f['vagas']}\s*VG", u.get("complemento") or "")]
    melhor = min(vg or un, key=lambda u: abs(1 - (u.get("checagem", {}).get("razao_anuncio_sobre_teorico") or 99)))
    c = melhor.get("checagem", {})
    return {"status": c.get("status", "?"), "mensagem": c.get("mensagem"), "razao": c.get("razao_anuncio_sobre_teorico"),
            "apto_identificado": bool(d.get("apto_encontrado")), "parcela_teorica_10x": melhor.get("iptu_teorico_parcela_10x"),
            "sql": melhor.get("sql"), "complemento": melhor.get("complemento"), "encontrado_por": d.get("encontrado_por")}


def digito_png(texto, esc=5):
    """PNG 'etiqueta' com o número da foto (fonte 3x5 própria: o ffmpeg daqui não tem drawtext)."""
    F = {"0": "111101101101111", "1": "010110010010111", "2": "111001111100111", "3": "111001111001111", "4": "101101111001001",
         "5": "111100111001111", "6": "111100111101111", "7": "111001001001001", "8": "111101111101111", "9": "111101111001111"}
    pad = 2
    n = len(texto)
    w, h = (n * 4 + 1 + 2 * pad) * esc, (5 + 2 * pad) * esc
    linhas = []
    for y in range(h):
        row = bytearray([0])
        for x in range(w):
            gx, gy = x // esc - pad, y // esc - pad
            on = 0 <= gy < 5 and gx >= 0 and gx % 4 != 3 and gx // 4 < n and F[texto[gx // 4]][gy * 3 + gx % 4] == "1"
            row += bytes((255, 255, 255, 255)) if on else bytes((0, 0, 0, 215))
        linhas.append(bytes(row))
    raw = zlib.compress(b"".join(linhas))
    ch = lambda t, d: struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
    return b"\x89PNG\r\n\x1a\n" + ch(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 6, 0, 0, 0)) + ch(b"IDAT", raw) + ch(b"IEND", b"")


def folhas_de_contato(f, d):
    mini = os.path.join(d, "mini"); os.makedirs(mini, exist_ok=True)
    tiles = os.path.join(d, "_tiles"); shutil.rmtree(tiles, ignore_errors=True); os.makedirs(tiles)

    falhas = []

    def baixar(x):
        p = os.path.join(mini, f"{x['i']:03d}.jpg")
        marca = p[:-4] + ".cinza"
        if os.path.exists(p) and os.path.getsize(p) > 0 and not os.path.exists(marca):
            return
        if os.path.exists(marca):
            os.remove(marca); os.path.exists(p) and os.remove(p)
        try:   # 480x320; se o CDN insistir em 503, o 1152x768 (reduzido depois, nos tiles)
            baixar_foto(x["url"], p, ("480x320", "1152x768"), tent=5, pausa=1.5)
        except Exception as e:
            falhas.append((x["i"], str(e)[-120:]))
            run([FFMPEG, "-v", "error", "-y", "-f", "lavfi", "-i", "color=c=gray:s=480x320", "-frames:v", "1", p])   # quadro cinza
    with ThreadPoolExecutor(3) as ex:
        list(ex.map(baixar, f["fotos"]))
    if falhas:
        print(f"AVISO: {len(falhas)} miniatura(s) não baixaram e viraram quadro cinza (foto: " + ", ".join(str(i) for i, _ in sorted(falhas)) + "). Rode o prep de novo: ele tenta só essas outra vez.")
        # o cinza fica em mini/; marcado para o próximo prep refazer
        for i, _ in falhas:
            open(os.path.join(mini, f"{i:03d}.cinza"), "w").close()
    for x in f["fotos"]:
        b = os.path.join(tiles, f"b{x['i']}.png"); open(b, "wb").write(digito_png(str(x["i"])))
        run([FFMPEG, "-v", "error", "-y", "-i", os.path.join(mini, f"{x['i']:03d}.jpg"), "-i", b, "-filter_complex",
             "[0]scale=320:213:force_original_aspect_ratio=increase,crop=320:213[a];[a][1]overlay=4:4", "-frames:v", "1",
             os.path.join(tiles, f"t{x['i']:03d}.jpg")])
    por = 30
    folhas = []
    for k in range(0, len(f["fotos"]), por):
        sub = os.path.join(tiles, f"s{k // por}"); os.makedirs(sub)
        for j, x in enumerate(f["fotos"][k:k + por]):
            os.symlink(os.path.join(tiles, f"t{x['i']:03d}.jpg"), os.path.join(sub, f"{j:03d}.jpg"))
        out = os.path.join(d, f"folha{k // por + 1}.jpg")
        run([FFMPEG, "-v", "error", "-y", "-framerate", "1", "-i", os.path.join(sub, "%03d.jpg"), "-vf", "tile=6x5:padding=4:color=black",
             "-frames:v", "1", "-q:v", "4", out])
        folhas.append(out)
    shutil.rmtree(tiles, ignore_errors=True)
    return folhas


def rascunho(f, bairro, iptu):
    ficha_extra = []
    near = []
    for p in f["proximidades"]:
        if p["tipo"] == "ESTACAO_METRO_OU_TREM":
            near.append([f"Estação {p['nome']}", "TROCAR"])
            ficha_extra.append(["Metrô", p["nome"], "TROCAR"])
        elif p["tipo"] in ("SHOPPING_MALL", "PARK_OR_GREEN_AREA"):
            near.append([p["nome"], "nas proximidades"])
    grupos, ordem = {}, []
    for x in f["fotos"]:
        if x["sub"] not in grupos:
            grupos[x["sub"]] = x["i"]; ordem.append(x["sub"])
    comodos = [{"id": slug(s) or f"foto{grupos[s]}", "n": s or "TROCAR", "d": "TROCAR", "foto": grupos[s], "pan": "in"} for s in ordem]
    return {
        "codigo": f["codigo"], "bairro": bairro or f["bairro_qa"], "bairro_slug": slug(bairro or f["bairro_qa"]), "rua": f["rua"],
        "argumento": "TROCAR",       # fim do h1: "<Rua>, <argumento>"  (ex.: "a poucos passos do metrô")
        "resumo": "TROCAR",          # meta description: "…: <resumo>. Tour por cômodo…"  (ex.: "130 m², 2 suítes e 2 vagas, perto do metrô")
        "og_argumento": "TROCAR",    # og:description: "R$ X, <og_argumento>. Veja o tour…"
        "hero": comodos[0]["id"] if comodos else "TROCAR", "hero_alt": "TROCAR",
        "destaques": [{"icone": "sofa", "texto": "TROCAR"}],   # além de área/quartos/vagas (automáticos); ícones: ruler bed car metro sofa building pin
        "ficha_extra": ficha_extra,  # [rótulo, valor, nota]
        "suites_nota": "",
        "apartamento": f["itens_apto"],      # rascunho da ficha: editar/ordenar/remover o que não vale citar
        "condominio": f["itens_condominio"], # idem
        "regiao": near,              # [local, distância]
        "comodos": comodos,          # ordem do tour; "foto" = nº na folha de contato; pan: in|out|dir|esq
        "descartadas": [],
        "_conferir": {"iptu": iptu, "bairro_postal": bairro, "bairro_quintoandar": f["bairro_qa"], "andar": f["andar"],
                      "tipo": f["tipo"], "mobiliado": f["mobiliado"], "descricao_anunciante": f["descricao_anunciante"]},
    }


def cmd_prep(a):
    f = extrair_ficha(a.alvo)
    d = pasta_trab(f["codigo"]); os.makedirs(d, exist_ok=True)
    bairro, log = bairro_postal(f)
    if a.numero:
        f["numero"] = str(a.numero)   # o anúncio costuma vir sem o número da rua; o intake sempre o traz
    iptu = consulta_iptu(f, a.apto)
    if not f["numero"]:
        print("AVISO: o anúncio não traz o número da rua; passe --numero N (e --apto N) para o IPTU achar a unidade.")
    iptu["apto"] = str(a.apto) if a.apto else None
    if not a.apto:
        print('AVISO: sem --apto o IPTU é comparado com unidades parecidas do prédio, não com a do anúncio; passe --apto N se souber o número.')
    f["bairro_postal"], f["iptu_checagem"] = bairro, iptu
    json.dump(f, open(os.path.join(d, "ficha.json"), "w"), ensure_ascii=False, indent=1)
    folhas = folhas_de_contato(f, d)
    rj = os.path.join(d, "imovel.json")
    if os.path.exists(rj) and not a.refazer:
        print(f"imovel.json já existe (não sobrescrito; use --refazer): {rj}")
    else:
        json.dump(rascunho(f, bairro, iptu), open(rj, "w"), ensure_ascii=False, indent=1)
    print(f"\nImóvel {f['codigo']} · {f['rua']}, {f['numero']} · {bairro or f['bairro_qa']} (QuintoAndar: {f['bairro_qa']}) · CEP {f['cep']}")
    print(f"R$ {brl(f['preco'])} · cond. R$ {f['condominio']} · IPTU 10x R$ {f['iptu_10x']} · {f['area']} m² · {f['quartos']} qt/{f['suites']} suítes/{f['vagas']} vagas")
    print(f"IPTU: {iptu.get('status')} {iptu.get('mensagem') or ''}")
    print(f"{len(f['fotos'])} fotos ({sum(1 for x in f['fotos'] if x['origem']=='apto')} do apto) — folhas de contato:")
    for p in folhas:
        print("  ", p)
    print("Legendas por foto:")
    for x in f["fotos"]:
        print(f"  {x['i']}:{x['sub']}", end=";")
    print(f"\n\nPróximo: editar {rj} (campos TROCAR), depois: landing.py build {f['codigo']}")


# ---------- mídia ----------
def pan_filtro(ow, oh, modo, c0=None, c1=None):
    """Filtro ffmpeg do pan digital (resolução 2x do vídeo final, depois reduz: suaviza o passo de 1 px)."""
    S, T = 2, VIDEO_SEG
    W, H = 720 * S, 1280 * S
    z0, z1, a, b = {"in": (1.0, 1.14, .5, .5), "out": (1.14, 1.0, .5, .5), "dir": (1.1, 1.1, .3, .7), "esq": (1.1, 1.1, .7, .3)}.get(modo, (1.0, 1.14, .5, .5))
    if c0 is not None: a = c0
    if c1 is not None: b = c1
    base = max(W / ow, H / oh)
    p = f"(0.5-0.5*cos(PI*min(t/{T},1)))"      # aceleração e freio suaves
    z = f"({z0}+({z1}-{z0})*{p})"
    cx = f"({a}+({b}-{a})*{p})"
    return (f"scale=w='trunc({ow}*{base}*{z}/2)*2':h='trunc({oh}*{base}*{z}/2)*2':eval=frame:flags=bicubic,"
            f"crop={W}:{H}:x='max(0,min(iw-{W},iw*{cx}-{W}/2))':y='max(0,min(ih-{H},(ih-{H})/2))',"
            f"scale=720:1280:flags=lanczos,format=yuv420p")


def gerar_midia(c, f, dest, d, aspecto=None):
    x = f["fotos"][c["foto"]]
    orig = os.path.join(d, "orig", f"{c['foto']:03d}.jpg")
    os.makedirs(os.path.dirname(orig), exist_ok=True)
    if not (os.path.exists(orig) and os.path.getsize(orig) > 0):   # com o original no cache não mede nem baixa de novo
        # medida por foto: um anúncio mistura formatos (fotos do condomínio, foto em pé); custa 1 quadro xxl por foto escolhida
        pedido = c.get("foto_aspecto") or aspecto
        if pedido:
            a, origem = aspecto_do_texto(pedido), "imovel.json"
        else:
            try:
                a, origem = medir_aspecto(x["url"]), "medido no quadro xxl"
            except Exception as e:   # sem medida não baixa: pedir 3:2 às cegas é o corte silencioso que isto evita
                sys.exit(f"foto {c['foto']} ({c['id']}): não consegui medir a proporção no quadro xxl ({str(e)[-160:]}). "
                         f'Ponha "foto_aspecto": "3:2", "4:3" ou "16:9" no cômodo ou no imovel.json.')
        tamanhos, rot = tamanhos_da_foto(a)
        print(f"  foto {c['foto']}: {rot} ({origem}) -> {tamanhos[0]}")
        baixar_foto(x["url"], orig, tamanhos)
    ow, oh = dims(orig)
    id_ = c["id"]
    foto = os.path.join(dest, "img", "foto", f"{id_}.jpg")
    vf = "scale=1600:-2:flags=lanczos"
    if c.get("foto_crop"):   # recorte publicado na galeria e no visualizador (e na miniatura), independente de c0/c1
        cx, cy, cw, ch = (int(v) for v in c["foto_crop"])
        if cx + cw > ow or cy + ch > oh:
            sys.exit(f"comodos[{id_}].foto_crop {c['foto_crop']} passa da foto original {ow}x{oh} ({orig})")
        if cw < 1600:
            print(f"AVISO: comodos[{id_}].foto_crop tem {cw} px de largura; a foto publicada (1600 px) sai ampliada")
        vf = f"crop={cw}:{ch}:{cx}:{cy}," + vf
    run([FFMPEG, "-v", "error", "-y", "-i", orig, "-vf", vf, "-q:v", "4", foto])
    thumb = os.path.join(dest, "img", "thumb", f"{id_}.jpg")
    run([FFMPEG, "-v", "error", "-y", "-i", foto, "-vf", "scale=168:112:force_original_aspect_ratio=increase,crop=168:112", "-q:v", "5", thumb])
    vid = os.path.join(dest, "video", f"{id_}.mp4")
    for crf in (24, 27, 30, 33, 36):
        run([FFMPEG, "-v", "error", "-y", "-loop", "1", "-framerate", "30", "-i", orig, "-vf", pan_filtro(ow, oh, c.get("pan", "in"), c.get("c0"), c.get("c1")),
             "-t", str(VIDEO_SEG), "-r", "30", "-an", "-c:v", "libx264", "-preset", "slow", "-crf", str(crf), "-movflags", "+faststart", vid])
        if os.path.getsize(vid) <= VIDEO_MAX:
            break
    poster = os.path.join(dest, "img", "poster", f"{id_}.jpg")
    run([FFMPEG, "-v", "error", "-y", "-i", vid, "-frames:v", "1", "-q:v", "4", poster])
    return os.path.getsize(vid), dims(foto)


# ---------- HTML ----------
E = lambda s: html.escape(str(s), quote=False)
ICONE = lambda n: f'<svg aria-hidden="true"><use href="#i-{n}"/></svg>'
LI = lambda s: f"        <li>{E(s)}</li>"


def artigo(rua):
    return "no" if re.match(r"(Largo|Beco|Viaduto|Parque|Jardim)\b", rua) else "na"


def trocar_um(txt, padrao, novo, flags=re.S, n=1):
    r, k = re.subn(padrao, lambda m: novo, txt, count=n, flags=flags)
    if k != n:
        sys.exit(f"o modelo mudou: trecho não encontrado -> {padrao[:60]}")
    return r


def render_html(j, f):
    cod, bairro, bslug, rua = j["codigo"], j["bairro"], j["bairro_slug"], j["rua"]
    h = open(os.path.join(SITE, "_modelo", "index.html"), encoding="utf-8").read()
    h = h.replace("/3066087/indianopolis/", f"/{cod}/{bslug}/").replace("3066087", cod)
    if j.get("iptu_10x"):   # valor confirmado pelo usuário, tem prioridade sobre o do anúncio
        f = dict(f, iptu_10x=j["iptu_10x"])
    if j.get("condominio_valor"):   # idem para o condomínio corrigido pelo usuário
        f = dict(f, condominio=j["condominio_valor"])
    if j.get("preco_valor"):   # preço passado pelo usuário (ex.: direto do proprietário), vale mais que o do anúncio
        f = dict(f, preco=j["preco_valor"])
    preco = f"R$ {brl(f['preco'])}"
    hoje = date.today()
    area = f["area"]
    area_s = f"{area:g}".replace(".", ",")
    quartos = f["quartos"]; suites = f["suites"]; vagas = f["vagas"]
    q_txt = (f"{suites} suíte" + ("s" if suites > 1 else "")) if suites == quartos else f"{quartos} quarto{'s' if quartos > 1 else ''}" + (f", {suites} suíte{'s' if suites > 1 else ''}" if suites else "")
    v_txt = f"{vagas} vaga" + ("s" if vagas != 1 else "")
    resumo = j["resumo"]
    hero = j["hero"]
    # cabeçalho
    h = trocar_um(h, r"<title>.*?</title>", f"<title>Apartamento à venda {artigo(rua)} {E(rua)}, {E(bairro)} · Marcelo Osawa</title>")
    h = trocar_um(h, r'<meta name="description" content=".*?">', f'<meta name="description" content="{html.escape("Apartamento à venda em " + bairro + ", São Paulo: " + resumo + ". Tour por cômodo e agendamento de visita pelo WhatsApp.")}">')
    h = trocar_um(h, r'<meta property="og:title" content=".*?">', f'<meta property="og:title" content="{html.escape(f"{rua}, {bairro}: {area_s} m², {q_txt} e {v_txt}")}">')
    h = trocar_um(h, r'<meta property="og:description" content=".*?">', f'<meta property="og:description" content="{html.escape(f"{preco}, {j["og_argumento"]}. Veja o tour por cômodo e agende uma visita pelo WhatsApp.")}">')
    h = trocar_um(h, r'<meta property="og:image:alt" content=".*?">', f'<meta property="og:image:alt" content="{html.escape(j["hero_alt"])}">')
    h = trocar_um(h, r'<meta property="og:image:height" content=".*?">', f'<meta property="og:image:height" content="{j["_hero_altura"]}">')
    h = h.replace("img/foto/living.jpg", f"img/foto/{hero}.jpg")
    # hero
    dest_hl = [("ruler", f"{area_s} m²"), ("bed", q_txt), ("car", v_txt)] + [(d["icone"], d["texto"]) for d in j["destaques"]]
    hl = "\n".join(f"      <li>{ICONE(i)}{E(t)}</li>" for i, t in dest_hl)
    cond_s = f"      <span>Condomínio R$ {brl_valor(f['condominio'])}</span>\n" if f.get("condominio") else ""
    iptu_s = f"      <span>IPTU 10x R$ {brl_valor(f['iptu_10x'])}</span>\n" if f.get("iptu_10x") else ""
    bloco = (f'<p class="bairro">{ICONE("pin")}{E(bairro)}</p>\n    <h1>{E(rua)}, {E(j["argumento"])}</h1>\n'
             f'    <ul class="highs" aria-label="Destaques">\n{hl}\n    </ul>\n    <div class="price">\n      <strong>{preco}</strong>\n{cond_s}{iptu_s}'
             f'      <small>Valor anunciado em {MESES[hoje.month - 1]}/{hoje.year}, sujeito a alteração.</small>\n    </div>\n    ')
    h = trocar_um(h, r'<p class="bairro">.*?(?=<div class="actions">)', bloco)
    # ficha técnica
    fatos = [("Área", f"{area_s} m²", "")]   # "privativa" só se a ficha disser: j["area_rotulo"] = "Área privativa"
    if j.get("area_rotulo"):
        fatos[0] = (j["area_rotulo"], fatos[0][1], "")
    if quartos:
        fatos.append(("Quartos", str(quartos), ""))
    if suites:
        fatos.append(("Suítes", str(suites), j.get("suites_nota", "")))
    if f.get("banheiros"):
        fatos.append(("Banheiros", str(f["banheiros"]), ""))
    # observação do proprietário sobre as vagas (ex.: "fixas"): nota sob o número, como a do condomínio ("por mês")
    fatos.append(("Vagas de garagem", str(vagas), (j.get("vagas_obs") or "").strip()))
    an = f.get("andar") or {}
    if j.get("andar_texto"):   # andar da unidade, informado por quem conhece (ex.: "12º")
        fatos.append(("Andar", j["andar_texto"], ""))
    elif an.get("min") and an["min"] == an.get("max"):
        fatos.append(("Andar", f"{an['min']}º", ""))
    # faixa de andares do anúncio (ex.: 24–27) é do prédio, não da unidade: não entra na página sem "andar_texto"
    fatos += [tuple(x) for x in j.get("ficha_extra", [])]
    if f.get("condominio"): fatos.append(("Condomínio", f"R$ {brl_valor(f['condominio'])}", "por mês"))
    if f.get("iptu_10x"): fatos.append(("IPTU", f"10x R$ {brl_valor(f['iptu_10x'])}", ""))
    dl = "\n".join(f"        <div><dt>{E(r)}</dt><dd>{E(v)}" + (f"<small>{E(n)}</small>" if n else "") + "</dd></div>" for r, v, n in fatos)
    h = trocar_um(h, r'<dl class="facts">.*?</dl>', f'<dl class="facts">\n{dl}\n      </dl>')
    for hid, chave in (("h-apto", "apartamento"), ("h-cond", "condominio")):
        pad = rf'(<h2 id="{hid}">.*?</h2>\s*<ul class="list">).*?(</ul>)'
        novo = "\n".join(LI(s) for s in j[chave])
        h, k = re.subn(pad, lambda m: f"{m.group(1)}\n{novo}\n      {m.group(2)}", h, count=1, flags=re.S)
        if k != 1: sys.exit(f"modelo mudou: {hid}")
    near = "\n".join(f"        <li><span>{E(a)}</span><span>{E(b)}</span></li>" for a, b in j["regiao"])
    h = trocar_um(h, r'<ul class="near">.*?</ul>', f'<ul class="near">\n{near}\n      </ul>')
    from urllib.parse import quote
    q = quote(f"{rua}, {bairro}, São Paulo", safe="")
    h = re.sub(r'(<a class="maplink" href=")[^"]*(")', lambda m: f'{m.group(1)}https://www.google.com/maps/search/?api=1&amp;query={q}{m.group(2)}', h, count=1)
    h = re.sub(r'(Ver no Google Maps<small>).*?(</small>)', lambda m: f"{m.group(1)}{E(rua)}, {E(bairro)}{m.group(2)}", h, count=1)
    h = re.sub(r"titulo: '[^']*'", lambda m: "titulo: '" + f"{rua}, {bairro}".replace("'", "\\'") + "'", h, count=1)
    linhas = []
    for c in j["comodos"]:
        linhas.append("  {id:%s, n:%s, d:%s}" % tuple(json.dumps(c[k], ensure_ascii=False) for k in ("id", "n", "d")))
    h = trocar_um(h, r"const ROOMS = \[.*?\n\];", "const ROOMS = [\n" + ",\n".join(linhas) + "\n];")
    return h


# ---------- build / check ----------
def carregar(cod):
    d = pasta_trab(cod)
    try:
        return d, json.load(open(os.path.join(d, "imovel.json"))), json.load(open(os.path.join(d, "ficha.json")))
    except FileNotFoundError:
        sys.exit(f"rode antes: landing.py prep {cod}")


def pendencias(j):
    achou = []
    def varre(o, caminho=""):
        if isinstance(o, str) and "TROCAR" in o:
            achou.append(caminho)
        elif isinstance(o, dict):
            for k, v in o.items():
                if not k.startswith("_"): varre(v, f"{caminho}.{k}")
        elif isinstance(o, list):
            for i, v in enumerate(o): varre(v, f"{caminho}[{i}]")
    varre(j)
    return achou


# palavras que só se leem na foto (o anúncio não as confirma); regra 14a da skill
PALAVRAS_DE_FOTO = [r"envidra\w+", r"cooktop", r"cuba esculpida", r"saída para (a )?varanda", r"saida para (a )?varanda",
                    r"ar[- ]condicionado", r"coifa", r"nichos?", r"painel de madeira", r"lareira", r"pé[- ]direito duplo"]


def palavras_de_foto(j):
    """Lista curta de termos nos campos d dos cômodos e hero_alt que só a foto sustenta. Só avisa."""
    achou = []
    campos = [("hero_alt", j.get("hero_alt") or "")] + [(f"comodos[{c['id']}].d", c.get("d") or "") for c in j.get("comodos", [])]
    for caminho, txt in campos:
        for pad in PALAVRAS_DE_FOTO:
            m = re.search(pad, txt, re.I)
            if m: achou.append((caminho, m.group(0)))
    return achou


def cmd_build(a):
    d, j, f = carregar(a.codigo)
    p = pendencias(j)
    if p:
        sys.exit("ainda há campos TROCAR no imovel.json:\n  " + "\n  ".join(p))
    ids = [c["id"] for c in j["comodos"]]
    if len(set(ids)) != len(ids) or j["hero"] not in ids:
        sys.exit("ids de cômodo repetidos ou hero fora da lista de cômodos")
    for c in j["comodos"]:   # campos opcionais conferidos antes de gerar qualquer coisa
        fc = c.get("foto_crop")
        if fc is not None and not (isinstance(fc, list) and len(fc) == 4 and all(isinstance(v, (int, float)) and not isinstance(v, bool) and v >= 0 for v in fc)
                                   and fc[2] >= 1 and fc[3] >= 1):
            sys.exit(f"comodos[{c['id']}].foto_crop deve ser [x, y, largura, altura] em pixels da foto original: {fc!r}")
        if c.get("foto_aspecto"):
            aspecto_do_texto(c["foto_aspecto"])
    if j.get("foto_aspecto"):
        aspecto_do_texto(j["foto_aspecto"])
    raiz = os.path.abspath(a.saida) if a.saida else SITE
    dest = os.path.join(raiz, j["codigo"], j["bairro_slug"])
    marca = os.path.join(dest, ".gerado-por-landing")
    if os.path.exists(dest) and not os.path.exists(marca) and not a.forcar:
        sys.exit(f"{dest} já existe e não foi gerada por este script (landing antiga). Use --forcar só se quiser sobrescrever.")
    if not os.path.exists(dest):
        if a.saida:   # mesmo que o nova-landing.sh, mas em outra raiz (testes)
            os.makedirs(dest)
            for n in ("index.html", "privacidade.html", ".htaccess"):
                t = open(os.path.join(SITE, "_modelo", n), encoding="utf-8").read()
                open(os.path.join(dest, n), "w", encoding="utf-8").write(t.replace("/3066087/indianopolis/", f"/{j['codigo']}/{j['bairro_slug']}/").replace("3066087", j["codigo"]))
        else:
            run(["bash", os.path.join(RAIZ, "scripts", "nova-landing.sh"), j["codigo"], j["bairro_slug"]])
    open(marca, "w").write("gerado por scripts/landing/landing.py\n")
    for sub in ("img/foto", "img/poster", "img/thumb", "video"):
        os.makedirs(os.path.join(dest, sub), exist_ok=True)
    only = set(a.so.split(",")) if a.so else None
    with ThreadPoolExecutor(3) as ex:
        res = list(ex.map(lambda c: (c["id"], gerar_midia(c, f, dest, d, j.get("foto_aspecto"))) if (not only or c["id"] in only) else None, j["comodos"]))
    for r in res:
        if r: print(f"  {r[0]:<16} vídeo {r[1][0] / 1024:.0f} KB · foto {r[1][1][0]}x{r[1][1][1]}")
    hero_dims = dims(os.path.join(dest, "img", "foto", f"{j['hero']}.jpg"))
    j["_hero_altura"] = hero_dims[1]
    open(os.path.join(dest, "index.html"), "w", encoding="utf-8").write(render_html(j, f))
    print(f"\npronto: {dest}")
    cmd_check(argparse.Namespace(codigo=a.codigo, saida=a.saida))


def cmd_check(a):
    d, j, f = carregar(a.codigo)
    dest = os.path.join(os.path.abspath(getattr(a, "saida", None) or SITE), j["codigo"], j["bairro_slug"])
    erros = [f"TROCAR em {p}" for p in pendencias(j)]
    for c in j["comodos"]:
        for sub, ext in (("img/foto", "jpg"), ("img/poster", "jpg"), ("img/thumb", "jpg"), ("video", "mp4")):
            p = os.path.join(dest, sub, f"{c['id']}.{ext}")
            if not os.path.exists(p): erros.append(f"falta {sub}/{c['id']}.{ext}")
            elif sub == "video" and os.path.getsize(p) > VIDEO_MAX: erros.append(f"{p} passa de 1,5 MB")
    ix = open(os.path.join(dest, "index.html"), encoding="utf-8").read() if os.path.exists(os.path.join(dest, "index.html")) else ""
    sem_com = re.sub(r"/\*.*?\*/", "", re.sub(r"<!--.*?-->", "", ix, flags=re.S), flags=re.S)
    # só strings que podem vir apenas do modelo (a palavra "Eucaliptos" sozinha pode ser o metrô do próprio imóvel)
    proprio = j["bairro"] + j["rua"] + j["codigo"] + j["bairro_slug"]
    for ruim in ("TROCAR", "3066087", "Avenida dos Eucaliptos", "Avenida%20dos%20Eucaliptos", "indianopolis", "Indianópolis"):
        if ruim in sem_com and ruim not in proprio:
            erros.append(f"sobrou do modelo: {ruim}")
    if j.get("iptu_10x"):
        print(f"IPTU confirmado pelo usuário (iptu_10x R$ {brl_valor(j['iptu_10x'])})")
    else:
        ic = f.get("iptu_checagem") or {}
        if not ic:
            erros.append("ficha.json sem iptu_checagem: rode prep --numero N --apto N (ou ponha iptu_10x no imovel.json)")
        elif ic.get("status") != "OK":
            erros.append(f"IPTU não confirmado (status {ic.get('status')}): conferir antes de publicar")
        elif not f.get("numero"):
            erros.append("ficha.json sem numero da rua: rode prep --numero N")
    avisos = palavras_de_foto(j)
    if avisos:
        print("AVISO (não bloqueia): palavras que só se leem na foto; a legenda segue a ficha (regra 14a):")
        for caminho, w in avisos:
            print(f"  {caminho}: {w}")
    print("CHECK:", "tudo certo" if not erros else "\n  ".join(["PENDÊNCIAS:"] + erros))
    print(f"ver: cd {os.path.dirname(os.path.dirname(dest))} && python3 -m http.server 8000  ->  http://localhost:8000/{j['codigo']}/{j['bairro_slug']}/")


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sp = ap.add_subparsers(dest="cmd", required=True)
    p = sp.add_parser("prep"); p.add_argument("alvo"); p.add_argument("--refazer", action="store_true"); p.add_argument("--apto", help="número do apartamento (para o IPTU identificar a unidade certa)"); p.add_argument("--numero", help="número da rua, quando o anúncio vem sem")
    p = sp.add_parser("build"); p.add_argument("codigo"); p.add_argument("--so", help="ids de cômodo (vírgula) para regerar só esses"); p.add_argument("--saida", help="raiz alternativa no lugar de site/ (testes)"); p.add_argument("--forcar", action="store_true")
    p = sp.add_parser("check"); p.add_argument("codigo"); p.add_argument("--saida")
    a = ap.parse_args()
    {"prep": cmd_prep, "build": cmd_build, "check": cmd_check}[a.cmd](a)
