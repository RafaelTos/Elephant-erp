<?php
session_start();
require_once 'conexao.php';

// =========================================================================
// LÓGICA DE LOGOUT
// =========================================================================
if (isset($_GET['sair'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// =========================================================================
// LÓGICA DE LOGIN (PELA BARRA SUPERIOR)
// =========================================================================
$erro_login = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_login'])) {
    $usuario_login = trim($_POST['usuario_login'] ?? '');
    $senha_login = trim($_POST['senha_login'] ?? '');

    if (empty($usuario_login) || empty($senha_login)) {
        $erro_login = "Preencha o usuário e a senha!";
    } else {
        try {
            $pdo_teste = new PDO("mysql:host=127.0.0.1", $usuario_login, $senha_login);
            $pdo_teste->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $_SESSION['logado'] = true;
            $_SESSION['usuario_nome'] = ucfirst($usuario_login); 
            header("Location: index.php"); 
            exit;
        } catch (\PDOException $e) {
            $erro_login = "Usuário ou senha incorretos!";
        }
    }
}

// Verifica status do login para proteção das telas
$esta_logado = isset($_SESSION['logado']) && $_SESSION['logado'] === true;
$aba_ativa = "dashboard"; 

// =========================================================================
// LÓGICA DE CADASTRO
// =========================================================================
$mensagem = "";
$classe_alerta = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_cadastro'])) {
    $aba_ativa = "cadastro"; 
    if (!$esta_logado) {
        $mensagem = "Ação negada! Você precisa fazer login para cadastrar.";
        $classe_alerta = "erro";
    } else {
        $cpf   = trim($_POST['cpf'] ?? '');
        $nome  = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $area  = trim($_POST['area'] ?? '');

        if (empty($cpf) || empty($nome) || empty($email) || empty($area)) {
            $mensagem = "Todos os campos são obrigatórios!";
            $classe_alerta = "erro";
        } else {
            try {
                $cpf_limpo = preg_replace('/[^0-9]/', '', $cpf);
                $sql = "INSERT INTO usuarios (cpf, nome, email, area) VALUES (?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                // CORRIGIDO: variável $area inserida corretamente abaixo
                $stmt->execute([$cpf_limpo, $nome, $email, $area]);

                $mensagem = "Usuário cadastrado com sucesso!";
                $classe_alerta = "sucesso";
            } catch (\PDOException $e) {
                $mensagem = "Erro: Já existe um registro com este CPF ou E-mail.";
                $classe_alerta = "erro";
            }
        }
    }
}

// =========================================================================
// LÓGICA DO CONSOLE DE CONSULTA (Aba Monitor)
// =========================================================================
$resultado_consulta = [];
$colunas_consulta = [];
$mensagem_consulta = "";
$classe_consulta = "";
$query_digitada = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_consulta'])) {
    $aba_ativa = "monitor"; 
    if (!$esta_logado) {
        $mensagem_consulta = "Você precisa estar logado para executar consultas.";
        $classe_consulta = "erro";
    } else {
        $query_digitada = trim($_POST['sql_query'] ?? '');
        // Trava de segurança: apenas SELECT
        if (strtoupper(substr($query_digitada, 0, 6)) !== 'SELECT') {
            $mensagem_consulta = "Bloqueado: Por segurança, apenas consultas 'SELECT' são permitidas aqui.";
            $classe_consulta = "erro";
        } else {
            try {
                $stmt_cons = $pdo->query($query_digitada);
                $resultado_consulta = $stmt_cons->fetchAll(PDO::FETCH_ASSOC);
                if (count($resultado_consulta) > 0) {
                    $colunas_consulta = array_keys($resultado_consulta[0]);
                    $mensagem_consulta = "Consulta executada com sucesso! (" . count($resultado_consulta) . " linhas)";
                    $classe_consulta = "sucesso";
                } else {
                    $mensagem_consulta = "A consulta não retornou nenhum resultado.";
                    $classe_consulta = "aviso";
                }
            } catch (\PDOException $e) {
                $mensagem_consulta = "Erro SQL: " . $e->getMessage();
                $classe_consulta = "erro";
            }
        }
    }
}

// =========================================================================
// COLETA DE DADOS DO BANCO (Tamanho, Telemetria Zabbix e Processos)
// =========================================================================
// 1. Tamanho do Banco
$tamanho_usado_mb = 0; $tamanho_total_mb = 1024; 
try {
    $db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($db_name) {
        $stmt_size = $pdo->prepare("SELECT SUM(data_length + index_length)/1024/1024 AS tamanho_mb FROM information_schema.TABLES WHERE table_schema = ?");
        $stmt_size->execute([$db_name]);
        $tamanho_usado_mb = round($stmt_size->fetchColumn() ?? 0, 2);
    }
} catch (\PDOException $e) {}
$tamanho_livre_mb = max(0, $tamanho_total_mb - $tamanho_usado_mb);

// 2. Telemetria Zabbix (Uptime, Threads, Queries)
$stats_zabbix = ['Uptime' => 0, 'Threads_connected' => 0, 'Questions' => 0];
try {
    $stmt_stat = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected', 'Questions')");
    while ($row = $stmt_stat->fetch(PDO::FETCH_ASSOC)) { $stats_zabbix[$row['Variable_name']] = $row['Value']; }
} catch (\PDOException $e) {}

// Formata o Uptime de segundos para Dias/Horas/Mins
$up_dias = floor($stats_zabbix['Uptime'] / 86400);
$up_horas = floor(($stats_zabbix['Uptime'] % 86400) / 3600);
$up_minutos = floor(($stats_zabbix['Uptime'] % 3600) / 60);
$uptime_formatado = "{$up_dias}d {$up_horas}h {$up_minutos}m";

// 3. Processos Ativos (Consumo por Usuário)
$processos_bd = [];
try {
    $stmt_proc = $pdo->query("SELECT ID, USER, HOST, DB, COMMAND, TIME, STATE, INFO FROM information_schema.PROCESSLIST ORDER BY TIME DESC");
    $processos_bd = $stmt_proc->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {}
?>

<!DOCTYPE html>
<html lang="pt-br" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elephant ERP - Painel</title>
    
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .material-symbols-outlined { font-family: 'Material Symbols Outlined', sans-serif !important; user-select: none; }
        :root {
            --bg-body: #f4f7f6; --bg-sidebar: #1a365d; --text-sidebar: #ffffff;
            --bg-card: #ffffff; --text-main: #2d3748; --text-label: #4a5568;
            --input-bg: #ffffff; --input-border: #e2e8f0; --input-focus: #3182ce;
            --btn-bg: #2b6cb0; --btn-hover: #1a365d; --btn-text: #ffffff; --btn-danger: #e53e3e;
            --shadow: rgba(0, 0, 0, 0.05) 0px 4px 15px; --border-color: #e2e8f0;
            --alert-success-bg: #c6f6d5; --alert-success-text: #22543d; 
            --alert-error-bg: #fed7d7; --alert-error-text: #742a2a; 
            --alert-warning-bg: #feebc8; --alert-warning-text: #7b341e;
        }
        [data-theme="dark"] {
            --bg-body: #1a202c; --bg-sidebar: #111827; --text-sidebar: #e2e8f0;
            --bg-card: #2d3748; --text-main: #f7fafc; --text-label: #e2e8f0;
            --input-bg: #1a202c; --input-border: #4a5568; --input-focus: #63b3ed;
            --btn-bg: #3182ce; --btn-hover: #63b3ed; --border-color: #4a5568;
            --shadow: rgba(0, 0, 0, 0.4) 0px 8px 20px;
            --alert-success-bg: #22543d; --alert-success-text: #c6f6d5; 
            --alert-error-bg: #742a2a; --alert-error-text: #fed7d7; 
            --alert-warning-bg: #7b341e; --alert-warning-text: #feebc8; 
        }
        body { font-family: 'Inter', sans-serif; margin: 0; display: flex; height: 100vh; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s; }
        
        .sidebar { width: 260px; background-color: var(--bg-sidebar); color: var(--text-sidebar); display: flex; flex-direction: column; }
        .sidebar-header { padding: 20px; font-size: 20px; font-weight: bold; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 0; margin: 20px 0; }
        .nav-item { padding: 15px 20px; display: flex; align-items: center; gap: 15px; cursor: pointer; font-weight: 500; transition: 0.2s; }
        .nav-item:hover, .nav-item.ativo { background-color: rgba(255,255,255,0.15); border-left: 4px solid #63b3ed; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        
        /* Topbar */
        .topbar { min-height: 60px; background-color: var(--bg-card); display: flex; justify-content: flex-end; align-items: center; padding: 10px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); gap: 15px; flex-wrap: wrap; }
        .btn-tema { background: none; border: none; cursor: pointer; color: var(--text-main); }
        .login-inline { display: flex; gap: 10px; align-items: center; }
        .login-inline input { padding: 8px; border: 1px solid var(--input-border); border-radius: 6px; background: var(--input-bg); color: var(--text-main); }
        .login-inline button { padding: 8px 15px; background: var(--btn-bg); color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .erro-topo { color: var(--alert-error-text); font-size: 13px; font-weight: bold; }
        .user-info { display: flex; align-items: center; gap: 8px; font-weight: 500; }
        .btn-sair { background: var(--btn-danger); color: white; padding: 8px 15px; border-radius: 6px; font-weight: bold; text-decoration: none; display: flex; align-items: center; gap: 5px;}

        /* Abas e Grids */
        .tab-content { display: none; padding: 30px; }
        .tab-content.ativa { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .zabbix-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        
        .card { background: var(--bg-card); padding: 25px; border-radius: 12px; box-shadow: var(--shadow); }
        .card-header { font-size: 16px; font-weight: bold; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;}
        
        .stat-box { background: var(--bg-card); border-left: 4px solid var(--btn-bg); padding: 15px; border-radius: 8px; box-shadow: var(--shadow); display: flex; align-items: center; gap: 15px;}
        .stat-box .valor { font-size: 24px; font-weight: bold; color: var(--text-main); }
        .stat-box .titulo { font-size: 12px; color: var(--text-label); text-transform: uppercase; letter-spacing: 0.5px; }

        /* Tabelas */
        .tabela-container { overflow-x: auto; margin-top: 15px; border-radius: 8px; border: 1px solid var(--border-color); }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        th { background-color: var(--input-bg); padding: 12px; border-bottom: 2px solid var(--border-color); color: var(--text-label); white-space: nowrap;}
        td { padding: 12px; border-bottom: 1px solid var(--border-color); }
        tr:hover { background-color: rgba(0,0,0,0.02); }
        [data-theme="dark"] tr:hover { background-color: rgba(255,255,255,0.05); }
        .badge-status { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .status-sleep { background: var(--border-color); color: var(--text-main); }
        .status-query { background: #68d391; color: #1a202c; }

        /* Formulários */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px;}
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--input-border); background-color: var(--input-bg); color: var(--text-main); border-radius: 8px; font-family: 'Inter', sans-serif;}
        .form-group textarea { resize: vertical; min-height: 100px; font-family: monospace; font-size: 15px; }
        .form-group input:disabled, .form-group textarea:disabled { opacity: 0.6; cursor: not-allowed; } 
        button.btn-principal { width: 100%; padding: 14px; background: var(--btn-bg); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; }
        button.btn-principal:disabled { background: #a0aec0; cursor: not-allowed; }

        .alerta { padding: 14px; margin-bottom: 20px; border-radius: 8px; text-align: center; font-size: 14px; font-weight: bold; }
        .sucesso { background: var(--alert-success-bg); color: var(--alert-success-text); }
        .erro { background: var(--alert-error-bg); color: var(--alert-error-text); }
        .aviso { background: var(--alert-warning-bg); color: var(--alert-warning-text); display: flex; align-items: center; justify-content: center; gap: 8px; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <span class="material-symbols-outlined">dataset</span>
            Elephant ERP
        </div>
        <ul class="nav-menu">
            <li class="nav-item <?= $aba_active == 'dashboard' || $aba_ativa == 'dashboard' ? 'ativo' : '' ?>" onclick="abrirAba('aba-dashboard', this)">
                <span class="material-symbols-outlined">dashboard</span>
                Visão Geral
            </li>
            <li class="nav-item <?= $aba_ativa == 'monitor' ? 'ativo' : '' ?>" onclick="abrirAba('aba-monitor', this)">
                <span class="material-symbols-outlined">monitoring</span>
                Monitor BD
            </li>
            <li class="nav-item <?= $aba_ativa == 'cadastro' ? 'ativo' : '' ?>" onclick="abrirAba('aba-cadastro', this)">
                <span class="material-symbols-outlined">person_add</span>
                Novo Usuário
            </li>
        </ul>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <?php if (!empty($erro_login)): ?>
                <span class="erro-topo"><?= $erro_login ?></span>
            <?php endif; ?>

            <?php if ($esta_logado): ?>
                <div class="user-info">
                    <span class="material-symbols-outlined">account_circle</span>
                    Olá, <?= htmlspecialchars($_SESSION['usuario_nome']) ?>
                </div>
                <a href="index.php?sair=true" class="btn-sair">Sair</a>
            <?php else: ?>
                <form class="login-inline" action="index.php" method="POST">
                    <input type="hidden" name="form_login" value="1">
                    <input type="text" name="usuario_login" placeholder="Usuário Admin" required>
                    <input type="password" name="senha_login" placeholder="Senha do Banco" required>
                    <button type="submit">Logar</button>
                </form>
            <?php endif; ?>

            <button class="btn-tema" id="btn-tema" title="Alternar Tema">
                <span class="material-symbols-outlined" id="icone-tema">toggle_off</span>
            </button>
        </header>

        <section id="aba-dashboard" class="tab-content <?= $aba_ativa == 'dashboard' ? 'ativa' : '' ?>">
            <h2 style="margin-top:0;">Visão Geral</h2>
            
            <div class="zabbix-grid">
                <div class="stat-box">
                    <span class="material-symbols-outlined" style="font-size:32px; color:var(--btn-bg)">schedule</span>
                    <div>
                        <div class="titulo">Uptime do Servidor</div>
                        <div class="valor"><?= $uptime_formatado ?></div>
                    </div>
                </div>
                <div class="stat-box">
                    <span class="material-symbols-outlined" style="font-size:32px; color:#48bb78">query_stats</span>
                    <div>
                        <div class="titulo">Total de Queries</div>
                        <div class="valor"><?= number_format($stats_zabbix['Questions'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="stat-box">
                    <span class="material-symbols-outlined" style="font-size:32px; color:#e53e3e">hub</span>
                    <div>
                        <div class="titulo">Conexões Ativas</div>
                        <div class="valor"><?= $stats_zabbix['Threads_connected'] ?></div>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header"><span class="material-symbols-outlined">database</span> Armazenamento do BD</div>
                    <div style="position: relative; height: 250px; width: 100%; display: flex; justify-content: center;">
                        <canvas id="graficoArmazenamento"></canvas>
                    </div>
                </div>
            </div>
        </section>

        <section id="aba-monitor" class="tab-content <?= $aba_ativa == 'monitor' ? 'ativa' : '' ?>">
            <h2 style="margin-top:0; display:flex; align-items:center; gap:8px;">
                <span class="material-symbols-outlined">monitoring</span> Monitoramento & Consultas
            </h2>

            <div class="card" style="margin-bottom: 25px;">
                <div class="card-header"><span class="material-symbols-outlined">group</span> Consumo por Usuário (Processos Ativos)</div>
                <p style="font-size: 13px; color: var(--text-label); margin-top:0;">Visualização em tempo real das threads e consultas rodando no banco.</p>
                
                <div class="tabela-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuário</th>
                                <th>Host / Origem</th>
                                <th>Banco</th>
                                <th>Comando</th>
                                <th>Tempo (s)</th>
                                <th>Status</th>
                                <th>Query</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($processos_bd)): ?>
                                <tr><td colspan="8" style="text-align:center;">Nenhum processo detectado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($processos_bd as $proc): ?>
                                    <tr>
                                        <td><?= $proc['ID'] ?></td>
                                        <td><strong><?= htmlspecialchars($proc['USER']) ?></strong></td>
                                        <td><?= htmlspecialchars($proc['HOST']) ?></td>
                                        <td><?= htmlspecialchars($proc['DB'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($proc['COMMAND']) ?></td>
                                        <td><?= $proc['TIME'] ?></td>
                                        <td>
                                            <span class="badge-status <?= $proc['COMMAND'] === 'Sleep' ? 'status-sleep' : 'status-query' ?>">
                                                <?= htmlspecialchars($proc['COMMAND']) ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($proc['INFO'] ?? '-') ?>">
                                            <code style="font-size: 12px;"><?= htmlspecialchars($proc['INFO'] ?? '-') ?></code>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><span class="material-symbols-outlined">terminal</span> Terminal SQL (Somente Consulta)</div>
                
                <?php if (!empty($mensagem_consulta)): ?>
                    <div class="alerta <?= $classe_consulta ?>"><?= $mensagem_consulta ?></div>
                <?php endif; ?>

                <?php if (!$esta_logado): ?>
                    <div class="alerta aviso">Faça login no topo para habilitar a execução de queries.</div>
                <?php endif; ?>

                <form action="index.php" method="POST">
                    <input type="hidden" name="form_consulta" value="1">
                    <div class="form-group">
                        <textarea name="sql_query" placeholder="Digite sua query SELECT aqui... Ex: SELECT * FROM usuarios LIMIT 10;" <?= !$esta_logado ? 'disabled' : 'required' ?>><?= htmlspecialchars($query_digitada) ?></textarea>
                    </div>
                    <button type="submit" class="btn-principal" style="width:auto; padding: 10px 20px;" <?= !$esta_logado ? 'disabled' : '' ?>>
                        <span class="material-symbols-outlined" style="vertical-align: middle; font-size:18px;">play_arrow</span> Rodar Consulta
                    </button>
                </form>

                <?php if (!empty($resultado_consulta)): ?>
                    <div class="tabela-container" style="margin-top: 20px;">
                        <table>
                            <thead>
                                <tr>
                                    <?php foreach ($colunas_consulta as $coluna): ?>
                                        <th><?= htmlspecialchars($coluna) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultado_consulta as $linha): ?>
                                    <tr>
                                        <?php foreach ($colunas_consulta as $coluna): ?>
                                            <td><?= htmlspecialchars($linha[$coluna] ?? '') ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section id="aba-cadastro" class="tab-content <?= $aba_ativa == 'cadastro' ? 'ativa' : '' ?>">
            <div class="card" style="max-width: 600px;">
                <div class="card-header"><span class="material-symbols-outlined">person_add</span> Cadastrar Novo Usuário</div>
                
                <?php if (!empty($mensagem)): ?>
                    <div class="alerta <?= $classe_alerta ?>"><?= $mensagem ?></div>
                <?php endif; ?>

                <?php if (!$esta_logado): ?>
                    <div class="alerta aviso">Faça login para habilitar o cadastro.</div>
                <?php endif; ?>

                <form action="index.php" method="POST">
                    <input type="hidden" name="form_cadastro" value="1">
                    <div style="display:flex; gap:15px; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1;">
                            <label>Área</label>
                            <select name="area" <?= !$esta_logado ? 'disabled' : 'required' ?>>
                                <option value="" disabled selected>Selecione...</option>
                                <option value="Desenvolvimento">Desenvolvimento</option>
                                <option value="Suporte">Suporte</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>CPF</label>
                            <input type="text" name="cpf" <?= !$esta_logado ? 'disabled' : 'required' ?>>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Nome Completo</label>
                        <input type="text" name="nome" <?= !$esta_logado ? 'disabled' : 'required' ?>>
                    </div>
                    <div class="form-group">
                        <label>E-mail</label>
                        <input type="email" name="email" <?= !$esta_logado ? 'disabled' : 'required' ?>>
                    </div>
                    <button type="submit" class="btn-principal" <?= !$esta_logado ? 'disabled' : '' ?>>Cadastrar</button>
                </form>
            </div>
        </section>
    </main>

<script>
    // Gerencia a troca de abas
    function abrirAba(idAba, elementoClicado) {
        document.querySelectorAll('.tab-content').forEach(aba => aba.classList.remove('ativa'));
        document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('ativo'));
        document.getElementById(idAba).classList.add('ativa');
        elementoClicado.classList.add('ativo');
    }

    // Gerencia o Tema Dark/Light
    const htmlElement = document.documentElement;
    const btnTema = document.getElementById('btn-tema');
    const iconeTema = document.getElementById('icone-tema');
    
    function atualizarCoresGrafico(tema) {
        if(window.meuGrafico) {
            const isDark = tema === 'dark';
            window.meuGrafico.data.datasets[0].backgroundColor = [isDark ? '#fc8181' : '#e53e3e', isDark ? '#68d391' : '#48bb78'];
            window.meuGrafico.options.plugins.legend.labels.color = isDark ? '#e2e8f0' : '#4a5568';
            window.meuGrafico.update();
        }
    }

    const temaSalvo = localStorage.getItem('tema');
    if (temaSalvo === 'dark') { htmlElement.setAttribute('data-theme', 'dark'); iconeTema.textContent = 'toggle_on'; } 

    btnTema.addEventListener('click', () => {
        const novoTema = htmlElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        iconeTema.textContent = novoTema === 'dark' ? 'toggle_on' : 'toggle_off';
        htmlElement.setAttribute('data-theme', novoTema);
        localStorage.setItem('tema', novoTema);
        atualizarCoresGrafico(novoTema);
    });

    // Gráfico Chart.js
    document.addEventListener('DOMContentLoaded', function() {
        const canvas = document.getElementById('graficoArmazenamento');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const isDark = (htmlElement.getAttribute('data-theme') || 'light') === 'dark';
        
        window.meuGrafico = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Usado (MB)', 'Livre (MB)'],
                datasets: [{
                    data: [<?= json_encode($tamanho_usado_mb) ?>, <?= json_encode($tamanho_livre_mb) ?>],
                    backgroundColor: [isDark ? '#fc8181' : '#e53e3e', isDark ? '#68d391' : '#48bb78'],
                    borderWidth: 0, hoverOffset: 4
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { color: isDark ? '#e2e8f0' : '#4a5568' } } } }
        });
    });
</script>

</body>
</html>