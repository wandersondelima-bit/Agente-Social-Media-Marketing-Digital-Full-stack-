<?php
/* Modelo de configuração. Copie para inc/config.php (ou, de preferência, para crm-config.php
   uma pasta ACIMA de public_html) e preencha. O arquivo preenchido nunca vai para o GitHub. */
return [
    // Produção (Hostinger): o banco roda no mesmo servidor do site.
    'db_dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=NOME_DO_BANCO;charset=utf8mb4',
    'db_user' => 'USUARIO_DO_BANCO',
    'db_pass' => 'SENHA_DO_BANCO',
    // Pedida uma única vez, na tela de instalação, para criar o primeiro administrador.
    'install_key' => 'TROQUE-POR-UMA-CHAVE-LONGA-E-ALEATORIA',
    // Pasta onde está o index.html do site (lista de imóveis). Em produção, a pasta acima de /app.
    // 'site_dir' => '/caminho/para/public_html',
    'debug' => false,
];
