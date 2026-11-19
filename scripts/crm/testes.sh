#!/usr/bin/env bash
# Bateria de testes do CRM de leads (app/). Roda só contra o banco SQLite LOCAL de teste:
# apaga app/inc/_dados/teste.sqlite, sobe um servidor PHP temporário na porta 8799 e confere
# instalação, login, permissões dos três perfis, validações, exportação, importação e sessão.
# Uso: bash scripts/crm/testes.sh        (sai com código 1 se algum teste falhar)
cd "$(dirname "$0")/../.." || exit 1
APP=app
DB=$APP/inc/_dados/teste.sqlite
grep -q "sqlite:" $APP/inc/config.php 2>/dev/null || { echo "app/inc/config.php não aponta para SQLite: recuso rodar (protege o banco de produção)."; exit 1; }
command -v php >/dev/null && command -v sqlite3 >/dev/null || { echo "Precisa de php e sqlite3."; exit 1; }

B=http://127.0.0.1:8799
J=$(mktemp -d)
rm -f "$DB"; mkdir -p "$APP/inc/_dados"
php -S 127.0.0.1:8799 -t $APP >/dev/null 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; wait $SRV 2>/dev/null; rm -rf "$J"' EXIT
for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "$B/" && break; sleep 0.3; done

OK=0; FALHA=0
ok(){ echo "ok     $1"; OK=$((OK+1)); }
nok(){ echo "FALHOU $1"; FALHA=$((FALHA+1)); }
tok(){ curl -sL -c "$J/$1" -b "$J/$1" "$B/?r=login" | grep -o 'name="csrf" content="[^"]*"' | sed 's/.*content="//;s/"//'; }
post(){ local j=$1 r=$2; shift 2; curl -s -o "$J/out" -w '%{http_code}' -c "$J/$j" -b "$J/$j" -X POST "$B/?r=$r" --data-urlencode "csrf=$(tok $j)" "$@"; }
postj(){ local j=$1 r=$2; shift 2; curl -s -o "$J/out" -w '%{http_code}' -c "$J/$j" -b "$J/$j" -H 'X-Requested-With: fetch' -X POST "$B/?r=$r" --data-urlencode "csrf=$(tok $j)" "$@"; }
get(){ curl -s -o "$J/out" -w '%{http_code}' -c "$J/$1" -b "$J/$1" "$B/?$2"; }
ck(){ [ "$2" = "$3" ] && ok "$1 ($2)" || nok "$1: esperado $3, veio $2"; }
has(){ grep -q -- "$2" "$J/out" && ok "$1" || nok "$1 (não achei '$2')"; }
hasnt(){ grep -q -- "$2" "$J/out" && nok "$1 (achei '$2')" || ok "$1"; }
sql(){ sqlite3 "$DB" "$1"; }
xlsx_linhas(){ python3 - "$1" <<'PY'
import sys, zipfile, xml.etree.ElementTree as E
try:
    z = zipfile.ZipFile(sys.argv[1])
    for n in z.namelist():
        if n.endswith(('.xml', '.rels')): E.fromstring(z.read(n))
    ns = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    print(len(E.fromstring(z.read('xl/worksheets/sheet1.xml')).findall('.//m:row', ns)))
except Exception as e:
    print('invalido:', e)
PY
}
baixar(){ curl -s -o "$J/arq" -w '%{http_code}' -b "$J/$1" "$B/?$2"; }

PA='Teste-Adm-2026'; PC='Teste-Cor-2026'; PG='Teste-Ger-2026'

echo "== instalação e login"
get adm "r=leads" >/dev/null; has "sem usuários mostra a instalação" 'Instalação'
ck "instalar com chave errada" "$(post adm instalar -d chave=errada -d nome=X -d login=adm -d senha=$PA)" 403
ck "instalar com a chave certa" "$(post adm instalar -d chave=chave-local-de-teste --data-urlencode 'nome=Admin Teste' -d login=adm -d senha=$PA)" 303
ck "instalar de novo é recusado" "$(post x instalar -d chave=chave-local-de-teste -d nome=X -d login=xx1 -d senha=$PA)" 404
post adm entrar -d login=adm -d senha=errada1 >/dev/null; get adm "r=leads" >/dev/null; hasnt "senha errada não entra" 'Novo lead'
ck "login do admin" "$(post adm entrar -d login=adm -d senha=$PA)" 303
get adm "r=leads" >/dev/null; has "admin vê Novo lead" 'Novo lead'; has "admin tem a aba Exportar" 'r=exportar'; hasnt "link antigo de CSV saiu da lista" 'Baixar todos os leads em CSV'
has "admin tem a aba Registros" 'r=registros'

echo "== registro de acessos"
ck "entrada do admin registrada" "$(sql "SELECT count(*) FROM log_acessos WHERE resultado='entrou' AND login='adm'")" 1
ck "senha errada registrada com o motivo" "$(sql "SELECT count(*) FROM log_acessos WHERE resultado='senha_errada' AND login='adm'")" 1
ck "IP registrado" "$(sql "SELECT ip FROM log_acessos WHERE resultado='entrou' LIMIT 1")" 127.0.0.1
post x entrar --data-urlencode 'login=<script>naoexiste</script>' -d senha=qualquer1 >/dev/null
ck "login inexistente registrado" "$(sql "SELECT count(*) FROM log_acessos WHERE resultado='login_inexistente'")" 1
sql "INSERT INTO log_acessos (quando, login, resultado) VALUES ('2020-01-01 00:00:00', 'antigo', 'entrou')"
sql "INSERT INTO log_exportacoes (quando, login, filtro) VALUES ('2020-01-01 00:00:00', 'antigo', 'x')"
post z entrar -A 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' -d login=adm -d senha=$PA >/dev/null
ck "aparelho registrado em palavras" "$(sql "SELECT aparelho FROM log_acessos ORDER BY id DESC LIMIT 1")" 'iPhone · Safari'
ck "registros com mais de 12 meses são apagados" "$(sql "SELECT (SELECT count(*) FROM log_acessos WHERE login='antigo') + (SELECT count(*) FROM log_exportacoes WHERE login='antigo')")" 0

echo "== usuários"
post adm usuario_criar --data-urlencode 'nome=Ana Teste' -d login=ana -d senha=$PC -d papel=corretor >/dev/null
post adm usuario_criar --data-urlencode 'nome=Bruno Teste' -d login=bruno -d senha=$PC -d papel=corretor >/dev/null
post adm usuario_criar --data-urlencode 'nome=Gerente Teste' -d login=gerente.teste -d senha=$PG -d papel=gerente >/dev/null
post adm usuario_criar -d nome=Curta -d login=curta -d senha=12345 -d papel=corretor >/dev/null
post adm usuario_criar -d nome=Intruso -d login=intruso -d senha=$PA -d papel=superadmin >/dev/null
ck "gerente criado com o perfil certo" "$(sql "SELECT papel FROM usuarios WHERE login='gerente.teste'")" gerente
ck "senha de 5 caracteres recusada" "$(sql "SELECT count(*) FROM usuarios WHERE login='curta'")" 0
ck "perfil inventado vira corretor" "$(sql "SELECT papel FROM usuarios WHERE login='intruso'")" corretor
get adm "r=usuarios" >/dev/null; has "lista mostra a etiqueta Gerente" '>Gerente<'; has "cadastro oferece o perfil Gerente" 'value="gerente"'
ANA=$(sql "SELECT id FROM usuarios WHERE login='ana'"); BRU=$(sql "SELECT id FROM usuarios WHERE login='bruno'")

echo "== leads"
post adm lead_salvar --data-urlencode 'nome=Lead Da Ana' -d telefone=11900000001 -d imovel_ref=2741071 --data-urlencode 'origem=WhatsApp Business' -d corretor_id=$ANA >/dev/null
post adm lead_salvar --data-urlencode 'nome=Lead Do Bruno' --data-urlencode 'telefone=(11) 90000-0002' --data-urlencode 'imovel_ref=Vila Nova Conceição' -d corretor_id=$BRU >/dev/null
post adm lead_salvar --data-urlencode 'nome=Lead Sem Dono' -d telefone=5511900000003 --data-urlencode 'imovel_ref=https://marceloosawaimoveis.com.br/2741071/jardim-das-acacias/?utm_source=ig' >/dev/null
post adm lead_salvar -d nome=Ruim -d telefone=123 >/dev/null
post adm lead_salvar --data-urlencode 'nome=Codigo Errado' -d telefone=11900000005 -d imovel_ref=9999999 >/dev/null
get adm "r=lead_form" >/dev/null; has "código inexistente é recusado" 'Não achei o código 9999999'
post adm lead_salvar --data-urlencode 'nome=Repetido' -d telefone=11900000001 >/dev/null
get adm "r=lead_form" >/dev/null; has "telefone repetido pede confirmação" 'Já existe um lead com esse WhatsApp'
ck "só os 3 leads válidos entraram" "$(sql 'SELECT count(*) FROM leads')" 3
ck "código do site vira link" "$(sql "SELECT imovel_url FROM leads WHERE nome='Lead Da Ana'")" 'https://marceloosawaimoveis.com.br/2741071/jardim-das-acacias/'
L1=$(sql "SELECT id FROM leads WHERE nome='Lead Da Ana'"); L2=$(sql "SELECT id FROM leads WHERE nome='Lead Do Bruno'"); L3=$(sql "SELECT id FROM leads WHERE nome='Lead Sem Dono'")

echo "== corretor"
ck "login da corretora" "$(post ana entrar -d login=ana -d senha=$PC)" 303
get ana "r=leads" >/dev/null; has "vê o próprio lead" 'Lead Da Ana'; hasnt "não vê lead de outro" 'Lead Do Bruno'; hasnt "não vê lead sem dono" 'Lead Sem Dono'; hasnt "não tem Novo lead" 'Novo lead'; hasnt "não tem a aba Exportar" 'r=exportar'; hasnt "não tem a aba Registros" 'r=registros'
ck "não abre Registros" "$(get ana r=registros)" 403
ck "muda o status do próprio lead" "$(postj ana lead_status -d id=$L1 -d valor=andamento)" 200
ck "não muda lead de outro" "$(postj ana lead_status -d id=$L2 -d valor=perdido)" 404
ck "não grava obs em lead sem dono" "$(postj ana lead_obs -d id=$L3 -d valor=oi)" 404
ck "não atribui" "$(postj ana lead_atribuir -d id=$L1 -d valor=$BRU)" 403
ck "não edita dados" "$(post ana lead_salvar -d id=$L1 -d nome=Hack -d telefone=11900000009)" 403
ck "não exclui" "$(post ana lead_excluir -d id=$L1)" 403
ck "não abre Usuários" "$(get ana r=usuarios)" 403
ck "não abre Exportar" "$(get ana r=exportar)" 403
ck "não baixa a planilha" "$(get ana r=exportar_arquivo)" 403
ck "observação com 301 caracteres é recusada" "$(postj ana lead_obs -d id=$L1 --data-urlencode "valor=$(printf 'a%.0s' $(seq 301))")" 422
ck "observação com 300 caracteres é aceita" "$(postj ana lead_obs -d id=$L1 --data-urlencode "valor=$(printf 'á%.0s' $(seq 300))")" 200
ck "banco guardou as 300 letras" "$(sql "SELECT length(observacoes) FROM leads WHERE id=$L1")" 300
get ana "r=leads" >/dev/null; has "contador mostra 300" 'de 300 caracteres'
ck "status inválido" "$(postj ana lead_status -d id=$L1 -d valor=xyz)" 422
ck "motivo sem estar perdido" "$(postj ana lead_motivo -d id=$L1 --data-urlencode 'valor=Desistiu')" 422
ck "marca perdido" "$(postj ana lead_status -d id=$L1 -d valor=perdido)" 200
ck "motivo fora da lista" "$(postj ana lead_motivo -d id=$L1 -d valor=Qualquer)" 422
ck "motivo da lista" "$(postj ana lead_motivo -d id=$L1 --data-urlencode 'valor=Sem retorno')" 200
ck "pedido sem token é recusado" "$(curl -s -o /dev/null -w '%{http_code}' -b "$J/ana" -H 'X-Requested-With: fetch' -X POST "$B/?r=lead_status" -d id=$L1 -d valor=novo)" 403
ck "sem login, ação devolve 401" "$(postj sem lead_status -d id=$L1 -d valor=novo)" 401
ck "sem login, a lista manda para o login" "$(get sem r=leads)" 303

echo "== gerente (somente leitura)"
ck "login do gerente" "$(post ger entrar -d login=gerente.teste -d senha=$PG)" 303
get ger "r=leads&f=todos" >/dev/null
has "vê lead da Ana" 'Lead Da Ana'; has "vê lead do Bruno" 'Lead Do Bruno'; has "vê lead sem dono" 'Lead Sem Dono'
hasnt "não tem seletor de status nem de corretor" 'data-acao='; hasnt "não tem Novo lead" 'Novo lead'; hasnt "não tem botão de WhatsApp" 'Abrir WhatsApp'
hasnt "não tem Salvar observações" 'Salvar observações'; hasnt "não tem Editar dados" 'Editar dados'; hasnt "não tem Excluir lead" 'Excluir lead'
has "cabeçalho indica Gerente" '· Gerente'; has "menu tem Exportar" 'r=exportar'; hasnt "menu não tem Usuários" 'r=usuarios'; hasnt "menu não tem Importar" 'r=importar'; hasnt "menu não tem Registros" 'r=registros'
has "vê o motivo da perda" 'Sem retorno'; has "vê as observações em texto" 'obs-lida'
ck "gerente não abre Registros" "$(get ger r=registros)" 403
for a in lead_status lead_motivo lead_obs lead_atribuir; do ck "gerente não executa $a" "$(postj ger $a -d id=$L1 -d valor=novo)" 403; done
for a in lead_salvar lead_excluir lead_restaurar lead_apagar usuario_criar usuario_senha usuario_ativo usuario_excluir importar; do ck "gerente não executa $a" "$(post ger $a -d id=$L1 -d nome=X -d login=xyz -d senha=$PG -d telefone=11911111111)" 403; done
ck "status continua o da corretora" "$(sql "SELECT status FROM leads WHERE id=$L1")" perdido
ck "gerente não abre Usuários" "$(get ger r=usuarios)" 403
ck "gerente não abre Importar" "$(get ger r=importar)" 403
ck "gerente não abre o formulário de lead" "$(get ger r=lead_form)" 403
ck "gerente abre Exportar" "$(get ger r=exportar)" 200
ck "gerente baixa a planilha" "$(baixar ger 'r=exportar_arquivo')" 200
ck "planilha do gerente tem cabeçalho + 3 leads" "$(xlsx_linhas "$J/arq")" 4

echo "== exportação (admin)"
post adm lead_salvar --data-urlencode 'nome=<script>alert(1)</script>' -d telefone=11900000004 --data-urlencode 'imovel_ref="><img src=x onerror=1>' >/dev/null
post adm lead_salvar --data-urlencode 'nome==HYPERLINK("x")' -d telefone=11900000006 >/dev/null
get adm "r=leads&f=todos" >/dev/null; hasnt "HTML no nome é neutralizado" '<script>alert(1)'; hasnt "HTML no imóvel é neutralizado" '<img src=x'
get adm "r=exportar" >/dev/null; has "resumo mensal aparece" 'Resumo pela data de entrada'; has "contagem inicial de 5 leads" '<b class="num">5</b>'
ck "xlsx de tudo" "$(baixar adm 'r=exportar_arquivo')" 200
ck "xlsx válido com cabeçalho + 5" "$(xlsx_linhas "$J/arq")" 6
grep -q 'sheet' <(unzip -l "$J/arq" 2>/dev/null) && ok "xlsx tem a aba de dados" || nok "xlsx sem aba"
baixar adm 'r=exportar_arquivo&enviado=1&s[]=perdido' >/dev/null; ck "filtro só Perdido" "$(xlsx_linhas "$J/arq")" 2
baixar adm 'r=exportar_arquivo&enviado=1&s[]=novo&s[]=andamento&c=sem' >/dev/null; ck "filtro A distribuir em aberto" "$(xlsx_linhas "$J/arq")" 4
baixar adm "r=exportar_arquivo&c=$BRU" >/dev/null; ck "filtro por corretor" "$(xlsx_linhas "$J/arq")" 2
baixar adm 'r=exportar_arquivo&periodo=mes_atual' >/dev/null; ck "filtro Este mês" "$(xlsx_linhas "$J/arq")" 6
get adm 'r=exportar&enviado=1&periodo=datas&de=2000-01-01&ate=2000-01-31&s[]=novo' >/dev/null; has "período sem leads conta 0" '<b class="num">0</b>'; has "botão fica desligado sem leads" 'disabled'
get adm 'r=exportar&enviado=1' >/dev/null; has "nenhum status marcado conta 0" '<b class="num">0</b>'
ck "csv" "$(baixar adm 'r=exportar_arquivo&fmt=csv')" 200
head -c 300 "$J/arq" | grep -q 'Recebido em' && ok "csv tem cabeçalho" || nok "csv sem cabeçalho"
grep -q "'=HYPERLINK" "$J/arq" && ok "csv neutraliza fórmula" || nok "csv deixou fórmula passar"
ck "csv tem cabeçalho + 5 linhas" "$(python3 -c "import csv,sys; print(len(list(csv.reader(open(sys.argv[1], encoding='utf-8-sig')))))" "$J/arq")" 6

echo "== registro de exportações"
ck "cada planilha baixada gera um registro; ver a contagem não (gerente 1 + admin 6)" "$(sql 'SELECT count(*) FROM log_exportacoes')" 7
ck "o registro tem só quem, quando e o filtro" "$(sql "SELECT group_concat(name, ',') FROM pragma_table_info('log_exportacoes')")" 'id,quando,usuario_id,login,filtro'
ck "filtro só Perdido, em palavras" "$(sql "SELECT filtro FROM log_exportacoes WHERE filtro LIKE '%Status: Perdido %'")" 'Período: Todo o período · Status: Perdido · Corretor: todos · Origem: todas · Excluídos: não'
ck "filtro A distribuir em aberto" "$(sql "SELECT count(*) FROM log_exportacoes WHERE filtro LIKE '%Status: Novo, Em andamento · Corretor: A distribuir%'")" 1
ck "filtro por corretor guarda o nome" "$(sql "SELECT count(*) FROM log_exportacoes WHERE filtro LIKE '%Corretor: Bruno Teste%'")" 1
ck "filtro Este mês guarda as datas" "$(sql "SELECT count(*) FROM log_exportacoes WHERE filtro LIKE 'Período: Este mês (01/%'")" 1
ck "exportação do gerente fica no nome dele" "$(sql "SELECT count(*) FROM log_exportacoes x JOIN usuarios u ON u.id = x.usuario_id WHERE u.login = 'gerente.teste'")" 1
ck "nenhum dado de lead no registro" "$(sql "SELECT count(*) FROM log_exportacoes WHERE filtro LIKE '%Lead%' OR filtro LIKE '%9000%' OR filtro LIKE '%HYPERLINK%'")" 0

echo "== aba Registros (admin)"
ck "admin abre Registros" "$(get adm r=registros)" 200
has "mostra as entradas" '>Entrou<'; has "mostra a senha errada" '>Senha errada<'; has "mostra o aparelho" 'iPhone · Safari'
hasnt "login digitado é neutralizado" '<script>naoexiste'
get adm "r=registros&res=recusadas" >/dev/null; hasnt "filtro Só tentativas recusadas esconde as entradas" '>Entrou<'; has "e mostra as recusadas" 'Login não existe'
get adm "r=registros&t=exportacoes" >/dev/null; has "aba Exportações mostra o filtro usado" 'Status: Perdido'; has "aba Exportações mostra quem baixou" 'Gerente Teste'
ck "sem login, Registros manda para o login" "$(get sem r=registros)" 303

echo "== exclusão e usuários"
ck "admin exclui o lead sem dono" "$(post adm lead_excluir -d id=$L3)" 303
get ger "r=leads&f=excluidos" >/dev/null; has "gerente vê a lixeira" 'Lead Sem Dono'; hasnt "gerente não tem Restaurar" 'Restaurar lead'
get adm "r=leads&f=excluidos" >/dev/null; has "admin tem Restaurar" 'Restaurar lead'
ck "exclusão definitiva pelo admin" "$(post adm lead_apagar -d id=$L3)" 303
ck "lead apagado sumiu do banco" "$(sql "SELECT count(*) FROM leads WHERE id=$L3")" 0
post adm usuario_ativo -d id=$ANA >/dev/null
ck "corretora desativada perde o acesso na hora" "$(get ana r=leads)" 303
post ana2 entrar -d login=ana -d senha=$PC >/dev/null
ck "tentativa de usuário desativado registrada" "$(sql "SELECT count(*) FROM log_acessos WHERE resultado='desativado' AND login='ana'")" 1
ck "admin não desativa a si mesmo" "$(post adm usuario_ativo -d id=1)" 303; ck "admin continua ativo" "$(sql 'SELECT ativo FROM usuarios WHERE id=1')" 1
post adm usuario_excluir -d id=$BRU >/dev/null
ck "lead do corretor excluído volta para A distribuir" "$(sql "SELECT IFNULL(corretor_id,'nulo') FROM leads WHERE id=$L2")" nulo

echo "== importação"
printf 'Recebido em,Status,Corretor,Nome,WhatsApp,Código do imóvel,Imóvel,Origem,Observações\n08/09/2026,Novo,Carla Teste,Importado Um,11 90000-0011,2741071,,Instagram DM,\n09/09/2026,Em andamento,Carla Teste,Importado Dois,11 90000-0012,,Vila Nova Conceição,Instagram DM,"Lead quente"\n' > "$J/imp.csv"
curl -s -o /dev/null -b "$J/adm" -c "$J/adm" -X POST "$B/?r=importar" -F "csrf=$(tok adm)" -F "arquivo=@$J/imp.csv;type=text/csv"
get adm r=importar >/dev/null; has "importou 2" '<b>2</b> leads importados'
curl -s -o /dev/null -b "$J/adm" -c "$J/adm" -X POST "$B/?r=importar" -F "csrf=$(tok adm)" -F "arquivo=@$J/imp.csv;type=text/csv"
get adm r=importar >/dev/null; has "reimportar não duplica" '<b>0</b> leads importados'

echo "== sessão de 24 horas e bloqueio por tentativas"
SID=$(awk '$6=="crm_sessao"{print $7}' "$J/adm"); F=$APP/inc/_dados/sess/sess_$SID
perl -pi -e "s/visto\|i:\d+;/visto|i:$(( $(date +%s) - 23*3600 ));/" "$F"; ck "23 h sem uso continua logado" "$(get adm r=leads)" 200
perl -pi -e "s/visto\|i:\d+;/visto|i:$(( $(date +%s) - 25*3600 ));/" "$F"; ck "25 h sem uso pede login" "$(get adm r=leads)" 303
for i in 1 2 3 4 5 6 7 8 9; do post y entrar -d login=adm -d senha=errada$i >/dev/null; done
get y r=login >/dev/null; has "bloqueia depois de 8 senhas erradas" 'Muitas tentativas'
post y entrar -d login=adm -d senha=errada10 >/dev/null
ck "bloqueio registrado uma vez só" "$(sql "SELECT count(*) FROM log_acessos WHERE resultado='bloqueado'")" 1
post ger sair >/dev/null
ck "saída registrada" "$(sql "SELECT count(*) FROM log_acessos WHERE resultado='saiu' AND login='gerente.teste'")" 1
curl -s -D "$J/h" -o /dev/null "$B/?r=login"; grep -qi 'x-robots-tag: noindex' "$J/h" && ok "página fora do Google" || nok "sem noindex"
grep -qi "content-security-policy" "$J/h" && ok "política de segurança de conteúdo" || nok "sem CSP"

echo "== migração de um banco sem as tabelas de registro"
sql "DROP TABLE log_acessos; DROP TABLE log_exportacoes"
MIG='require "app/inc/bootstrap.php"; require "app/inc/schema.php"; echo implode("; ", migrar()) ?: "nada a fazer";'
ck "migrar() cria as tabelas que faltam" "$(php -r "$MIG")" 'tabela nova: log_acessos; tabela nova: log_exportacoes'
ck "migrar() de novo não faz nada" "$(php -r "$MIG")" 'nada a fazer'

echo; echo "passaram: $OK · falharam: $FALHA"
[ "$FALHA" -eq 0 ]
