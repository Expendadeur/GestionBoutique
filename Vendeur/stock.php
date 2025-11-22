<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'adjust_stock':
            $id_produit = intval($_POST['id_produit']);
            $type_mouvement = $_POST['type_mouvement']; // entree, sortie, ajustement
            $quantite = intval($_POST['quantite']);
            $motif = trim($_POST['motif']);
            $prix_unitaire = floatval($_POST['prix_unitaire'] ?? 0);
            
            if ($id_produit <= 0 || $quantite <= 0 || empty($motif)) {
                flashMessage('Veuillez remplir tous les champs obligatoires', 'error');
            } else {
                try {
                    $db->getConnection()->beginTransaction();
                    
                    // Vérifier le stock actuel
                    $stock_actuel = $db->fetch("
                        SELECT quantite_actuelle FROM stocks WHERE id_produit = ?
                    ", [$id_produit]);
                    
                    if (!$stock_actuel) {
                        // Créer l'entrée stock si elle n'existe pas
                        $db->query("INSERT INTO stocks (id_produit, quantite_actuelle) VALUES (?, 0)", [$id_produit]);
                        $stock_actuel = ['quantite_actuelle' => 0];
                    }
                    
                    // Calculer la nouvelle quantité
                    $nouvelle_quantite = $stock_actuel['quantite_actuelle'];
                    if ($type_mouvement === 'entree' || $type_mouvement === 'ajustement') {
                        $nouvelle_quantite += $quantite;
                    } elseif ($type_mouvement === 'sortie') {
                        if ($quantite > $stock_actuel['quantite_actuelle']) {
                            throw new Exception('Stock insuffisant pour cette sortie');
                        }
                        $nouvelle_quantite -= $quantite;
                    }
                    
                    // Mettre à jour le stock
                    $date_field = $type_mouvement === 'entree' ? 'date_derniere_entree' : 'date_derniere_sortie';
                    $db->query("
                        UPDATE stocks 
                        SET quantite_actuelle = ?, $date_field = NOW() 
                        WHERE id_produit = ?
                    ", [$nouvelle_quantite, $id_produit]);
                    
                    // Enregistrer le mouvement
                    $reference = 'MOV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $db->query("
                        INSERT INTO mouvements_stock (
                            id_produit, type_mouvement, quantite, prix_unitaire,
                            reference_operation, motif, id_utilisateur
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ", [
                        $id_produit, $type_mouvement, $quantite, $prix_unitaire,
                        $reference, $motif, $user['id_utilisateur']
                    ]);
                    
                    $db->getConnection()->commit();
                    flashMessage('Mouvement de stock enregistré avec succès', 'success');
                    
                } catch (Exception $e) {
                    $db->getConnection()->rollBack();
                    flashMessage('Erreur: ' . $e->getMessage(), 'error');
                }
            }
            break;
    }
}

// Filtres et pagination
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$stock_filter = $_GET['stock_filter'] ?? '';
$categorie_filter = intval($_GET['categorie'] ?? 0);

// Construction de la requête avec filtres
$where_conditions = ["p.statut = 'actif'"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.nom_produit LIKE ? OR p.code_barre LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($categorie_filter > 0) {
    $where_conditions[] = "p.id_categorie = ?";
    $params[] = $categorie_filter;
}

if ($stock_filter === 'rupture') {
    $where_conditions[] = "(s.quantite_actuelle IS NULL OR s.quantite_actuelle <= 0)";
} elseif ($stock_filter === 'faible') {
    $where_conditions[] = "s.quantite_actuelle <= p.stock_minimum AND s.quantite_actuelle > 0";
} elseif ($stock_filter === 'normal') {
    $where_conditions[] = "s.quantite_actuelle > p.stock_minimum";
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Récupérer les statistiques
$stats = $db->fetch("
    SELECT 
        COUNT(*) as total_produits,
        SUM(CASE WHEN s.quantite_actuelle IS NULL OR s.quantite_actuelle <= 0 THEN 1 ELSE 0 END) as rupture_stock,
        SUM(CASE WHEN s.quantite_actuelle <= p.stock_minimum AND s.quantite_actuelle > 0 THEN 1 ELSE 0 END) as stock_faible,
        SUM(s.quantite_actuelle * p.prix_achat) as valeur_totale
    FROM produits p
    LEFT JOIN stocks s ON p.id_produit = s.id_produit
    WHERE p.statut = 'actif'
");

// Compter le total pour la pagination
$total_query = "
    SELECT COUNT(*) as total 
    FROM produits p 
    LEFT JOIN stocks s ON p.id_produit = s.id_produit
    LEFT JOIN categories c ON p.id_categorie = c.id_categorie
    $where_clause
";
$total = $db->fetch($total_query, $params)['total'];
$total_pages = ceil($total / $limit);

// Récupérer les produits avec stock
$produits = $db->fetchAll("
    SELECT 
        p.*,
        c.nom_categorie,
        f.nom_fournisseur,
        COALESCE(s.quantite_actuelle, 0) as stock_actuel,
        COALESCE(s.quantite_reservee, 0) as stock_reserve,
        COALESCE(s.quantite_disponible, 0) as stock_disponible,
        s.date_derniere_entree,
        s.date_derniere_sortie,
        CASE 
            WHEN COALESCE(s.quantite_actuelle, 0) <= 0 THEN 'rupture'
            WHEN COALESCE(s.quantite_actuelle, 0) <= p.stock_minimum THEN 'faible'
            ELSE 'normal'
        END as stock_status,
        COALESCE(s.quantite_actuelle, 0) * p.prix_achat as valeur_stock
    FROM produits p
    LEFT JOIN stocks s ON p.id_produit = s.id_produit
    LEFT JOIN categories c ON p.id_categorie = c.id_categorie
    LEFT JOIN fournisseurs f ON p.id_fournisseur = f.id_fournisseur
    $where_clause
    ORDER BY 
        CASE 
            WHEN COALESCE(s.quantite_actuelle, 0) <= 0 THEN 1
            WHEN COALESCE(s.quantite_actuelle, 0) <= p.stock_minimum THEN 2
            ELSE 3
        END,
        p.nom_produit
    LIMIT $limit OFFSET $offset
", $params);

// Récupérer les catégories pour les filtres
$categories = $db->fetchAll("SELECT * FROM categories WHERE statut = 'active' ORDER BY nom_categorie");

// Récupérer les derniers mouvements
$derniers_mouvements = $db->fetchAll("
    SELECT 
        ms.*,
        p.nom_produit,
        u.prenom,
        u.nom
    FROM mouvements_stock ms
    JOIN produits p ON ms.id_produit = p.id_produit
    JOIN utilisateurs u ON ms.id_utilisateur = u.id_utilisateur
    ORDER BY ms.date_mouvement DESC
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des stocks - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <a href="dashboard_vendeur.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Retour
                    </a>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-warehouse text-blue-600 mr-2"></i>Gestion des stocks
                    </h1>
                </div>
                <button onclick="openModal('adjustModal')" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                    <i class="fas fa-plus-minus mr-2"></i>Ajuster stock
                </button>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto p-6">
        <?php $flash = getFlashMessage(); ?>
        <?php if ($flash): ?>
            <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Produits gérés</p>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['total_produits'] ?></p>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-boxes text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Ruptures de stock</p>
                        <p class="text-2xl font-bold text-red-600"><?= $stats['rupture_stock'] ?></p>
                    </div>
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Stock faible</p>
                        <p class="text-2xl font-bold text-orange-600"><?= $stats['stock_faible'] ?></p>
                    </div>
                    <div class="bg-orange-100 rounded-full p-3">
                        <i class="fas fa-exclamation-circle text-orange-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Valeur totale</p>
                        <p class="text-2xl font-bold text-green-600"><?= formatMoney($stats['valeur_totale'] ?? 0) ?></p>
                    </div>
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-dollar-sign text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Liste des stocks -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Filtres -->
                <div class="bg-white rounded-lg shadow p-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Nom ou code-barres..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">État du stock</label>
                            <select name="stock_filter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Tous</option>
                                <option value="rupture" <?= $stock_filter === 'rupture' ? 'selected' : '' ?>>Rupture</option>
                                <option value="faible" <?= $stock_filter === 'faible' ? 'selected' : '' ?>>Stock faible</option>
                                <option value="normal" <?= $stock_filter === 'normal' ? 'selected' : '' ?>>Stock normal</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Catégorie</label>
                            <select name="categorie" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Toutes</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id_categorie'] ?>" <?= $categorie_filter == $cat['id_categorie'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['nom_categorie']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end space-x-2">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                                <i class="fas fa-search mr-2"></i>Filtrer
                            </button>
                            <a href="stock.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                                <i class="fas fa-undo mr-2"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Tableau des stocks -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produit</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Valeur</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($produits)): ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                            Aucun produit trouvé
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($produits as $produit): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4">
                                                <div>
                                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($produit['nom_produit']) ?></div>
                                                    <div class="text-sm text-gray-500"><?= htmlspecialchars($produit['nom_categorie']) ?></div>
                                                    <?php if ($produit['code_barre']): ?>
                                                        <div class="text-xs text-gray-400"><?= htmlspecialchars($produit['code_barre']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php
                                                $stockClass = 'text-gray-900';
                                                $stockIcon = 'fas fa-circle';
                                                $statusBg = 'bg-gray-100';
                                                $statusText = 'text-gray-600';
                                                $statusLabel = 'Normal';
                                                
                                                if ($produit['stock_status'] === 'rupture') {
                                                    $stockClass = 'text-red-600';
                                                    $stockIcon = 'fas fa-times-circle';
                                                    $statusBg = 'bg-red-100';
                                                    $statusText = 'text-red-800';
                                                    $statusLabel = 'Rupture';
                                                } elseif ($produit['stock_status'] === 'faible') {
                                                    $stockClass = 'text-orange-600';
                                                    $stockIcon = 'fas fa-exclamation-triangle';
                                                    $statusBg = 'bg-orange-100';
                                                    $statusText = 'text-orange-800';
                                                    $statusLabel = 'Stock faible';
                                                } else {
                                                    $stockClass = 'text-green-600';
                                                    $stockIcon = 'fas fa-check-circle';
                                                    $statusBg = 'bg-green-100';
                                                    $statusText = 'text-green-800';
                                                }
                                                ?>
                                                <div>
                                                    <div class="flex items-center">
                                                        <i class="<?= $stockIcon ?> <?= $stockClass ?> mr-2 text-sm"></i>
                                                        <span class="font-medium <?= $stockClass ?>">
                                                            <?= $produit['stock_actuel'] ?> <?= htmlspecialchars($produit['unite_mesure']) ?>
                                                        </span>
                                                    </div>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        Min: <?= $produit['stock_minimum'] ?>
                                                        <?php if ($produit['stock_reserve'] > 0): ?>
                                                            | Réservé: <?= $produit['stock_reserve'] ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="inline-block mt-1 px-2 py-1 text-xs rounded-full <?= $statusBg ?> <?= $statusText ?>">
                                                        <?= $statusLabel ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?= formatMoney($produit['valeur_stock']) ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    Prix unit: <?= formatMoney($produit['prix_achat']) ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm space-x-2">
                                                <button onclick="adjustStock(<?= htmlspecialchars(json_encode($produit)) ?>)" 
                                                        class="text-blue-600 hover:text-blue-900 bg-blue-50 px-2 py-1 rounded">
                                                    <i class="fas fa-plus-minus mr-1"></i>Ajuster
                                                </button>
                                                <button onclick="viewHistory(<?= $produit['id_produit'] ?>)" 
                                                        class="text-green-600 hover:text-green-900 bg-green-50 px-2 py-1 rounded">
                                                    <i class="fas fa-history mr-1"></i>Historique
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="bg-white px-4 py-3 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-700">
                                    Affichage <?= ($page - 1) * $limit + 1 ?> à <?= min($page * $limit, $total) ?> sur <?= $total ?> résultats
                                </div>
                                <div class="flex space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter(['search' => $search, 'stock_filter' => $stock_filter, 'categorie' => $categorie_filter])) ?>" 
                                           class="px-3 py-1 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                                            Précédent
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <a href="?page=<?= $i ?>&<?= http_build_query(array_filter(['search' => $search, 'stock_filter' => $stock_filter, 'categorie' => $categorie_filter])) ?>" 
                                           class="px-3 py-1 <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-700 hover:bg-gray-400' ?> rounded">
                                            <?= $i ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter(['search' => $search, 'stock_filter' => $stock_filter, 'categorie' => $categorie_filter])) ?>" 
                                           class="px-3 py-1 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                                            Suivant
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar - Derniers mouvements -->
            <div class="space-y-6">
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Derniers mouvements</h3>
                    </div>
                    <div class="p-6">
                        <?php if (empty($derniers_mouvements)): ?>
                            <p class="text-gray-500 text-center">Aucun mouvement récent</p>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($derniers_mouvements as $mouvement): ?>
                                    <div class="border-b border-gray-100 pb-3">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <?php
                                                $iconClass = '';
                                                $textClass = '';
                                                switch ($mouvement['type_mouvement']) {
                                                    case 'entree':
                                                        $iconClass = 'fas fa-arrow-up text-green-500';
                                                        $textClass = 'text-green-600';
                                                        break;
                                                    case 'sortie':
                                                        $iconClass = 'fas fa-arrow-down text-red-500';
                                                        $textClass = 'text-red-600';
                                                        break;
                                                    case 'ajustement':
                                                        $iconClass = 'fas fa-cog text-blue-500';
                                                        $textClass = 'text-blue-600';
                                                        break;
                                                    default:
                                                        $iconClass = 'fas fa-circle text-gray-500';
                                                        $textClass = 'text-gray-600';
                                                }
                                                ?>
                                                <i class="<?= $iconClass ?>"></i>
                                                <span class="text-sm font-medium <?= $textClass ?>">
                                                    <?= ucfirst($mouvement['type_mouvement']) ?>
                                                </span>
                                            </div>
                                            <span class="text-sm font-semibold text-gray-800">
                                                <?= $mouvement['quantite'] ?>
                                            </span>
                                        </div>
                                        <div class="mt-1">
                                            <p class="text-sm text-gray-800"><?= htmlspecialchars($mouvement['nom_produit']) ?></p>
                                            <p class="text-xs text-gray-500"><?= htmlspecialchars($mouvement['motif']) ?></p>
                                            <p class="text-xs text-gray-400">
                                                <?= formatDate($mouvement['date_mouvement']) ?> - 
                                                <?= htmlspecialchars($mouvement['prenom'] . ' ' . $mouvement['nom']) ?>
                                            </p>
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

    <!-- Modal d'ajustement de stock -->
    <div id="adjustModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <form id="adjustForm" method="POST">
                <div class="p-6 border-b border-gray-200">
                    <h3 id="adjustTitle" class="text-lg font-semibold text-gray-800">Ajuster le stock</h3>
                </div>
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="adjust_stock">
                    <input type="hidden" name="id_produit" id="adjustProductId">
                    
                    <div id="productInfo" class="p-3 bg-gray-50 rounded">
                        <p id="productName" class="font-medium"></p>
                        <p id="currentStock" class="text-sm text-gray-600"></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quantité</label>
                        <input type="number" name="quantite" id="adjustQuantity" min="1" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Prix unitaire (optionnel)</label>
                        <input type="number" name="prix_unitaire" id="adjustPrice" step="0.01" min="0"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Motif</label>
                        <textarea name="motif" id="adjustReason" rows="3" required
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                  placeholder="Expliquez la raison de cet ajustement..."></textarea>
                    </div>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('adjustModal')" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal d'historique -->
    <div id="historyModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Historique des mouvements</h3>
            </div>
            <div id="historyContent" class="p-6">
                <!-- Contenu chargé dynamiquement -->
            </div>
            <div class="p-6 border-t border-gray-200 flex justify-end">
                <button onclick="closeModal('historyModal')" 
                        class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                    Fermer
                </button>
            </div>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.getElementById(modalId).classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
        }

        function adjustStock(product) {
            document.getElementById('adjustProductId').value = product.id_produit;
            document.getElementById('productName').textContent = product.nom_produit;
            document.getElementById('currentStock').textContent = `Stock actuel: ${product.stock_actuel} ${product.unite_mesure}`;
            
            // Reset form
            document.getElementById('adjustForm').reset();
            document.getElementById('adjustProductId').value = product.id_produit;
            
            openModal('adjustModal');
        }

        function viewHistory(productId) {
            // Charger l'historique via AJAX
            fetch(`get_stock_history.php?id_produit=${productId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('historyContent').innerHTML = data;
                    openModal('historyModal');
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    document.getElementById('historyContent').innerHTML = '<p class="text-red-500">Erreur lors du chargement de l\'historique.</p>';
                    openModal('historyModal');
                });
        }

        // Fermer les modales en cliquant à l'extérieur
        document.addEventListener('click', function(event) {
            const modals = ['adjustModal', 'historyModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        });
    </script>
</body>
</html>