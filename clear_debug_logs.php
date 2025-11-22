<?php
// clear_debug_logs.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logFile = 'debug_vente.log';
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
        echo json_encode(['success' => true, 'message' => 'Logs vidés']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Fichier de log introuvable']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
}
?>