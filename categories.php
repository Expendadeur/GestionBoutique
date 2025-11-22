<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Vérifier les permissions (seul le propriétaire peut gérer les catégories)
if ($user['role'] !== 'proprietaire') {
    header('Location: dashboard.php');
    exit;
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_category':
            $nom_categorie = trim($_POST['nom_categorie']);
            $description = trim($_POST['description']);
            
            if (empty($nom_categorie)) {
                flashMessage('Le nom de la catégorie est obligatoire', 'error');
            } else {
                try {
                    // Vérifier si la catégorie existe déjà
                    $existing = $db->fetch("SELECT id_categorie FROM categories WHERE nom_categorie = ?", [$nom_categorie]);
                    if ($existing) {
                        flashMessage('Une catégorie avec ce nom existe déjà', 'error');
                    } else {
                        $db->query("
                            INSERT INTO categories (nom_categorie, description, statut)
                            VALUES (?, ?, 'active')
                        ", [$nom_categorie, $description]);
                        
                        flashMessage('Catégorie ajoutée avec succès', 'success');
                    }
                } catch (Exception $e) {
                    flashMessage('Erreur lors de l\'ajout: ' . $e->getMessage(), 'error');
                }
            }
            break;
            
        case 'edit_category':
            $id_categorie = intval($_POST['id_categorie']);
            $nom_categorie = trim($_POST['nom_categorie']);
            $description = trim($_POST['description']);
            $statut = $_POST['statut'];
            
            if ($id_categorie <= 0 || empty($nom_categorie)) {
                flashMessage('Données invalides', 'error');
            } else {
                try {
                    // Vérifier si une autre catégorie a le même nom
                    $existing = $db->fetch("SELECT id_categorie FROM categories WHERE nom_categorie = ? AND id_categorie != ?", [$nom_categorie, $id_categorie]);
                    if ($existing) {
                        flashMessage('Une autre catégorie avec ce nom existe déjà', 'error');
                    } else {
                        $db->query("
                            UPDATE categories 
                            SET nom_categorie = ?, description = ?, statut = ?
                            WHERE id_categorie = ?
                        ", [$nom_categorie, $description, $statut, $id_categorie]);
                        
                        flashMessage('Catégorie modifiée avec succès', 'success');
                    }
                } catch (Exception $e) {
                    flashMessage('Erreur lors de la modification: ' . $e->getMessage(), 'error');
                }
            }
            break;
            
        case 'delete_category':
            $id_categorie = intval($_POST['id_categorie']);
            
            if ($id_categorie <= 0) {
                flashMessage('Catégorie invalide', 'error');
            } else {
                try {
                    // Vérifier s'il y a des produits dans cette catégorie
                    $produits_count = $db->fetch("SELECT COUNT(*) as count FROM produits WHERE id_categorie = ? AND statut = 'actif'", [$id_categorie])['count'];
                    
                    if ($produits_count > 0) {
                        flashMessage("Impossible de supprimer cette catégorie car elle contient $produits_count produit(s) actif(s)", 'error');
                    } else {
                        // Marquer comme inactive au lieu de supprimer
                        $db->query("UPDATE categories SET statut = 'inactive' WHERE id_categorie = ?", [$id_categorie]);
                        flashMessage('Catégorie désactivée avec succès', 'success');
                    }
                } catch (Exception $e) {
                    flashMessage('Erreur lors de la suppression: ' . $e->getMessage(), 'error');
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
$statut_filter = $_GET['statut_filter'] ?? '';

// Construction de la requête avec filtres - CORRIGÉ: spécifier les alias pour les colonnes
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(c.nom_categorie LIKE ? OR c.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($statut_filter)) {
    $where_conditions[] = "c.statut = ?";
    $params[] = $statut_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Statistiques
$stats = $db->fetch("
    SELECT 
        COUNT(*) as total_categories,
        SUM(CASE WHEN statut = 'active' THEN 1 ELSE 0 END) as categories_actives,
        SUM(CASE WHEN statut = 'inactive' THEN 1 ELSE 0 END) as categories_inactives
    FROM categories
    WHERE 1=1
");

// Compter le total pour la pagination
$total = $db->fetch("
    SELECT COUNT(*) as total 
    FROM categories c
    LEFT JOIN produits p ON c.id_categorie = p.id_categorie
    $where_clause
    GROUP BY c.id_categorie
", $params);

// Ajustement pour compter correctement le nombre total de catégories
$total_count = $db->fetch("
    SELECT COUNT(DISTINCT c.id_categorie) as total 
    FROM categories c
    LEFT JOIN produits p ON c.id_categorie = p.id_categorie
    $where_clause
", $params)['total'];

$total_pages = ceil($total_count / $limit);

// Récupérer les catégories avec le nombre de produits - CORRIGÉ: utiliser les alias pour éviter l'ambiguïté
$categories = $db->fetchAll("
    SELECT 
        c.id_categorie,
        c.nom_categorie,
        c.description,
        c.statut,
        c.date_creation,
        COUNT(p.id_produit) as nombre_produits,
        SUM(CASE WHEN p.statut = 'actif' THEN 1 ELSE 0 END) as produits_actifs
    FROM categories c
    LEFT JOIN produits p ON c.id_categorie = p.id_categorie
    $where_clause
    GROUP BY c.id_categorie, c.nom_categorie, c.description, c.statut, c.date_creation
    ORDER BY c.nom_categorie
    LIMIT $limit OFFSET $offset
", $params);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des catégories - <?= APP_NAME ?></title>
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
                        <i class="fas fa-tags text-blue-600 mr-2"></i>Gestion des catégories
                    </h1>
                </div>
                <button onclick="openModal('addCategoryModal')" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                    <i class="fas fa-plus mr-2"></i>Ajouter une catégorie
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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total catégories</p>
                        <p class="text-2xl font-bold text-gray-800"><?= $stats['total_categories'] ?></p>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-tags text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Catégories actives</p>
                        <p class="text-2xl font-bold text-green-600"><?= $stats['categories_actives'] ?></p>
                    </div>
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Catégories inactives</p>
                        <p class="text-2xl font-bold text-red-600"><?= $stats['categories_inactives'] ?></p>
                    </div>
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-times-circle text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Nom de catégorie..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                    <select name="statut_filter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Tous</option>
                        <option value="active" <?= $statut_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statut_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <a href="categories.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                        <i class="fas fa-undo mr-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Liste des catégories -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Catégorie</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produits</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date création</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                    Aucune catégorie trouvée
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $categorie): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="bg-blue-100 rounded-full p-2 mr-3">
                                                <i class="fas fa-tag text-blue-600"></i>
                                            </div>
                                            <div class="font-medium text-gray-900">
                                                <?= htmlspecialchars($categorie['nom_categorie']) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-600">
                                            <?= $categorie['description'] ? htmlspecialchars($categorie['description']) : '-' ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm">
                                            <div class="font-medium text-gray-900">
                                                <?= intval($categorie['produits_actifs']) ?> actifs
                                            </div>
                                            <?php if ($categorie['nombre_produits'] > $categorie['produits_actifs']): ?>
                                                <div class="text-gray-500">
                                                    <?= $categorie['nombre_produits'] - $categorie['produits_actifs'] ?> inactifs
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-block px-2 py-1 text-xs rounded-full
                                            <?= $categorie['statut'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= $categorie['statut'] === 'active' ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?= formatDate($categorie['date_creation']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm space-x-1">
                                        <button onclick="editCategory(<?= htmlspecialchars(json_encode($categorie)) ?>)" 
                                                class="text-blue-600 hover:text-blue-900 bg-blue-50 px-2 py-1 rounded">
                                            <i class="fas fa-edit mr-1"></i>Modifier
                                        </button>
                                        <?php if (intval($categorie['produits_actifs']) == 0): ?>
                                            <button onclick="deleteCategory(<?= $categorie['id_categorie'] ?>, '<?= htmlspecialchars($categorie['nom_categorie']) ?>')" 
                                                    class="text-red-600 hover:text-red-900 bg-red-50 px-2 py-1 rounded">
                                                <i class="fas fa-trash mr-1"></i>Supprimer
                                            </button>
                                        <?php endif; ?>
                                        <a href="produits.php?categorie=<?= $categorie['id_categorie'] ?>" 
                                           class="text-green-600 hover:text-green-900 bg-green-50 px-2 py-1 rounded">
                                            <i class="fas fa-box mr-1"></i>Voir produits
                                        </a>
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
                            Affichage <?= ($page - 1) * $limit + 1 ?> à <?= min($page * $limit, $total_count) ?> sur <?= $total_count ?> résultats
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter(['search' => $search, 'statut_filter' => $statut_filter])) ?>" 
                                   class="px-3 py-1 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">Précédent</a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>&<?= http_build_query(array_filter(['search' => $search, 'statut_filter' => $statut_filter])) ?>" 
                                   class="px-3 py-1 <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-700 hover:bg-gray-400' ?> rounded">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter(['search' => $search, 'statut_filter' => $statut_filter])) ?>" 
                                   class="px-3 py-1 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">Suivant</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal d'ajout de catégorie -->
    <div id="addCategoryModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <form method="POST">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Ajouter une catégorie</h3>
                </div>
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="add_category">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nom de la catégorie *</label>
                        <input type="text" name="nom_categorie" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                               placeholder="Ex: Alimentaire, Boissons, Hygiène...">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description (optionnelle)</label>
                        <textarea name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                  placeholder="Description de la catégorie..."></textarea>
                    </div>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('addCategoryModal')" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Ajouter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de modification de catégorie -->
    <div id="editCategoryModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <form id="editCategoryForm" method="POST">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Modifier la catégorie</h3>
                </div>
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="edit_category">
                    <input type="hidden" name="id_categorie" id="editCategoryId">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nom de la catégorie *</label>
                        <input type="text" name="nom_categorie" id="editCategoryName" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="editCategoryDesc" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                        <select name="statut" id="editCategoryStatus" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('editCategoryModal')" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Modifier
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de confirmation de suppression -->
    <div id="deleteCategoryModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <form id="deleteCategoryForm" method="POST">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-red-600">Confirmer la suppression</h3>
                </div>
                <div class="p-6">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="id_categorie" id="deleteCategoryId">
                    
                    <p class="text-gray-700">
                        Êtes-vous sûr de vouloir supprimer la catégorie 
                        <span id="deleteCategoryName" class="font-semibold"></span> ?
                    </p>
                    <p class="text-sm text-red-600 mt-2">
                        Cette action désactivera la catégorie au lieu de la supprimer définitivement.
                    </p>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('deleteCategoryModal')" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        Supprimer
                    </button>
                </div>
            </form>
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

        function editCategory(category) {
            document.getElementById('editCategoryId').value = category.id_categorie;
            document.getElementById('editCategoryName').value = category.nom_categorie;
            document.getElementById('editCategoryDesc').value = category.description || '';
            document.getElementById('editCategoryStatus').value = category.statut;
            
            openModal('editCategoryModal');
        }

        function deleteCategory(id, name) {
            document.getElementById('deleteCategoryId').value = id;
            document.getElementById('deleteCategoryName').textContent = name;
            
            openModal('deleteCategoryModal');
        }

        // Fermer les modales en cliquant à l'extérieur
        document.addEventListener('click', function(event) {
            const modals = ['addCategoryModal', 'editCategoryModal', 'deleteCategoryModal'];
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