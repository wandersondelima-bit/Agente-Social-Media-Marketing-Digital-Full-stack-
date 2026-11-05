# Marca · Marcelo Osawa Imóveis

Assinatura visual da butique (decisão do usuário em 05/11/2026). O monograma é o **Anel**: o M dentro
de um anel duplo, o O do sobrenome como moldura. Traço fino em champanhe (`#CDAD6E`; `#B18F4E` sobre
fundo claro), com o segundo anel gravado em meio-tom.

## Nome

- Na tela: **Marcelo Osawa** em destaque (Poppins 500, a única fonte do site) e **Imóveis** embaixo, menor e espaçado.
- Em `<title>`, Open Graph, `og:site_name`, alt e textos corridos: **Marcelo Osawa Imóveis** (igual ao Instagram).
- O CRECI não aparece em lugar nenhum do site.

## Símbolo inline (substitui o antigo `#i-apt`)

```html
<symbol id="i-marca" viewBox="0 0 48 48"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="24" cy="24" r="22" stroke-width="1.15"/><circle cx="24" cy="24" r="18.4" stroke-width=".6" opacity=".55"/><path d="M15.5 31.5V16.8l8.5 10.4 8.5-10.4v14.7" stroke-width="1.75"/></g></symbol>
```

## Lockup do cabeçalho e do rodapé

```html
<a class="brand" href="/" title="Página inicial: todos os imóveis à venda">
  <span class="mark" aria-hidden="true"><svg><use href="#i-marca"/></svg></span>
  <span class="t"><strong>Marcelo Osawa</strong><span>Imóveis</span></span>
</a>
```

```css
.brand{display:flex;align-items:center;gap:13px;text-decoration:none;min-width:0;color:var(--on)}
.mark{width:44px;height:44px;flex:none;color:var(--gold)}            /* sem círculo de borda: o anel já é a moldura */
.mark svg{width:44px;height:44px;display:block}
.brand .t{display:grid;line-height:1.05}
.brand .t strong{font-weight:500;font-size:1.12rem;letter-spacing:.04em}
.brand .t span{font-size:.66rem;font-weight:300;color:var(--on-muted);letter-spacing:.34em;text-transform:uppercase;margin-top:5px;padding-left:2px}
@media (max-width:420px){ .mark,.mark svg{width:38px;height:38px} .brand .t strong{font-size:1rem} .brand .t span{font-size:.6rem;letter-spacing:.3em} }
```

Fonte: só Poppins, já carregada pelo site
(`https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap`). Não acrescentar serifa:
o usuário recusou (05/11/2026).

## Favicon (traço mais grosso, para 16–32 px)

```html
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='24' r='24' fill='%23141719'/%3E%3Cg fill='none' stroke='%23CDAD6E' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='24' cy='24' r='19.5' stroke-width='2'/%3E%3Cpath d='M15.5 31.5V17l8.5 10.3L32.5 17v14.5' stroke-width='2.4'/%3E%3C/g%3E%3C/svg%3E">
```

## Arquivos

- `monograma.svg` — o Anel, 48×48, champanhe (para Instagram, Canva, assinatura de e-mail).
- `favicon.svg` — versão de favicon, sobre círculo grafite.
- `alternativa-portal.svg` — a outra finalista (arco com o M como pórtico), guardada.
