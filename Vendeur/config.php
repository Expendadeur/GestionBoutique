<?php
//config.php
// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'gestion_boutique_quartier');
define('DB_USER', 'root');
define('DB_PASS', '');

// Configuration de l'application
define('APP_NAME', 'Gestion Boutique');
define('PHONE', '+257 68680268');
define('ADRESSE', 'KIRIRI VUGIZO');
define('APP_VERSION', '1.0');

// Démarrer la session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Classe de connexion à la base de données
class Database {
    private static $instance = null;
    private $connection;
   
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            die("Erreur de connexion à la base de données : " . $e->getMessage());
        }
    }
   
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
   
    public function getConnection() {
        return $this->connection;
    }
   
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
   
    public function fetch($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
   
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
   
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
   
    // Méthode execute() manquante - AJOUTÉE
    public function execute($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute($params);
    }
   
    // Méthode pour obtenir le nombre de lignes affectées
    public function rowCount($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
   
    // Méthodes pour les transactions
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
   
    public function commit() {
        return $this->connection->commit();
    }
   
    public function rollback() {
        return $this->connection->rollBack();
    }
   
    // Méthode pour vérifier si une transaction est active
    public function inTransaction() {
        return $this->connection->inTransaction();
    }
   
    // Méthode pour préparer une requête (utile pour des cas spécifiques)
    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }
   
    // Méthode pour exécuter directement une requête sans paramètres
    public function exec($sql) {
        return $this->connection->exec($sql);
    }
}

// Fonctions utilitaires
function redirect($url) {
    header("Location: $url");
    exit();
}

function flashMessage($message, $type = 'info') {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function setFlashMessage($message, $type = 'info') {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

function getCurrentUser() {
    if (isLoggedIn()) {
        $db = Database::getInstance();
        return $db->fetch("SELECT * FROM utilisateurs WHERE id_utilisateur = ?", [$_SESSION['user_id']]);
    }
    return null;
}

function formatMoney($amount) {
    return number_format($amount, 0, ',', ' ') . ' BIF';
}

function formatDate($date) {
    return date('d/m/Y H:i', strtotime($date));
}

function generateTicketNumber() {
    return 'T' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateFactureNumber() {
    return 'F' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

// Fonction pour nettoyer les données d'entrée
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Fonction pour générer un mot de passe aléatoire
function generateRandomPassword($length = 8) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

// Fonction pour valider un email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Fonction pour logger les erreurs
function logError($message, $file = null, $line = null) {
    $log = date('Y-m-d H:i:s') . " - ";
    if ($file) $log .= "[$file";
    if ($line) $log .= ":$line";
    if ($file || $line) $log .= "] ";
    $log .= $message . PHP_EOL;
    
    error_log($log, 3, 'errors.log');
}
?>