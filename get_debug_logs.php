<?php
// get_debug_logs.php
header('Content-Type: text/plain');

$logFile = 'debug_vente.log';

if (file_exists($logFile)) {
    // Lire les 1000 dernières lignes pour éviter de surcharger
    $lines = file($logFile);
    if ($lines === false) {
        echo "Erreur de lecture du fichier de log";
        exit;
    }
    
    $totalLines = count($lines);
    $maxLines = 1000;
    
    if ($totalLines > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
        echo "... (Affichage des $maxLines dernières lignes sur $totalLines)\n\n";
    }
    
    echo implode('', $lines);
} else {
    echo "Fichier de log introuvable: $logFile";
}
?>