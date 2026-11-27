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
get ana "r=leads" >/dev/null; hasnt "corretor não tem a aba Painel" 'r=painel'
ck "corretor não abre o Painel" "$(get ana r=painel)" 403
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
sql "UPDATE leads SET observacoes='Cliente pediu retorno no (11 97873-0170), código 2902302, até R$ 1.250.000' WHERE nome='Lead Sem Dono'"
get ger "r=leads&f=todos" >/dev/null
hasnt "ficha do gerente não mostra telefone completo" '90000-000'; has "ficha do gerente mostra o telefone parcial" '(11) •••••-0001'
hasnt "observação do gerente esconde o telefone escrito" '97873-0170'; has "observação do gerente mostra o telefone parcial" '(11 •••••-0170)'
has "observação do gerente mantém código e preço" 'código 2902302, até R$ 1.250.000'
get adm "r=leads&f=todos" >/dev/null; has "ficha do admin mostra o telefone completo" '(11) 90000-0001'; has "observação do admin mostra o telefone completo" '97873-0170'
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
ck "gerente abre o Painel" "$(get ger r=painel)" 200; has "Painel do gerente mostra os números" 'Entraram'; hasnt "Painel do gerente não tem ação de gravação" 'data-acao='
ck "gerente baixa a planilha" "$(baixar ger 'r=exportar_arquivo')" 200
ck "planilha do gerente tem cabeçalho + 3 leads" "$(xlsx_linhas "$J/arq")" 4
unzip -p "$J/arq" xl/worksheets/sheet1.xml > "$J/xml"
grep -q '(11) •••••-0001' "$J/xml" && ok "planilha do gerente traz o telefone parcial" || nok "planilha do gerente sem o telefone parcial"
grep -q '90000-000' "$J/xml" && nok "planilha do gerente vazou o telefone completo" || ok "planilha do gerente não traz telefone completo"
grep -q '97873-0170' "$J/xml" && nok "observação na planilha do gerente vazou telefone" || ok "observação na planilha do gerente esconde o telefone"
ck "gerente baixa o csv" "$(baixar ger 'r=exportar_arquivo&fmt=csv')" 200
grep -q '90000-000' "$J/arq" && nok "csv do gerente vazou o telefone completo" || ok "csv do gerente não traz telefone completo"
get ger "r=leads" >/dev/null; hasnt "lista do gerente não fala em só leitura" 'só para leitura'
get ger "r=exportar" >/dev/null; has "Exportar avisa o gerente do telefone parcial" 'dígitos do meio ocultos'; hasnt "Exportar do gerente não promete telefone completo" 'ele tem nome e telefone'

echo "== exportação (admin)"
post adm lead_salvar --data-urlencode 'nome=<script>alert(1)</script>' -d telefone=11900000004 --data-urlencode 'imovel_ref="><img src=x onerror=1>' >/dev/null
post adm lead_salvar --data-urlencode 'nome==HYPERLINK("x")' -d telefone=11900000006 >/dev/null
get adm "r=leads&f=todos" >/dev/null; hasnt "HTML no nome é neutralizado" '<script>alert(1)'; hasnt "HTML no imóvel é neutralizado" '<img src=x'
get adm "r=exportar" >/dev/null; has "resumo mensal aparece" 'Resumo pela data de entrada'; has "contagem inicial de 5 leads" '<b class="num">5</b>'
ck "xlsx de tudo" "$(baixar adm 'r=exportar_arquivo')" 200
ck "xlsx válido com cabeçalho + 5" "$(xlsx_linhas "$J/arq")" 6
unzip -p "$J/arq" xl/worksheets/sheet1.xml | grep -q '(11) 90000-0001' && ok "planilha do admin traz o telefone completo" || nok "planilha do admin sem o telefone completo"
unzip -p "$J/arq" xl/worksheets/sheet1.xml | grep -q '97873-0170' && ok "observação na planilha do admin fica completa" || nok "observação do admin perdeu o telefone"
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
ck "cada planilha baixada gera um registro; ver a contagem não (gerente 2 + admin 6)" "$(sql 'SELECT count(*) FROM log_exportacoes')" 8
ck "o registro tem só quem, quando e o filtro" "$(sql "SELECT group_concat(name, ',') FROM pragma_table_info('log_exportacoes')")" 'id,quando,usuario_id,login,filtro'
ck "filtro só Perdido, em palavras" "$(sql "SELECT filtro FROM log_exportacoes WHERE filtro LIKE '%Status: Perdido %'")" 'Período: Todo o período · Status: Perdido · Corretor: todos · Origem: todas · Excluídos: não'
ck "filtro A distribuir em aberto" "$(sql "SELECT count(*) FROM log_exportacoes WHERE filtro LIKE '%Status: Novo, Em andamento · Corretor: A distribuir%'")" 1
ck "filtro por corretor guarda o nome" "$(sql "SELECT count(*) FROM log_exportacoes WHERE filtro LIKE '%Corretor: Bruno Teste%'")" 1
ck "filtro Este mês guarda as datas" "$(sql "SELECT count(*) FROM log_exportacoes WHERE filtro LIKE 'Período: Este mês (01/%'")" 1
ck "exportação do gerente fica no nome dele" "$(sql "SELECT count(*) FROM log_exportacoes x JOIN usuarios u ON u.id = x.usuario_id WHERE u.login = 'gerente.teste'")" 2
ck "registro marca o telefone parcial do gerente" "$(sql "SELECT count(*) FROM log_exportacoes WHERE login = 'gerente.teste' AND filtro LIKE '% · Telefone parcial'")" 2
ck "registro do admin não marca telefone parcial" "$(sql "SELECT count(*) FROM log_exportacoes WHERE login = 'adm' AND filtro LIKE '%Telefone parcial%'")" 0
ck "nenhum dado de lead no registro" "$(sql "SELECT count(*) FROM log_exportacoes WHERE filtro LIKE '%Lead%' OR filtro LIKE '%9000%' OR filtro LIKE '%HYPERLINK%'")" 0

echo "== aba Registros (admin)"
ck "admin abre Registros" "$(get adm r=registros)" 200
has "mostra as entradas" '>Entrou<'; has "mostra a senha errada" '>Senha errada<'; has "mostra o aparelho" 'iPhone · Safari'
hasnt "login digitado é neutralizado" '<script>naoexiste'
get adm "r=registros&res=recusadas" >/dev/null; hasnt "filtro Só tentativas recusadas esconde as entradas" '>Entrou<'; has "e mostra as recusadas" 'Login não existe'
get adm "r=registros&t=exportacoes" >/dev/null; has "aba Exportações mostra o filtro usado" 'Status: Perdido'; has "aba Exportações mostra quem baixou" 'Gerente Teste'
ck "sem login, Registros manda para o login" "$(get sem r=registros)" 303

echo "== planilha CSV dos registros (só admin)"
csvn(){ python3 -c "import csv,sys; print(len(list(csv.reader(open(sys.argv[1], encoding='utf-8-sig')))))" "$1"; }
csvcol(){ python3 -c "import csv,sys; r=list(csv.reader(open(sys.argv[1], encoding='utf-8-sig'))); print(sum(1 for x in r[1:] if x[int(sys.argv[2])]==sys.argv[3]))" "$@"; }
post x entrar --data-urlencode 'login=@soma(1)' -d senha=qualquer1 >/dev/null
get adm "r=registros" >/dev/null; has "tela tem o botão de baixar CSV com a contagem" 'Baixar planilha CSV · [0-9]* registros'
NAC=$(sql 'SELECT count(*) FROM log_acessos'); NEX=$(sql 'SELECT count(*) FROM log_exportacoes')
ck "admin baixa o CSV de acessos" "$(baixar adm 'r=registros_arquivo')" 200
ck "CSV de acessos tem cabeçalho + todos os registros" "$(csvn "$J/arq")" $((NAC+1))
head -1 "$J/arq" | grep -q 'Data,Hora,Usuário,Login,Resultado,IP,Aparelho' && ok "CSV de acessos tem as colunas certas" || nok "CSV de acessos com colunas erradas"
grep -q "'@soma(1)" "$J/arq" && ok "CSV de acessos neutraliza fórmula no login digitado" || nok "CSV de acessos deixou fórmula passar"
baixar adm 'r=registros_arquivo&res=recusadas' >/dev/null; ck "CSV com filtro Só tentativas recusadas não traz entradas" "$(csvcol "$J/arq" 4 Entrou)" 0
ck "CSV de exportações" "$(baixar adm 'r=registros_arquivo&t=exportacoes')" 200
head -1 "$J/arq" | grep -q 'Data,Hora,Usuário,Login,Período,Status,Corretor,Origem,Excluídos' && ok "CSV de exportações separa o filtro em colunas" || nok "CSV de exportações com colunas erradas"
ck "CSV de exportações tem cabeçalho + todos os registros" "$(csvn "$J/arq")" $((NEX+1))
ck "coluna Status traz o filtro só Perdido" "$(csvcol "$J/arq" 5 Perdido)" 1
GER=$(sql "SELECT id FROM usuarios WHERE login='gerente.teste'")
baixar adm "r=registros_arquivo&t=exportacoes&u=$GER" >/dev/null; ck "CSV filtrado pelo gerente (planilha e csv)" "$(csvn "$J/arq")" 3
ck "baixar os registros não entra no registro de exportações de leads" "$(sql 'SELECT count(*) FROM log_exportacoes')" "$NEX"
ck "gerente não baixa os registros" "$(get ger r=registros_arquivo)" 403
ck "corretor não baixa os registros" "$(get ana r=registros_arquivo)" 403
ck "sem login, o CSV dos registros manda para o login" "$(get sem r=registros_arquivo)" 303

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

echo "== entrada de leads do site (receber.php)"
SITE=https://marceloosawaimoveis.com.br
rcv(){ curl -s -o "$J/out" -w '%{http_code}' -X POST "$B/receber.php" -H 'Content-Type: text/plain;charset=utf-8' "$@"; }
sql "DELETE FROM entradas_site"
FORM='{"tipo":"formulario","nome":"Lead Site Teste","whatsapp":"+5511987650001","consentimento":true,"consentimento_versao":"v2-2026-10","imovel_codigo":"3143315","imovel":"Avenida Bernardino de Campos, Paraíso","interesse":"Agendar uma visita","pagina":"'$SITE'/3143315/paraiso/","utm_source":"instagram","utm_content":"reel-24-11","aceita_ofertas":true}'
ck "abrir no navegador responde" "$(curl -s -o /dev/null -w '%{http_code}' "$B/receber.php")" 200
ck "sem origem é recusado" "$(rcv --data "$FORM")" 403
ck "outro site é recusado" "$(rcv -H 'Origin: https://golpe.example' --data "$FORM")" 403
ck "JSON quebrado é recusado" "$(rcv -H "Origin: $SITE" --data '{nao e json')" 400
ck "clique no WhatsApp não vira lead" "$(rcv -H "Origin: $SITE" --data '{"tipo":"clique_whatsapp","imovel_codigo":"3143315"}')" 200
ck "formulário sem consentimento não vira lead" "$(rcv -H "Origin: $SITE" --data '{"tipo":"formulario","nome":"Sem Consent","whatsapp":"+5511987650009"}')" 200
ck "campo invisível preenchido (robô) responde ok" "$(rcv -H "Origin: $SITE" --data '{"tipo":"formulario","nome":"Robo","whatsapp":"+5511987650008","consentimento":true,"empresa":"spam"}')" 200
ck "nenhum dos três criou lead" "$(sql "SELECT count(*) FROM leads WHERE telefone IN ('11987650009','11987650008') OR nome='Robo'")" 0
ck "formulário da landing cria o lead" "$(rcv -H "Origin: $SITE" --data "$FORM")" 201
ck "lead do site: origem, status e A distribuir" "$(sql "SELECT origem||'|'||status||'|'||ifnull(corretor_id,'-')||'|'||telefone FROM leads WHERE nome='Lead Site Teste'")" 'Formulário do site|novo|-|11987650001'
ck "lead do site: imóvel e interesse" "$(sql "SELECT imovel_codigo||'|'||interesse FROM leads WHERE nome='Lead Site Teste'")" '3143315|Agendar uma visita'
ck "lead do site: origem do clique guardada" "$(sql "SELECT utm LIKE '%reel-24-11%' FROM leads WHERE nome='Lead Site Teste'")" 1
ck "lead do site: consentimento no histórico" "$(sql "SELECT count(*) FROM lead_eventos e JOIN leads l ON l.id=e.lead_id WHERE l.nome='Lead Site Teste' AND e.acao='criar' AND e.detalhe LIKE '%v2-2026-10%aceita outras opções: sim%'")" 1
ck "mesmo envio de novo não duplica" "$(rcv -H "Origin: $SITE" --data "$FORM")" 200
ck "continua um lead só" "$(sql "SELECT count(*) FROM leads WHERE telefone='11987650001'")" 1
ck "repetição anotada no histórico" "$(sql "SELECT count(*) FROM lead_eventos WHERE acao='site_repetido'")" 1
ck "mesma pessoa em outro imóvel vira outro lead" "$(rcv -H "Origin: $SITE" --data "${FORM/3143315\"/2792851\"}")" 201
ck "isca cria o lead" "$(rcv -H "Origin: $SITE" --data '{"tipo":"formulario","nome":"Lead Isca Teste","whatsapp":"(11) 98765-0002","consentimento":true,"isca":"guia-vila-olimpia","isca_titulo":"Guia Vila Olímpia","utm_campaign":"guia-vila-olimpia"}')" 201
ck "lead da isca: origem e material" "$(sql "SELECT origem||'|'||interesse||'|'||ifnull(imovel_codigo,'-') FROM leads WHERE nome='Lead Isca Teste'")" 'Isca do site|Isca: Guia Vila Olímpia|-'
ck "isca com nome inválido é recusada" "$(rcv -H "Origin: $SITE" --data '{"tipo":"formulario","nome":"X","whatsapp":"+5511987650003","consentimento":true,"isca":"../admin"}')" 400
ck "telefone inválido é recusado" "$(rcv -H "Origin: $SITE" --data '{"tipo":"formulario","nome":"Tel Ruim","whatsapp":"123","consentimento":true}')" 400
ck "sem Origin, com a página do site como referência, é aceito" "$(rcv -e "$SITE/3143315/paraiso/" --data '{"tipo":"formulario","nome":"Lead Referer","whatsapp":"+5511987650004","consentimento":true,"imovel_codigo":"3143315"}')" 201
ck "nome com quebra de linha é limpo" "$(rcv -H "Origin: $SITE" --data '{"tipo":"formulario","nome":"Linha\nQuebrada","whatsapp":"+5511987650005","consentimento":true}')" 201
ck "nome guardado numa linha só" "$(sql "SELECT nome FROM leads WHERE telefone='11987650005'")" 'Linha Quebrada'
get adm r=leads >/dev/null; has "admin vê o lead do site na lista" 'Lead Site Teste'; has "admin vê a isca na lista" 'Isca: Guia Vila Olímpia'
get ger r=leads >/dev/null; has "gerente vê o lead do site" 'Lead Isca Teste'
get ana r=leads >/dev/null; hasnt "corretor não vê lead A distribuir" 'Lead Site Teste'
get adm r=lead_form >/dev/null; has "cadastro manual oferece a origem Isca do site" 'Isca do site'
sql "DELETE FROM entradas_site"
for i in 1 2 3 4 5 6 7 8 9 10; do rcv -H "Origin: $SITE" --data '{"tipo":"formulario","nome":"Lote","whatsapp":"1","consentimento":true}' >/dev/null; done
ck "o 11º envio na mesma hora é barrado" "$(rcv -H "Origin: $SITE" --data "${FORM/0001/0006}")" 429
sql "UPDATE entradas_site SET quando='2020-01-01 00:00:00'"
ck "depois de 1 hora volta a aceitar" "$(rcv -H "Origin: $SITE" --data "${FORM/0001/0007}")" 201
ck "registros com mais de 1 dia são apagados" "$(sql "SELECT count(*) FROM entradas_site WHERE quando < '2021-01-01'")" 0

echo "== Painel (números) e histórico na ficha"
get adm "r=leads" >/dev/null; has "admin tem a aba Painel" 'r=painel'
ck "admin abre o Painel" "$(get adm r=painel)" 200
N30=$(sql "SELECT count(*) FROM leads WHERE excluido_em IS NULL AND entrada >= '$(date -v-29d +%F)'")
has "Painel conta os leads que entraram nos 30 dias ($N30)" "Entraram</span><b class=\"num\">$N30<"
NDIS=$(sql "SELECT count(*) FROM leads WHERE excluido_em IS NULL AND corretor_id IS NULL AND status IN ('novo','andamento')")
has "Painel mostra os A distribuir de agora ($NDIS)" "A distribuir agora</span><b class=\"num\">$NDIS<"
has "Painel mostra leads por dia" 'Leads por dia'; has "Painel separa a origem Isca do site" 'Isca do site'
has "Painel mostra o material da isca" 'Material: Guia Vila Olímpia'; has "Painel mostra a campanha do link" 'guia-vila-olimpia'
has "Painel mostra o imóvel pelo código" 'Cód. 3143315'; has "Painel tem a tabela por corretor" 'Por corretor'
hasnt "HTML do imóvel é neutralizado no Painel" '<img src=x'; hasnt "HTML do nome não aparece no Painel" '<script>alert(1)'
hasnt "Painel não usa estilo em linha (bloqueado pela segurança)" 'style="'
get adm "r=painel&p=tudo" >/dev/null; has "Todo o período agrupa por mês" 'Leads por mês'
get adm "r=painel&p=7d" >/dev/null; has "7 dias mostra o número de cada dia" 'class="eixo cheio num"'
ck "período inventado cai nos 30 dias" "$(get adm 'r=painel&p=xyz')" 200; has "e marca Últimos 30 dias" 'aria-current="true">Últimos 30 dias'
get adm "r=painel&p=mes_passado" >/dev/null; has "mês sem leads mostra o aviso" 'Nenhum lead entrou neste período'
ck "sem login, o Painel manda para o login" "$(get sem r=painel)" 303
sql "UPDATE leads SET atualizado_em='2020-01-01 00:00:00' WHERE nome='Lead Isca Teste'"
get adm "r=leads&f=parados" >/dev/null; has "filtro Parados traz o lead sem atualização" 'Lead Isca Teste'; hasnt "filtro Parados deixa de fora o atualizado" 'Lead Site Teste'
get adm "r=painel" >/dev/null; has "Painel destaca os parados" 'class="kpi atencao" href="[^"]*f=parados'
get adm "r=leads&f=todos" >/dev/null
has "ficha tem o Histórico" 'class="hist"'
has "histórico: entrada pelo site com o consentimento" 'Entrou pelo site · consentimento v2-2026-10'
has "histórico: pedido repetido pelo site" 'Pediu de novo pelo site'
has "histórico: mudança de status" 'Status: Novo → Em andamento'
has "histórico: quem mudou" '· Ana Teste</span>'
has "histórico: motivo da perda" 'Motivo da perda: Sem retorno'
has "histórico: lead importado" 'Importado da planilha'
has "histórico: cadastro manual" 'Cadastrado no CRM'
get ger "r=leads&f=todos" >/dev/null; has "gerente também vê o histórico" 'Status: Novo → Em andamento'
LH=$(sql "SELECT id FROM leads WHERE nome='Lead Site Teste' ORDER BY id LIMIT 1")
for i in $(seq 1 20); do sql "INSERT INTO lead_eventos (lead_id, acao, detalhe, quando) VALUES ($LH, 'site_repetido', '<b>x</b>', '2026-01-01 00:00:0$((i % 10))')"; done
get adm "r=leads&f=todos" >/dev/null; has "histórico longo resume os mais antigos" 'E mais [0-9]* registros anteriores'
hasnt "detalhe do histórico é neutralizado" '<b>x</b>'

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

# Ícone de aplicativo ("Adicionar à Tela de Início"): a tela de login aponta para o ícone e o manifest, e os dois existem
curl -s -o "$J/out" "$B/?r=login"
has "login aponta para o apple-touch-icon" 'rel="apple-touch-icon" href="/assets/apple-touch-icon.png"'
has "login aponta para o manifest" 'rel="manifest" href="/assets/manifest.json"'
has "nome do aplicativo é Leads" 'name="apple-mobile-web-app-title" content="Leads"'
ck "apple-touch-icon é PNG de 180 px" "$(python3 -c "from PIL import Image; i=Image.open('$APP/assets/apple-touch-icon.png'); print(i.format, i.size[0], i.size[1], i.mode)" 2>&1)" "PNG 180 180 RGB"
ck "ícones do manifest existem" "$(python3 - "$APP/assets" <<'PY'
import json, os, sys
d = sys.argv[1]; m = json.load(open(d + '/manifest.json'))
print(m['short_name'], all(os.path.isfile(os.path.join(d, i['src'])) for i in m['icons']))
PY
)" "Leads True"
grep -qi "content-security-policy" "$J/h" && ok "política de segurança de conteúdo" || nok "sem CSP"

echo "== migração de um banco sem as tabelas de registro"
sql "DROP TABLE log_acessos; DROP TABLE log_exportacoes"
MIG='require "app/inc/bootstrap.php"; require "app/inc/schema.php"; echo implode("; ", migrar()) ?: "nada a fazer";'
ck "migrar() cria as tabelas que faltam" "$(php -r "$MIG")" 'tabela nova: log_acessos; tabela nova: log_exportacoes'
ck "migrar() de novo não faz nada" "$(php -r "$MIG")" 'nada a fazer'

echo; echo "passaram: $OK · falharam: $FALHA"
[ "$FALHA" -eq 0 ]
