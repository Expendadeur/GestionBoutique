<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Vérifier que l'utilisateur est propriétaire
if ($user['role'] !== 'proprietaire') {
    header('Location: dashboard.php');
    exit();
}

// Paramètres de filtrage
$date_debut = $_GET['date_debut'] ?? date('Y-m-01'); // Début du mois
$date_fin = $_GET['date_fin'] ?? date('Y-m-d'); // Aujourd'hui
$periode = $_GET['periode'] ?? 'mois'; // jour, semaine, mois, annee

// Ajuster les dates selon la période
switch($periode) {
    case 'jour':
        $date_debut = $date_fin = date('Y-m-d');
        break;
    case 'semaine':
        $date_debut = date('Y-m-d', strtotime('monday this week'));
        $date_fin = date('Y-m-d');
        break;
    case 'mois':
        $date_debut = date('Y-m-01');
        $date_fin = date('Y-m-d');
        break;
    case 'annee':
        $date_debut = date('Y-01-01');
        $date_fin = date('Y-m-d');
        break;
}

// ========== DONNÉES POUR LES RAPPORTS ==========

// 1. Chiffre d'affaires et bénéfices
$ca_benefices = $db->fetch("
    SELECT 
        COUNT(DISTINCT v.id_vente) as nombre_ventes,
        COALESCE(SUM(v.montant_total_ttc), 0) as chiffre_affaires,
        COALESCE(SUM(dv.marge_ligne), 0) as benefice_brut,
        COALESCE(SUM(dv.quantite * dv.prix_achat_unitaire), 0) as cout_marchandises,
        COALESCE(AVG(v.montant_total_ttc), 0) as panier_moyen
    FROM ventes v
    LEFT JOIN details_ventes dv ON v.id_vente = dv.id_vente
    WHERE DATE(v.date_vente) BETWEEN ? AND ? 
    AND v.statut = 'validee'
", [$date_debut, $date_fin]);

// 2. Répartition par mode de paiement
$modes_paiement = $db->fetchAll("
    SELECT 
        mode_paiement,
        COUNT(*) as nombre_transactions,
        SUM(montant_total_ttc) as montant_total
    FROM ventes 
    WHERE DATE(date_vente) BETWEEN ? AND ? 
    AND statut = 'validee'
    GROUP BY mode_paiement
    ORDER BY montant_total DESC
", [$date_debut, $date_fin]);

// 3. Top 10 des produits les plus vendus
$top_produits = $db->fetchAll("
    SELECT 
        p.nom_produit,
        p.prix_vente,
        SUM(dv.quantite) as quantite_vendue,
        SUM(dv.montant_ligne) as ca_produit,
        SUM(dv.marge_ligne) as marge_produit,
        ROUND(SUM(dv.marge_ligne) / SUM(dv.montant_ligne) * 100, 2) as taux_marge
    FROM details_ventes dv
    JOIN ventes v ON dv.id_vente = v.id_vente
    JOIN produits p ON dv.id_produit = p.id_produit
    WHERE DATE(v.date_vente) BETWEEN ? AND ? 
    AND v.statut = 'validee'
    GROUP BY p.id_produit, p.nom_produit, p.prix_vente
    ORDER BY quantite_vendue DESC
    LIMIT 10
", [$date_debut, $date_fin]);

// 4. Évolution des ventes (7 derniers jours) - CORRIGÉ
$evolution_ventes = $db->fetchAll("
    SELECT 
        DATE(date_vente) as jour,
        COUNT(*) as nombre_ventes,
        COALESCE(SUM(montant_total_ttc), 0) as ca_jour
    FROM ventes 
    WHERE DATE(date_vente) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    AND DATE(date_vente) <= CURDATE()
    AND statut = 'validee'
    GROUP BY DATE(date_vente)
    ORDER BY jour ASC
");

// S'assurer qu'on a des données pour tous les 7 derniers jours
$evolution_complete = [];
for ($i = 6; $i >= 0; $i--) {
    $date_courante = date('Y-m-d', strtotime("-$i days"));
    $trouve = false;
    foreach ($evolution_ventes as $donnee) {
        if ($donnee['jour'] == $date_courante) {
            $evolution_complete[] = $donnee;
            $trouve = true;
            break;
        }
    }
    if (!$trouve) {
        $evolution_complete[] = [
            'jour' => $date_courante,
            'nombre_ventes' => 0,
            'ca_jour' => 0
        ];
    }
}

// 5. Analyse par catégorie
$ventes_categories = $db->fetchAll("
    SELECT 
        c.nom_categorie,
        COUNT(DISTINCT v.id_vente) as nombre_ventes,
        SUM(dv.quantite) as quantite_vendue,
        SUM(dv.montant_ligne) as ca_categorie,
        SUM(dv.marge_ligne) as marge_categorie
    FROM details_ventes dv
    JOIN ventes v ON dv.id_vente = v.id_vente
    JOIN produits p ON dv.id_produit = p.id_produit
    JOIN categories c ON p.id_categorie = c.id_categorie
    WHERE DATE(v.date_vente) BETWEEN ? AND ? 
    AND v.statut = 'validee'
    GROUP BY c.id_categorie, c.nom_categorie
    ORDER BY ca_categorie DESC
", [$date_debut, $date_fin]);

// 6. Clients les plus actifs
$top_clients = $db->fetchAll("
    SELECT 
        COALESCE(CONCAT(c.prenom_client, ' ', c.nom_client), 'Client anonyme') as nom_complet,
        COUNT(v.id_vente) as nombre_achats,
        SUM(v.montant_total_ttc) as montant_total,
        AVG(v.montant_total_ttc) as panier_moyen,
        MAX(v.date_vente) as derniere_visite
    FROM ventes v
    LEFT JOIN clients c ON v.id_client = c.id_client
    WHERE DATE(v.date_vente) BETWEEN ? AND ? 
    AND v.statut = 'validee'
    GROUP BY v.id_client
    ORDER BY montant_total DESC
    LIMIT 10
", [$date_debut, $date_fin]);

// Calculer les pourcentages et ratios
$marge_pourcentage = $ca_benefices['chiffre_affaires'] > 0 ? 
    round(($ca_benefices['benefice_brut'] / $ca_benefices['chiffre_affaires']) * 100, 2) : 0;

// Préparer les données pour les graphiques
$labels_evolution = array_map(function($item) {
    return date('d/m', strtotime($item['jour']));
}, $evolution_complete);

$data_ca_evolution = array_map(function($item) {
    return floatval($item['ca_jour']);
}, $evolution_complete);

$data_nombre_evolution = array_map(function($item) {
    return intval($item['nombre_ventes']);
}, $evolution_complete);

// Préparer les données des modes de paiement
$labels_paiement = [];
$data_paiement = [];
$couleurs_paiement = ['#10B981', '#3B82F6', '#8B5CF6', '#F59E0B', '#EF4444', '#6366F1', '#EC4899'];

foreach ($modes_paiement as $index => $mode) {
    $labels_paiement[] = ucfirst($mode['mode_paiement']);
    $data_paiement[] = floatval($mode['montant_total']);
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-break { page-break-before: always; }
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg no-print">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-chart-bar text-blue-600 mr-2"></i>Rapports
                    </h1>
                </div>
                <div class="flex items-center space-x-4">
                    <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-print mr-2"></i>Imprimer
                    </button>
                    <a href="dashboard.php" class="text-gray-600 hover:text-gray-800">
                        <i class="fas fa-home"></i> Accueil
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto p-6">
        <!-- Filtres de période -->
        <div class="bg-white rounded-lg shadow p-6 mb-6 no-print">
            <form method="GET" class="flex flex-wrap items-end space-x-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Période</label>
                    <select name="periode" class="border rounded-lg px-3 py-2" onchange="this.form.submit()">
                        <option value="jour" <?= $periode === 'jour' ? 'selected' : '' ?>>Aujourd'hui</option>
                        <option value="semaine" <?= $periode === 'semaine' ? 'selected' : '' ?>>Cette semaine</option>
                        <option value="mois" <?= $periode === 'mois' ? 'selected' : '' ?>>Ce mois</option>
                        <option value="annee" <?= $periode === 'annee' ? 'selected' : '' ?>>Cette année</option>
                        <option value="personnalise" <?= !in_array($periode, ['jour', 'semaine', 'mois', 'annee']) ? 'selected' : '' ?>>Personnalisé</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Du</label>
                    <input type="date" name="date_debut" value="<?= $date_debut ?>" class="border rounded-lg px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Au</label>
                    <input type="date" name="date_fin" value="<?= $date_fin ?>" class="border rounded-lg px-3 py-2">
                </div>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">
                    <i class="fas fa-filter mr-2"></i>Filtrer
                </button>
            </form>
        </div>

        <!-- En-tête du rapport -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="text-center">
                <h2 class="text-2xl font-bold text-gray-800 mb-2"><?= APP_NAME ?></h2>
                <h3 class="text-xl text-gray-600 mb-4">Rapport d'activité</h3>
                <p class="text-gray-500">
                    Période: <?= formatDate($date_debut) ?> au <?= formatDate($date_fin) ?>
                    <span class="ml-4">Généré le: <?= formatDate(date('Y-m-d H:i:s')) ?></span>
                </p>
            </div>
        </div>

        <!-- Résumé financier -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Chiffre d'affaires</p>
                        <p class="text-2xl font-bold text-green-600"><?= formatMoney($ca_benefices['chiffre_affaires']) ?></p>
                        <p class="text-sm text-gray-500"><?= $ca_benefices['nombre_ventes'] ?> vente(s)</p>
                    </div>
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-euro-sign text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Bénéfice brut</p>
                        <p class="text-2xl font-bold text-blue-600"><?= formatMoney($ca_benefices['benefice_brut']) ?></p>
                        <p class="text-sm text-gray-500"><?= $marge_pourcentage ?>% de marge</p>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-chart-line text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Coût marchandises</p>
                        <p class="text-2xl font-bold text-red-600"><?= formatMoney($ca_benefices['cout_marchandises']) ?></p>
                    </div>
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-shopping-cart text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Panier moyen</p>
                        <p class="text-2xl font-bold text-purple-600"><?= formatMoney($ca_benefices['panier_moyen']) ?></p>
                    </div>
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-basket-shopping text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Graphiques -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Évolution des ventes -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Évolution des ventes (7 derniers jours)</h3>
                <div class="chart-container">
                    <canvas id="evolutionVentes"></canvas>
                </div>
            </div>

            <!-- Modes de paiement -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Répartition par mode de paiement</h3>
                <div class="chart-container">
                    <canvas id="modesPaiement"></canvas>
                </div>
            </div>
        </div>

        <!-- Top produits -->
        <div class="bg-white rounded-lg shadow mb-6 print-break">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Top 10 des produits les plus vendus</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qté vendue</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix unitaire</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">CA</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Marge</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taux marge</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($top_produits)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">Aucun produit vendu sur cette période</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($top_produits as $produit): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?= htmlspecialchars($produit['nom_produit']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $produit['quantite_vendue'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= formatMoney($produit['prix_vente']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                <?= formatMoney($produit['ca_produit']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-blue-600">
                                <?= formatMoney($produit['marge_produit']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $produit['taux_marge'] ?>%
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ventes par catégorie -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Analyse par catégorie</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Catégorie</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nb ventes</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qté vendue</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">CA</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Marge</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($ventes_categories)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-gray-500">Aucune vente par catégorie sur cette période</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($ventes_categories as $categorie): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?= htmlspecialchars($categorie['nom_categorie']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $categorie['nombre_ventes'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $categorie['quantite_vendue'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                <?= formatMoney($categorie['ca_categorie']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-blue-600">
                                <?= formatMoney($categorie['marge_categorie']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top clients -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Top 10 clients</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nb achats</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant total</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Panier moyen</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dernière visite</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($top_clients)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-gray-500">Aucun client sur cette période</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($top_clients as $client): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?= htmlspecialchars($client['nom_complet']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $client['nombre_achats'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                <?= formatMoney($client['montant_total']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= formatMoney($client['panier_moyen']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= formatDate($client['derniere_visite']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Données préparées depuis PHP
        const labelsEvolution = <?= json_encode($labels_evolution) ?>;
        const dataCAEvolution = <?= json_encode($data_ca_evolution) ?>;
        const dataNombreEvolution = <?= json_encode($data_nombre_evolution) ?>;
        
        const labelsPaiement = <?= json_encode($labels_paiement) ?>;
        const dataPaiement = <?= json_encode($data_paiement) ?>;
        const couleursPaiement = <?= json_encode(array_slice($couleurs_paiement, 0, count($labels_paiement))) ?>;

        // Configuration commune des graphiques
        Chart.defaults.font.family = 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto';
        Chart.defaults.font.size = 12;

        // Graphique évolution des ventes
        const ctx1 = document.getElementById('evolutionVentes').getContext('2d');
        const evolutionChart = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: labelsEvolution,
                datasets: [{
                    label: 'CA journalier (BIF)',
                    data: dataCAEvolution,
                    borderColor: '#3B82F6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3B82F6',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }, {
                    label: 'Nombre de ventes',
                    data: dataNombreEvolution,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    fill: false,
                    tension: 0.4,
                    pointBackgroundColor: '#10B981',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: '#3B82F6',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                if (context.datasetIndex === 0) {
                                    return 'CA: ' + new Intl.NumberFormat('fr-FR').format(context.parsed.y) + 'BIF';
                                } else {
                                    return 'Ventes: ' + context.parsed.y;
                                }
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Chiffre d\'affaires (BIF)'
                        },
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('fr-FR').format(value) + 'BIF';
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                        title: {
                            display: true,
                            text: 'Nombre de ventes'
                        },
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Graphique modes de paiement
        const ctx2 = document.getElementById('modesPaiement').getContext('2d');
        const paiementChart = new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: labelsPaiement,
                datasets: [{
                    data: dataPaiement,
                    backgroundColor: couleursPaiement,
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverBorderWidth: 4,
                    hoverBorderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '50%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            generateLabels: function(chart) {
                                const data = chart.data;
                                if (data.labels.length && data.datasets.length) {
                                    const total = data.datasets[0].data.reduce((a, b) => a + b, 0);
                                    return data.labels.map((label, i) => {
                                        const value = data.datasets[0].data[i];
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return {
                                            text: `${label}: ${percentage}%`,
                                            fillStyle: data.datasets[0].backgroundColor[i],
                                            strokeStyle: data.datasets[0].backgroundColor[i],
                                            lineWidth: 0,
                                            pointStyle: 'circle'
                                        };
                                    });
                                }
                                return [];
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: '#3B82F6',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${context.label}: ${new Intl.NumberFormat('fr-FR').format(value)} FCFA (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Gestion de l'affichage conditionnel des graphiques
        if (dataCAEvolution.every(val => val === 0) && dataNombreEvolution.every(val => val === 0)) {
            document.getElementById('evolutionVentes').style.display = 'none';
            const evolutionContainer = document.getElementById('evolutionVentes').closest('.bg-white');
            evolutionContainer.innerHTML = '<div class="p-6"><h3 class="text-lg font-semibold text-gray-800 mb-4">Évolution des ventes (7 derniers jours)</h3><p class="text-gray-500 text-center py-8">Aucune donnée disponible pour cette période</p></div>';
        }

        if (dataPaiement.length === 0 || dataPaiement.every(val => val === 0)) {
            document.getElementById('modesPaiement').style.display = 'none';
            const paiementContainer = document.getElementById('modesPaiement').closest('.bg-white');
            paiementContainer.innerHTML = '<div class="p-6"><h3 class="text-lg font-semibold text-gray-800 mb-4">Répartition par mode de paiement</h3><p class="text-gray-500 text-center py-8">Aucune donnée disponible pour cette période</p></div>';
        }

        // Fonction pour redimensionner les graphiques lors du redimensionnement de la fenêtre
        window.addEventListener('resize', function() {
            evolutionChart.resize();
            paiementChart.resize();
        });

        // Masquer les graphiques lors de l'impression si pas de données
        window.addEventListener('beforeprint', function() {
            if (dataCAEvolution.every(val => val === 0) && dataNombreEvolution.every(val => val === 0)) {
                const evolutionContainer = document.getElementById('evolutionVentes').closest('.bg-white');
                evolutionContainer.style.display = 'none';
            }
            if (dataPaiement.length === 0 || dataPaiement.every(val => val === 0)) {
                const paiementContainer = document.getElementById('modesPaiement').closest('.bg-white');
                paiementContainer.style.display = 'none';
            }
        });

        window.addEventListener('afterprint', function() {
            const evolutionContainer = document.getElementById('evolutionVentes').closest('.bg-white');
            const paiementContainer = document.getElementById('modesPaiement').closest('.bg-white');
            evolutionContainer.style.display = 'block';
            paiementContainer.style.display = 'block';
        });
    </script>
</body>
</html>