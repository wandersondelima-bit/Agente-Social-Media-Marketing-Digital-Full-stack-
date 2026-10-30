# Link do QuintoAndar → landing page

`scripts/landing/landing.py` (só Python padrão + ffmpeg + `../iptu/consulta_iptu.py`; sem Kling, sem créditos).
Rascunhos ficam em `_trabalho/<codigo>/` (fora do git). A landing sai em `site/<codigo>/<bairro>/`.

## Por imóvel (3 comandos + 1 edição)

```
python3 scripts/landing/landing.py prep  <link ou código>   # ficha, IPTU, bairro pelo CEP, folhas de contato, rascunho
# ← Claude: abre as folhas (_trabalho/<cod>/folha1..N.jpg, fotos numeradas) e edita imovel.json
python3 scripts/landing/landing.py build <codigo>           # mídia (foto/poster/thumb/vídeo) + index.html + check
cd site && python3 -m http.server 8000                      # http://localhost:8000/<codigo>/<bairro>/
```

`build` recusa pasta que já existe e não foi gerada por ele (marca `.gerado-por-landing`), então não sobrescreve
as landings antigas. `--so id1,id2` regera só esses cômodos; `--saida DIR` gera numa raiz de teste.

## O que o Claude decide no `imovel.json` (campos `TROCAR`; o build recusa se sobrar algum)

- `argumento`, `resumo`, `og_argumento`, `hero_alt`, `destaques` (1–2 além de m²/quartos/vagas; ícones: ruler bed car metro sofa building pin)
- `apartamento` e `condominio`: o rascunho já traz os itens "SIM" da ficha; cortar/ordenar e **não citar** o que não é confirmável
- `regiao` (local + distância) e `ficha_extra` (ex.: metrô e linha)
- `comodos`: ordem do tour, `id` (sem acento, vira nome de arquivo), `n` (nome), `d` (frase curta), `foto` (nº da folha), `pan`
  (`in`, `out`, `dir`, `esq`; opcional `c0`/`c1` = posição 0–1 da janela no início/fim). O rascunho traz um cômodo por legenda do anúncio.
- descarte: foto com pessoa, tela com imagem, foto pessoal, marca d'água de portal, distorção. Simplesmente não entra em `comodos`.
- `hero`: id do cômodo mais amplo da área social.

Fixos e automáticos: preço, condomínio, IPTU em 10x (anúncio × 12 ÷ 10), área, suítes, vagas, andar, bairro postal (ViaCEP),
título, mês do valor, mapa, código, WhatsApp/planilha (vêm do modelo).

## Exceções

- IPTU confirmado pelo usuário: `"iptu_10x": 1050` no `imovel.json` (vale mais que o do anúncio).
- IPTU `ALERTA`/`NAO_ENCONTRADO` no `check`: perguntar ao usuário. O script já compara só com unidades de mesmo nº de vagas.
- Fotos de condomínio com marca d'água do QuintoAndar (logo "N" translúcido): não usar nos cômodos; só texto.

## Conferências antes de mostrar ao usuário

- `check` imprime `IPTU não confirmado` se o status não for `OK` → perguntar ao usuário (regra da skill).
- Se `bairro_postal` ≠ `bairro_quintoandar` em `_conferir`, usar o postal e avisar.
- Olhar no navegador (390 px e 1440 px), sem erro de console. **Não publicar na Hostinger sem o OK do usuário.**

## Exemplo aprovado

Imóvel 2597070 (cobertura, Vila Sofia): `scripts/landing/exemplo-imovel.json` (cópia do `imovel.json` aprovado) serve de modelo de preenchimento (12 cômodos, hero `sala`).

## Lote de 4–5 imóveis por sessão (Sonnet)

1. `prep` dos 4–5 links, um depois do outro (≈15 s cada).
2. Para cada um: abrir só as folhas de contato, editar o `imovel.json` (use um script Python curto para gravar os campos).
3. `build` de cada um, `http.server` e conferir.
4. Publicar e registrar na página inicial só depois do OK, seguindo a skill (passos 6–10).

### Prompt para colar numa sessão nova (Sonnet)

> Siga `site-repo/scripts/landing/README.md`. Links: <link1> <link2> <link3> <link4>. Para cada um: `prep`, olhe só as
> folhas de contato, preencha o `imovel.json` (use `exemplo-imovel.json` como modelo), `build`, confira no navegador a 390 px.
> No fim, liste por imóvel: bairro postal, status do IPTU e fotos descartadas. Não publique nada.
