<?php
// ========================================
// FICHIER: logout.php
// Gestion de la déconnexion
// ========================================

// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Détruire toutes les données de session
$_SESSION = array();

// Supprimer le cookie de session s'il existe
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Détruire la session
session_destroy();

// Empêcher la mise en cache de cette page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Rediriger vers la page de connexion avec un message
header('Location: login.php');
exit();
?>