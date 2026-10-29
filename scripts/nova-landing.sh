#!/usr/bin/env bash
# Cria a landing page de um apartamento novo a partir do modelo.
# Uso: scripts/nova-landing.sh <codigo-do-imovel> <bairro-sem-acento-minusculo>
set -euo pipefail
cod="${1:?codigo do imovel}"; bairro="${2:?bairro (ex.: moema)}"
raiz="$(cd "$(dirname "$0")/.." && pwd)"
dest="$raiz/site/$cod/$bairro"
[ -e "$dest" ] && { echo "já existe: $dest"; exit 1; }
mkdir -p "$dest/img/foto" "$dest/img/poster" "$dest/img/thumb" "$dest/video"
cp "$raiz/site/_modelo/index.html" "$raiz/site/_modelo/privacidade.html" "$raiz/site/_modelo/.htaccess" "$dest/"
perl -pi -e "s#/3066087/indianopolis/#/$cod/$bairro/#g; s#3066087#$cod#g" "$dest/index.html" "$dest/privacidade.html"   # perl: o sed -i do macOS e o do Linux não aceitam a mesma sintaxe
echo "criado: site/$cod/$bairro — agora troque os trechos marcados com TROCAR e coloque a mídia (ver site/_modelo/README.md)"
