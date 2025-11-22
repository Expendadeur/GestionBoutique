<?php
// config2.php - Configuration de base pour boutique de quartier
session_start();

// Configuration base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'gestion_boutique_quartier');
define('DB_USER', 'root');
define('DB_PASS', '');

// Configuration application
define('APP_NAME', 'Boutique de Quartier');
define('APP_VERSION', '1.0.0');
define('TIMEZONE', 'Africa/Bujumbura');

// Configuration monétaire
define('DEVISE', 'BIF');
define('DEVISE_SYMBOLE', 'BIF');

date_default_timezone_set(TIMEZONE);

class Database {
    private static $instance = null;
    private $connection = null;

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
        } catch (PDOException $e) {
            die("Erreur de connexion à la base de données: " . $e->getMessage());
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

    public function fetch($query, $params = []) {
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            // Log l'erreur et retourner null au lieu de faire planter l'application
            error_log("Erreur SQL dans fetch(): " . $e->getMessage());
            return null;
        }
    }

    public function fetchAll($query, $params = []) {
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // Log l'erreur et retourner un tableau vide au lieu de faire planter l'application
            error_log("Erreur SQL dans fetchAll(): " . $e->getMessage());
            return [];
        }
    }

    public function execute($query, $params = []) {
        try {
            $stmt = $this->connection->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Erreur SQL dans execute(): " . $e->getMessage());
            return false;
        }
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    // Nouvelle méthode pour vérifier l'existence d'une table
    public function tableExists($tableName) {
        try {
            $result = $this->connection->query("SELECT 1 FROM {$tableName} LIMIT 1");
            return $result !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    // Nouvelle méthode pour exécuter une requête avec gestion d'erreur silencieuse
    public function safeQuery($query, $params = [], $defaultReturn = null) {
        try {
            if (empty($params)) {
                $stmt = $this->connection->query($query);
                return $stmt->fetchAll();
            } else {
                $stmt = $this->connection->prepare($query);
                $stmt->execute($params);
                return $stmt->fetchAll();
            }
        } catch (PDOException $e) {
            error_log("Erreur SQL dans safeQuery(): " . $e->getMessage());
            return $defaultReturn;
        }
    }
}

// Fonctions utilitaires
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $db = Database::getInstance();
    return $db->fetch(
        "SELECT * FROM utilisateurs WHERE id_utilisateur = ?", 
        [$_SESSION['user_id']]
    );
}

function checkRole($allowedRoles) {
    $user = getCurrentUser();
    if (!$user || !in_array($user['role'], $allowedRoles)) {
        header('HTTP/1.0 403 Forbidden');
        die('Accès refusé');
    }
    return $user;
}

function formatMoney($amount) {
    // Gérer les valeurs nulles ou non numériques
    if (!is_numeric($amount)) {
        $amount = 0;
    }
    return number_format($amount, 0, ',', ' ') . ' ' . DEVISE_SYMBOLE;
}

function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') {
        return 'N/A';
    }
    return date('d/m/Y', strtotime($date));
}

function formatDateTime($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    return date('d/m/Y H:i', strtotime($datetime));
}

function setFlashMessage($message, $type = 'info') {
    $_SESSION['flash_message'] = [
        'message' => $message,
        'type' => $type
    ];
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $flash;
    }
    return null;
}

function generateTicketNumber() {
    return 'T' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function logActivity($user_id, $action, $details = '') {
    $db = Database::getInstance();
    
    // Vérifier si la table logs_activites existe
    if ($db->tableExists('logs_activites')) {
        $db->execute(
            "INSERT INTO logs_activites (id_utilisateur, action, details, date_action) VALUES (?, ?, ?, NOW())",
            [$user_id, $action, $details]
        );
    } else {
        // Fallback: log dans un fichier si la table n'existe pas
        error_log("Activity Log - User: $user_id, Action: $action, Details: $details");
    }
}

// Fonctions spécifiques vendeur
function canAccessVendeurFeatures() {
    $user = getCurrentUser();
    return $user && in_array($user['role'], ['vendeur', 'proprietaire']);
}

function getVendeurStats($vendeur_id, $period = 'today') {
    $db = Database::getInstance();
    
    switch ($period) {
        case 'today':
            $condition = "DATE(date_vente) = CURRENT_DATE()";
            break;
        case 'week':
            $condition = "WEEK(date_vente) = WEEK(CURRENT_DATE()) AND YEAR(date_vente) = YEAR(CURRENT_DATE())";
            break;
        case 'month':
            $condition = "MONTH(date_vente) = MONTH(CURRENT_DATE()) AND YEAR(date_vente) = YEAR(CURRENT_DATE())";
            break;
        default:
            $condition = "DATE(date_vente) = CURRENT_DATE()";
    }
    
    $stats = $db->fetch("
        SELECT 
            COUNT(*) as nb_ventes,
            COALESCE(SUM(montant_total_ttc), 0) as ca_total,
            COALESCE(AVG(montant_total_ttc), 0) as ticket_moyen
        FROM ventes 
        WHERE id_utilisateur = ? AND statut = 'validee' AND $condition
    ", [$vendeur_id]);
    
    // Retourner des valeurs par défaut si la requête échoue
    return $stats ?: ['nb_ventes' => 0, 'ca_total' => 0, 'ticket_moyen' => 0];
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function isValidPhone($phone) {
    // Validation simple pour numéros burundais
    return preg_match('/^(\+257|257)?[67][0-9]{7}$/', $phone);
}

// Fonction pour initialiser les tables manquantes
function initializeMissingTables() {
    $db = Database::getInstance();
    
    // Vérifier et créer la table notifications si elle n'existe pas
    if (!$db->tableExists('notifications')) {
        $createNotifications = "
            CREATE TABLE `notifications` (
                `id_notification` int(11) NOT NULL AUTO_INCREMENT,
                `message` text NOT NULL,
                `type` varchar(50) DEFAULT 'info',
                `destinataire_role` varchar(50) DEFAULT NULL,
                `destinataire_id` int(11) DEFAULT NULL,
                `statut` enum('lu','non_lu') DEFAULT 'non_lu',
                `date_creation` timestamp DEFAULT CURRENT_TIMESTAMP,
                `date_lecture` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id_notification`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        if ($db->execute($createNotifications)) {
            // Ajouter quelques notifications par défaut
            $db->execute("INSERT INTO notifications (message, type, destinataire_role) VALUES (?, ?, ?)", 
                ['Bienvenue dans votre espace vendeur !', 'info', 'vendeur']);
        }
    }
    
    // Vérifier et créer la table logs_activites si elle n'existe pas
    if (!$db->tableExists('logs_activites')) {
        $createLogs = "
            CREATE TABLE `logs_activites` (
                `id_log` int(11) NOT NULL AUTO_INCREMENT,
                `id_utilisateur` int(11) DEFAULT NULL,
                `action` varchar(255) NOT NULL,
                `details` text DEFAULT NULL,
                `date_action` timestamp DEFAULT CURRENT_TIMESTAMP,
                `ip_address` varchar(45) DEFAULT NULL,
                PRIMARY KEY (`id_log`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $db->execute($createLogs);
    }
}

// Auto-initialisation des tables au premier chargement
if (!isset($_SESSION['tables_checked'])) {
    initializeMissingTables();
    $_SESSION['tables_checked'] = true;
}
?>