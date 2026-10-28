# Marcelo Osawa Imóveis · regras do projeto

Site: marceloosawaimoveis.com.br (Hostinger, estático, pasta `site/`). Corretor Marcelo Osawa, CRECI 277144.
Público: compradores de apartamentos de R$ 1-5 milhões em São Paulo capital, chegando pelo celular a partir de Reels.

## Quando chegar um imóvel novo (dados + fotos)

Rodar a skill `reels-imoveis-alto-padrao` do início ao fim. Ela produz o Reel, o roteiro CapCut, a copy e,
como etapa fixa, a landing page do imóvel. Nunca entregar o pacote sem a landing page publicada.

Passos da landing page, sempre nesta ordem:
1. `scripts/nova-landing.sh <codigo> <bairro>` cria `site/<codigo>/<bairro>/` a partir de `site/_modelo/`.
2. Trocar só os trechos marcados com `TROCAR` em `index.html`; não redesenhar (checklist em `site/_modelo/README.md`).
3. Mídia com o mesmo `id` de cômodo em `img/foto`, `img/poster`, `img/thumb` e `video` (clipes do próprio Reel, ≤ 1,5 MB).
4. Testar localmente no celular e no desktop (Playwright), com o envio do formulário interceptado para não gravar lead falso.
5. Publicar na Hostinger em `public_html/<codigo>/<bairro>/` (site e usuário em `.hostinger/site.json`), conferir a URL no ar.
6. Acrescentar o imóvel em `IMOVEIS` no `site/index.html` e republicar a página inicial.
7. Colocar o link `marceloosawaimoveis.com.br/<codigo>/<bairro>/?ref=...` na copy e na bio.

Imóvel vendido: apagar a linha dele em `IMOVEIS`, republicar a página inicial e deixar no lugar da pasta
um `.htaccess` com `Redirect 301` para a raiz (como em `site/eucaliptos/.htaccess`).

## Padrões que não mudam

- Endereço: `/<codigo-do-imovel>/<bairro>/`, bairro postal (Passo 0 da skill), minúsculo, sem acento.
- Visual: grafite em gradiente, champanhe como único acento, Poppins; hero em tela cheia → tour em vídeo → fotos → detalhes com formulário fixo.
- Formulário só de agendamento de visita (nome + WhatsApp), gravando na planilha com interesse "Agendar uma visita".
- WhatsApp, planilha (`CONFIG.endpoint`) e política de privacidade são os mesmos para todos os imóveis.
- Textos seguem as regras da copy da skill: só dados da ficha, sem nome do condomínio, sem "mobiliado", sem valores de m² da região.
- A raiz do domínio é a página inicial com os imóveis; não existe WordPress nem outro CMS. Endereço inexistente cai em `site/404.html`.

## Publicação

- Upload por TUS com `hosting_files_generate-upload-url` (MCP da Hostinger); todo deploy sobrescreve só os arquivos enviados.
- Antes de publicar qualquer alteração em página que já está no ar, mostrar a prévia e pedir confirmação.
- Depois de publicar, conferir com `curl` as URLs principais (página, privacidade, um vídeo, uma foto, redirecionamentos, um endereço inexistente).
- A CDN da Hostinger guarda cópias antigas das páginas: depois de publicar, rodar `hosting_cache_clear` (scope `all`) no domínio e conferir num navegador de verdade (Playwright), não só com `curl`, que pode receber uma versão diferente da que o celular recebe.
