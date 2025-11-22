<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Vérification des permissions - seul le propriétaire peut accéder
if ($user['role'] !== 'proprietaire') {
    header('Location: dashboard.php');
    exit;
}

// Traitement des actions
if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'ajouter':
                try {
                    $db->query("
                        INSERT INTO fournisseurs (nom_fournisseur, contact, telephone, email, adresse) 
                        VALUES (?, ?, ?, ?, ?)
                    ", [
                        $_POST['nom_fournisseur'],
                        $_POST['contact'] ?: null,
                        $_POST['telephone'] ?: null,
                        $_POST['email'] ?: null,
                        $_POST['adresse'] ?: null
                    ]);
                    setFlashMessage('Fournisseur ajouté avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors de l\'ajout du fournisseur: ' . $e->getMessage(), 'error');
                }
                break;
                
            case 'modifier':
                try {
                    $db->query("
                        UPDATE fournisseurs 
                        SET nom_fournisseur = ?, contact = ?, telephone = ?, email = ?, adresse = ? 
                        WHERE id_fournisseur = ?
                    ", [
                        $_POST['nom_fournisseur'],
                        $_POST['contact'] ?: null,
                        $_POST['telephone'] ?: null,
                        $_POST['email'] ?: null,
                        $_POST['adresse'] ?: null,
                        $_POST['id_fournisseur']
                    ]);
                    setFlashMessage('Fournisseur modifié avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors de la modification: ' . $e->getMessage(), 'error');
                }
                break;
                
            case 'changer_statut':
                try {
                    $nouveau_statut = $_POST['statut'] === 'actif' ? 'inactif' : 'actif';
                    $db->query("UPDATE fournisseurs SET statut = ? WHERE id_fournisseur = ?", [$nouveau_statut, $_POST['id_fournisseur']]);
                    setFlashMessage('Statut du fournisseur modifié avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors du changement de statut: ' . $e->getMessage(), 'error');
                }
                break;
        }
    }
    header('Location: fournisseurs.php');
    exit;
}

// Récupération des fournisseurs avec recherche
$search = $_GET['search'] ?? '';
$statut_filter = $_GET['statut'] ?? '';

$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(nom_fournisseur LIKE ? OR contact LIKE ? OR telephone LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

if (!empty($statut_filter)) {
    $where_conditions[] = "statut = ?";
    $params[] = $statut_filter;
}

$where_clause = implode(' AND ', $where_conditions);

$fournisseurs = $db->fetchAll("
    SELECT f.*, 
           COUNT(p.id_produit) as nombre_produits,
           COALESCE(SUM(a.montant_total_ttc), 0) as total_achats,
           COUNT(a.id_achat) as nombre_achats
    FROM fournisseurs f
    LEFT JOIN produits p ON f.id_fournisseur = p.id_fournisseur AND p.statut = 'actif'
    LEFT JOIN achats a ON f.id_fournisseur = a.id_fournisseur AND a.statut = 'validee'
    WHERE $where_clause
    GROUP BY f.id_fournisseur
    ORDER BY f.date_creation DESC
", $params);

// Statistiques fournisseurs
$stats_fournisseurs = $db->fetch("
    SELECT 
        COUNT(*) as total_fournisseurs,
        COUNT(CASE WHEN statut = 'actif' THEN 1 END) as fournisseurs_actifs,
        (SELECT COUNT(*) FROM produits WHERE id_fournisseur IS NOT NULL) as produits_avec_fournisseur,
        (SELECT COALESCE(SUM(montant_total_ttc), 0) FROM achats WHERE statut = 'validee' AND MONTH(date_achat) = MONTH(CURRENT_DATE())) as achats_mois
    FROM fournisseurs
");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Fournisseurs - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
                    <a href="dashboard.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Tableau de bord</span>
                    </a>
                    <a href="vente.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-cash-register"></i>
                        <span>Point de vente</span>
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
                    <a href="fournisseurs.php" class="flex items-center space-x-3 text-blue-600 bg-blue-50 px-3 py-2 rounded-lg">
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

            <!-- En-tête et statistiques -->
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">
                    <i class="fas fa-truck mr-3"></i>Gestion des Fournisseurs
                </h1>
                <button onclick="openModal('ajouterModal')" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center">
                    <i class="fas fa-plus mr-2"></i>Nouveau Fournisseur
                </button>
            </div>

            <!-- Statistiques -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total Fournisseurs</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $stats_fournisseurs['total_fournisseurs'] ?></p>
                        </div>
                        <div class="bg-blue-100 rounded-full p-3">
                            <i class="fas fa-truck text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Fournisseurs Actifs</p>
                            <p class="text-2xl font-bold text-green-600"><?= $stats_fournisseurs['fournisseurs_actifs'] ?></p>
                        </div>
                        <div class="bg-green-100 rounded-full p-3">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Produits Liés</p>
                            <p class="text-2xl font-bold text-purple-600"><?= $stats_fournisseurs['produits_avec_fournisseur'] ?></p>
                        </div>
                        <div class="bg-purple-100 rounded-full p-3">
                            <i class="fas fa-box text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Achats ce mois</p>
                            <p class="text-2xl font-bold text-orange-600"><?= formatMoney($stats_fournisseurs['achats_mois']) ?></p>
                        </div>
                        <div class="bg-orange-100 rounded-full p-3">
                            <i class="fas fa-shopping-cart text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtres et recherche -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Nom, contact ou téléphone..." 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                            <select name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Tous les statuts</option>
                                <option value="actif" <?= $statut_filter === 'actif' ? 'selected' : '' ?>>Actif</option>
                                <option value="inactif" <?= $statut_filter === 'inactif' ? 'selected' : '' ?>>Inactif</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 mr-2">
                                <i class="fas fa-search mr-2"></i>Rechercher
                            </button>
                            <a href="fournisseurs.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Liste des fournisseurs -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto">
                            <thead>
                                <tr class="border-b">
                                    <th class="text-left py-3 px-4">Fournisseur</th>
                                    <th class="text-left py-3 px-4">Contact</th>
                                    <th class="text-left py-3 px-4">Produits</th>
                                    <th class="text-left py-3 px-4">Achats</th>
                                    <th class="text-left py-3 px-4">Statut</th>
                                    <th class="text-left py-3 px-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fournisseurs)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-truck text-4xl mb-4"></i>
                                            <p>Aucun fournisseur trouvé</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($fournisseurs as $fournisseur): ?>
                                        <tr class="border-b hover:bg-gray-50">
                                            <td class="py-3 px-4">
                                                <div>
                                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($fournisseur['nom_fournisseur']) ?></p>
                                                    <?php if ($fournisseur['contact']): ?>
                                                        <p class="text-sm text-gray-500">Contact: <?= htmlspecialchars($fournisseur['contact']) ?></p>
                                                    <?php endif; ?>
                                                    <p class="text-xs text-gray-400">ID: <?= $fournisseur['id_fournisseur'] ?></p>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div>
                                                    <?php if ($fournisseur['telephone']): ?>
                                                        <p class="text-sm"><i class="fas fa-phone mr-2"></i><?= htmlspecialchars($fournisseur['telephone']) ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($fournisseur['email']): ?>
                                                        <p class="text-sm"><i class="fas fa-envelope mr-2"></i><?= htmlspecialchars($fournisseur['email']) ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($fournisseur['adresse']): ?>
                                                        <p class="text-xs text-gray-500"><i class="fas fa-map-marker-alt mr-2"></i><?= htmlspecialchars(substr($fournisseur['adresse'], 0, 30)) ?><?= strlen($fournisseur['adresse']) > 30 ? '...' : '' ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="font-medium text-blue-600"><?= $fournisseur['nombre_produits'] ?></span>
                                                <span class="text-sm text-gray-500">produit(s)</span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div>
                                                    <p class="font-medium"><?= formatMoney($fournisseur['total_achats']) ?></p>
                                                    <p class="text-sm text-gray-500"><?= $fournisseur['nombre_achats'] ?> achat(s)</p>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="changer_statut">
                                                    <input type="hidden" name="id_fournisseur" value="<?= $fournisseur['id_fournisseur'] ?>">
                                                    <input type="hidden" name="statut" value="<?= $fournisseur['statut'] ?>">
                                                    <button type="submit" class="inline-block px-2 py-1 text-xs rounded-full
                                                        <?= $fournisseur['statut'] === 'actif' ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>">
                                                        <?= ucfirst($fournisseur['statut']) ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="flex space-x-2">
                                                    <button onclick="editFournisseur(<?= htmlspecialchars(json_encode($fournisseur)) ?>)" 
                                                            class="bg-blue-500 text-white px-2 py-1 rounded text-sm hover:bg-blue-600">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="fournisseur_details.php?id=<?= $fournisseur['id_fournisseur'] ?>" 
                                                       class="bg-green-500 text-white px-2 py-1 rounded text-sm hover:bg-green-600">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ajouter Fournisseur -->
    <div id="ajouterModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Ajouter un Fournisseur</h3>
                        <button onclick="closeModal('ajouterModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="ajouter">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom du Fournisseur *</label>
                                <input type="text" name="nom_fournisseur" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Personne de Contact</label>
                                <input type="text" name="contact" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                <input type="tel" name="telephone" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Adresse</label>
                                <textarea name="adresse" class="w-full px-3 py-2 border border-gray-300 rounded-lg" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-4 mt-6">
                            <button type="button" onclick="closeModal('ajouterModal')" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400">
                                Annuler
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                                Ajouter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Modifier Fournisseur -->
    <div id="modifierModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Modifier le Fournisseur</h3>
                        <button onclick="closeModal('modifierModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form method="POST" id="modifierForm">
                        <input type="hidden" name="action" value="modifier">
                        <input type="hidden" name="id_fournisseur" id="edit_id_fournisseur">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom du Fournisseur *</label>
                                <input type="text" name="nom_fournisseur" id="edit_nom_fournisseur" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Personne de Contact</label>
                                <input type="text" name="contact" id="edit_contact" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                <input type="tel" name="telephone" id="edit_telephone" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" name="email" id="edit_email" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Adresse</label>
                                <textarea name="adresse" id="edit_adresse" class="w-full px-3 py-2 border border-gray-300 rounded-lg" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-4 mt-6">
                            <button type="button" onclick="closeModal('modifierModal')" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400">
                                Annuler
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                                Modifier
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Gestion des modales
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        // Fonction pour éditer un fournisseur
        function editFournisseur(fournisseur) {
            document.getElementById('edit_id_fournisseur').value = fournisseur.id_fournisseur;
            document.getElementById('edit_nom_fournisseur').value = fournisseur.nom_fournisseur || '';
            document.getElementById('edit_contact').value = fournisseur.contact || '';
            document.getElementById('edit_telephone').value = fournisseur.telephone || '';
            document.getElementById('edit_email').value = fournisseur.email || '';
            document.getElementById('edit_adresse').value = fournisseur.adresse || '';
            openModal('modifierModal');
        }

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

        // Fermer les modales en cliquant à l'extérieur
        document.addEventListener('click', function(event) {
            const modals = ['ajouterModal', 'modifierModal'];
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