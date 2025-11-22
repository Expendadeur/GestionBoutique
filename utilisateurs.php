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
                    // Vérifier si le nom d'utilisateur existe déjà
                    $user_exists = $db->fetch("SELECT id_utilisateur FROM utilisateurs WHERE nom_utilisateur = ?", [$_POST['nom_utilisateur']]);
                    if ($user_exists) {
                        setFlashMessage('Ce nom d\'utilisateur existe déjà', 'error');
                        break;
                    }

                    $mot_de_passe_hash = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);
                    $db->query("
                        INSERT INTO utilisateurs (nom_utilisateur, mot_de_passe, role, nom, prenom, telephone) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ", [
                        $_POST['nom_utilisateur'],
                        $mot_de_passe_hash,
                        $_POST['role'],
                        $_POST['nom'],
                        $_POST['prenom'],
                        $_POST['telephone'] ?: null
                    ]);
                    setFlashMessage('Utilisateur ajouté avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors de l\'ajout de l\'utilisateur: ' . $e->getMessage(), 'error');
                }
                break;
                
            case 'modifier':
                try {
                    // Vérifier si le nom d'utilisateur existe déjà (sauf pour l'utilisateur actuel)
                    $user_exists = $db->fetch("SELECT id_utilisateur FROM utilisateurs WHERE nom_utilisateur = ? AND id_utilisateur != ?", [$_POST['nom_utilisateur'], $_POST['id_utilisateur']]);
                    if ($user_exists) {
                        setFlashMessage('Ce nom d\'utilisateur existe déjà', 'error');
                        break;
                    }

                    $query = "UPDATE utilisateurs SET nom_utilisateur = ?, role = ?, nom = ?, prenom = ?, telephone = ?";
                    $params = [$_POST['nom_utilisateur'], $_POST['role'], $_POST['nom'], $_POST['prenom'], $_POST['telephone'] ?: null];
                    
                    // Si un nouveau mot de passe est fourni
                    if (!empty($_POST['mot_de_passe'])) {
                        $query .= ", mot_de_passe = ?";
                        $params[] = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);
                    }
                    
                    $query .= " WHERE id_utilisateur = ?";
                    $params[] = $_POST['id_utilisateur'];
                    
                    $db->query($query, $params);
                    setFlashMessage('Utilisateur modifié avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors de la modification: ' . $e->getMessage(), 'error');
                }
                break;
                
            case 'changer_statut':
                try {
                    // Empêcher la désactivation de son propre compte
                    if ($_POST['id_utilisateur'] == $user['id_utilisateur']) {
                        setFlashMessage('Vous ne pouvez pas désactiver votre propre compte', 'error');
                        break;
                    }
                    
                    $nouveau_statut = $_POST['statut'] === 'actif' ? 'inactif' : 'actif';
                    $db->query("UPDATE utilisateurs SET statut = ? WHERE id_utilisateur = ?", [$nouveau_statut, $_POST['id_utilisateur']]);
                    setFlashMessage('Statut de l\'utilisateur modifié avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors du changement de statut: ' . $e->getMessage(), 'error');
                }
                break;
        }
    }
    header('Location: utilisateurs.php');
    exit;
}

// Récupération des utilisateurs avec recherche
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$statut_filter = $_GET['statut'] ?? '';

$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(u.nom_utilisateur LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

if (!empty($role_filter)) {
    $where_conditions[] = "u.role = ?";
    $params[] = $role_filter;
}

if (!empty($statut_filter)) {
    $where_conditions[] = "u.statut = ?";
    $params[] = $statut_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// CORRECTION: Préfixer toutes les colonnes avec leur alias de table pour éviter l'ambiguïté
$utilisateurs = $db->fetchAll("
    SELECT u.*, 
           COUNT(v.id_vente) as nombre_ventes,
           COALESCE(SUM(v.montant_total_ttc), 0) as total_ventes
    FROM utilisateurs u
    LEFT JOIN ventes v ON u.id_utilisateur = v.id_utilisateur AND v.statut = 'validee'
    WHERE $where_clause
    GROUP BY u.id_utilisateur
    ORDER BY u.date_creation DESC
", $params);

// Statistiques utilisateurs
$stats_utilisateurs = $db->fetch("
    SELECT 
        COUNT(*) as total_utilisateurs,
        COUNT(CASE WHEN statut = 'actif' THEN 1 END) as utilisateurs_actifs,
        COUNT(CASE WHEN role = 'proprietaire' THEN 1 END) as proprietaires,
        COUNT(CASE WHEN role = 'vendeur' THEN 1 END) as vendeurs
    FROM utilisateurs
");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs - <?= APP_NAME ?></title>
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
                    <a href="fournisseurs.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-truck"></i>
                        <span>Fournisseurs</span>
                    </a>
                    <a href="achats.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-shopping-cart"></i>
                        <span>Achats</span>
                    </a>
                    <a href="tresorerie.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-coins"></i>
                        <span>Trésorerie</span>
                    </a>
                    <a href="rapports.php" class="flex items-center space-x-3 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports</span>
                    </a>
                    <a href="utilisateurs.php" class="flex items-center space-x-3 text-blue-600 bg-blue-50 px-3 py-2 rounded-lg">
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
                    <i class="fas fa-user-cog mr-3"></i>Gestion des Utilisateurs
                </h1>
                <button onclick="openModal('ajouterModal')" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center">
                    <i class="fas fa-plus mr-2"></i>Nouvel Utilisateur
                </button>
            </div>

            <!-- Statistiques -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total Utilisateurs</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $stats_utilisateurs['total_utilisateurs'] ?></p>
                        </div>
                        <div class="bg-blue-100 rounded-full p-3">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Utilisateurs Actifs</p>
                            <p class="text-2xl font-bold text-green-600"><?= $stats_utilisateurs['utilisateurs_actifs'] ?></p>
                        </div>
                        <div class="bg-green-100 rounded-full p-3">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Propriétaires</p>
                            <p class="text-2xl font-bold text-purple-600"><?= $stats_utilisateurs['proprietaires'] ?></p>
                        </div>
                        <div class="bg-purple-100 rounded-full p-3">
                            <i class="fas fa-crown text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Vendeurs</p>
                            <p class="text-2xl font-bold text-orange-600"><?= $stats_utilisateurs['vendeurs'] ?></p>
                        </div>
                        <div class="bg-orange-100 rounded-full p-3">
                            <i class="fas fa-user-tie text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtres et recherche -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Nom d'utilisateur, nom ou prénom..." 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Rôle</label>
                            <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Tous les rôles</option>
                                <option value="proprietaire" <?= $role_filter === 'proprietaire' ? 'selected' : '' ?>>Propriétaire</option>
                                <option value="vendeur" <?= $role_filter === 'vendeur' ? 'selected' : '' ?>>Vendeur</option>
                            </select>
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
                            <a href="utilisateurs.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Liste des utilisateurs -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto">
                            <thead>
                                <tr class="border-b">
                                    <th class="text-left py-3 px-4">Utilisateur</th>
                                    <th class="text-left py-3 px-4">Nom Complet</th>
                                    <th class="text-left py-3 px-4">Contact</th>
                                    <th class="text-left py-3 px-4">Rôle</th>
                                    <th class="text-left py-3 px-4">Ventes</th>
                                    <th class="text-left py-3 px-4">Statut</th>
                                    <th class="text-left py-3 px-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($utilisateurs)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-users text-4xl mb-4"></i>
                                            <p>Aucun utilisateur trouvé</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($utilisateurs as $utilisateur): ?>
                                        <tr class="border-b hover:bg-gray-50">
                                            <td class="py-3 px-4">
                                                <div>
                                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($utilisateur['nom_utilisateur']) ?></p>
                                                    <p class="text-sm text-gray-500">ID: <?= $utilisateur['id_utilisateur'] ?></p>
                                                    <p class="text-xs text-gray-400">Créé: <?= formatDate($utilisateur['date_creation']) ?></p>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <p class="font-medium text-gray-800"><?= htmlspecialchars($utilisateur['prenom'] . ' ' . $utilisateur['nom']) ?></p>
                                            </td>
                                            <td class="py-3 px-4">
                                                <?php if ($utilisateur['telephone']): ?>
                                                    <p class="text-sm"><i class="fas fa-phone mr-2"></i><?= htmlspecialchars($utilisateur['telephone']) ?></p>
                                                <?php else: ?>
                                                    <p class="text-sm text-gray-400">Non renseigné</p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="inline-block px-2 py-1 text-xs rounded-full
                                                    <?= $utilisateur['role'] === 'proprietaire' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' ?>">
                                                    <i class="fas <?= $utilisateur['role'] === 'proprietaire' ? 'fa-crown' : 'fa-user-tie' ?> mr-1"></i>
                                                    <?= ucfirst($utilisateur['role']) ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div>
                                                    <p class="font-medium"><?= formatMoney($utilisateur['total_ventes']) ?></p>
                                                    <p class="text-sm text-gray-500"><?= $utilisateur['nombre_ventes'] ?> vente(s)</p>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <?php if ($utilisateur['id_utilisateur'] != $user['id_utilisateur']): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="changer_statut">
                                                        <input type="hidden" name="id_utilisateur" value="<?= $utilisateur['id_utilisateur'] ?>">
                                                        <input type="hidden" name="statut" value="<?= $utilisateur['statut'] ?>">
                                                        <button type="submit" class="inline-block px-2 py-1 text-xs rounded-full
                                                            <?= $utilisateur['statut'] === 'actif' ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>">
                                                            <?= ucfirst($utilisateur['statut']) ?>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="inline-block px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">
                                                        <?= ucfirst($utilisateur['statut']) ?> (Vous)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="flex space-x-2">
                                                    <button onclick="editUtilisateur(<?= htmlspecialchars(json_encode($utilisateur)) ?>)" 
                                                            class="bg-blue-500 text-white px-2 py-1 rounded text-sm hover:bg-blue-600">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
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

    <!-- Modal Ajouter Utilisateur -->
    <div id="ajouterModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Ajouter un Utilisateur</h3>
                        <button onclick="closeModal('ajouterModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="ajouter">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom d'utilisateur *</label>
                                <input type="text" name="nom_utilisateur" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Mot de passe *</label>
                                <input type="password" name="mot_de_passe" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Prénom *</label>
                                <input type="text" name="prenom" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom *</label>
                                <input type="text" name="nom" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                <input type="text" name="telephone" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Rôle *</label>
                                <select name="role" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option value="vendeur">Vendeur</option>
                                    <option value="proprietaire">Propriétaire</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-3 mt-6">
                            <button type="button" onclick="closeModal('ajouterModal')" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">
                                Annuler
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                Ajouter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Modifier Utilisateur -->
    <div id="modifierModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg max-w-md w-full">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Modifier l'Utilisateur</h3>
                        <button onclick="closeModal('modifierModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="modifier">
                        <input type="hidden" name="id_utilisateur" id="edit_id_utilisateur">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom d'utilisateur *</label>
                                <input type="text" name="nom_utilisateur" id="edit_nom_utilisateur" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau mot de passe (laisser vide pour garder l'actuel)</label>
                                <input type="password" name="mot_de_passe" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Prénom *</label>
                                <input type="text" name="prenom" id="edit_prenom" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom *</label>
                                <input type="text" name="nom" id="edit_nom" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                <input type="text" name="telephone" id="edit_telephone" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Rôle *</label>
                                <select name="role" id="edit_role" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option value="vendeur">Vendeur</option>
                                    <option value="proprietaire">Propriétaire</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-3 mt-6">
                            <button type="button" onclick="closeModal('modifierModal')" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">
                                Annuler
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                Modifier
                            </button>
                        </div>
                    </form>
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

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function editUtilisateur(utilisateur) {
            document.getElementById('edit_id_utilisateur').value = utilisateur.id_utilisateur;
            document.getElementById('edit_nom_utilisateur').value = utilisateur.nom_utilisateur;
            document.getElementById('edit_prenom').value = utilisateur.prenom;
            document.getElementById('edit_nom').value = utilisateur.nom;
            document.getElementById('edit_telephone').value = utilisateur.telephone || '';
            document.getElementById('edit_role').value = utilisateur.role;
            openModal('modifierModal');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('fixed')) {
                event.target.classList.add('hidden');
            }
        }
    </script>
</body>
</html>