<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Statistiques du jour
$today = date('Y-m-d');
$stats = [];

// Ventes du jour
$ventes_jour = $db->fetch("
    SELECT 
        COUNT(*) as nombre_ventes,
        COALESCE(SUM(montant_total_ttc), 0) as total_ventes
    FROM ventes 
    WHERE DATE(date_vente) = ? AND statut = 'validee'
", [$today]);

// Produits en rupture de stock
$produits_rupture = $db->fetch("
    SELECT COUNT(*) as nombre_rupture
    FROM produits p
    LEFT JOIN stocks s ON p.id_produit = s.id_produit
    WHERE s.quantite_actuelle <= p.stock_minimum OR s.quantite_actuelle IS NULL
");

// Top 5 des produits les plus vendus ce mois
$top_produits = $db->fetchAll("
    SELECT 
        p.nom_produit,
        SUM(dv.quantite) as total_vendu,
        SUM(dv.montant_ligne) as total_ca
    FROM details_ventes dv
    JOIN produits p ON dv.id_produit = p.id_produit
    JOIN ventes v ON dv.id_vente = v.id_vente
    WHERE MONTH(v.date_vente) = MONTH(CURRENT_DATE()) 
    AND YEAR(v.date_vente) = YEAR(CURRENT_DATE())
    AND v.statut = 'validee'
    GROUP BY p.id_produit, p.nom_produit
    ORDER BY total_vendu DESC
    LIMIT 5
");

// Dernières ventes
$dernieres_ventes = $db->fetchAll("
    SELECT 
        v.*,
        c.nom_client,
        c.prenom_client,
        u.prenom as vendeur_prenom,
        u.nom as vendeur_nom
    FROM ventes v
    LEFT JOIN clients c ON v.id_client = c.id_client
    JOIN utilisateurs u ON v.id_utilisateur = u.id_utilisateur
    ORDER BY v.date_vente DESC
    LIMIT 10
");

// Valeur totale du stock
$valeur_stock = $db->fetch("
    SELECT COALESCE(SUM(s.quantite_actuelle * p.prix_achat), 0) as valeur_totale
    FROM stocks s
    JOIN produits p ON s.id_produit = p.id_produit
");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
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
                        <i class="fas fa-store text-blue-600 mr-2"></i><?= APP_NAME ?>
                    </h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-600">Bonjour, <?= htmlspecialchars($user['prenom']) ?></span>
                    <div class="relative">
                        <button id="userMenuButton" class="flex items-center space-x-2 bg-gray-100 rounded-lg px-3 py-2 hover:bg-gray-200">
                            <i class="fas fa-user-circle text-xl text-gray-600"></i>
                            <i class="fas fa-chevron-down text-sm text-gray-400"></i>
                        </button>
                        <div id="userMenu" class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border hidden">
                            <a href="profile.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-user mr-2"></i>Profil
                            </a>
                            <a href="logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50">
                                <i class="fas fa-sign-out-alt mr-2"></i>Déconnexion
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        <!-- Sidebar -->
        <div class="w-64 bg-white shadow-lg h-screen sticky top-0">
            <div class="p-4">
                <nav class="space-y-2">
                    <a href="dashboard.php" class="flex items-center space-x-3 text-blue-600 bg-blue-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Tableau de bord</span>
                    </a>
                    <a href="vente.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-cash-register"></i>
                        <span>Point de vente</span>
                    </a>
                    <a href="categories.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
    <i class="fas fa-tags"></i>
    <span>Catégories</span>
</a>
                    <a href="produits.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-box"></i>
                        <span>Produits</span>
                    </a>
                    <a href="stock.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-warehouse"></i>
                        <span>Gestion stock</span>
                    </a>
                    <a href="clients.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-users"></i>
                        <span>Clients</span>
                    </a>
                    <?php if ($user['role'] === 'proprietaire'): ?>
                    <a href="fournisseurs.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-truck"></i>
                        <span>Fournisseurs</span>
                    </a>
                    <a href="achats.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-shopping-cart"></i>
                        <span>Achats</span>
                    </a>
                    <a href="rapports.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports</span>
                    </a>
                    <a href="utilisateurs.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-user-cog"></i>
                        <span>Utilisateurs</span>
                    </a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>

        <!-- Main content -->
        <div class="flex-1 p-8">
            <?php $flash = getFlashMessage(); ?>
            <?php if ($flash): ?>
                <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?>">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Ventes aujourd'hui</p>
                            <p class="text-2xl font-bold text-gray-800"><?= formatMoney($ventes_jour['total_ventes']) ?></p>
                            <p class="text-sm text-gray-500"><?= $ventes_jour['nombre_ventes'] ?> vente(s)</p>
                        </div>
                        <div class="bg-green-100 rounded-full p-3">
                            <i class="fas fa-cash-register text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Valeur du stock</p>
                            <p class="text-2xl font-bold text-gray-800"><?= formatMoney($valeur_stock['valeur_totale']) ?></p>
                        </div>
                        <div class="bg-blue-100 rounded-full p-3">
                            <i class="fas fa-warehouse text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Ruptures de stock</p>
                            <p class="text-2xl font-bold text-red-600"><?= $produits_rupture['nombre_rupture'] ?></p>
                            <p class="text-sm text-gray-500">produit(s)</p>
                        </div>
                        <div class="bg-red-100 rounded-full p-3">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total clients</p>
                            <p class="text-2xl font-bold text-gray-800">
                                <?= $db->fetch("SELECT COUNT(*) as total FROM clients WHERE statut = 'actif'")['total'] ?>
                            </p>
                        </div>
                        <div class="bg-purple-100 rounded-full p-3">
                            <i class="fas fa-users text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Top produits -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Top produits du mois</h3>
                    </div>
                    <div class="p-6">
                        <?php if (empty($top_produits)): ?>
                            <p class="text-gray-500 text-center">Aucune vente ce mois</p>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($top_produits as $index => $produit): ?>
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <div class="bg-blue-100 text-blue-600 rounded-full w-8 h-8 flex items-center justify-center font-bold">
                                                <?= $index + 1 ?>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-800"><?= htmlspecialchars($produit['nom_produit']) ?></p>
                                                <p class="text-sm text-gray-500"><?= $produit['total_vendu'] ?> unités</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-semibold text-gray-800"><?= formatMoney($produit['total_ca']) ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dernières ventes -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Dernières ventes</h3>
                    </div>
                    <div class="p-6">
                        <?php if (empty($dernieres_ventes)): ?>
                            <p class="text-gray-500 text-center">Aucune vente récente</p>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($dernieres_ventes as $vente): ?>
                                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                        <div>
                                            <p class="font-medium text-gray-800">
                                                <?= htmlspecialchars($vente['numero_ticket'] ?? 'N/A') ?>
                                            </p>
                                            <p class="text-sm text-gray-500">
                                                <?= $vente['nom_client'] ? htmlspecialchars($vente['prenom_client'] . ' ' . $vente['nom_client']) : 'Client anonyme' ?>
                                            </p>
                                            <p class="text-xs text-gray-400"><?= formatDate($vente['date_vente']) ?></p>
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
    </script>
</body>
</html>