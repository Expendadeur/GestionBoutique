<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $nom = trim($_POST['nom_produit']);
            $description = trim($_POST['description']);
            $code_barre = trim($_POST['code_barre']);
            $prix_achat = floatval($_POST['prix_achat']);
            $prix_vente = floatval($_POST['prix_vente']);
            $stock_minimum = intval($_POST['stock_minimum']);
            $unite_mesure = trim($_POST['unite_mesure']);
            $id_categorie = intval($_POST['id_categorie']);
            $id_fournisseur = !empty($_POST['id_fournisseur']) ? intval($_POST['id_fournisseur']) : null;
            
            if (empty($nom) || $prix_vente <= 0 || $id_categorie <= 0) {
                flashMessage('Veuillez remplir tous les champs obligatoires', 'error');
            } else {
                try {
                    // Calculer la marge
                    $marge = $prix_achat > 0 ? (($prix_vente - $prix_achat) / $prix_achat) * 100 : 0;
                    
                    $productId = $db->query("
                        INSERT INTO produits (
                            nom_produit, description, code_barre, prix_achat, 
                            prix_vente, marge_beneficiaire, stock_minimum, 
                            unite_mesure, id_categorie, id_fournisseur
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", [
                        $nom, $description, $code_barre, $prix_achat, 
                        $prix_vente, $marge, $stock_minimum, 
                        $unite_mesure, $id_categorie, $id_fournisseur
                    ]);
                    
                    $productId = $db->lastInsertId();
                    
                    // Créer l'entrée stock
                    $db->query("
                        INSERT INTO stocks (id_produit, quantite_actuelle) 
                        VALUES (?, 0)
                    ", [$productId]);
                    
                    flashMessage('Produit ajouté avec succès', 'success');
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'code_barre') !== false) {
                        flashMessage('Ce code-barres existe déjà', 'error');
                    } else {
                        flashMessage('Erreur lors de l\'ajout du produit', 'error');
                    }
                }
            }
            break;
            
        case 'update':
            $id_produit = intval($_POST['id_produit']);
            $nom = trim($_POST['nom_produit']);
            $description = trim($_POST['description']);
            $code_barre = trim($_POST['code_barre']);
            $prix_achat = floatval($_POST['prix_achat']);
            $prix_vente = floatval($_POST['prix_vente']);
            $stock_minimum = intval($_POST['stock_minimum']);
            $unite_mesure = trim($_POST['unite_mesure']);
            $id_categorie = intval($_POST['id_categorie']);
            $id_fournisseur = !empty($_POST['id_fournisseur']) ? intval($_POST['id_fournisseur']) : null;
            $statut = $_POST['statut'];
            
            if (empty($nom) || $prix_vente <= 0 || $id_categorie <= 0) {
                flashMessage('Veuillez remplir tous les champs obligatoires', 'error');
            } else {
                try {
                    $marge = $prix_achat > 0 ? (($prix_vente - $prix_achat) / $prix_achat) * 100 : 0;
                    
                    $db->query("
                        UPDATE produits SET 
                            nom_produit = ?, description = ?, code_barre = ?, 
                            prix_achat = ?, prix_vente = ?, marge_beneficiaire = ?, 
                            stock_minimum = ?, unite_mesure = ?, id_categorie = ?, 
                            id_fournisseur = ?, statut = ?
                        WHERE id_produit = ?
                    ", [
                        $nom, $description, $code_barre, $prix_achat, 
                        $prix_vente, $marge, $stock_minimum, $unite_mesure, 
                        $id_categorie, $id_fournisseur, $statut, $id_produit
                    ]);
                    
                    flashMessage('Produit modifié avec succès', 'success');
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'code_barre') !== false) {
                        flashMessage('Ce code-barres existe déjà', 'error');
                    } else {
                        flashMessage('Erreur lors de la modification du produit', 'error');
                    }
                }
            }
            break;
            
        case 'delete':
            $id_produit = intval($_POST['id_produit']);
            try {
                $db->query("UPDATE produits SET statut = 'inactif' WHERE id_produit = ?", [$id_produit]);
                flashMessage('Produit désactivé avec succès', 'success');
            } catch (PDOException $e) {
                flashMessage('Erreur lors de la suppression du produit', 'error');
            }
            break;
    }
}

// Pagination et filtres
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$categorie_filter = intval($_GET['categorie'] ?? 0);
$statut_filter = $_GET['statut'] ?? '';

// Construction de la requête avec filtres
$where_conditions = [];
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

if (!empty($statut_filter)) {
    $where_conditions[] = "p.statut = ?";
    $params[] = $statut_filter;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Compter le total pour la pagination
$total_query = "
    SELECT COUNT(*) as total 
    FROM produits p 
    $where_clause
";
$total = $db->fetch($total_query, $params)['total'];
$total_pages = ceil($total / $limit);

// Récupérer les produits
$produits = $db->fetchAll("
    SELECT 
        p.*,
        c.nom_categorie,
        f.nom_fournisseur,
        COALESCE(s.quantite_actuelle, 0) as stock_actuel,
        COALESCE(s.quantite_disponible, 0) as stock_disponible,
        CASE 
            WHEN COALESCE(s.quantite_actuelle, 0) <= p.stock_minimum THEN 'danger'
            WHEN COALESCE(s.quantite_actuelle, 0) <= p.stock_minimum * 1.5 THEN 'warning'
            ELSE 'ok'
        END as stock_status
    FROM produits p
    LEFT JOIN categories c ON p.id_categorie = c.id_categorie
    LEFT JOIN fournisseurs f ON p.id_fournisseur = f.id_fournisseur
    LEFT JOIN stocks s ON p.id_produit = s.id_produit
    $where_clause
    ORDER BY p.nom_produit
    LIMIT $limit OFFSET $offset
", $params);

// Récupérer les catégories et fournisseurs pour les formulaires
$categories = $db->fetchAll("SELECT * FROM categories WHERE statut = 'active' ORDER BY nom_categorie");
$fournisseurs = $db->fetchAll("SELECT * FROM fournisseurs WHERE statut = 'actif' ORDER BY nom_fournisseur");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des produits - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Retour
                    </a>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-box text-blue-600 mr-2"></i>Gestion des produits
                    </h1>
                </div>
                <!-- Remplacez cette ligne dans votre code (vers la ligne 185) -->
<button onclick="openModal('productModal')" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
    <i class="fas fa-plus mr-2"></i>Nouveau produit
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

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Nom ou code-barres..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
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
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                    <select name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Tous</option>
                        <option value="actif" <?= $statut_filter === 'actif' ? 'selected' : '' ?>>Actif</option>
                        <option value="inactif" <?= $statut_filter === 'inactif' ? 'selected' : '' ?>>Inactif</option>
                    </select>
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <a href="produits.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                        <i class="fas fa-undo mr-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Liste des produits -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produit</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Catégorie</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prix</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($produits)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                    Aucun produit trouvé
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($produits as $produit): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div>
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($produit['nom_produit']) ?></div>
                                            <?php if ($produit['code_barre']): ?>
                                                <div class="text-sm text-gray-500"><?= htmlspecialchars($produit['code_barre']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($produit['nom_fournisseur']): ?>
                                                <div class="text-xs text-gray-400">
                                                    <i class="fas fa-truck mr-1"></i><?= htmlspecialchars($produit['nom_fournisseur']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?= htmlspecialchars($produit['nom_categorie'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                Vente: <?= formatMoney($produit['prix_vente']) ?>
                                            </div>
                                            <?php if ($produit['prix_achat'] > 0): ?>
                                                <div class="text-sm text-gray-500">
                                                    Achat: <?= formatMoney($produit['prix_achat']) ?>
                                                </div>
                                                <div class="text-xs text-green-600">
                                                    Marge: <?= number_format($produit['marge_beneficiaire'], 1) ?>%
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <?php
                                            $stockClass = 'text-gray-900';
                                            $stockIcon = 'fas fa-circle';
                                            
                                            if ($produit['stock_status'] === 'danger') {
                                                $stockClass = 'text-red-600';
                                                $stockIcon = 'fas fa-exclamation-circle';
                                            } elseif ($produit['stock_status'] === 'warning') {
                                                $stockClass = 'text-orange-600';
                                                $stockIcon = 'fas fa-exclamation-triangle';
                                            } else {
                                                $stockClass = 'text-green-600';
                                                $stockIcon = 'fas fa-check-circle';
                                            }
                                            ?>
                                            <i class="<?= $stockIcon ?> <?= $stockClass ?> mr-2 text-sm"></i>
                                            <div>
                                                <div class="text-sm font-medium <?= $stockClass ?>">
                                                    <?= $produit['stock_actuel'] ?> <?= htmlspecialchars($produit['unite_mesure']) ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    Min: <?= $produit['stock_minimum'] ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            <?= $produit['statut'] === 'actif' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= ucfirst($produit['statut']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                        <button onclick="editProduct(<?= htmlspecialchars(json_encode($produit)) ?>)" 
                                                class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($user['role'] === 'proprietaire'): ?>
                                            <button onclick="deleteProduct(<?= $produit['id_produit'] ?>, '<?= htmlspecialchars($produit['nom_produit']) ?>')" 
                                                    class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
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
                                <a href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter(['search' => $search, 'categorie' => $categorie_filter, 'statut' => $statut_filter])) ?>" 
                                   class="px-3 py-1 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                                    Précédent
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>&<?= http_build_query(array_filter(['search' => $search, 'categorie' => $categorie_filter, 'statut' => $statut_filter])) ?>" 
                                   class="px-3 py-1 <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-700 hover:bg-gray-400' ?> rounded">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter(['search' => $search, 'categorie' => $categorie_filter, 'statut' => $statut_filter])) ?>" 
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

    <!-- Modal Ajouter/Modifier produit -->
    <div id="productModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
            <form id="productForm" method="POST">
                <div class="p-6 border-b border-gray-200">
                    <h3 id="modalTitle" class="text-lg font-semibold text-gray-800">Nouveau produit</h3>
                </div>
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id_produit" id="produitId">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Nom du produit *</label>
                            <input type="text" name="nom_produit" id="nomProduit" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Code-barres</label>
                            <input type="text" name="code_barre" id="codeBarre"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Prix d'achat</label>
                            <input type="number" name="prix_achat" id="prixAchat" step="0.01" min="0"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Prix de vente *</label>
                            <input type="number" name="prix_vente" id="prixVente" step="0.01" min="0" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Marge calculée</label>
                            <input type="text" id="margeCalculee" readonly
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Stock minimum</label>
                            <input type="number" name="stock_minimum" id="stockMinimum" min="0" value="0"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Unité de mesure</label>
                            <select name="unite_mesure" id="uniteMesure"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="pièce">Pièce</option>
                                <option value="kg">Kilogramme</option>
                                <option value="g">Gramme</option>
                                <option value="l">Litre</option>
                                <option value="ml">Millilitre</option>
                                <option value="m">Mètre</option>
                                <option value="cm">Centimètre</option>
                                <option value="paquet">Paquet</option>
                                <option value="carton">Carton</option>
                            </select>
                        </div>
                        <div id="statutDiv" class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                            <select name="statut" id="statut"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="actif">Actif</option>
                                <option value="inactif">Inactif</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Catégorie *</label>
                            <select name="id_categorie" id="idCategorie" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Sélectionner une catégorie</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id_categorie'] ?>"><?= htmlspecialchars($cat['nom_categorie']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fournisseur</label>
                            <select name="id_fournisseur" id="idFournisseur"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Aucun fournisseur</option>
                                <?php foreach ($fournisseurs as $fournisseur): ?>
                                    <option value="<?= $fournisseur['id_fournisseur'] ?>"><?= htmlspecialchars($fournisseur['nom_fournisseur']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end space-x-4">
                    <button type="button" onclick="closeModal('productModal')" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de confirmation de suppression -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md mx-4">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Confirmer la suppression</h3>
                <p class="text-gray-600 mb-6">Êtes-vous sûr de vouloir désactiver le produit "<span id="productName"></span>" ?</p>
                <form id="deleteForm" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_produit" id="deleteProductId">
                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="closeModal('deleteModal')" 
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            Désactiver
                        </button>
                    </div>
                </form>
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
            if (modalId === 'productModal') {
                resetForm();
            }
        }
        
        function resetForm() {
            document.getElementById('productForm').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('modalTitle').textContent = 'Nouveau produit';
            document.getElementById('statutDiv').classList.add('hidden');
            document.getElementById('margeCalculee').value = '';
            document.getElementById('produitId').value = '';
        }
        
        function editProduct(product) {
            document.getElementById('formAction').value = 'update';
            document.getElementById('modalTitle').textContent = 'Modifier le produit';
            document.getElementById('produitId').value = product.id_produit;
            document.getElementById('nomProduit').value = product.nom_produit;
            document.getElementById('description').value = product.description || '';
            document.getElementById('codeBarre').value = product.code_barre || '';
            document.getElementById('prixAchat').value = product.prix_achat;
            document.getElementById('prixVente').value = product.prix_vente;
            document.getElementById('stockMinimum').value = product.stock_minimum;
            document.getElementById('uniteMesure').value = product.unite_mesure;
            document.getElementById('idCategorie').value = product.id_categorie;
            document.getElementById('idFournisseur').value = product.id_fournisseur || '';
            document.getElementById('statut').value = product.statut;
            document.getElementById('statutDiv').classList.remove('hidden');
            
            calculateMargin();
            openModal('productModal');
        }
        
        function deleteProduct(id, name) {
            document.getElementById('deleteProductId').value = id;
            document.getElementById('productName').textContent = name;
            openModal('deleteModal');
        }
        
        function calculateMargin() {
            const prixAchat = parseFloat(document.getElementById('prixAchat').value) || 0;
            const prixVente = parseFloat(document.getElementById('prixVente').value) || 0;
            
            if (prixAchat > 0 && prixVente > 0) {
                const marge = ((prixVente - prixAchat) / prixAchat) * 100;
                document.getElementById('margeCalculee').value = marge.toFixed(2) + '%';
            } else {
                document.getElementById('margeCalculee').value = '';
            }
        }
        
        // Calculer la marge automatiquement
        document.getElementById('prixAchat').addEventListener('input', calculateMargin);
        document.getElementById('prixVente').addEventListener('input', calculateMargin);
        
        // Fermer les modals en cliquant à l'extérieur
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('fixed')) {
                closeModal(event.target.id);
            }
        });
        
        // Raccourcis clavier
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.fixed.flex');
                modals.forEach(modal => {
                    closeModal(modal.id);
                });
            }
        });
    </script>
</body>
</html>