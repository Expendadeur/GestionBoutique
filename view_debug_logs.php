<?php
// view_debug_logs.php
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs de débogage - Vente</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .log-entry { font-family: 'Courier New', monospace; font-size: 12px; }
        .log-error { color: #dc2626; }
        .log-success { color: #059669; }
        .log-warning { color: #d97706; }
    </style>
</head>
<body class="bg-gray-100 p-4">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex justify-between items-center mb-4">
                <h1 class="text-2xl font-bold text-gray-800">Logs de débogage - Vente</h1>
                <div class="space-x-2">
                    <button onclick="refreshLogs()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        Actualiser
                    </button>
                    <button onclick="clearLogs()" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
                        Vider
                    </button>
                </div>
            </div>
            
            <div id="logsContainer" class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-auto" style="height: 70vh;">
                <div class="log-entry">Chargement des logs...</div>
            </div>
        </div>
    </div>

    <script>
        function loadLogs() {
            fetch('get_debug_logs.php')
                .then(response => response.text())
                .then(logs => {
                    const container = document.getElementById('logsContainer');
                    if (logs.trim() === '') {
                        container.innerHTML = '<div class="log-entry text-gray-400">Aucun log disponible</div>';
                    } else {
                        // Colorer les logs selon le contenu
                        let coloredLogs = logs
                            .replace(/ERREUR/g, '<span class="log-error">ERREUR</span>')
                            .replace(/SUCCÈS/g, '<span class="log-success">SUCCÈS</span>')
                            .replace(/DÉBUT/g, '<span class="log-success">DÉBUT</span>')
                            .replace(/FIN/g, '<span class="log-success">FIN</span>')
                            .replace(/BLOQUÉE/g, '<span class="log-warning">BLOQUÉE</span>')
                            .replace(/Transaction commitée/g, '<span class="log-success">Transaction commitée</span>')
                            .replace(/Transaction annulée/g, '<span class="log-error">Transaction annulée</span>');
                        
                        container.innerHTML = `<pre class="log-entry">${coloredLogs}</pre>`;
                    }
                    // Scroll vers le bas
                    container.scrollTop = container.scrollHeight;
                })
                .catch(error => {
                    document.getElementById('logsContainer').innerHTML = 
                        `<div class="log-entry log-error">Erreur de chargement: ${error.message}</div>`;
                });
        }
        
        function refreshLogs() {
            loadLogs();
        }
        
        function clearLogs() {
            if (confirm('Vider tous les logs ?')) {
                fetch('clear_debug_logs.php', {method: 'POST'})
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            loadLogs();
                        } else {
                            alert('Erreur: ' + result.message);
                        }
                    })
                    .catch(error => {
                        alert('Erreur lors de la suppression: ' + error.message);
                    });
            }
        }
        
        // Charger les logs au démarrage
        loadLogs();
        
        // Auto-refresh toutes les 5 secondes
        setInterval(loadLogs, 5000);
    </script>
</body>
</html>