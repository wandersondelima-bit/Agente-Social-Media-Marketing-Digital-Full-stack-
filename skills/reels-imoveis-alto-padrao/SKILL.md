---
name: "reels-imoveis-alto-padrao"
description: "Use ao receber um novo imóvel (dados do QuintoAndar + fotos) para produzir o pacote completo de imóveis de alto padrão em São Paulo capital (R$ 1-5M) — intake, confirmação de bairro pelo CEP, IPTU automático (consulta-iptu-sp), geração de vídeo IA (Higgsfield/Kling), roteiro CapCut em 3 blocos, copywriting AIDA para Instagram/TikTok e a landing page do imóvel publicada em marceloosawaimoveis.com.br. Também cobre impulsionamento pago no Instagram, alterações no site (página inicial, imóvel vendido) e a publicação na Hostinger. Vale igual no chat e no Claude Code."
---

# Reels de Imóveis de Alto Padrão (SP Capital)

Pipeline fim-a-fim para transformar um anúncio do QuintoAndar (R$ 1-5M, São Paulo capital) em um pacote pronto para postar: vídeo 9:16 gerado por IA a partir de fotos reais, roteiro de edição para CapCut e copy de Instagram/TikTok. Objetivo: fluxo "copia e cola" — o cliente entra só com fotos + link/dados, sai tudo pronto de uma vez, sem etapas separadas.

## Passo 0 — Confirmar bairro pelo CEP (fazer ANTES de tudo)

Antes de ler fotos ou montar qualquer documento, confirme o bairro oficial pela base dos Correios via ViaCEP (o site dos Correios só aceita POST, não funciona por fetch direto):
`https://viacep.com.br/ws/SP/Sao Paulo/<NOME_DA_RUA>/json/`

- Uma mesma rua pode cruzar vários bairros — case o NÚMERO do imóvel com a faixa do campo `complemento` retornado; nunca use o primeiro resultado da lista.
- Se o bairro dos Correios divergir do bairro anunciado no QuintoAndar (comum quando o comercial "vende" um bairro mais nobre), use o bairro postal diretamente nos documentos e apenas avise a divergência — não precisa perguntar.
- Guarde o CEP exato: ele também define o raio de público do impulsionamento no Instagram (ver seção de Tráfego Pago).

## Intake de dados e fotos

- Fonte de dados: a própria página de venda no QuintoAndar (link), não PDF/RTF — extrai dados atualizados direto. O código do imóvel é o rótulo "Imóvel [NÚMERO]" na página, não o número da URL.
- Fotos: vêm por pasta conectada no computador do usuário (device bridge), organizada por cômodo. Se o `device_bash` falhar em montar a pasta, use `device_stage_files` → processar no sandbox → `device_commit_files` de volta.
- O link do Google Drive só serve para inventariar a pasta (lista nomes/metadados); para produzir (ver fotos, cortar 9:16) as imagens precisam estar acessíveis como arquivo real, não via base64 do conector.
- Fotos oficiais de área comum do condomínio (piscina, lazer) às vezes têm marca d'água de portal — excluir do vídeo, usar só em texto/legenda.
- Descartar também fotos com pessoa em qualquer tela/quadro (ex: TV ligada mostrando alguém) e cortar fora fotos pessoais presas em geladeira/mural.
- Nunca afirmar no roteiro ou na copy algo não confirmável pelas fotos/ficha (ex: "marcenaria planejada", "projetado por arquiteto") — só dados factuais da ficha (m², suítes, vagas, banheiros). Itens marcados como NÃO na ficha (ex: varanda gourmet, pet, home office) nunca entram na copy.

### IPTU e condomínio — regra permanente (atualizada em 26/10/2026)
O usuário NÃO envia mais IPTU nem condomínio.
- **IPTU:** logo depois do Passo 0, rodar a skill `consulta-iptu-sp` (cadastro aberto da Prefeitura, no Mac do usuário). Ela converte o valor da página do QuintoAndar (que mostra 12x, mas SP cobra em 10x: anual = valor × 12) e confere com o cadastro. Status OK → usar direto na copy em 10x, sem perguntar. Só perguntar ao usuário se der ALERTA/CONFERIR ou se o apto não for identificado.
- **Condomínio:** usar o valor da página do QuintoAndar, sem confirmação.
- O antigo template "Confirma para mim o IPTU?" está desativado.

## Geração de vídeo (Higgsfield / Kling 3.0 turbo)

- Modelo: `kling3_0_turbo`, foto real como `start_image`, 9:16 nativo, áudio desligado na narração (a locução é sempre feita depois no CapCut, nunca no Higgsfield/Kling).
- Se o clipe vier COM áudio ambiente, remover no sandbox: `ffmpeg -i in.mp4 -c:v copy -an out.mp4`.
- Pré-cortar cada foto para 9:16 ANTES de gerar (evita reframe caro): `ffmpeg -vf "crop=ih*9/16:ih"`. Escolher a posição horizontal do corte por foto (não sempre centralizado) para manter o elemento de venda no quadro. Fotos horizontais não recebem 9:16 automaticamente mesmo pedindo no parâmetro.
- Upload de mídia local vai pelo `media_upload_widget` (o usuário sobe os arquivos e devolve os `media_ids` para usar como `start_image`).
- Prompt padrão (inglês, ajustar só a palavra do cômodo): "Cinematic architectural walkthrough of a modern apartment [ROOM], 9:16 vertical aspect ratio. Ultra-smooth slow steadycam push-in shot, gliding gently forward across the room. Natural soft daylight, warm ambient lighting. Hyper-realistic materials, pristine interior architecture. Highly stable geometry, zero warping, strictly preserving walls and furniture shapes without morphing, photorealistic 8k, luxury aesthetic."
- Regras de movimento:
  - Push-in só quando o conteúdo do frame final já está visível no frame inicial, na direção do movimento — senão usar só o frame inicial.
  - Pan lateral usa prompt próprio (não o padrão de push-in), sempre "medium-paced" no mínimo — "slow" em espaços pequenos fica estático e entediante.
  - Portas de vidro, divisórias e espelhos ficam na borda do enquadramento ou fora dele, nunca no centro do caminho da câmera.
  - Nunca descrever no prompt elemento arquitetônico que não esteja 100% visível no frame JÁ CORTADO em 9:16 (o modelo inventa elementos, ex. parede virando janela, para satisfazer o texto).
- Quando houver dúvida sobre o Hero Shot de abertura, gerar as duas opções em paralelo em vez de escolher uma de antemão.
- Ao avaliar cada clipe: checar duração exata, ausência de pessoa/silhueta/reflexo humano, ausência de texto renderizado, direção do movimento, geometria estável, e se o elemento principal não saiu de quadro antes do fim do clipe (extrair frames em pontos intermediários para conferir).
- Download dos .mp4 finais: tentar `curl` no sandbox de nuvem direto para a pasta do Mac via `device_commit_files`. Se o host de hospedagem do Higgsfield retornar 403 no proxy, entregar em vez disso um documento com os links diretos (rawUrl) mapeados aos nomes de arquivo do roteiro, para download manual pelo usuário.

### Amplitude primeiro — pan digital sem IA é o PADRÃO (regra permanente, imóvel 2913657)

O cliente quer SEMPRE os cômodos mostrados com amplitude, nunca com foco em uma parte. O corte 9:16 de foto horizontal mostra só uma faixa estreita, e o push-in do Kling fecha ainda mais. Por isso a base do Reel são cenas de pan digital sobre a foto ORIGINAL inteira, sem IA, 100% fiéis à foto. Aprovado no 2167152 (sala) e no 2913657, onde ele pediu mais duas cenas nesse formato (cozinha + living e varanda com jantar) e depois fixou a regra.

- **Um pan amplo por ambiente principal** sempre que existir uma foto horizontal aberta: área social (sala + jantar + varanda), cozinha integrada, varanda gourmet e suíte (se a foto aberta não tiver espelho no centro, pessoa ou objeto pessoal). Meta de **3 a 5 pans por Reel**.
- **Kling (push-in)** só complementa: hook, fechamento com vista, ou cômodo que não tem foto horizontal aberta. Nunca use Kling em recorte de detalhe (bancada, arandela, nicho) como cena principal de um cômodo.
- **Curadoria:** priorize as fotos que mostram mais do cômodo de uma vez (vários ambientes ou o cômodo de parede a parede). Fotos de detalhe viram reserva.
- **Direção:** a janela 9:16 desliza com easing suave e TERMINA no ponto de maior valor (varanda, vista, churrasqueira, ilha). Pode ir da esquerda para a direita (X0 < X1) ou da direita para a esquerda (X0 > X1). Gere 6 s e corte em 5,0 s no CapCut.
- **Comando.** Largura da foto na altura de 1920 = L. Curso de 60 até L − 1080 − 60. Upscale 2x antes do crop para não tremer.
  - Foto 3:2 (ex: 2000×1333): L = 2880, `scale=5760:3840`, curso 60 ↔ 1740.
  - Foto 16:9 (ex: 1280×720): L = 3413, `scale=6827:3840`, curso 60 ↔ 2273.

```
T=6; X0=60; X1=1740   # inverter X0/X1 para ir da direita para a esquerda
ffmpeg -loop 1 -framerate 30 -i FOTO.jpg -t $T \
 -vf "scale=5760:3840:flags=lanczos,crop=2160:3840:x='2*($X0+($X1-$X0)*(1-cos(PI*t/$T))/2)':y=0,scale=1080:1920:flags=lanczos,format=yuv420p" \
 -c:v libx264 -crf 16 -preset slow -r 30 SAIDA.mp4
```
- **Nome do arquivo:** `[PREFIXO]_C[NN]_[COMODO]_PAN.mp4` (ex: `C02_SALA_AMPLA_PAN`, `C09_COZINHA_LIVING_PAN`, `C10_VARANDA_JANTAR_PAN`).
- **Conferência:** frames em 0 / 1,5 / 2,5 / 3,5 / 4,9 s para garantir que nenhum trecho passe por pessoa, TV ligada, foto pessoal, espelho no centro ou objeto de marca (notebook, rótulos).
- **Ordem no roteiro:** hook → primeiro pan amplo (área social, cena 2) → demais pans intercalados com os clipes Kling complementares → fechamento. Se um clipe Kling repetir o trecho de um pan (ex: push-in sala → varanda), o Kling sai do corte e fica como reserva.
- **Alternativa**, só se o cliente pedir profundidade: gerar no Kling em 16:9 a partir da foto inteira (push-in de no máximo ~8%) e aplicar o mesmo pan 9:16 por cima. Fica um pouco menos nítido e não é 100% fiel.

## Roteiro CapCut — estrutura padrão aprovada (repetir sempre)

**Cabeçalho:** `# Roteiro CapCut — Imóvel [CODIGO]` + linha em negrito `[Rua], [nº] — [Bairro] · [tipo] · [N] segundos · 9:16`, seguida de bloco de citação avisando que os timecodes já foram ajustados depois de conferir os clipes gerados.

**Bloco 1 — Vídeos para subir.** Tabela: `Ordem | Arquivo do clipe | Entra | Sai | Duração | Trecho a usar`. Timecodes com precisão de décimo quando necessário; total em negrito no fim. "Trecho a usar" indica o recorte real (ex: "do 0,0 ao 4,5"), em **negrito** quando o corte precisa ser mais curto que o padrão. Notas fixas: corte seco entre cenas (sem fade/zoom/efeito); sempre cortar o FIM do clipe (o início é o frame real da foto); pode inverter abertura/fechamento se textos e narração trocarem junto.

**Bloco 2 — Textos na tela.** Tabela: `# | Entra | Sai | Tempo na tela | Texto`. Modelo "Texto Simples" (barra sólida, alto contraste); fora da faixa de 250px do topo e 420px da base; title case, nunca caixa alta; nenhum card com menos de 2,5-3s na tela.

**Bloco 3 — Narrações.** Tabela: `# | Intervalo | Duração | Narração | Tom`. Coluna Tom com direção curta por bloco (ex: "Afirmativo, abertura de autoridade"). Nenhum bloco de texto na tela pode ficar sem narração cobrindo o mesmo intervalo, mesmo que curta. Ritmo de fala até ~2,7 palavras por segundo.

**Seções finais:** `## Trilha` (estilo, volume relativo à narração, entrada/fade out) e `## Conferência dos N clipes gerados` (parágrafo geral + tabela `Cena | Movimento entregue | Observação` com ✅/⚠️).

**Duração do Reel — regra permanente (a partir dos imóveis depois do 2167152):** alvo de ~30 s e **limite máximo de 36 s** no total do Bloco 1. Nunca passar de 36 s; se a seleção de cenas passar disso, cortar cenas (as de detalhe e as que repetem cômodo saem primeiro) em vez de encurtar tudo por igual.
- Ritmo de referência: 7-8 clipes. Os pans amplos entram com 4,5-5,0 s e os clipes Kling complementares com 3,0-4,0 s (durações não uniformes).
- Abertura/hook com 4,0-5,0 s, primeiro pan amplo (área social) logo em seguida, demais pans no miolo, e fechamento com pelo menos 3,5 s para caber a frase final da narração.
- Quando passar de 36 s, cortam primeiro os clipes Kling de detalhe. Os pans amplos têm prioridade no corte final.
- Textos na tela: 5-6 cards. Narração total de ~70-80 palavras (até ~2,7 palavras por segundo).
- Gerar no Kling só os clipes que vão entrar no corte, mais 1-2 de reserva, para não gastar crédito com cenas que o limite de tempo vai descartar. Os pans não gastam crédito: gere um para cada foto ampla boa e escolha no roteiro.

## Copywriting — Instagram e TikTok (AIDA)

Estrutura da legenda: abertura com emoji de alerta + bairro/metragem/diferencial → texto de conexão emocional → **💰 Venda:** logo após a introdução → lista de detalhes com emoji próprio em CADA item (inclusive amenidades do condomínio, um por linha, sem separador) → bloco de lazer/condomínio → repetir preço de venda + condomínio + IPTU no bloco final → CTA de WhatsApp → código QuintoAndar (`🔗 QuintoAndar #[CODIGO]`) → hashtags.

**Regras permanentes de legenda:**
- Tom acolhedor e humanizado, mas vocabulário elevado ("endereço", "visita reservada", "ficha completa") para público de R$ 1-5M — evitar gírias/coloquialismos.
- Emojis estratégicos em toda seção temática (🚨 abertura, 💰 preço, 🛏️ quartos, 🚿 banheiros, 🧖 amenidades etc.) — aumenta escaneabilidade no mobile.
- Sempre citar o nome da rua (sem número) em algum ponto do texto.
- Sempre destacar vagas de garagem e deixar claro quando os quartos são suítes.
- Nunca prometer "mobiliado" mesmo se a ficha indicar isso.
- Nunca divulgar o nome próprio do condomínio.
- Nenhum qualificador que as fotos e a ficha não comprovam ("projeto assinado", "assinado por escritório", "projetado por arquiteto", "marcenaria planejada"). Vale para todo texto publicado, inclusive a etiqueta (`tag`) do cartão da página inicial e os destaques e listas da landing page.
- No bloco de comparação de preço, nunca citar valores de m² ou média da região — argumentar de forma qualitativa ("preço competitivo para o padrão da região").
- CTA de captação de WhatsApp (vale para os imóveis depois do 2167152 e substitui o CTA de comentário de palavra-chave): o objetivo é o cliente enviar o número de WhatsApp por mensagem. Uma ação só, benefício concreto (ficha completa + visita reservada) e fricção mínima ("número com DDD"). Nunca inventar urgência que não existe (ex: "últimas visitas da semana").
  - Instagram (antes do `🔗 QuintoAndar #[CODIGO]`):
    ```
    📲 Receba a ficha completa no seu WhatsApp: envie seu número com DDD no direct.
    🗓️ Prefere conhecer pessoalmente? Pelo mesmo direct, reservamos o horário da sua visita.
    ```
  - TikTok (substitui o antigo "link da bio"):
    ```
    📩 Envie seu WhatsApp com DDD por mensagem e receba a ficha completa e o agendamento da sua visita reservada.
    ```

**Regras permanentes de narração (roteiro CapCut, Bloco 3):**
- NUNCA valor monetário na narração — preço é só de legenda; narração cria emoção/contexto.
- Última frase é sempre: "Envie seu WhatsApp por mensagem e agende sua visita reservada." — serve para Instagram e TikTok com o mesmo áudio ("direct" não existe no TikTok). Substitui a antiga "Agende sua visita reservada pelo direct." a partir dos imóveis depois do 2167152. São 9 palavras: reservar pelo menos 3,5 s no último bloco de narração.
- Sem vácuo: todo intervalo com texto na tela precisa de narração cobrindo o mesmo trecho.

O modelo acima não é regra fixa de formatação — pode ser ajustado visando melhor performance de venda, sempre pensando no comprador de R$ 1-5M em São Paulo capital.

## Tráfego pago (impulsionamento Instagram)

- Feito pelo botão "Impulsionar"/"Turbinar" do app do Instagram, não pelo Gerenciador de Anúncios.
- Público: raio geográfico a partir do CEP exato do imóvel (confirmado no Passo 0), não mais estimado só pelo nome do bairro.
- Registrar por imóvel: verba, dias de duração, visualizações, conversas iniciadas — para comparar performance entre anúncios ao longo do tempo.

## Landing page do imóvel (etapa fixa, a partir do 3066087)

Todo imóvel sai com a própria landing page publicada em marceloosawaimoveis.com.br (Hostinger), feita a partir do modelo aprovado no 3066087 (Av. dos Eucaliptos, Indianópolis). Nunca entregar o pacote sem ela. O link vai na copy, na bio e nos anúncios. Esta seção vale igual no chat (Cowork) e no Claude Code: é a fonte única das regras do site.

### Onde está o projeto
- Repositório GitHub `wandersondelima-bit/Agente-Social-Media-Marketing-Digital-Full-stack-` (branch padrão `main`): `site/` é exatamente o que está no ar; `site/_modelo/` é o modelo (README com o checklist); `./scripts/nova-landing.sh` cria a página; `.hostinger/site.json` tem domínio e usuário da hospedagem.
- **No Mac:** a cópia fica em `~/AgenteSocialMedia/site-repo` (no Cowork, `$HOME/mnt/AgenteSocialMedia/site-repo`). Se não existir, clonar: `git clone https://github.com/wandersondelima-bit/Agente-Social-Media-Marketing-Digital-Full-stack-.git site-repo` dentro de `AgenteSocialMedia`. Antes de começar, `git pull` na `main`.
- **Em sessão na nuvem do Claude Code:** o repositório já é a pasta de trabalho.
- Nunca montar a página do zero nem redesenhar: sempre a partir do modelo.

### Passos, sempre nesta ordem
1. `./scripts/nova-landing.sh <codigo> <bairro>` cria `site/<codigo>/<bairro>/`.
2. Trocar só os trechos marcados com `TROCAR` em `index.html`: `<title>`, meta e Open Graph, hero (bairro, título, destaques, preço com mês de referência, condomínio, IPTU em 10x), ficha técnica, listas do apartamento e do condomínio, região e link do Google Maps, `CONFIG` (código e título), `ROOMS` (um item por cômodo, na ordem do tour) e o código no rodapé.
3. Mídia reaproveitada do próprio Reel, com o mesmo `id` de cômodo em todas as pastas: `img/foto/<id>.jpg` foto original 3:2 a 1600 px; `video/<id>.mp4` o clipe 9:16 do cômodo (pan digital ou Kling, sem áudio, recomprimido para ≤ 1,5 MB); `img/poster/<id>.jpg` primeiro frame do clipe a 720 px; `img/thumb/<id>.jpg` 168×112. A foto do hero é a mais ampla da área social (se o `id` não for `living`, trocar a URL em `.hero{background:...}`).
4. Testar localmente (`python3 -m http.server` dentro de `site/`) no celular (390 px) e no desktop (1440 px), de preferência com Playwright: vídeo tocando, fotos carregando, sem rolagem lateral, sem erro no console. **Interceptar o envio do formulário** (rota para `script.google.com`) para não gravar lead falso; conferir que o payload sai com interesse "Agendar uma visita".
5. Mostrar a prévia ao usuário (artifact) e **só publicar depois do OK**.
6. Publicar a pasta do imóvel na Hostinger em `public_html/<codigo>/<bairro>/`: `hosting_files_generate-upload-url` (usuário e domínio do `.hostinger/site.json`) e upload TUS de cada arquivo (`POST` com `Upload-Length`, depois `PATCH` com o conteúdo; conferir `Upload-Offset` = tamanho). Todo upload sobrescreve só os arquivos enviados.
7. Registrar no GitHub antes de mexer na página inicial: acrescentar o imóvel na lista `IMOVEIS` do `site/index.html` (código, endereço da página, bairro, título, foto, área, quartos, vagas, preço, destaque), commit numa branch nova a partir da `main` atualizada, push e pull request para a `main`. O próprio agente faz o merge, sem perguntar (decisão do usuário em 29/10/2026): `gh pr merge <n> --merge` rodando **sozinho**, sem `&&` com outros comandos, porque a regra de permissão `Bash(gh pr merge *)` só cobre o comando isolado. Depois, `git checkout main`, `git pull` e apagar a branch local. Só parar e perguntar se o merge der conflito.
8. Publicar a página inicial **só a partir da `main`**, nunca de uma branch: outras sessões podem ter publicado cartões que a branch não tem. Antes do upload, baixar o `index.html` do ar e compará-lo com o da `main` de antes do merge (`git show HEAD^1:site/index.html`). Os dois têm de ser idênticos, e a diferença para o arquivo que vai subir tem de ser só o cartão novo. Se o ar tiver algo que a `main` não tem, parar e avisar.
   - Se o merge for barrado pelo controle de permissões: publicar a partir da branch só se o ar for igual à `origin/main` e a branch diferir apenas pelo cartão novo; deixar o PR aberto e pedir ao usuário o merge pelo link.
9. **Limpar o cache:** `hosting_cache_clear` (scope `all`) no domínio. A CDN guarda cópias antigas e o celular pode receber a versão velha mesmo com o servidor certo.
10. Conferir no ar com navegador de verdade, não só `curl`: página do imóvel, página inicial (cartão novo aparecendo), política de privacidade, um vídeo, uma foto e um endereço inexistente (deve cair na página 404). Conferir também que todo código de `IMOVEIS` na `main` tem cartão na página inicial no ar e que o `site/index.html` da `main` é idêntico ao do ar.
11. Colocar o link `marceloosawaimoveis.com.br/<codigo>/<bairro>/?ref=reel-01` na copy (e `?ref=bio` na bio, `?ref=ads` em anúncio) no lugar de "link da bio".

### Padrões que não mudam
- Endereço: `/<codigo-do-imovel>/<bairro>/`, com o bairro postal do Passo 0, minúsculo, sem acento (ex.: `/3066087/indianopolis/`).
- Visual: grafite em gradiente, champanhe como único acento, Poppins. Ordem: hero em tela cheia → tour em vídeo → galeria de fotos → detalhes com o formulário ao lado (fixo no desktop). O nome "Marcelo Osawa" leva à página inicial; o rodapé tem "Outros imóveis à venda".
- Formulário só de agendamento de visita (nome + WhatsApp). Grava na planilha "Leads Imóveis" com interesse "Agendar uma visita" e abre o WhatsApp com a mensagem pronta. WhatsApp, endereço da planilha (`CONFIG.endpoint`), versão do consentimento e política de privacidade são os mesmos para todos os imóveis: nunca trocar.
- O aviso de lead novo sai por e-mail de contato@marceloosawaimoveis.com.br para ele mesmo (Apps Script da planilha); nada a fazer por imóvel.
- Textos seguem as regras da copy: só dados da ficha, sem nome do condomínio, sem "mobiliado", sem valores de m² da região.
- A raiz do domínio é a página inicial estática com os imóveis. Não existe WordPress nem outro CMS; endereço inexistente cai em `/404.html`. O `hosting_websites_list` da Hostinger mostra `website_type: wordpress` por uma classificação antiga: ignorar.

### Imóvel vendido ou retirado
Apagar a linha dele em `IMOVEIS` e seguir os Passos 7 a 10 (PR, merge, página inicial a partir da `main`, cache, conferência).
- **Apagar a pasta do imóvel no servidor:** o conector da Hostinger não tem operação para apagar arquivo. Use a API do File Browser com as mesmas credenciais do upload (`auth_key` e `rest_auth_key` do `hosting_files_generate-upload-url`): `DELETE {base}/api/resources/public_html/<codigo>/<bairro>/` com os cabeçalhos `X-Auth` e `X-Auth-Rest`, onde `{base}` é a `url` do upload sem o final `/api/tus/public_html`. Responde 204 e apaga a pasta inteira (verificado em 29/10/2026 com arquivo e pasta descartáveis). O endpoint não é documentado: se não responder 204, use a reserva abaixo e avise o usuário.
- **Redirecionar o endereço antigo:** subir em `public_html/<codigo>/<bairro>/.htaccess` a linha `RedirectMatch 301 ^/<codigo>/<bairro>/.* https://marceloosawaimoveis.com.br/`, que leva para a página inicial qualquer arquivo da pasta, apagado ou não. Também é a reserva quando o `DELETE` falhar. Registrar o `.htaccess` no repositório no lugar da pasta.
- Limpar o cache e conferir no ar: a página antiga redireciona para a página inicial e o cartão sumiu.

## Entrega final

Entregar tudo de uma vez (nunca em etapas separadas): catálogo de fotos selecionadas, prompts de geração de vídeo, roteiro CapCut completo (3 blocos), copy de Instagram (TXT/MD), copy de TikTok (TXT/MD) e o **link da landing page publicada** (já na página inicial, com cache limpo e conferida no celular) — como arquivos separados em `/outputs`, apresentados juntos.

Convenção de nome de arquivo: `[ID_QUINTOANDAR]_[BAIRRO_MAIUSCULO_UNDERSCORE]_RUA_[NOME_RUA_MAIUSCULO_UNDERSCORE]_[TIPO_DE_ARQUIVO]`.

## Gotchas técnicos conhecidos

- `device_bash` pode falhar ao montar a pasta de fotos conectada — contornar com `device_stage_files`/`device_commit_files`.
- Host de hospedagem dos clipes do Higgsfield pode ficar bloqueado no proxy de egress (403) de forma intermitente — se `curl` falhar, cair para entrega de links diretos.
- Para vídeos curtos (≤15s) no reframe do Higgsfield, não passar `duration_seconds` nem `resolution` (dá erro de validação) — só `aspect_ratio` + `medias`.
- Se o `generate_video_batch` recusar um item recomendando um preset genérico, reenviar só aquele índice com `declined_preset_id` (o preset "IN THE DARK", id 24bae836-2c4a-48e0-89b6-49fcc0b21612, recusa todos os itens com frequência — dá para já incluir o `declined_preset_id` no primeiro envio).
- Limites do `device_commit_files` (cópia da nuvem para a pasta do Mac, fixos do Cowork, sem configuração): máximo 50 arquivos, **20 MB por arquivo** e **100 MB por chamada**.
  - Antes de enviar, checar o tamanho de cada clipe (`du -m`). Dividir os envios para que cada chamada fique abaixo de 100 MB.
  - Clipe acima de 20 MB (comum em 1080p — a piscina do 2167152 deu 18 MB): recomprimir no sandbox antes de enviar, sem perda visível no celular: `ffmpeg -i in.mp4 -c:v libx264 -crf 20 -preset slow -an out.mp4` (subir o CRF de 1 em 1 até ficar abaixo de 20 MB). Nunca reenviar sem checar.
  - Quando o `device_bash` montar a pasta normalmente, preferir baixar os clipes com `curl` direto no Mac, dentro de `clipes_video/` — esse caminho não tem esses limites.