---
name: "reels-imoveis-alto-padrao"
description: "Use ao receber um novo imóvel (dados do QuintoAndar + fotos) para produzir o pacote completo de imóveis de alto padrão em São Paulo capital (R$ 1-5M) — intake, confirmação de bairro pelo CEP, IPTU automático (consulta-iptu-sp), cenas de vídeo (pan digital, parallax 2.5D, Higgsfield/Kling e Google Flow), roteiro CapCut em 3 blocos, copywriting AIDA para Instagram/TikTok, a landing page publicada em marceloosawaimoveis.com.br e a automação comentário → direct em cartão (Zernio, UTM). Também cobre impulsionamento pago no Instagram, alterações no site (página inicial, imóvel vendido) e a publicação na Hostinger. Vale igual no chat e no Claude Code."
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
- Upload de mídia local: `media_upload` do Higgsfield e envio do arquivo com `curl` (PUT) direto do Mac, sem pedir nada ao usuário (usado no 3116143). Só se isso falhar, `media_upload_widget` (o usuário sobe os arquivos e devolve os `media_ids` para usar como `start_image`).
- **Quem paga (decisão do usuário, 05/11/2026):** o Kling 3.0 Turbo é gerado pelo **Higgsfield**, que é a fonte principal. O conector Kling (`kling-ai`) só entra como reserva, se o Higgsfield estiver sem crédito e o conector tiver saldo. Antes de gerar, conferir o saldo dos dois (`balance` no Higgsfield, `query_membership_and_credits` no conector Kling) e dizer qual vai pagar. Se os dois estiverem zerados, cair no parallax e nos pans e avisar o usuário; nunca recarregar nem trocar de plano sem ele pedir. Registrar o gasto de créditos na entrega.
- Custo no extrato (05/11/2026): `kling3_0_turbo`, 9:16, 1080p = 12 créditos por clipe (7,5 em 720p), saída 1080×1920. Gerar em 1080p só os clipes que entram no corte. Planos Plus ou acima liberam Kling 3.0 `pro` e Seedance 2.0 `std` (1080p/4k, foto de início e de fim): testar só com o OK do usuário, pois cada teste gasta crédito.
- Prompt padrão (inglês, ajustar só a palavra do cômodo): "Cinematic architectural walkthrough of a modern apartment [ROOM], 9:16 vertical aspect ratio. Ultra-smooth slow steadycam push-in shot, gliding gently forward across the room. Natural soft daylight, warm ambient lighting. Hyper-realistic materials, pristine interior architecture. Highly stable geometry, zero warping, strictly preserving walls and furniture shapes without morphing, photorealistic 8k, luxury aesthetic."
- Regras de movimento:
  - Push-in só quando o conteúdo do frame final já está visível no frame inicial, na direção do movimento — senão usar só o frame inicial.
  - Pan lateral usa prompt próprio (não o padrão de push-in), sempre "medium-paced" no mínimo — "slow" em espaços pequenos fica estático e entediante.
  - Portas de vidro, divisórias e espelhos ficam na borda do enquadramento ou fora dele, nunca no centro do caminho da câmera.
  - Nunca descrever no prompt elemento arquitetônico que não esteja 100% visível no frame JÁ CORTADO em 9:16 (o modelo inventa elementos, ex. parede virando janela, para satisfazer o texto).
- Quando houver dúvida sobre o Hero Shot de abertura, gerar as duas opções em paralelo em vez de escolher uma de antemão.
- Ao avaliar cada clipe: checar duração exata, ausência de pessoa/silhueta/reflexo humano, ausência de texto renderizado, direção do movimento, geometria estável, e se o elemento principal não saiu de quadro antes do fim do clipe (extrair frames em pontos intermediários para conferir). Nos clipes do Kling, conferir também luminária ou spot de forro que muda de lugar ou acende no meio do clipe (no 2918448 o spot foi para o montante da janela a partir de 3,4 s): usar o clipe só até o último quadro limpo e registrar o ponto de corte.
- Download dos .mp4 finais: tentar `curl` no sandbox de nuvem direto para a pasta do Mac via `device_commit_files`. Se o host de hospedagem do Higgsfield retornar 403 no proxy, entregar em vez disso um documento com os links diretos (rawUrl) mapeados aos nomes de arquivo do roteiro, para download manual pelo usuário.

### Amplitude primeiro — pan digital sem IA é o PADRÃO (regra permanente, imóvel 2913657)

O cliente quer SEMPRE os cômodos mostrados com amplitude, nunca com foco em uma parte. O corte 9:16 de foto horizontal mostra só uma faixa estreita, e o push-in do Kling fecha ainda mais. Por isso a base do Reel são cenas de pan digital sobre a foto ORIGINAL inteira, sem IA, 100% fiéis à foto. Aprovado no 2167152 (sala) e no 2913657, onde ele pediu mais duas cenas nesse formato (cozinha + living e varanda com jantar) e depois fixou a regra.

- **Um pan amplo por ambiente principal** sempre que existir uma foto horizontal aberta: área social (sala + jantar + varanda), cozinha integrada, varanda gourmet e suíte (se a foto aberta não tiver espelho no centro, pessoa ou objeto pessoal). Meta de **3 a 5 pans por Reel**.
- **Kling (push-in)** só complementa: hook, fechamento com vista, ou cômodo que não tem foto horizontal aberta. Nunca use Kling em recorte de detalhe (bancada, arandela, nicho) como cena principal de um cômodo.
- **Flow** (seção "Cenas pelo conector Google Flow", abaixo) também complementa: hook, transição entre ambientes ou entre imóveis. Os pans continuam sendo a base. **A transição de passagem de cômodo é a forma de Flow que o usuário aprovou** (2255516, `whip-pan` sala→cozinha: "ficou ótima para a ideia de tour por dentro do apartamento, junto com as cenas pans"): dentro do tour, ela entra entre dois pans de cômodos vizinhos.
- **Curadoria:** priorize as fotos que mostram mais do cômodo de uma vez (vários ambientes ou o cômodo de parede a parede). Fotos de detalhe viram reserva.
- **Faixa do pan, antes de renderizar (obrigatório).** Na planilha de contatos (~600 px) um pano, esponja, lixeira ou escorredor na bancada passa despercebido, e o clipe só é reprovado depois do corte planejado (2918448 e 3116143, nas duas vezes na cozinha). Para cada foto de pan, gere a faixa que a janela vai percorrer e olhe-a em tela cheia: `ffmpeg -i FOTO.jpg -vf "scale=-2:1920,crop=$((X1-X0+1080)):1920:$X0:0" -frames:v 1 FAIXA.jpg` (X0 e X1 na base de altura 1920). Item de limpeza, objeto pessoal ou de marca na faixa: mude X0/X1 para deixá-lo fora ou troque a foto. Vale também para `comodos` da landing.
- **Direção:** a janela 9:16 desliza com easing suave e TERMINA no ponto de maior valor (varanda, vista, churrasqueira, ilha). Pode ir da esquerda para a direita (X0 < X1) ou da direita para a esquerda (X0 > X1). Gere 6 s e corte em 5,0 s no CapCut.
- **Comando.** Largura da foto na altura de 1920 = L. Curso de 60 até L − 1080 − 60. Upscale 2x antes do crop para não tremer. O padrão é **pan + zoom suave (1,00 → 1,07)**, com os dois movimentos no mesmo easing de cosseno; o pan sem zoom (bloco logo abaixo) é a reserva.
  - Foto 3:2 (ex: 2000×1333): L = 2880, `scale=5760:3840`, curso 60 ↔ 1740.
  - Foto 16:9 (ex: 1280×720): L = 3413, `scale=6827:3840`, curso 60 ↔ 2273.

```
T=6; X0=60; X1=1740   # inverter X0/X1 para ir da direita para a esquerda
ffmpeg -loop 1 -framerate 30 -i FOTO.jpg -t $T \
 -vf "scale=5760:3840:flags=lanczos,crop=2160:3840:x='2*($X0+($X1-$X0)*(1-cos(PI*t/$T))/2)':y=0,scale=1080:1920:flags=lanczos,format=yuv420p" \
 -c:v libx264 -crf 16 -preset slow -r 30 SAIDA.mp4
```
- **Pan + zoom (padrão), verificado no 2918448** (7 clipes 1080×1920, 6 s, frames conferidos). O `crop` do ffmpeg não aceita largura que varia por quadro; por isso o zoom passa pelo `scale` com `eval=frame` e o X do crop é multiplicado pelo fator de zoom. Foto 3:2: base L = 2880, curso 60 ↔ 1740.

```
T=6; X0=300; X1=1740; Z=0.07; E="(1-cos(PI*t/$T))/2"
ffmpeg -loop 1 -framerate 30 -i FOTO.jpg -t $T \
 -vf "scale=w='trunc(5760*(1+$Z*$E)/2)*2':h='trunc(3840*(1+$Z*$E)/2)*2':eval=frame:flags=lanczos,crop=2160:3840:x='2*(1+$Z*$E)*(($X0)+(($X1)-($X0))*$E+540)-1080':y='(ih-3840)/2',scale=1080:1920:flags=lanczos,format=yuv420p" \
 -c:v libx264 -crf 16 -preset slow -r 30 -an SAIDA.mp4
```
- **Nome do arquivo:** `[PREFIXO]_C[NN]_[COMODO]_PAN.mp4` (ex: `C02_SALA_AMPLA_PAN`, `C09_COZINHA_LIVING_PAN`, `C10_VARANDA_JANTAR_PAN`).
- **Conferência:** frames em 0 / 1,5 / 2,5 / 3,5 / 4,9 s para garantir que nenhum trecho passe por pessoa, TV ligada, foto pessoal, espelho no centro, objeto de marca (notebook, rótulos) ou item de limpeza (pano, esponja).
- **Ordem no roteiro:** hook → primeiro pan amplo (área social, cena 2) → demais pans intercalados com os clipes Kling complementares → fechamento. Se um clipe Kling repetir o trecho de um pan (ex: push-in sala → varanda), o Kling sai do corte e fica como reserva.
- **Alternativa**, só se o cliente pedir profundidade: gerar no Kling em 16:9 a partir da foto inteira (push-in de no máximo ~8%) e aplicar o mesmo pan 9:16 por cima. Fica um pouco menos nítido e não é 100% fiel.

## Cenas pelo conector Google Flow (a partir do 2255516)

Além dos pans e do Kling, uma cena pode ser criada pelo conector `flow` (Google Flow). A dinâmica é a mesma do resto do pipeline: a **curadoria das fotos** decide, e a pergunta é "qual ambiente ou par de ambientes vira o momento mais impressionante que um pan não entrega?". As possibilidades são abertas; os exemplos do usuário são **sala → varanda** e **churrasqueira → sala**. Usos:
- **Hook** de abertura a partir da foto mais forte (a vista, a varanda).
- **Transição entre ambientes** (`first_frame` = foto de saída, `last_frame` = foto de chegada, mais um `technique` de `flow_techniques`, categoria `transition`).
- **Transição de um imóvel para outro**, quando houver outro imóvel com fotos já curadas. O clipe mostra um ambiente que não é do imóvel do Reel: pedir ao usuário onde usar, nunca descrevê-lo na copy, no roteiro nem nos textos de tela, e passar pela mesma curadoria (sem pessoa, marca ou reflexo) as fotos dos dois imóveis.

**Passo de curadoria.** Na seção "Candidatas a cena Flow" do `Catalogo_Fotos.md`, listar de 1 a 3 candidatas: tipo (hook, transição de ambientes, transição de imóveis), fotos de entrada e de saída, por que impressiona, modelo e custo estimado. Cada candidata nasce da curadoria, nunca de um capricho de prompt.

**Crédito e autorização.** O Flow gasta créditos Google AI do usuário (valores da descrição da ferramenta, conferir no momento: Omni 1.1 Flash 7-12, Veo 3.1 Lite 10, Fast 20, Quality 100). Nunca gerar sem o OK do usuário para aquela cena, sempre com `max_credits` na chamada (`flow_continue` não mostra cotação: perguntar antes). Falha do conector: parar e reportar o erro, sem refazer a geração nem mexer no conector sem pedido do usuário.

**Pré-requisito.** O projeto do Flow tem de estar aberto no Chrome do conector (perfil dedicado, porta 9333) e **sem painel de sessão do agente aberto**: o Flow abre um por padrão em projeto novo, ele cobre o compositor, o botão "Agent" some da página e todo job estoura o tempo. Com o botão "Start new session" visível, o painel está aberto: fechar à mão se a correção descrita em "Falhas conhecidas" ainda não estiver carregada. Pedir ao usuário que abra o projeto e esperar o "aberto" antes de chamar `flow_generate`.

**Como gerar (verificado no hook do 2255516).**
- `flow_generate` com `first_frame` = o pré-corte 9:16 da foto (mesma regra do Kling: `crop=ih*9/16:ih`, posição do corte escolhida por foto), `aspect_ratio` "9:16", `model` "Omni 1.1 Flash", `duration` 6, `resolution` "720p". Resultado: 720×1280, 6 s, sem áudio, 10 créditos. Acompanhar com `flow_wait`. O arquivo sai em `~/flow-mcp-out/<project>/scene-NN.mp4` (no 2255516, `scene-03.mp4`, 3,3 MB): o NN é a ordem do job, não a do roteiro, então copiar para `03_Cenas_Prontas/` já com o nome final.
- Prompt em inglês, uma câmera e um movimento só, descrevendo apenas o que já está visível nos quadros de entrada e de saída (mesma regra do Kling). Acrescentar sempre: "keep the sky color, lighting and every object exactly as in the first frame; no people, no text".
- Salvar em `03_Cenas_Prontas/` como `C[NN]_[DESCRICAO]_FLOW.mp4` e registrar o prompt, o modelo e os créditos em `Prompts_Cenas.md` (antes `Prompts_Higgsfield.md`), seção "Flow". Se vier com áudio, `ffmpeg -i in.mp4 -c:v copy -an out.mp4`.
- **Inspeção obrigatória** com `inspetor-clipes`, avisando que o clipe é do Flow: além do checklist de sempre, mudança de cor do céu ou da luz, objeto novo inventado e canto degradado. O hook do 2255516 mudou a cor do céu e inventou uma estrela, e só foi aproveitável cortado em 4,5 s. O Roteiro usa só o trecho aprovado ("Trecho a usar").
- No Roteiro, o clipe do Flow é complementar (hook até 4,5-5,0 s, demais 3,0-4,0 s) e respeita o limite de 36 s. Se repetir um pan do mesmo ambiente, um dos dois sai do corte. O arquivo do Flow tem 720×1280 e os pans 1080×1920: avisar no Bloco 1 para escalar no CapCut.

**Exercitados** no 2255516: `flow_generate` com `first_frame` (hook) e com `first_frame` + `last_frame` + `technique` `whip-pan` (passagem sala→cozinha, 10 créditos, 6 s/720p, projeto novo, job completo sem intervenção), `flow_status`, `flow_wait`, `flow_assets` e `flow_retry`. A transição saiu fiel nos dois extremos (primeiro e último frame batem com as fotos, sem objeto inventado), mas o Flow entrega o clipe **com faixa de áudio**: remover com `ffmpeg -c:v copy -an` antes de pôr no Roteiro, e prever a escala 720→1080 no CapCut. Pré-cortes 9:16 de ambas as fotos (1214×2160, recorte fixo) são feitos antes. Projeto do Flow: o conector antigo não criava (com a lista de projetos aberta o `flow_status` devolvia "no project is open"). A versão corrigida em `~/flow-mcp` (2026-11-04) cria o projeto sozinho no `flow_status` e nas gerações quando a aba está na lista de projetos, e abre uma aba se o Chrome estiver sem janelas; testado de ponta a ponta no Flow real (`flow_status` na lista de projetos criou o projeto e o job seguinte gerou o clipe sem intervenção). Vale só depois de o servidor `flow` reiniciar: o "reconectar" do app pode manter o processo antigo vivo, e enquanto o `flow_status` ainda pedir para abrir um projeto o código antigo está rodando. O primeiro processo `node …/flow-mcp/dist/index.js` é o dono do painel 8787 e os demais são clientes; encerrar o dono sem religar um deixa as ferramentas com "fetch failed" até um novo processo subir.

**Transições** (`technique`): `whip-pan` já testado e **aprovado pelo usuário** como cena de tour. `seamless-loop` também foi gerado (mesma foto da vista como primeiro e último quadro, 6 s, 10 créditos, fechou a 31,9 dB do quadro inicial), mas o usuário **não aprovou** o loop como cena do projeto: não propor loop como cena nem gastar crédito com ele; o que vale é a passagem entre ambientes. Os outros (`into-the-screen`, `before-after`, `match-cut`, `logo-resolve`, `particle-dissolve`, `axis-flip`) seguem sem teste; todos pedem primeiro e último quadro, menos `seamless-loop`, que usa a mesma imagem nos dois, e `logo-resolve`, que pede o logo como último quadro.

**Não testados** (a descrição da ferramenta diz que existem; ninguém rodou aqui): `chain_previous`; `flow_continue`; `download_quality` "upscaled"; `flow_assemble`; `variants`. Ao usar um deles, relatar o que de fato aconteceu para reescrever o item e tirá-lo desta lista.

## Parallax 2.5D e mix de cenas (a partir do 3066087)

Objetivo: o tour mais realista possível com as fotos disponíveis, sem inventar nada que não existe no apartamento. A fidelidade à foto real vem antes de qualquer efeito. Esta seção complementa "Amplitude primeiro" e "Cenas pelo conector Google Flow"; onde houver divergência, vale a regra mais restritiva (por exemplo, o OK do usuário por cena do Flow).

### Mix de cenas por Reel
| Tipo de cena | Técnica | Uso |
|---|---|---|
| Plano amplo do cômodo | Pan + zoom digital (ffmpeg, sem IA) | Base do tour, 60 a 70% das cenas. Mostra a dimensão do cômodo |
| Câmera deslizando pelo ambiente | **Truck parallax** | 1 a 2 por Reel, em cômodos com objetos em primeiro plano (sofá, bancada, cama, mesa) |
| Gancho (C01) e fechamento | **Dolly parallax** | Substitui o Kling quando acabarem os créditos do Higgsfield |
| Passagem entre cômodos ou ângulo que nenhuma foto cobre | **Google Flow** (flow-mcp, Frames to Video, `whip-pan` aprovado no 2255516) | 0 a 2 cenas por Reel; a passagem entre dois cômodos vizinhos, entre dois pans, é a cena de Flow preferida no tour interno |

- Ordem das cenas pela planta real: entrada, sala, varanda, cozinha, quartos, suíte.
- Mesmo sentido de movimento entre cenas vizinhas; match cut em portas e aberturas.
- Pan e truck se somam: nunca trocar um pelo outro por padrão. Um Reel bom alterna pan amplo, truck e hero shot.
- Enquanto houver créditos no Higgsfield, o Kling continua permitido nos ganchos. Critério para trocá-lo: o parallax empatar ou ganhar no mesmo checklist de frames.

### Parallax 2.5D (sem IA generativa)
Um mapa de profundidade da própria foto move uma câmera virtual alguns centímetros dentro do cômodo: o primeiro plano se desloca mais que o fundo. Só reaproveita pixels da foto, por isso não aparece móvel, viga ou janela inventada. Saída: 1080×1920, 30 fps, sem áudio.

Ferramentas, em `/Users/wandersondelima/AgenteSocialMedia/_ferramentas/`:
- `depth.py`: gera o mapa de profundidade (modelo Depth-Anything-V2-Base, CPU do sandbox, cerca de 15 s por foto). Verificar que o arquivo existe antes de depender dele; se faltar, avisar o usuário em vez de recriá-lo por conta própria.
- `parallax.py`: renderiza o clipe.

Dependências do sandbox (instalar no início de cada sessão): `pip install --break-system-packages torch --index-url https://download.pytorch.org/whl/cpu` e `pip install --break-system-packages transformers opencv-python-headless pillow`. Rodar os scripts com `python3 -I`.

Comandos validados no 3066087 (foto 3000×2000, janela 9:16 de 1125 px):
```
# Truck: câmera desliza para a direita (pan0 positivo → pan1 negativo = câmera vai para a direita)
python3 -I parallax.py FOTO.jpg FOTO_depth.npy SAIDA.mp4 --x0 800 --pan0 300 --pan1 -250 --dolly 0.03 --T 6 --ss 1

# Dolly: avanço de ~22% em direção ao ponto de interesse (cx,cy = ponto para onde a câmera avança, em px da foto)
python3 -I parallax.py FOTO.jpg FOTO_depth.npy SAIDA.mp4 --x0 510 --cx 1150 --cy 900 --dolly 0.18 --T 6 --ss 1
```
- `--x0`: borda esquerda da janela 9:16 dentro da foto (escolher para deixar fora objetos pessoais, marcas e árvore de Natal).
- `--dolly`: 0.10 = primeiro plano cresce cerca de 11%. Padrão para hero shots: 0.15 a 0.18.
- `--ss 1` na produção; `--ss 2` só para uma checagem final de bordas.
- `--blur 5 --dilate 9 --steps 48`: padrões que eliminam a tremida nas bordas (não mexer sem motivo).
- Render: alguns minutos por clipe de 6 s; gerar em segundo plano. Truck gera 6 s; cortar o trecho útil no CapCut, como nos pans.

Regras de uso:
1. Truck: o intervalo de origem `x0 + pan` precisa ficar dentro da foto (0 a largura). Conferir antes de renderizar.
2. Dolly com rede de proteção, grade ou malha fina: no máximo `--dolly 0.08`; acima os fios ondulam e se partem. Preferir pan comum.
3. Nunca vidro ou espelho no centro do caminho da câmera (mesma regra do Kling).
4. O parallax não atravessa portas nem revela o que está atrás de parede. Para isso: corte seco, match cut ou Google Flow.
5. Foto 3000×2000: o corte 9:16 sai com 1125 px, sem upscale. Foto de 2000×1333 sai com cerca de 750 px: fazer upscale antes, se necessário.
6. Na curadoria, preferir fotos da vista sem rede de proteção. Se só houver foto com rede, dolly sutil ou pan.

Checklist de aprovação (frames em 0 / 2,5 / 3,5 / 4,9 / 5,9 s):
- Bordas de almofadas, listras de tecido e cantos de móveis sem tremida nem estiramento; fios de rede, grades e batentes sem ondulação.
- Objeto indesejado (brinquedo, árvore de Natal, porta-retrato, marca) fora do quadro ao longo do movimento.
- O elemento de venda (janela, vista, painel, bancada) continua em quadro.
- Reprovou: reduzir `--dolly` ou o curso do pan, ou trocar por pan comum.

### Google Flow via flow-mcp (só para o que a foto não resolve)
Estado: o repositório GTAI-1/flow-mcp está em `~/flow-mcp` no Mac, e controla o Chrome clicando na interface do Flow (Playwright ligado ao Chrome por CDP na porta 9333, perfil `~/.flow-mcp/chrome-profile`, painel local em 127.0.0.1:8787, saídas em `~/flow-mcp-out`; plano Google AI Pro: 1.000 créditos por mês + 50 por dia). **Exercitado uma vez**, no hook do 2255516 e com `first_frame` (hook) e com `first_frame` + `last_frame` (transição `whip-pan` sala→cozinha, fiel nos dois extremos; ver "Como gerar"). O painel do agente não chegou a abrir no projeto novo do segundo teste: a correção não falhou, mas ainda não foi provada contra o painel aberto. Se as ferramentas `flow_*` não estiverem conectadas, usar o Flow manualmente, com o mesmo fluxo e os mesmos prompts. Vale a seção "Cenas pelo conector Google Flow" para crédito e autorização (OK do usuário por cena, `max_credits` sempre).
- Usar: `flow_generate` com `first_frame` e `last_frame` (duas fotos reais; o modelo só interpola), `flow_continue` / `chain_previous`, `flow_download` (1080p ou 2K sem gastar crédito), `flow_status` e `flow_wait`.
- Não usar: `flow_agent_images` (gera imagem nova e traz a "maquiagem"), `flow_narrate` e `flow_captions` (narração e legenda ficam no CapCut), `flow_assemble` (não entregar prévia montada).
- Configuração fixa: Gemini Omni 1.1 Flash, 9:16 forçado, 1 variante, `max_credits: 15` (custo informado: 15 créditos por 10 s em 720p; verificado no 2255516: 6 s em 720p custa 10 créditos, então `max_credits: 12` basta para 6 s). Clipes mudos.
- Quando: passagem entre cômodos (porta, corredor, abertura) com duas fotos reais dos dois lados; ou completar um cômodo cujo ângulo nenhuma foto cobre, com duas fotos reais do mesmo cômodo. Pré-cortar as duas fotos em 9:16 sem elementos indesejados.

Prompt-base (inglês, colar sempre com a confirmação por escrito):
```
Animate the two uploaded photos directly: first frame = photo A, last frame = photo B. Interpolate only the camera movement between them. 9:16 vertical, ultra-smooth slow steadycam, straight line, no rotation. Strictly preserve every wall, floor, ceiling, furniture, material, color and lighting exactly as in the photos. Do not add, remove, invent or improve any furniture, object, light fixture or architectural element. No people, no text, no reflections of people. Do not generate a new image: animate the uploaded photos.
Confirm in writing before generating: aspect ratio 9:16, first frame = photo A, last frame = photo B, 1 variant.
```
Checklist de aprovação (frames em 2,5 / 3,5 / 4,9 s): mobília ou objeto inventado (em apartamento vazio, isso primeiro); material, luz ou layout diferente das fotos; pessoa, silhueta ou reflexo humano, texto, formato diferente de 9:16; geometria deformada, porta ou parede virando outra coisa. Reprovou: não gastar mais crédito; corte seco ou pan.

Cuidados: o README do flow-mcp diz que o uso automatizado pode violar os termos do Google e a conta é a principal; usar em baixo volume, nunca em lote noturno, e preferir o Flow manual na dúvida. Um clipe por vez, pausa de 25 a 70 s entre eles; o Mac precisa ficar acordado e com a tela ligada. Pode quebrar quando o Google mudar a interface: se falhar, voltar ao Flow manual.

**Falhas conhecidas e como diagnosticar.**
- *Timeout esperando o botão "Agent"*, três vezes seguidas no 2255516. Causa real: o painel de sessão do agente aberto cobrindo o compositor. A hipótese do idioma da interface estava errada: o erro continuou com o Flow em inglês. A correção está em `ensureProjectGrid`, em `~/flow-mcp/src/flow.ts`: se o botão "Start new session" estiver visível, fecha o painel antes de procurar o "Agent". Está compilada em `dist/`, mas só vale depois de o usuário reconectar o servidor `flow` (a ferramenta de reconexão da sessão só atua em conectores com falha, e o `flow` aparece como conectado mesmo com o código antigo). Ainda não foi commitada nem testada de ponta a ponta com o servidor reconectado; se o repositório for atualizado, conferir com `grep -n "Start new session" src/flow.ts`.
- *Diagnóstico:* erro de "elemento não encontrado" se investiga olhando a página real da aba do conector (captura ou árvore de acessibilidade) antes de levantar hipótese. A mesma falha três vezes seguidas: parar e reportar. Investigar ou corrigir o conector só com pedido do usuário.
- *Gasto:* as falhas por timeout aconteceram antes do envio; o gasto registrado foi só o do clipe final, 10 créditos.

Teste de validação (primeiro imóvel em que for usado com duas fotos): o 2255516 já cobriu "hook a partir de uma foto"; a passagem de cômodo (sala→cozinha, `whip-pan`) também foi coberta e aprovada pelo inspetor; falta o completar-cômodo (duas fotos do mesmo ambiente). Gerar, aplicar o checklist e registrar na memória do projeto se o resultado atende. Só depois tornar rotina.

### Entrega e registro por imóvel
- Arquivos em `03_Cenas_Prontas/` com prefixos `PLX_Cxx_..._TRUCK.mp4`, `PLX_Cxx_..._DOLLY.mp4`, `FLOW_Cxx_....mp4`, além dos `..._PAN.mp4` de sempre. Todos sem faixa de áudio.
- `04_Documentos/Prompts_Higgsfield.md` passa a se chamar `Prompts_Cenas.md`, com os comandos exatos (pan, truck, dolly, Flow) de cada cena.
- `Roteiro_CapCut.md` mantém os 3 blocos. No Bloco 1, informar a técnica de cada clipe e o trecho útil em segundos, conferido por frames (nunca duração uniforme). Não entregar prévia montada.
- Depois de cada imóvel, anotar na memória do projeto: técnicas que funcionaram, valores de `--dolly` e `--x0` aprovados, cenas reprovadas e o motivo, e se o Flow foi usado e com que resultado.

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
- Gerar no Kling (e no Flow, depois do OK do usuário) só os clipes que vão entrar no corte, mais 1-2 de reserva, para não gastar crédito com cenas que o limite de tempo vai descartar. Os pans não gastam crédito: gere um para cada foto ampla boa e escolha no roteiro.

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
- **Fronteira do que a copy pode afirmar (decisão do usuário, 05/11/2026; vale para legenda, roteiro e landing; o `revisor-ficha-copy` cobra as mesmas regras):**
  - Móvel solto visível na foto (mesa de jantar, sofá): pode ser citado se a foto usada no Reel o mostra, sempre com o aviso "mobília ilustrativa". Continua proibido prometer "mobiliado".
  - Material ou característica lida na foto ("bancada em pedra", "varanda envidraçada"): só se estiver na ficha ou for inconfundível numa foto que aparece no Reel.
  - Frase guarda-chuva ("lazer completo", "vista panorâmica"): só quando cada comodidade esperada consta como SIM na ficha; se alguma for NÃO, listar as comodidades SIM em vez da frase.
  - "Portaria 24h", "piscina aquecida", "varanda gourmet" e afins só se o termo estiver na ficha. Distâncias: "perto de" quando não foram medidas; número só se medido no mapa.
- No bloco de comparação de preço, nunca citar valores de m² ou média da região — argumentar de forma qualitativa ("preço competitivo para o padrão da região").
- CTA de captação (regra a partir de 04/11/2026, decisão do usuário): todo Reel novo sai com a landing page, então o CTA do Instagram é o de comentário com a palavra da automação Zernio (abaixo). O CTA de WhatsApp vale para o TikTok e para o Reel sem landing page. Em qualquer CTA: uma ação só, benefício concreto (ficha completa + visita reservada) e fricção mínima ("número com DDD"). Nunca inventar urgência que não existe (ex: "últimas visitas da semana").
  - Instagram, Reel COM landing page e automação Zernio (padrão a partir do 3066087; a palavra vem do Checklist da seção "Automação Comentário → Direct → Landing"). Antes do `🔗 QuintoAndar #[CODIGO]`:
    ```
    📋 Quer a ficha completa deste apartamento?
    💬 Comente {PALAVRA_SEM_ACENTO} e receba no seu direct o tour completo com todos os detalhes.
    📲 Prefere falar com um corretor? Me chame no direct.
    ```
    Comentário fixado no Reel:
    ```
    📋 Ficha completa deste apartamento: comente {PALAVRA_SEM_ACENTO} e receba o link no seu direct.
    ```
  - Instagram, Reel SEM landing page (imóvel antigo, sem automação): use o bloco de WhatsApp abaixo, sem o comentário.
    ```
    📲 Receba a ficha completa no seu WhatsApp: envie seu número com DDD no direct.
    🗓️ Prefere conhecer pessoalmente? Pelo mesmo direct, reservamos o horário da sua visita.
    ```
  - TikTok (substitui o antigo "link da bio"; sem automação no TikTok, este CTA não muda):
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
- Nunca montar a página do zero nem redesenhar por conta própria: sempre a partir do modelo.
- **Reforma de marca ou de layout, só com pedido explícito do usuário** (feita em 05/11/2026): edita-se `site/_modelo/index.html` e propaga-se às páginas já publicadas por script de substituição com contagem exata, depois de provar que os blocos compartilhados (cabeçalho, rodapé, `<symbol>` da marca, favicon) são idênticos em todas as páginas (hash dos blocos; se uma página divergir, o script falha e a página é tratada à mão). Republicar **todas** as pastas alteradas, não só a home, e atualizar "Padrões que não mudam" aqui no mesmo dia.

### Passos, sempre nesta ordem
1. `./scripts/nova-landing.sh <codigo> <bairro>` cria `site/<codigo>/<bairro>/`.
   - **Atalho a partir do link do QuintoAndar:** `python3 ./scripts/landing/landing.py prep <link> [--apto N]` (passar o número do apartamento sempre que o intake trouxer; sem ele o IPTU confere outra unidade), preencher `_trabalho/<codigo>/imovel.json`, `landing.py build <codigo>` (faz os passos 1–3 de uma vez, com pan digital local; ver `./scripts/landing/README.md`).
2. Trocar só os trechos marcados com `TROCAR` em `index.html`: `<title>`, meta e Open Graph, hero (bairro, título, destaques, preço com mês de referência, condomínio, IPTU em 10x), ficha técnica, listas do apartamento e do condomínio, região e link do Google Maps, `CONFIG` (código e título), `ROOMS` (um item por cômodo, na ordem do tour) e o código no rodapé. As tags Open Graph (`og:image`, `og:title`, `og:description`) são obrigatórias: o cartão do direct automático lê os três direto da landing.
3. Mídia reaproveitada do próprio Reel, com o mesmo `id` de cômodo em todas as pastas: `img/foto/<id>.jpg` foto original 3:2 a 1600 px; `video/<id>.mp4` o clipe 9:16 do cômodo (pan digital ou Kling, sem áudio, recomprimido para ≤ 1,5 MB); `img/poster/<id>.jpg` primeiro frame do clipe a 720 px; `img/thumb/<id>.jpg` 168×112. A foto do hero é a mais ampla da área social (se o `id` não for `living`, trocar a URL em `.hero{background:...}`).
4. Testar localmente (`python3 -m http.server` dentro de `site/`) no celular (390 px) e no desktop (1440 px), de preferência com Playwright: vídeo tocando, fotos carregando, sem rolagem lateral, sem erro no console. **Interceptar o envio do formulário** (rota para `script.google.com`) para não gravar lead falso; conferir que o payload sai com interesse "Agendar uma visita".
5. Mostrar a prévia ao usuário (artifact) e **só publicar depois do OK**.
6. Publicar a pasta do imóvel na Hostinger em `public_html/<codigo>/<bairro>/`: `hosting_files_generate-upload-url` (usuário e domínio do `.hostinger/site.json`) e upload TUS de cada arquivo (`POST` com `Upload-Length`, depois `PATCH` com o conteúdo; conferir `Upload-Offset` = tamanho). **A URL de cada arquivo leva `?override=true`** (`{url}/<caminho>?override=true`, no `POST` e no `PATCH`): sem isso, um arquivo que já existe no servidor, como o `index.html` da página inicial, responde 409 Conflict (verificado em 07/11 e 08/11/2026). Com o parâmetro, o upload sobrescreve só os arquivos enviados.
7. Registrar no GitHub antes de mexer na página inicial: acrescentar o imóvel na lista `IMOVEIS` do `site/index.html` (código, endereço da página, bairro, título, foto, área, quartos, vagas, preço, destaque). **Antes do commit, conferir a sintaxe da lista** (uma vírgula faltando derruba a home inteira): `node -e 'const h=require("fs").readFileSync("site/index.html","utf8");const m=h.match(/const IMOVEIS = \[\n([\s\S]*?)\n\];/);console.log(new Function("return ["+m[1]+"]")().length)'` tem de imprimir o número de cartões. Depois, commit numa branch nova a partir da `main` atualizada, push e pull request para a `main`. O próprio agente faz o merge, sem perguntar (decisão do usuário em 29/10/2026): `gh pr merge <n> --merge` rodando **sozinho**, sem `&&` com outros comandos, porque a regra de permissão `Bash(gh pr merge *)` só cobre o comando isolado. Depois, `git checkout main`, `git pull` e apagar a branch local. Só parar e perguntar se o merge der conflito.
8. Publicar a página inicial **só a partir da `main`**, nunca de uma branch: outras sessões podem ter publicado cartões que a branch não tem. Antes do upload, baixar o `index.html` do ar e compará-lo com o da `main` de antes do merge (`git show HEAD^1:site/index.html`). Os dois têm de ser idênticos, e a diferença para o arquivo que vai subir tem de ser só o cartão novo. Se o ar tiver algo que a `main` não tem, parar e avisar.
   - Se o merge for barrado pelo controle de permissões: publicar a partir da branch só se o ar for igual à `origin/main` e a branch diferir apenas pelo cartão novo; deixar o PR aberto e pedir ao usuário o merge pelo link.
9. **Limpar o cache:** `hosting_cache_clear` (scope `all`) no domínio. A CDN guarda cópias antigas e o celular pode receber a versão velha mesmo com o servidor certo.
10. Conferir no ar com navegador de verdade, não só `curl`: página do imóvel, página inicial (cartão novo aparecendo), política de privacidade, um vídeo, uma foto e um endereço inexistente (deve cair na página 404). Conferir também que todo código de `IMOVEIS` na `main` tem cartão na página inicial no ar e que o `site/index.html` da `main` é idêntico ao do ar.
11. Colocar o link na copy no lugar de "link da bio". Link entregue pelo direct automático: UTM completa (ver "Automação Comentário → Direct → Landing"). Bio e anúncio: `?ref=bio` e `?ref=ads` (ou `utm_medium=bio`). Link na legenda e no comentário não é clicável no Instagram: a entrega do link do Reel é pelo direct.
12. Com a landing no ar e conferida, seguir para a seção "Automação Comentário → Direct → Landing" (depois de publicar o Reel).

### Padrões que não mudam
- Endereço: `/<codigo-do-imovel>/<bairro>/`, com o bairro postal do Passo 0, minúsculo, sem acento (ex.: `/3066087/indianopolis/`).
- Visual: grafite em gradiente, champanhe como único acento, **Poppins como única fonte** (pesos leves nos títulos, 600 nos preços; o usuário recusou serifa). Ordem: hero em tela cheia → tour em vídeo → galeria de fotos → detalhes com o formulário ao lado (fixo no desktop). O nome "Marcelo Osawa" leva à página inicial; o rodapé tem "Outros imóveis à venda".
- **Marca (decisão do usuário em 05/11/2026):** monograma **anel** (M dentro de um anel duplo, traço fino champanhe), em SVG inline como `<symbol id="i-marca">` e no favicon; fontes em `site/_marca/` (README, `monograma.svg`, `favicon.svg`). Lockup: "Marcelo Osawa" em destaque e "Imóveis" embaixo, menor, em caixa alta espaçada; em `<title>`, `og:site_name` e textos corridos, "Marcelo Osawa Imóveis" (igual ao Instagram). Sem ícone de prédio nem ícones decorativos no cabeçalho.
- **CRECI não aparece em lugar nenhum do site** (home, páginas de imóvel, privacidade, 404, modelo), por decisão do usuário em 05/11/2026, avisado de que a norma do COFECI exige o número em anúncios. Se voltar a ser exigido, o lugar é a linha `.legal` do rodapé, nunca ao lado do nome.
- **Página inicial:** o hero não usa foto de imóvel (qualquer apartamento pode ser vendido a qualquer momento); a marca é o elemento visual e o Open Graph é fixo em `site/img/og-home.jpg`. A barra de filtro (bairro, faixa de preço, suítes, vagas) e a ordenação leem os campos de texto de cada item de `IMOVEIS`: nada a preencher a mais por imóvel. Os cartões continuam no modelo de `IMOVEIS` (código, url, bairro, título, foto, alt, área, quartos, vagas, preço, tag).
- Formulário só de agendamento de visita (nome + WhatsApp). Grava na planilha "Leads Imóveis" com interesse "Agendar uma visita" e abre o WhatsApp com a mensagem pronta. WhatsApp, endereço da planilha (`CONFIG.endpoint`), versão do consentimento e política de privacidade são os mesmos para todos os imóveis: nunca trocar.
- O aviso de lead novo sai por e-mail de contato@marceloosawaimoveis.com.br para ele mesmo (Apps Script da planilha); nada a fazer por imóvel.
- Textos seguem as regras da copy: só dados da ficha, sem nome do condomínio, sem "mobiliado", sem valores de m² da região.
- A raiz do domínio é a página inicial estática com os imóveis. Não existe WordPress nem outro CMS; endereço inexistente cai em `/404.html`. O `hosting_websites_list` da Hostinger mostra `website_type: wordpress` por uma classificação antiga: ignorar.

### Imóvel vendido ou retirado
Vale para venda pela equipe ou por outro corretor do QuintoAndar: o site só mostra imóveis à venda.
1. `python3 ./scripts/remover-imovel.py <codigo> --dry-run` (mostra o cartão que sai e confere a sintaxe da lista), depois sem `--dry-run`: tira o cartão de `IMOVEIS`, apaga a pasta `site/<codigo>/<bairro>/` deixando só o `.htaccess` com o `RedirectMatch 301` para a página inicial, e imprime o que falta.
2. Passos 7 a 10 (PR, merge, página inicial a partir da `main`, cache, conferência no ar: cartão sumiu e o endereço antigo redireciona para a inicial).
3. No servidor: `--remoto`, com `HOSTINGER_FILES_URL`, `HOSTINGER_AUTH_KEY` e `HOSTINGER_REST_KEY` no ambiente (valores de `hosting_files_generate-upload-url`), apaga a pasta pela API do File Browser e sobe o `.htaccess` e o `index.html`. **Ainda não exercitado contra a Hostinger** (05/11/2026): na primeira venda real, conferir o resultado e, se funcionar, apagar este aviso. Sem credenciais no ambiente, fazer o lado do servidor à mão, como abaixo.
4. Fora do site: tirar o Reel do Instagram e do TikTok e pausar a automação Zernio do post.

Reserva manual (quando o script ou o `DELETE` falhar):
- **Apagar a pasta do imóvel no servidor:** o conector da Hostinger não tem operação para apagar arquivo. Use a API do File Browser com as mesmas credenciais do upload (`auth_key` e `rest_auth_key` do `hosting_files_generate-upload-url`): `DELETE {base}/api/resources/public_html/<codigo>/<bairro>/` com os cabeçalhos `X-Auth` e `X-Auth-Rest`, onde `{base}` é a `url` do upload sem o final `/api/tus/public_html`. Responde 204 e apaga a pasta inteira (verificado em 29/10/2026 com arquivo e pasta descartáveis). O endpoint não é documentado: se não responder 204, use a reserva abaixo e avise o usuário.
- **Redirecionar o endereço antigo:** subir em `public_html/<codigo>/<bairro>/.htaccess` a linha `RedirectMatch 301 ^/<codigo>/<bairro>/.* https://marceloosawaimoveis.com.br/`, que leva para a página inicial qualquer arquivo da pasta, apagado ou não. Também é a reserva quando o `DELETE` falhar. Registrar o `.htaccess` no repositório no lugar da pasta.
- Limpar o cache e conferir no ar: a página antiga redireciona para a página inicial e o cartão sumiu.

## Automação Comentário → Direct → Landing (Zernio, a partir do 3066087)

Playbook atualizado em 04/11/2026; modelo cartão **validado** com conta que não segue o perfil. Automações no ar neste modelo: Reel 3066087 (Av. dos Eucaliptos, Indianópolis) e Reel 2255516 (Rua Eugênio de Medeiros, Pinheiros). Vale só para Instagram; TikTok não tem automação. O comentário com a palavra dispara um direct em **cartão** (foto da landing + título + subtítulo + botão "Ver o apartamento" com o link da landing e UTM). A automação é criada **já ativa** assim que o usuário manda o link do Reel e o ID numérico do post bate; ele não precisa dizer "ative" (regra do usuário desde 05/11/2026, vale para todos os próximos).

### Checklist por imóvel
1. Landing no ar e conferida (preço igual ao da legenda), com `og:image`, `og:title` e `og:description` preenchidos: o cartão usa os três.
2. Escolher a palavra: bairro ou rua, única entre os imóveis ativos. `{PALAVRA}` com acento e `{PALAVRA_SEM_ACENTO}`. Evitar palavra comum que aparece em comentário solto (ex.: "vista" dispara em "que vista linda").
3. Publicar o Reel com o CTA de comentário (seção Copywriting) e fixar o comentário.
4. Rodar o prompt abaixo na Zernio (cria e ativa) e conferir o ID do post antes de criar.
5. Com a automação ativa, testar com **uma conta que não segue o perfil**: o cartão chegou (foto, título, botão), o botão abre a landing certa com UTM, a resposta pública saiu, a mensagem caiu na aba "Pedidos" e não houve disparo repetido.
6. Lead de teste no formulário da landing e conferir a linha no Google Sheet.
7. Acompanhar histórico de disparos e cliques nos primeiros dias (com botão ou cartão os cliques passam a contar).

### Prompt reutilizável da Zernio
Trocar: `{SHORTCODE}`, `{PALAVRA}`, `{PALAVRA_SEM_ACENTO}`, `{BAIRRO}`, `{CODIGO}`, `{bairro_slug}` (o mesmo slug da landing), `{AAAAMMDD}` (data da publicação do Reel), `{TITULO_OG}` (`og:title` da landing, até 80 caracteres), `{SUBTITULO}` (preço + frase curta da `og:description`), `{IMAGEM_URL}` (`og:image` da landing, no próprio domínio, ex.: `.../img/foto/sala.jpg`). Ler os três campos direto da landing publicada.

```
Pode criar a automação e deixá-la ATIVA.

Post: o Reel {SHORTCODE} (localize o ID numérico e me confirme que é o post certo antes de criar).

- Gatilho: comentário no post
- Palavras: {PALAVRA}, {PALAVRA_SEM_ACENTO}
- Comparação: palavra inteira, com tolerância a erro de digitação
- Público: qualquer pessoa
- Repetição: uma vez por pessoa

Direct em CARTÃO (sem botão avulso, o botão fica dentro do cartão):
- Texto de reserva: "Olá! Tour completo deste apartamento em {BAIRRO} 👇\nQualquer dúvida, é só responder aqui."
- Cartão genérico, 1 elemento:
  - imagem: {IMAGEM_URL}
  - título: {TITULO_OG}
  - subtítulo: {SUBTITULO}
  - botão de link "Ver o apartamento": https://marceloosawaimoveis.com.br/{CODIGO}/{bairro_slug}/?utm_source=instagram&utm_medium=dm_comentario&utm_campaign={CODIGO}_{bairro_slug}&utm_content=reel_{AAAAMMDD}

Resposta pública (3 variações, enviada só depois do direct):
1. "Enviei no seu direct 📩 Se não aparecer, confira a aba Pedidos."
2. "Te mandei o tour por mensagem 🙂 Não achou? Olhe em Pedidos."
3. "Pronto, está no seu direct com todos os detalhes. Se estiver em Pedidos, é só aceitar."

Rastrear cliques no link: sim. Não ocultar comentário.

Depois de criar, me mostre a configuração completa (ID do post, palavras, modo, textos, cartão, regras, status) para eu conferir.
```
O título do cartão segue as regras da copy: nome da rua sem número, sem nome do condomínio, só dados da ficha.

### Padrão de UTM
Sempre minúsculas, sem acento, `utm_campaign` no formato `CODIGO_bairro`.

| Campo | Identifica | Exemplo |
|---|---|---|
| `utm_source` | Plataforma | `instagram` |
| `utm_medium` | Entrada | `dm_comentario` (outros: `dm_direct`, `bio`, `stories`) |
| `utm_campaign` | Imóvel | `3066087_indianopolis` |
| `utm_content` | Publicação | `reel_20261024` |

A landing do modelo já lê `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` e `ref` e usa o primeiro que existir como origem do lead (visto em `site/_modelo/index.html`); nada a mudar no site por imóvel.

### Regras
- **Direct em cartão** (padrão validado em 04/11/2026). O cartão só aparece no app do Instagram, não no Instagram web: aceito, porque a maioria dos clientes usa o celular. Não voltar para texto com link corrido (ficava feio e perdia o preview).
- **Não seguidor recebe o direct na aba "Pedidos"** (regra do Instagram). As respostas públicas já avisam; não existe resposta pública diferente para seguidor e não seguidor (um só conjunto por automação, o Instagram não revela quem segue no momento do comentário).
- **Trocar o formato de uma automação existente exige recriar:** a ferramenta de edição não aceita o campo do cartão. Fluxo: pausar a antiga e criar a nova já ativa. A Zernio só aceita uma automação ativa por post (erro 409).
- Um botão só (3 botões derrubam a conversão). Tamanho máximo do direct: 1.000 bytes, só texto puro. Sem redirecionamento nem encurtador no link (podem quebrar o preview). O preview de link do Instagram fica em cache por até 30 dias por URL.
- Uma vez por pessoa. A resposta privada vale até 7 dias após o comentário e só existe uma por comentário; se o direct falhar, o histórico mostra o erro exato.
- Respostas no direct precisam de humano no inbox em até 24 h; sem monitoramento, remover do texto de reserva a frase "é só responder aqui".
- Preço na legenda, sem nome de condomínio.
- Reel sem landing page: sem automação, CTA "Quer visitar? Me chama no direct".

## Entrega final

Entregar tudo de uma vez (nunca em etapas separadas): catálogo de fotos selecionadas, prompts de geração de vídeo, roteiro CapCut completo (3 blocos), copy de Instagram (TXT/MD), copy de TikTok (TXT/MD) e o **link da landing page publicada** (já na página inicial, com cache limpo e conferida no celular) — como arquivos separados em `/outputs`, apresentados juntos. A copy de Instagram já sai com o CTA de comentário e a palavra escolhida; o prompt da Zernio no modelo cartão, com tudo preenchido (inclusive título, subtítulo e imagem lidos da landing) menos `{SHORTCODE}` e `{AAAAMMDD}`, vai junto num arquivo `Zernio_Automacao` (o Reel ainda não foi publicado na entrega, então os dois dados faltantes são preenchidos depois).

**Antes de dizer que um arquivo está pronto** (pacote, roteiro, zip da skill para o claude.ai), liste-o no disco na mesma resposta (`ls -l` com o tamanho) e cite essa listagem; nunca repita o caminho de um resumo anterior. Um zip anunciado sem existir já fez o usuário perder uma subida (v4).

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