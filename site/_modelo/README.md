# Modelo de landing page de apartamento

Cada apartamento ganha uma cópia desta pasta. O endereço segue sempre o padrão
`marceloosawaimoveis.com.br/{codigo-do-imovel}/{bairro}/`
(ex.: `/3066087/indianopolis/`), e no repositório fica em `site/{codigo}/{bairro}/`.

## Como criar a página de um apartamento novo

```
scripts/nova-landing.sh 1234567 moema
```

O script copia o modelo para `site/1234567/moema/`, troca o código e o endereço
canônico, e deixa as pastas de mídia prontas. Depois:

1. **Mídia** (mesmos nomes de cômodo em todas as pastas; o nome é o `id` em `ROOMS`):
   - `img/foto/<id>.jpg` — foto original 3:2, 1600 px de largura (galeria, modo foto e hero)
   - `img/poster/<id>.jpg` — primeiro frame do vídeo, 9:16, 720 px de largura
   - `img/thumb/<id>.jpg` — miniatura 3:2, 168×112 px
   - `video/<id>.mp4` — clipe 9:16 do tour (pan digital ou Kling, sem áudio, ≤ 1,5 MB)
   - A foto do hero é `img/foto/living.jpg`: se o cômodo de abertura tiver outro nome,
     troque a URL em `.hero{background:...}`.
2. **`index.html`**, nos trechos marcados com `TROCAR`:
   - `<title>`, `meta description`, Open Graph (`og:title`, `og:description`, `og:image`)
   - hero: bairro, título (rua + argumento), destaques, preço, condomínio, IPTU, mês do valor
   - ficha técnica, listas "O apartamento" e "O condomínio", região e link do Google Maps
   - `CONFIG` (código e título) e `ROOMS` (um item por cômodo, na ordem do tour)
   - código do imóvel no rodapé
3. **`privacidade.html`**: só o `canonical` (o script já troca).
4. **Conferir** localmente: `python3 -m http.server 8000` dentro de `site/` e abrir
   `http://localhost:8000/{codigo}/{bairro}/`.
5. **Publicar** na Hostinger na pasta `public_html/{codigo}/{bairro}/` (ver `.hostinger/site.json`).
   Depois, abrir a página no celular, enviar um teste pelo formulário e conferir a linha na planilha.

## O que nunca muda

- WhatsApp, planilha (`CONFIG.endpoint`), versão do consentimento e a política de privacidade.
- O comportamento: tour automático, galeria, formulário só de agendamento de visita,
  rastreamento por `?ref=` (use `?ref=reel-01`, `?ref=bio` etc. nos links dos Reels e da bio).
- A ordem das seções no celular: hero → tour em vídeo → fotos → detalhes → formulário.
