---
name: publicar-crm
description: "Publica alterações do CRM de leads (pasta app/ do repositório do site, servida em marceloosawaimoveis.com.br/app) na Hostinger: roda a bateria de testes, compara cada arquivo do Mac com a main e com o servidor, envia só o que mudou, amplia o banco quando o esquema muda, confere o sistema no ar e fecha com PR e merge. Use em \"publica o CRM\", \"sobe a mudança do /app\", \"/publicar-crm\" e ao terminar qualquer ajuste no CRM. Landing de imóvel, página inicial e imóvel vendido são da skill reels-imoveis-alto-padrao."
metadata:
  requires: "binary:php -v; binary:sqlite3 -version; binary:gh --version; binary:git --version; binary:curl --version; binary:openssl version"
---

# Publicar o CRM de leads

Skill interna do projeto Marcelo Osawa Imóveis. O CRM é o sistema em `app/` do repositório
`site-repo` (PHP 8.3 + MySQL na Hostinger). O trabalho pesado fica em `scripts/crm/publicar.sh`;
esta skill diz em que ordem chamar e o que fazer quando ele para.

## Antes de publicar (ao escrever a mudança)

- **Mudou o banco?** O ajuste entra em `migrar()` (`app/inc/schema.php`), idempotente, e o `CREATE TABLE`
  de instalação nova recebe o mesmo valor. O comando roda `migrar()` no servidor antes do código novo.
- **Ação nova de gravação?** Entra na lista `$acoes` de `app/index.php`. É ali que a barreira do perfil
  Gerente recusa tudo que grava; uma ação fora da lista não existe para ninguém.
- **Comportamento novo?** Ganha teste em `scripts/crm/testes.sh` na mesma mudança.
- Configuração (`app/inc/config.php`) e dados locais (`app/inc/_dados/`) nunca sobem nem entram no Git:
  o comando já os ignora e recusa um commit que os contenha.

## Passos

1. **Credenciais de envio.** No conector da Hostinger do claude.ai (o plugin local costuma estar sem
   autorização), rode `hosting_files_generate-upload-url` com o `username` e o `domain` de
   `.hostinger/site.json`. Guarde `url`, `auth_key` e `rest_auth_key`; valem algumas horas.
2. **Verificar.** Na raiz de `site-repo`:
   `HOSTINGER_FILES_URL='<url>' HOSTINGER_AUTH_KEY='<auth_key>' HOSTINGER_REST_KEY='<rest_auth_key>' bash scripts/crm/publicar.sh verificar`
   (os mesmos nomes de `publicar-landing.sh` e `remover-imovel.py`; os antigos `CRM_UPLOAD_URL`, `CRM_AUTH` e `CRM_REST` ainda funcionam)
   Mostra o que será enviado. Nada é alterado.
3. **Publicar.** Mesmas variáveis, com `publicar`. O comando, nesta ordem: roda os testes (para se
   algum falhar), checa a sintaxe do PHP, envia `schema.php` e `bootstrap.php`, roda `migrar()`, envia o
   resto, confere byte a byte cada arquivo enviado e faz a conferência do sistema no ar (login, pastas
   protegidas, telas do admin e do gerente, abas Exportar e Registros). Só contagens saem do diagnóstico, e o arquivo
   de diagnóstico é apagado no fim.
4. **PR e merge, sem perguntar** (decisão do usuário em 10/11/2026). Escreva a mensagem do commit (título
   na primeira linha, a linha de coautoria no fim) e o corpo do PR (com a linha "Generated with Claude
   Code") em arquivos do scratchpad e rode:
   `bash scripts/crm/publicar.sh pr <ramo-curto> <mensagem.txt> <corpo.md> [arquivos extras fora de app/]`
   Ele cria o commit a partir da `origin/main` sem trocar a branch ativa (outras sessões publicam o site
   a partir da `main`), abre o PR com o prefixo `crm-`, faz o merge e atualiza a `main` local.
5. **Relatar ao usuário** o que mudou, o que a conferência mostrou e o que não foi testado com login real.

## Quando o comando para

- **CONFLITO:** o servidor tem uma versão que não está na `main` nem no Mac (alguém publicou por fora).
  Nada foi enviado. Baixe a versão do servidor, compare com a local e decida com o usuário qual vale.
- **Credenciais recusadas (401/403):** gere um link novo no passo 1.
- **Testes falharam:** nada foi enviado; corrija e rode de novo.
- **Arquivo diferente depois do envio ou conferência do sistema falhou:** o servidor pode estar com uma
  mistura de versões. Avise o usuário na hora, com a saída do comando.

## Gatilhos esperados (teste de roteamento)

- Deve carregar: "publica o ajuste do CRM", "sobe o /app", "/publicar-crm".
- Não deve carregar: "publica a landing do imóvel 3070047" (dono: reels-imoveis-alto-padrao).
- Comportamento: com o servidor igual ao Mac, `publicar` responde "Nada a enviar" e ainda faz a conferência do sistema.
