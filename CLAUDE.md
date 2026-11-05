# Marcelo Osawa Imóveis · regras do projeto

Todas as regras do trabalho (Reel, roteiro, copy, IPTU, landing page, página inicial, publicação na
Hostinger, imóvel vendido) ficam numa fonte única: `skills/reels-imoveis-alto-padrao/SKILL.md`, neste
repositório. É o único arquivo que se edita.

Onde a skill é lida:
- **Claude Code no Mac:** `~/.claude/skills/reels-imoveis-alto-padrao` é um link simbólico para a pasta
  `skills/reels-imoveis-alto-padrao/` deste repositório. Não existe segunda cópia no disco.
- **Chat do claude.ai:** não enxerga o Mac nem o GitHub; a skill da conta é o `.zip` gerado a partir deste
  arquivo e enviado nas configurações do claude.ai.

- Imóvel novo, alteração no site ou imóvel vendido: carregar a skill e seguir a seção correspondente.
- Regra nova combinada com o usuário entra na skill (não aqui): editar o arquivo deste repositório (por PR),
  gerar o `.zip` e avisar o usuário para subir a versão nova na conta do claude.ai.
- Se o link simbólico faltar (máquina nova), recriar com
  `ln -s <caminho-do-repositório>/skills/reels-imoveis-alto-padrao ~/.claude/skills/reels-imoveis-alto-padrao`.
- Cuidado: o Claude Code lê a skill da branch que estiver ativa neste repositório; edições da skill ficam em
  branch própria até o merge.
