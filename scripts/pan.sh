#!/bin/bash
# Pan digital do Reel: janela 9:16 desliza sobre a foto horizontal com easing cosseno + zoom suave 1,00 -> 1,07,
# 6 s, 30 fps, 1080x1920, sem áudio. Substitui as cópias por imóvel (pan32.sh, pan43.sh, pan169.sh).
#
# uso: pan.sh FOTO X0 X1 SAIDA [--faixas DIR]
#   X0/X1 = borda esquerda da janela no início/fim, em px da BASE (foto escalada para 1920 de altura).
#   A base L = round(1920*largura/altura) sai da própria foto: 3:2 -> 2880, 4:3 -> 2560, 16:9 -> 3413.
#   Curso permitido 0..L-1080 (a janela tem 1080 px de largura na base).
#
# Barreira (obs. 0036): só renderiza se já existir a faixa de faixas-pan.py que cobre esta foto e este curso
# (c = (X+540)/L) em <pasta do imóvel>/_faixas, ou em --faixas DIR. Sem ela sai com código 2 e mostra o comando.
# Várias faixas de uma vez: faixas-pan.py lote <pasta das fotos> F03:200:1740 F09:600:1500
# Códigos de saída: 0 ok, 1 argumento/curso inválido, 2 falta a faixa.
set -e
[ -x /opt/homebrew/bin/brew ] && eval "$(/opt/homebrew/bin/brew shellenv)"

AQUI=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
uso() { echo "uso: pan.sh FOTO X0 X1 SAIDA [--faixas DIR]" >&2; exit 1; }

POS=(); FAIXAS=""
while [ $# -gt 0 ]; do
  case "$1" in
    --faixas) [ $# -ge 2 ] || uso; FAIXAS="$2"; shift 2 ;;
    --faixas=*) FAIXAS="${1#--faixas=}"; shift ;;
    -h|--help) sed -n '2,13p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) POS+=("$1"); shift ;;
  esac
done
[ ${#POS[@]} -eq 4 ] || uso
FOTO="${POS[0]}"; X0="${POS[1]}"; X1="${POS[2]}"; OUT="${POS[3]}"; T=6; Z=0.07; E="(1-cos(PI*t/$T))/2"

[ -f "$FOTO" ] || { echo "foto não encontrada: $FOTO" >&2; exit 1; }
[[ "$X0" =~ ^-?[0-9]+$ && "$X1" =~ ^-?[0-9]+$ ]] || { echo "X0 e X1 devem ser inteiros (px da base): '$X0' '$X1'" >&2; exit 1; }

# tamanho da foto: PIL se houver, senão ffprobe
WH=$(python3 -c 'import sys; from PIL import Image; print("%dx%d" % Image.open(sys.argv[1]).size)' "$FOTO" 2>/dev/null) ||
  WH=$(ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0:s=x "$FOTO")
W=${WH%x*}; H=${WH#*x}
[[ "$W" =~ ^[0-9]+$ && "$H" =~ ^[0-9]+$ ]] || { echo "não consegui ler o tamanho de $FOTO ($WH)" >&2; exit 1; }
L=$(python3 -c "print(round(1920*$W/$H))")
MAX=$(( L - 1080 ))
[ $MAX -ge 0 ] || { echo "foto ${W}x${H} estreita demais para pan horizontal (base ${L}x1920 < 1080)" >&2; exit 1; }
lo=$(( X0<X1 ? X0 : X1 )); hi=$(( X0>X1 ? X0 : X1 ))
[ $lo -ge 0 ] && [ $hi -le $MAX ] || { echo "curso fora da foto: $X0 -> $X1, permitido 0..$MAX (base ${L}x1920 da foto ${W}x${H})" >&2; exit 1; }

# barreira: faixa_<base>_<c0>-<c1>.jpg cobrindo [min c, max c] (tolerância de meio px da base)
nome=$(basename "$FOTO"); base="${nome%.*}"
[ -n "$FAIXAS" ] || FAIXAS="$(cd "$(dirname "$FOTO")/.." && pwd)/_faixas"
read -r c0 c1 < <(python3 -c "print(round(($X0+540)/$L,6), round(($X1+540)/$L,6))")
ok=$(python3 - "$FAIXAS" "$base" "$X0" "$X1" "$L" <<'PY' || true
import os, re, sys
d, base = sys.argv[1], sys.argv[2]
x0, x1, L = int(sys.argv[3]), int(sys.argv[4]), int(sys.argv[5])
lo, hi = sorted([(x0 + 540) / L, (x1 + 540) / L])
tol = 0.5 / L
pat = re.compile("faixa_" + re.escape(base) + r"_([0-9.]+)-([0-9.]+)\.jpg")
for n in (sorted(os.listdir(d)) if os.path.isdir(d) else []):
    m = pat.fullmatch(n)
    if not m:
        continue
    try:
        a, b = sorted(float(g) for g in m.groups())
    except ValueError:
        continue
    if a <= lo + tol and b >= hi - tol:
        print(n)
        break
PY
)
if [ -z "$ok" ]; then
  echo "SEM FAIXA para $base $X0->$X1 (base L=$L, c $c0-$c1) em $FAIXAS" >&2
  echo "Gere a faixa, abra em TELA CHEIA e só então renderize:" >&2
  echo "  python3 \"$AQUI/faixas-pan.py\" pan \"$FOTO\" $c0 $c1 -o \"$FAIXAS\"" >&2
  exit 2
fi
echo "base ${L}x1920 (foto ${W}x${H}), curso $X0 -> $X1 de 0..$MAX, faixa conferida: $FAIXAS/$ok"

ffmpeg -loglevel error -y -loop 1 -framerate 30 -i "$FOTO" -t $T \
 -vf "scale=w='trunc($((2*L))*(1+$Z*$E)/2)*2':h='trunc(3840*(1+$Z*$E)/2)*2':eval=frame:flags=lanczos,crop=2160:3840:x='2*(1+$Z*$E)*(($X0)+(($X1)-($X0))*$E+540)-1080':y='(ih-3840)/2',scale=1080:1920:flags=lanczos,format=yuv420p" \
 -c:v libx264 -crf 16 -preset slow -r 30 -an "$OUT"
echo "ok $OUT"
