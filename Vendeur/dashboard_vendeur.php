<?php
require_once 'config2.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Vérifier que l'utilisateur est vendeur
if ($user['role'] !== 'vendeur') {
    header('Location: login.php');
    exit;
}

// Statistiques du jour pour le vendeur
$today = date('Y-m-d');
$stats = [];

// Mes ventes du jour
$mes_ventes_jour = $db->fetch("
    SELECT 
        COUNT(*) as nombre_ventes,
        COALESCE(SUM(montant_total_ttc), 0) as total_ventes
    FROM ventes 
    WHERE DATE(date_vente) = ? AND statut = 'validee' AND id_utilisateur = ?
", [$today, $user['id_utilisateur']]);

// Si aucun résultat, initialiser avec des valeurs par défaut
if (!$mes_ventes_jour) {
    $mes_ventes_jour = ['nombre_ventes' => 0, 'total_ventes' => 0];
}

// Ventes de l'équipe du jour
$ventes_equipe_jour = $db->fetch("
    SELECT 
        COUNT(*) as nombre_ventes,
        COALESCE(SUM(montant_total_ttc), 0) as total_ventes
    FROM ventes 
    WHERE DATE(date_vente) = ? AND statut = 'validee'
", [$today]);

if (!$ventes_equipe_jour) {
    $ventes_equipe_jour = ['nombre_ventes' => 0, 'total_ventes' => 0];
}

// Produits en rupture de stock (alerte) - avec vérification d'existence des tables
try {
    $produits_rupture = $db->fetch("
        SELECT COUNT(*) as nombre_rupture
        FROM produits p
        LEFT JOIN stocks s ON p.id_produit = s.id_produit
        WHERE s.quantite_actuelle <= p.stock_minimum OR s.quantite_actuelle IS NULL
    ");
    
    if (!$produits_rupture) {
        $produits_rupture = ['nombre_rupture' => 0];
    }
} catch (PDOException $e) {
    // Si les tables n'existent pas, initialiser à 0
    $produits_rupture = ['nombre_rupture' => 0];
}

// Mes top 5 produits vendus ce mois
try {
    $mes_top_produits = $db->fetchAll("
        SELECT 
            p.nom_produit,
            SUM(dv.quantite) as total_vendu,
            SUM(dv.montant_ligne) as total_ca
        FROM details_ventes dv
        JOIN produits p ON dv.id_produit = p.id_produit
        JOIN ventes v ON dv.id_vente = v.id_vente
        WHERE MONTH(v.date_vente) = MONTH(CURRENT_DATE()) 
        AND YEAR(v.date_vente) = YEAR(CURRENT_DATE())
        AND v.statut = 'validee' AND v.id_utilisateur = ?
        GROUP BY p.id_produit, p.nom_produit
        ORDER BY total_vendu DESC
        LIMIT 5
    ", [$user['id_utilisateur']]);
} catch (PDOException $e) {
    $mes_top_produits = [];
}

// Mes dernières ventes
try {
    $mes_dernieres_ventes = $db->fetchAll("
        SELECT 
            v.*,
            c.nom_client,
            c.prenom_client
        FROM ventes v
        LEFT JOIN clients c ON v.id_client = c.id_client
        WHERE v.id_utilisateur = ?
        ORDER BY v.date_vente DESC
        LIMIT 10
    ", [$user['id_utilisateur']]);
} catch (PDOException $e) {
    $mes_dernieres_ventes = [];
}

// Messages/notifications pour le vendeur - avec gestion d'erreur
$notifications = [];
try {
    // Vérifier si la table notifications existe
    $db->getConnection()->query("SELECT 1 FROM notifications LIMIT 1");
    
    // Si la requête réussit, récupérer les notifications
    $notifications = $db->fetchAll("
        SELECT * FROM notifications 
        WHERE (destinataire_role = 'vendeur' OR destinataire_id = ?) 
        AND statut = 'non_lu'
        ORDER BY date_creation DESC
        LIMIT 5
    ", [$user['id_utilisateur']]);
    
} catch (PDOException $e) {
    // La table n'existe pas, créer quelques notifications par défaut en session
    if (!isset($_SESSION['default_notifications_shown'])) {
        $_SESSION['default_notifications'] = [
            ['message' => 'Bienvenue dans votre espace vendeur !', 'type' => 'info'],
            ['message' => 'Pensez à vérifier régulièrement les stocks', 'type' => 'warning']
        ];
        $_SESSION['default_notifications_shown'] = true;
    }
    $notifications = $_SESSION['default_notifications'] ?? [];
}

// Performance mensuelle du vendeur
try {
    $performance_mois = $db->fetch("
        SELECT 
            COUNT(*) as ventes_mois,
            COALESCE(SUM(montant_total_ttc), 0) as ca_mois
        FROM ventes 
        WHERE MONTH(date_vente) = MONTH(CURRENT_DATE()) 
        AND YEAR(date_vente) = YEAR(CURRENT_DATE())
        AND statut = 'validee' AND id_utilisateur = ?
    ", [$user['id_utilisateur']]);
    
    if (!$performance_mois) {
        $performance_mois = ['ventes_mois' => 0, 'ca_mois' => 0];
    }
} catch (PDOException $e) {
    $performance_mois = ['ventes_mois' => 0, 'ca_mois' => 0];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Espace Vendeur</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-store text-green-600 mr-2"></i><?= APP_NAME ?>
                    </h1>
                    <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                        Vendeur
                    </span>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Connecté en tant que</p>
                        <p class="font-medium text-gray-700"><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></p>
                    </div>
                    <div class="relative">
                        <button id="userMenuButton" class="flex items-center space-x-2 bg-gray-100 rounded-lg px-3 py-2 hover:bg-gray-200">
                            <i class="fas fa-user-circle text-xl text-gray-600"></i>
                            <i class="fas fa-chevron-down text-sm text-gray-400"></i>
                        </button>
                        <div id="userMenu" class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border hidden">
                            <a href="profile.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-user mr-2"></i>Mon Profil
                            </a>
                            <a href="mes_ventes.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-chart-line mr-2"></i>Mes Statistiques
                            </a>
                            <a href="../logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50">
                                <i class="fas fa-sign-out-alt mr-2"></i>Déconnexion
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        <!-- Sidebar Vendeur -->
        <div class="w-64 bg-white shadow-lg h-screen sticky top-0">
            <div class="p-4">
                <nav class="space-y-2">
                    <a href="dashboard_vendeur.php" class="flex items-center space-x-3 text-green-600 bg-green-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Mon Tableau de Bord</span>
                    </a>
                    <a href="vente.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-cash-register"></i>
                        <span>Nouvelle Vente</span>
                    </a>
                    <a href="categories.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-cash-register"></i>
                        <span>Nouvelle categorie</span>
                    </a>
                    <a href="produits.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-search"></i>
                        <span>Rechercher Produit</span>
                    </a>
                    <a href="clients.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-users"></i>
                        <span>Mes Clients</span>
                    </a>
                    <a href="stock.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-warehouse"></i>
                        <span>Vérifier Stock</span>
                    </a>
                    <a href="historique_ventes.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-history"></i>
                        <span>Mes Ventes</span>
                    </a>
                    <a href="aide_vendeur.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-question-circle"></i>
                        <span>Aide & Support</span>
                    </a>
                </nav>
            </div>
        </div>

        <!-- Contenu principal -->
        <div class="flex-1 p-8">
            <?php $flash = getFlashMessage(); ?>
            <?php if ($flash): ?>
                <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?>">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Notifications -->
            <?php if (!empty($notifications)): ?>
                <div class="mb-6 bg-yellow-50 border-l-4 border-yellow-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-bell text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                <strong>Notifications importantes:</strong>
                            </p>
                            <?php foreach ($notifications as $notif): ?>
                                <p class="text-sm text-yellow-600 mt-1">• <?= htmlspecialchars($notif['message']) ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Mes ventes aujourd'hui</p>
                            <p class="text-2xl font-bold text-green-600"><?= formatMoney($mes_ventes_jour['total_ventes']) ?></p>
                            <p class="text-sm text-gray-500"><?= $mes_ventes_jour['nombre_ventes'] ?> vente(s)</p>
                        </div>
                        <div class="bg-green-100 rounded-full p-3">
                            <i class="fas fa-cash-register text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Performance mensuelle</p>
                            <p class="text-2xl font-bold text-blue-600"><?= formatMoney($performance_mois['ca_mois']) ?></p>
                            <p class="text-sm text-gray-500"><?= $performance_mois['ventes_mois'] ?> vente(s) ce mois</p>
                        </div>
                        <div class="bg-blue-100 rounded-full p-3">
                            <i class="fas fa-chart-line text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Ventes équipe aujourd'hui</p>
                            <p class="text-2xl font-bold text-purple-600"><?= formatMoney($ventes_equipe_jour['total_ventes']) ?></p>
                            <p class="text-sm text-gray-500"><?= $ventes_equipe_jour['nombre_ventes'] ?> vente(s) totales</p>
                        </div>
                        <div class="bg-purple-100 rounded-full p-3">
                            <i class="fas fa-users text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Alertes stock</p>
                            <p class="text-2xl font-bold text-red-600"><?= $produits_rupture['nombre_rupture'] ?></p>
                            <p class="text-sm text-gray-500">produit(s) en rupture</p>
                        </div>
                        <div class="bg-red-100 rounded-full p-3">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions rapides -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Actions rapides</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <a href="vente.php" class="bg-green-500 hover:bg-green-600 text-white p-6 rounded-lg text-center transition duration-200">
                        <i class="fas fa-plus-circle text-3xl mb-2"></i>
                        <p class="font-semibold">Nouvelle Vente</p>
                        <p class="text-sm text-green-100">Commencer une transaction</p>
                    </a>
                    <a href="clients.php" class="bg-blue-500 hover:bg-blue-600 text-white p-6 rounded-lg text-center transition duration-200">
                        <i class="fas fa-user-plus text-3xl mb-2"></i>
                        <p class="font-semibold">Nouveau Client</p>
                        <p class="text-sm text-blue-100">Ajouter un client</p>
                    </a>
                    <a href="stock.php" class="bg-orange-500 hover:bg-orange-600 text-white p-6 rounded-lg text-center transition duration-200">
                        <i class="fas fa-search text-3xl mb-2"></i>
                        <p class="font-semibold">Vérifier Stock</p>
                        <p class="text-sm text-orange-100">Consulter disponibilité</p>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Mes top produits -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Mes produits les plus vendus ce mois</h3>
                    </div>
                    <div class="p-6">
                        <?php if (empty($mes_dernieres_ventes)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-receipt text-gray-300 text-4xl mb-4"></i>
                                <p class="text-gray-500">Aucune vente récente</p>
                                <p class="text-sm text-gray-400">Vos dernières ventes apparaîtront ici</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($mes_dernieres_ventes as $vente): ?>
                                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                        <div>
                                            <p class="font-medium text-gray-800">
                                                Ticket #<?= htmlspecialchars($vente['numero_ticket'] ?? 'N/A') ?>
                                            </p>
                                            <p class="text-sm text-gray-500">
                                                <?= $vente['nom_client'] ? htmlspecialchars($vente['prenom_client'] . ' ' . $vente['nom_client']) : 'Client sans compte' ?>
                                            </p>
                                            <p class="text-xs text-gray-400"><?= formatDateTime($vente['date_vente']) ?></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-semibold text-gray-800"><?= formatMoney($vente['montant_total_ttc']) ?></p>
                                            <span class="inline-block px-2 py-1 text-xs rounded-full 
                                                <?= $vente['statut'] === 'validee' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' ?>">
                                                <?= ucfirst($vente['statut']) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle user menu
        document.getElementById('userMenuButton').addEventListener('click', function() {
            document.getElementById('userMenu').classList.toggle('hidden');
        });

        // Close user menu when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('userMenu');
            const userMenuButton = document.getElementById('userMenuButton');
            
            if (!userMenuButton.contains(event.target)) {
                userMenu.classList.add('hidden');
            }
        });

        // Auto-refresh des stats toutes les 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);

        // Afficher une notification de bienvenue si c'est la première visite
        <?php if (!isset($_SESSION['dashboard_visited'])): ?>
            <?php $_SESSION['dashboard_visited'] = true; ?>
            setTimeout(function() {
                // Créer une notification toast
                const notification = document.createElement('div');
                notification.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 transform translate-x-full transition-transform duration-300';
                notification.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Bienvenue dans votre espace vendeur !';
                document.body.appendChild(notification);
                
                // Animation d'entrée
                setTimeout(() => {
                    notification.classList.remove('translate-x-full');
                }, 100);
                
                // Masquer après 4 secondes
                setTimeout(() => {
                    notification.classList.add('translate-x-full');
                    setTimeout(() => {
                        document.body.removeChild(notification);
                    }, 300);
                }, 4000);
            }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>