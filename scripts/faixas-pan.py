#!/usr/bin/env python3
"""Grava a faixa que a janela de cada pan cobre, em 1920 px de altura, para abrir em tela cheia ANTES de renderizar.

  faixas-pan.py landing <codigo>                       um arquivo por cômodo do imovel.json (c0/c1 e pan da landing)
  faixas-pan.py pan <foto.jpg> <c0> <c1> [-o DIR]      uma faixa avulsa (pans do Reel); c0/c1 = posição 0-1 da janela
  faixas-pan.py lote <pasta> NOME:X0:X1 ... [-o DIR]   várias faixas do Reel de uma vez, com os mesmos X0/X1 (px da base)
                                                       que o pan.sh recebe; NOME = foto da pasta sem extensão (ex. F03).
                                                       DIR padrão: <pasta>/../_faixas, onde o pan.sh procura

No lote, cada foto usa a própria base L = round(1920*largura/altura) (3:2 2880, 4:3 2560, 16:9 3413) e
c = (X+540)/L arredondado a 6 casas; nome e conteúdo saem iguais aos do `pan` com esses c0/c1.

A faixa vai do início ao fim do movimento (união das janelas de c0 e c1), com a proporção 9:16 do vídeo.
Saída: _trabalho/<codigo>/faixas/<id>.jpg (landing) ou o DIR dado (pan, lote). Só biblioteca padrão + ffmpeg.
Termina listando os arquivos: abra cada um em tela cheia (não em miniatura) e procure fotos de gente,
placas, pôsteres, marcas e objetos pessoais; se houver, retoque (delogo no recorte 1:1) ou troque a janela.
"""
import argparse, json, os, shutil, subprocess, sys

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
FFMPEG = shutil.which("ffmpeg") or "/opt/homebrew/bin/ffmpeg"
FFPROBE = shutil.which("ffprobe") or "/opt/homebrew/bin/ffprobe"
ALTURA = 1920
JANELAS = {"in": (.5, .5), "out": (.5, .5), "dir": (.3, .7), "esq": (.7, .3)}   # mesmos valores do pan_filtro


def dims(p):
    o = subprocess.run([FFPROBE, "-v", "error", "-show_entries", "stream=width,height", "-of", "csv=p=0", p],
                       capture_output=True, text=True, check=True).stdout.strip().split(",")
    return int(o[0]), int(o[1])


def faixa(foto, c0, c1, saida):
    ow, oh = dims(foto)
    w_jan = ALTURA * 9 / 16                      # largura da janela 9:16 depois de escalar a altura para 1920
    w_tot = ow * ALTURA / oh
    x0 = max(0, min(w_tot - w_jan, w_tot * min(c0, c1) - w_jan / 2))
    x1 = max(0, min(w_tot - w_jan, w_tot * max(c0, c1) - w_jan / 2)) + w_jan
    larg = int(round(min(w_tot, x1) - x0))
    larg -= larg % 2
    os.makedirs(os.path.dirname(saida), exist_ok=True)
    r = subprocess.run([FFMPEG, "-v", "error", "-y", "-i", foto, "-vf", f"scale=-2:{ALTURA},crop={larg}:{ALTURA}:{int(x0)}:0",
                        "-frames:v", "1", "-q:v", "2", saida], capture_output=True, text=True)
    if r.returncode:
        sys.exit(f"ffmpeg falhou em {foto}: {r.stderr[-300:]}")
    return saida


def nome_faixa(foto, c0, c1, saida):
    base = os.path.splitext(os.path.basename(foto))[0]
    return os.path.join(saida, f"faixa_{base}_{c0:g}-{c1:g}.jpg")


EXTS = (".jpg", ".jpeg", ".png", ".webp")


def lote(pasta, specs, saida):
    """Converte NOME:X0:X1 (px da base, como no pan.sh) em c0/c1 com a base L de cada foto e grava as faixas."""
    if not os.path.isdir(pasta):
        sys.exit(f"pasta não encontrada: {pasta}")
    arquivos = sorted(os.listdir(pasta))
    trabalhos, erros = [], []
    for spec in specs:
        partes = spec.rsplit(":", 2)
        if len(partes) != 3:
            erros.append(f"{spec}: use NOME:X0:X1 (ex. F03:200:1740)"); continue
        nome, sx0, sx1 = partes
        try:
            x0, x1 = int(sx0), int(sx1)
        except ValueError:
            erros.append(f"{spec}: X0 e X1 devem ser inteiros (px da base)"); continue
        if os.path.splitext(nome)[1].lower() in EXTS and nome in arquivos:
            achados = [nome]
        else:
            achados = [f for f in arquivos if os.path.splitext(f)[0] == nome and os.path.splitext(f)[1].lower() in EXTS]
        if len(achados) != 1:
            erros.append(f"{spec}: {'nenhuma foto' if not achados else 'mais de uma foto (' + ', '.join(achados) + ')'}"
                         f" chamada {nome} em {pasta}"); continue
        foto = os.path.join(pasta, achados[0])
        w, h = dims(foto)
        L = round(ALTURA * w / h)
        if not (0 <= min(x0, x1) and max(x0, x1) <= L - 1080):
            erros.append(f"{spec}: curso fora da foto, permitido 0..{L - 1080} (base {L}x{ALTURA} da foto {w}x{h})"); continue
        c0, c1 = round((x0 + 540) / L, 6), round((x1 + 540) / L, 6)
        trabalhos.append((foto, x0, x1, L, c0, c1))
    if erros:   # nada é gravado se algum spec estiver errado
        sys.exit("lote recusado:\n  " + "\n  ".join(erros))
    feitos = []
    for foto, x0, x1, L, c0, c1 in trabalhos:
        print(f"  {os.path.basename(foto)} {x0}->{x1} (base L={L}, c {c0:g}-{c1:g})")
        feitos.append(faixa(foto, c0, c1, nome_faixa(foto, c0, c1, saida)))
    return feitos


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sp = ap.add_subparsers(dest="cmd", required=True)
    l = sp.add_parser("landing"); l.add_argument("codigo")
    p = sp.add_parser("pan"); p.add_argument("foto"); p.add_argument("c0", type=float); p.add_argument("c1", type=float); p.add_argument("-o", "--saida", default=".")
    t = sp.add_parser("lote", help="várias faixas: NOME:X0:X1 em px da base, como no pan.sh")
    t.add_argument("pasta"); t.add_argument("specs", nargs="+", metavar="NOME:X0:X1"); t.add_argument("-o", "--saida")
    a = ap.parse_args()
    feitos = []
    if a.cmd == "landing":
        d = os.path.join(RAIZ, "_trabalho", str(a.codigo))
        j = json.load(open(os.path.join(d, "imovel.json")))
        for c in j["comodos"]:
            orig = os.path.join(d, "orig", f"{c['foto']:03d}.jpg")
            if not os.path.exists(orig):
                print(f"  falta {orig} (rode landing.py build antes, ou baixe a foto): {c['id']} ignorado"); continue
            a0, b0 = JANELAS.get(c.get("pan", "in"), (.5, .5))
            feitos.append(faixa(orig, c.get("c0", a0), c.get("c1", b0), os.path.join(d, "faixas", f"{c['id']}.jpg")))
    elif a.cmd == "lote":
        saida = a.saida or os.path.join(os.path.dirname(os.path.abspath(a.pasta)), "_faixas")
        feitos = lote(a.pasta, a.specs, saida)
    else:
        feitos.append(faixa(a.foto, a.c0, a.c1, nome_faixa(a.foto, a.c0, a.c1, a.saida)))
    print(f"{len(feitos)} faixa(s) em {ALTURA} px de altura. Abra cada uma em TELA CHEIA antes de renderizar:")
    for f in feitos:
        print("  ", f)


if __name__ == "__main__":
    main()
