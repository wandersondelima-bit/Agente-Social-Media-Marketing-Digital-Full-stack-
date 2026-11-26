#!/usr/bin/env python3
"""Gera os ícones de aplicativo do CRM (app/assets/) a partir do anel com o M da marca.

Mesmo desenho de app/assets/favicon.svg (viewBox 48), em fundo cheio #141719: o iPhone e o Android
recortam o ícone em quadrado arredondado ou círculo, então o fundo vai até a borda e o anel fica
dentro da zona segura (raio de 40% do lado).

Uso: python3 scripts/crm/icones.py     (precisa do Pillow)
"""
from pathlib import Path
from PIL import Image, ImageDraw

FUNDO, OURO = (20, 23, 25), (205, 173, 110)
ESCALA = 0.85          # o anel do favicon (r 19,5 de 48) reduzido para caber na zona segura
SUPER = 4              # desenha 4x maior e reduz, para a borda ficar lisa
DESTINO = Path(__file__).resolve().parents[2] / 'app' / 'assets'
M = [(15.5, 31.5), (15.5, 17), (24, 27.3), (32.5, 17), (32.5, 31.5)]


def icone(lado: int) -> Image.Image:
    n = lado * SUPER
    k = n / 48 * ESCALA
    p = lambda x, y: (n / 2 + (x - 24) * k, n / 2 + (y - 24) * k)
    img = Image.new('RGB', (n, n), FUNDO)
    d = ImageDraw.Draw(img)
    # anel: traço de 2 centrado no raio 19,5
    cx, cy = p(24, 24)
    for r, cor in ((20.5, OURO), (18.5, FUNDO)):
        d.ellipse((cx - r * k, cy - r * k, cx + r * k, cy + r * k), fill=cor)
    # M: traço de 2,4 com pontas e junções redondas
    t = 2.4 * k
    pts = [p(*q) for q in M]
    d.line(pts, fill=OURO, width=round(t))
    for x, y in pts:
        d.ellipse((x - t / 2, y - t / 2, x + t / 2, y + t / 2), fill=OURO)
    return img.resize((lado, lado), Image.LANCZOS)


if __name__ == '__main__':
    for nome, lado in (('apple-touch-icon.png', 180), ('icone-192.png', 192), ('icone-512.png', 512)):
        icone(lado).save(DESTINO / nome, optimize=True)
        print('gerado', DESTINO / nome)
