"""Monta site/guias/<slug>/index.html a partir da página inicial (cabeçalho, rodapé, cores, ícones e cartões)
e do conteúdo do guia em scripts/guias/<slug>-conteudo.html. O guia fica igual ao site sem copiar à mão.

Uso: python3 scripts/guias/montar-guia.py pinheiros   (ou brooklin)
"""
import pathlib, re, sys

RAIZ = pathlib.Path(__file__).resolve().parents[2]
SLUG = sys.argv[1]
GUIAS = {
    "pinheiros": {
        "bairro": "Pinheiros",
        "isca": "quanto-custa-morar-em-pinheiros",
        "titulo": "Quanto custa morar em Pinheiros",
        "title": "Quanto custa morar em Pinheiros · Guia de bairro · Marcelo Osawa Imóveis",
        "description": "O preço do m² em Pinheiros, a conta da compra com ITBI e cartório para 70, 100 e 130 m², o aluguel e o que pedir antes da visita. Dados de outubro de 2026.",
        "og_title": "Quanto custa morar em Pinheiros: a conta completa da compra",
        "og_description": "R$ 18.225 por m² (FipeZAP, out/2026). ITBI e cartório somam cerca de 4% do preço. Guia completo com a conta por metragem.",
    },
    "brooklin": {
        "bairro": "Brooklin",
        # cartões: o bairro e a vizinhança imediata, nesta ordem (o nome é o do campo `bairro` da página inicial)
        "bairros": ["Brooklin Paulista", "Brooklin - Itaim Bibi", "Jardim das Acácias", "Cidade Monções",
                    "Vila Cordeiro", "Campo Belo", "Chácara Santo Antônio"],
        "isca": "quanto-custa-morar-no-brooklin",
        "titulo": "Quanto custa morar no Brooklin",
        "title": "Quanto custa morar no Brooklin · Guia de bairro · Marcelo Osawa Imóveis",
        "description": "O preço do m² no Brooklin, a conta da compra com ITBI e cartório para 70, 100 e 130 m², o aluguel e o que pedir antes da visita. Dados de 2026.",
        "og_title": "Quanto custa morar no Brooklin: a conta completa da compra",
        "og_description": "R$ 14.649 por m² (levantamento do QuintoAndar, fev/2026). ITBI e cartório somam cerca de 4% do preço. Guia completo com a conta por metragem.",
    },
}
g = GUIAS[SLUG]
home = (RAIZ / "site/index.html").read_text(encoding="utf-8")
conteudo = (RAIZ / f"scripts/guias/{SLUG}-conteudo.html").read_text(encoding="utf-8")


def pega(padrao, nome):
    m = re.search(padrao, home, re.S)
    if not m:
        sys.exit(f"Não achei {nome} na página inicial: nada foi gravado.")
    return m.group(0)


favicon = pega(r'<link rel="icon"[^\n]*>', "o favicon")
fontes = pega(r'<link rel="preconnect" href="https://fonts.googleapis.com">.*?display=swap">', "as fontes")
estilo = pega(r"<style>\n/\* Identidade:.*?</style>", "o estilo do site")
icones = pega(r'<svg width="0" height="0" style="position:absolute" aria-hidden="true">.*?</svg>\n', "os ícones")
cabecalho = pega(r'<header class="top">.*?</header>', "o cabeçalho")
rodape = pega(r"<footer>.*?</footer>", "o rodapé")
whatsapp = pega(r"const WHATSAPP = '\d+';", "o WhatsApp")
url = f"https://marceloosawaimoveis.com.br/guias/{SLUG}/"

css_guia = """<style>
/* Guia de bairro (isca): mesma identidade da página inicial; coluna única de leitura. */
.top{position:relative}
.g-narrow{max-width:820px}
.g-hero{position:relative;overflow:hidden;isolation:isolate;padding:28px 0 56px;
  background:radial-gradient(ellipse 60% 70% at 85% 30%, rgba(205,173,110,.12), transparent 70%)}
.g-hero .orn{position:absolute;z-index:-1;right:-14%;top:-6%;width:min(70vw, 520px);color:var(--gold);opacity:.16;pointer-events:none}
.g-rotulo{margin:0 0 14px;color:var(--gold);font-size:.78rem;font-weight:500;letter-spacing:.32em;text-transform:uppercase;display:flex;align-items:center;gap:14px}
.g-rotulo::before{content:"";width:44px;height:1px;background:var(--gold)}
.g-hero h1{max-width:14ch}
.g-hero .lead{margin:18px 0 0;max-width:52ch;color:var(--on-muted);font-weight:300;font-size:1.05rem;line-height:1.7}
.g-form{margin-top:32px;background:var(--card);color:var(--ink);border-radius:var(--r-m);padding:26px 22px;box-shadow:var(--shadow);max-width:520px}
@media (min-width:720px){ .g-form{padding:32px 34px} }
.g-form h2{margin:0;font-weight:600;font-size:1.3rem;line-height:1.25}
.g-form .sub{margin:6px 0 18px;color:var(--muted);font-size:.95rem}
.g-form label{display:block;margin:14px 0 6px;font-weight:500;font-size:.92rem}
.g-form input{width:100%;min-height:50px;border:1px solid var(--line);border-radius:var(--r-s);padding:12px 14px;font:400 16px var(--font);color:var(--ink);background:#fff}
.g-form input:focus{outline:2px solid var(--gold-deep);outline-offset:1px;border-color:var(--gold-deep)}
.g-form input[aria-invalid=true]{border-color:#B3261E}
.g-form .err{margin:6px 0 0;color:#B3261E;font-size:.86rem}
.g-form .btn{width:100%;margin-top:20px;background:var(--ink);color:var(--card)}
.g-form .btn:hover{background:#000}
.g-form .btn[disabled]{opacity:.6;cursor:wait}
.g-form .consent{margin:12px 0 0;color:var(--muted);font-size:.8rem;line-height:1.5}
.g-form .consent a{color:var(--ink)}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.g-aberto{margin:0;font-weight:500}
.g-guia{padding:8px 0 72px}
.g-sec{padding:44px 0 8px;border-top:1px solid var(--on-line);position:relative}
.g-sec::before{content:"";position:absolute;top:-1px;left:0;width:56px;height:1px;background:var(--gold)}
.g-sec h2{margin:0 0 14px;font-weight:400;font-size:clamp(1.5rem, 4vw, 2.1rem);line-height:1.2}
.g-sec p{margin:0 0 14px;color:var(--on-muted);font-weight:300;line-height:1.75}
.g-sec > p strong,.g-fatos strong{color:var(--on);font-weight:500}
.g-num{margin:0 0 8px!important;color:var(--gold)!important;font-weight:500!important;font-size:.85rem;letter-spacing:.2em}
.g-destaque{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 12px;margin:6px 0 16px}
.g-destaque .rs{color:var(--gold);font-size:1.1rem}
.g-destaque strong{font-weight:300;font-size:clamp(3rem, 11vw, 4.6rem);line-height:1;letter-spacing:-.02em}
.g-destaque .un{flex-basis:100%;color:var(--on-muted);font-weight:300}
.g-fatos{margin:0 0 18px;padding:0;list-style:none;display:grid;gap:12px}
.g-fatos li{padding-left:22px;position:relative;color:var(--on-muted);font-weight:300;line-height:1.7}
.g-fatos li::before{content:"";position:absolute;left:0;top:.72em;width:10px;height:1px;background:var(--gold)}
.g-nota{font-size:.9rem}
.g-tabela{overflow-x:auto;margin:6px 0 18px;border:1px solid var(--on-line);border-radius:var(--r-s)}
.g-tabela table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
.g-tabela table.g-custos{min-width:470px}
@media (max-width:480px){ .g-tabela th,.g-tabela td{padding:11px 10px;font-size:.9rem} }
.g-tabela th,.g-tabela td{padding:13px 14px;text-align:right;border-bottom:1px solid var(--on-line);font-weight:300;white-space:nowrap}
.g-tabela th:first-child,.g-tabela td:first-child{text-align:left}
.g-tabela thead th{color:var(--gold);font-weight:500;font-size:.85rem;letter-spacing:.04em}
.g-tabela tbody tr:last-child th,.g-tabela tbody tr:last-child td{border-bottom:0}
.g-tabela tbody th{font-weight:400}
.g-tabela .total th,.g-tabela .total td{font-weight:600;color:var(--on);background:var(--on-fill)}
.g-tabela small{display:block;font-weight:300;color:var(--on-muted);font-size:.78rem}
.g-copia{border:1px solid var(--on-line);border-radius:var(--r-m);padding:20px 22px;display:grid;gap:16px;justify-items:start;background:var(--on-fill)}
.g-copia ol{margin:0;padding-left:20px;display:grid;gap:8px;color:var(--on);font-weight:300;line-height:1.6}
.g-imoveis{margin-top:18px}
@media (min-width:720px){ .g-imoveis{grid-template-columns:repeat(2, minmax(0,1fr))} }
@media (min-width:1080px){ .g-imoveis{grid-template-columns:repeat(2, minmax(0,1fr))} }
.g-fontes ul{margin:0 0 14px;padding-left:18px;color:var(--on-muted);font-weight:300;font-size:.9rem;display:grid;gap:6px}
.g-fim{margin-top:48px}
</style>"""

# Guia de um bairro só: filtro igual ao de sempre. Guia com vizinhança ("bairros"): cartões na ordem da lista,
# os mais novos primeiro dentro de cada bairro.
if "bairros" in g:
    extra_guia = ", bairros:[" + ", ".join("'" + b + "'" for b in g["bairros"]) + "]"
    filtro = ("todos.filter(im => GUIA.bairros.includes(im.bairro)).reverse()"
              ".sort((a, b) => GUIA.bairros.indexOf(a.bairro) - GUIA.bairros.indexOf(b.bairro))")
else:
    extra_guia = ""
    filtro = "todos.filter(im => im.bairro === GUIA.bairro).reverse()"

script = """<script>
""" + whatsapp + """
const GUIA = {isca:'""" + g["isca"] + """', titulo:'""" + g["titulo"] + """', bairro:'""" + g["bairro"] + """'""" + extra_guia + """};
const CONFIG = {
  endpoint: 'https://script.google.com/macros/s/AKfycbwzySt1EMvokNnTXpiIOeslLxopo_WfLSI9UWSEHWbyKqSjBvfvUJHlAFrJuPL6tO68/exec',   /* a mesma planilha das landings */
  consentimentoVersao: 'v2-2026-10'
};
const CRM_ENDPOINT = '/app/receber.php';   /* entrada de leads do CRM: lead de isca entra como "Isca do site" */
const CHAVE = 'guia-' + GUIA.isca;

/* Origem do acesso (o link do direct leva UTM): a mesma leitura das landings */
const ORIGEM = (() => {
  const keys = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ref'];
  let qs; try { qs = new URLSearchParams(location.search); } catch (e) { qs = new URLSearchParams(); }
  const o = {};
  keys.forEach(k => { const v = qs.get(k); if (v) o[k] = v.slice(0, 80); });
  o.referrer = (document.referrer || '').slice(0, 200);
  return o;
})();

function abrir(nome, rolar) {
  document.getElementById('form').hidden = true;
  const ok = document.getElementById('aberto'); ok.hidden = false;
  document.getElementById('nomeOk').textContent = nome || 'boa leitura';
  const guia = document.getElementById('guia'); guia.hidden = false;
  if (rolar) guia.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
}
try { const salvo = localStorage.getItem(CHAVE); if (salvo) abrir(salvo, false); } catch (e) {}

const whats = document.getElementById('whats');
whats.addEventListener('input', () => {
  let d = whats.value.replace(/\\D/g, '');
  if (d.length > 11 && d.startsWith('55')) d = d.slice(2);
  d = d.slice(0, 11);
  let out = d;
  if (d.length > 2) out = `(${d.slice(0,2)}) ${d.slice(2)}`;
  if (d.length > 6) out = `(${d.slice(0,2)}) ${d.slice(2, d.length - 4)}-${d.slice(-4)}`;
  whats.value = out;
});
function flag(id, bad) {
  const el = document.getElementById(id), err = document.getElementById(id + '-err');
  el.setAttribute('aria-invalid', String(bad)); err.hidden = !bad; return bad;
}
document.getElementById('form').addEventListener('submit', e => {
  e.preventDefault();
  const nome = document.getElementById('nome').value.trim().replace(/\\s+/g, ' ');
  const digits = whats.value.replace(/\\D/g, '');
  const bad = [flag('nome', nome.length < 2), flag('whats', !(digits.length === 10 || digits.length === 11))];
  const first = ['nome','whats'][bad.indexOf(true)];
  if (first) { document.getElementById(first).focus(); return; }
  const primeiro = nome.split(' ')[0];
  if (document.getElementById('empresa').value) { abrir(primeiro, true); return; }   // robô: abre e não grava
  const payload = Object.assign({tipo:'formulario', enviado_em: new Date().toISOString(), imovel_codigo:'',
    imovel: 'Guia: ' + GUIA.titulo, pagina: location.origin + location.pathname,
    isca: GUIA.isca, isca_titulo: GUIA.titulo}, ORIGEM, {
    nome: nome.slice(0, 80), whatsapp: '+55' + digits, interesse: 'Isca: ' + GUIA.titulo,
    consentimento: true, consentimento_versao: CONFIG.consentimentoVersao, aceita_ofertas: false
  });
  const body = JSON.stringify(payload);
  document.getElementById('send').disabled = true;
  const envios = [];
  if (CONFIG.endpoint) {
    try { envios.push(fetch(CRM_ENDPOINT, {method:'POST', keepalive:true, headers:{'Content-Type':'text/plain;charset=utf-8'}, body}).catch(() => {})); } catch (err) {}
    envios.push(fetch(CONFIG.endpoint, {method:'POST', mode:'no-cors', headers:{'Content-Type':'text/plain;charset=utf-8'}, body}).catch(() => {}));
  }
  Promise.race([Promise.allSettled(envios), new Promise(r => setTimeout(r, 2500))]).then(() => {
    try { localStorage.setItem(CHAVE, primeiro); } catch (err) {}
    abrir(primeiro, true);
  });
});

document.getElementById('copiar').addEventListener('click', e => {
  const b = e.currentTarget;
  const t = [...document.querySelectorAll('#checklist li')].map((li, i) => `${i + 1}. ${li.textContent}`).join('\\n');
  const ok = () => { b.textContent = 'Lista copiada'; setTimeout(() => b.textContent = 'Copiar a lista', 1800); };
  try { navigator.clipboard.writeText(t).then(ok, () => {}); } catch (err) {}
});

/* Apartamentos do bairro: lidos da lista da página inicial, então imóvel vendido some daqui também */
fetch('/', {cache:'no-store'}).then(r => r.text()).then(h => {
  const m = h.match(/const IMOVEIS = \\[\\n([\\s\\S]*?)\\n\\];/);
  if (!m) return;
  const todos = new Function('return [' + m[1] + ']')();
  const doBairro = """ + filtro + """;
  if (!doBairro.length) return;
  const lista = document.getElementById('lista');
  doBairro.forEach(im => {
    const li = document.createElement('li');
    li.innerHTML = `<a class="imovel" href="${im.url}?utm_source=guia&utm_medium=isca&utm_campaign=${GUIA.isca}">
    <span class="foto"><img src="${im.foto}" alt="${im.alt}" loading="lazy" width="1600" height="1066">${im.tag ? `<span class="tag">${im.tag}</span>` : ''}</span>
    <span class="body">
      <p class="bairro"><svg aria-hidden="true"><use href="#i-pin"/></svg>${im.bairro}</p>
      <h3>${im.titulo}</h3>
      <ul class="highs">
        <li><svg aria-hidden="true"><use href="#i-ruler"/></svg>${im.area}</li>
        <li><svg aria-hidden="true"><use href="#i-bed"/></svg>${im.quartos}</li>
        <li><svg aria-hidden="true"><use href="#i-car"/></svg>${im.vagas}</li>
      </ul>
      <span class="preco"><strong>${im.preco}</strong><span>Ver o apartamento</span></span>
    </span></a>`;
    lista.appendChild(li);
  });
  document.getElementById('imoveis').hidden = false;
}).catch(() => {});

document.querySelectorAll('a.wa').forEach(a => {
  const t = a.dataset.msg || 'Olá! Vi o site de Marcelo Osawa Imóveis e gostaria de conhecer os apartamentos à venda.';
  a.href = `https://wa.me/${WHATSAPP}?text=${encodeURIComponent(t)}`;
});
</script>"""

html = f"""<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<!-- Guia de bairro (isca) · gerado por scripts/guias/montar-guia.py {SLUG}. Não editar à mão: mude o conteúdo em
     scripts/guias/{SLUG}-conteudo.html e rode o script de novo. -->
<title>{g['title']}</title>
<meta name="description" content="{g['description']}">
<link rel="canonical" href="{url}">
<meta property="og:type" content="article">
<meta property="og:locale" content="pt_BR">
<meta property="og:site_name" content="Marcelo Osawa Imóveis">
<meta property="og:title" content="{g['og_title']}">
<meta property="og:description" content="{g['og_description']}">
<meta property="og:url" content="{url}">
<meta property="og:image" content="{url}img/capa.jpg">
<meta property="og:image:width" content="1080">
<meta property="og:image:height" content="1080">
<meta property="og:image:alt" content="Capa do guia {g['titulo']}">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#141719">
{favicon}
{fontes}
{estilo}
{css_guia}
</head>
<body>

{icones}
{cabecalho}

{conteudo}
{rodape}

{script}
</body>
</html>
"""
destino = RAIZ / f"site/guias/{SLUG}/index.html"
destino.parent.mkdir(parents=True, exist_ok=True)
destino.write_text(html, encoding="utf-8")
print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode())} bytes)")
