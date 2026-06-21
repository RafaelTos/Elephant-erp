<?php
// 1. Carrega de forma nativa e segura as credenciais salvas no arquivo .env
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignora linhas de comentários que comecem com '#'
        if (strpos(trim($line), '#') === 0) continue;
        
        // Separa a linha em Nome da variável e Valor correspondente
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

// 2. Busca os dados obtidos dinamicamente do ambiente local
$host    = $_ENV['DB_HOST'] ?? '127.0.0.1';
$db      = $_ENV['DB_NAME'] ?? '';
$user    = $_ENV['DB_USER'] ?? '';
$pass    = $_ENV['DB_PASS'] ?? '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // A conexão ocorre normalmente sem expor a senha no código-fonte
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Segurança extra: oculta caminhos internos de arquivos caso ocorra erro
    die("Erro ao conectar com o banco de dados."); 
}