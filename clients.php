<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Traitement des actions
if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'ajouter':
                try {
                    $db->query("
                        INSERT INTO clients (nom_client, prenom_client, telephone, email, adresse, type_client, limite_credit) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ", [
                        $_POST['nom_client'] ?: null,
                        $_POST['prenom_client'] ?: null,
                        $_POST['telephone'] ?: null,
                        $_POST['email'] ?: null,
                        $_POST['adresse'] ?: null,
                        $_POST['type_client'],
                        $_POST['limite_credit'] ?: 0
                    ]);
                    setFlashMessage('Client ajouté avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors de l\'ajout du client: ' . $e->getMessage(), 'error');
                }
                break;
                
            case 'modifier':
                try {
                    $db->query("
                        UPDATE clients 
                        SET nom_client = ?, prenom_client = ?, telephone = ?, email = ?, 
                            adresse = ?, type_client = ?, limite_credit = ? 
                        WHERE id_client = ?
                    ", [
                        $_POST['nom_client'] ?: null,
                        $_POST['prenom_client'] ?: null,
                        $_POST['telephone'] ?: null,
                        $_POST['email'] ?: null,
                        $_POST['adresse'] ?: null,
                        $_POST['type_client'],
                        $_POST['limite_credit'] ?: 0,
                        $_POST['id_client']
                    ]);
                    setFlashMessage('Client modifié avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors de la modification: ' . $e->getMessage(), 'error');
                }
                break;
                
            case 'changer_statut':
                try {
                    $nouveau_statut = $_POST['statut'] === 'actif' ? 'inactif' : 'actif';
                    $db->query("UPDATE clients SET statut = ? WHERE id_client = ?", [$nouveau_statut, $_POST['id_client']]);
                    setFlashMessage('Statut du client modifié avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors du changement de statut: ' . $e->getMessage(), 'error');
                }
                break;
        }
    }
    header('Location: clients.php');
    exit;
}

// Récupération des clients avec recherche
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$statut_filter = $_GET['statut'] ?? '';

$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(nom_client LIKE ? OR prenom_client LIKE ? OR telephone LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

if (!empty($type_filter)) {
    $where_conditions[] = "type_client = ?";
    $params[] = $type_filter;
}

if (!empty($statut_filter)) {
    $where_conditions[] = "statut = ?";
    $params[] = $statut_filter;
}

$where_clause = implode(' AND ', $where_conditions);

$clients = $db->fetchAll("
    SELECT c.*, 
           COALESCE(SUM(v.montant_total_ttc), 0) as total_achats,
           COUNT(v.id_vente) as nombre_achats
    FROM clients c
    LEFT JOIN ventes v ON c.id_client = v.id_client AND v.statut = 'validee'
    WHERE $where_clause
    GROUP BY c.id_client
    ORDER BY c.date_creation DESC
", $params);

// Statistiques clients
$stats_clients = $db->fetch("
    SELECT 
        COUNT(*) as total_clients,
        COUNT(CASE WHEN statut = 'actif' THEN 1 END) as clients_actifs,
        COUNT(CASE WHEN type_client = 'credit' THEN 1 END) as clients_credit,
        COALESCE(SUM(solde_compte), 0) as total_credits
    FROM clients
");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Clients - <?= APP_NAME ?></title>
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
                    <a href="clients.php" class="flex items-center space-x-3 text-blue-600 bg-blue-50 px-3 py-2 rounded-lg">
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

            <!-- En-tête et statistiques -->
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">
                    <i class="fas fa-users mr-3"></i>Gestion des Clients
                </h1>
                <button onclick="openModal('ajouterModal')" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center">
                    <i class="fas fa-plus mr-2"></i>Nouveau Client
                </button>
            </div>

            <!-- Statistiques -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total Clients</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $stats_clients['total_clients'] ?></p>
                        </div>
                        <div class="bg-blue-100 rounded-full p-3">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Clients Actifs</p>
                            <p class="text-2xl font-bold text-green-600"><?= $stats_clients['clients_actifs'] ?></p>
                        </div>
                        <div class="bg-green-100 rounded-full p-3">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Clients Crédit</p>
                            <p class="text-2xl font-bold text-orange-600"><?= $stats_clients['clients_credit'] ?></p>
                        </div>
                        <div class="bg-orange-100 rounded-full p-3">
                            <i class="fas fa-credit-card text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Crédits Totaux</p>
                            <p class="text-2xl font-bold text-red-600"><?= formatMoney($stats_clients['total_credits']) ?></p>
                        </div>
                        <div class="bg-red-100 rounded-full p-3">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
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
                                   placeholder="Nom, prénom ou téléphone..." 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Type Client</label>
                            <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Tous les types</option>
                                <option value="particulier" <?= $type_filter === 'particulier' ? 'selected' : '' ?>>Particulier</option>
                                <option value="entreprise" <?= $type_filter === 'entreprise' ? 'selected' : '' ?>>Entreprise</option>
                                <option value="credit" <?= $type_filter === 'credit' ? 'selected' : '' ?>>Crédit</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                            <select name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Tous les statuts</option>
                                <option value="actif" <?= $statut_filter === 'actif' ? 'selected' : '' ?>>Actif</option>
                                <option value="inactif" <?= $statut_filter === 'inactif' ? 'selected' : '' ?>>Inactif</option>
                                <option value="bloque" <?= $statut_filter === 'bloque' ? 'selected' : '' ?>>Bloqué</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 mr-2">
                                <i class="fas fa-search mr-2"></i>Rechercher
                            </button>
                            <a href="clients.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Liste des clients -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto">
                            <thead>
                                <tr class="border-b">
                                    <th class="text-left py-3 px-4">Client</th>
                                    <th class="text-left py-3 px-4">Contact</th>
                                    <th class="text-left py-3 px-4">Type</th>
                                    <th class="text-left py-3 px-4">Achats</th>
                                    <th class="text-left py-3 px-4">Solde</th>
                                    <th class="text-left py-3 px-4">Statut</th>
                                    <th class="text-left py-3 px-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($clients)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-users text-4xl mb-4"></i>
                                            <p>Aucun client trouvé</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($clients as $client): ?>
                                        <tr class="border-b hover:bg-gray-50">
                                            <td class="py-3 px-4">
                                                <div>
                                                    <p class="font-medium text-gray-800">
                                                        <?= $client['nom_client'] || $client['prenom_client'] 
                                                            ? htmlspecialchars(trim($client['prenom_client'] . ' ' . $client['nom_client'])) 
                                                            : 'Client anonyme' ?>
                                                    </p>
                                                    <p class="text-sm text-gray-500">ID: <?= $client['id_client'] ?></p>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div>
                                                    <?php if ($client['telephone']): ?>
                                                        <p class="text-sm"><i class="fas fa-phone mr-2"></i><?= htmlspecialchars($client['telephone']) ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($client['email']): ?>
                                                        <p class="text-sm"><i class="fas fa-envelope mr-2"></i><?= htmlspecialchars($client['email']) ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="inline-block px-2 py-1 text-xs rounded-full
                                                    <?= $client['type_client'] === 'particulier' ? 'bg-blue-100 text-blue-800' : 
                                                        ($client['type_client'] === 'entreprise' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800') ?>">
                                                    <?= ucfirst($client['type_client']) ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div>
                                                    <p class="font-medium"><?= formatMoney($client['total_achats']) ?></p>
                                                    <p class="text-sm text-gray-500"><?= $client['nombre_achats'] ?> achat(s)</p>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="font-medium <?= $client['solde_compte'] > 0 ? 'text-red-600' : 'text-gray-600' ?>">
                                                    <?= formatMoney($client['solde_compte']) ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="changer_statut">
                                                    <input type="hidden" name="id_client" value="<?= $client['id_client'] ?>">
                                                    <input type="hidden" name="statut" value="<?= $client['statut'] ?>">
                                                    <button type="submit" class="inline-block px-2 py-1 text-xs rounded-full
                                                        <?= $client['statut'] === 'actif' ? 'bg-green-100 text-green-800 hover:bg-green-200' : 
                                                            ($client['statut'] === 'bloque' ? 'bg-red-100 text-red-800 hover:bg-red-200' : 'bg-gray-100 text-gray-800 hover:bg-gray-200') ?>">
                                                        <?= ucfirst($client['statut']) ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="flex space-x-2">
                                                    <button onclick="editClient(<?= htmlspecialchars(json_encode($client)) ?>)" 
                                                            class="bg-blue-500 text-white px-2 py-1 rounded text-sm hover:bg-blue-600">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="client_details.php?id=<?= $client['id_client'] ?>" 
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

    <!-- Modal Ajouter Client - Version Améliorée -->
    <div id="ajouterModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
            <!-- En-tête du modal -->
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-4 rounded-t-xl">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <i class="fas fa-user-plus mr-3 text-lg"></i>
                        <h3 class="text-lg font-semibold">Nouveau Client</h3>
                    </div>
                    <button onclick="closeModal('ajouterModal')" class="text-white hover:text-gray-200 text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Corps du modal -->
            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="ajouter">
                
                <!-- Section Informations personnelles -->
                <div class="mb-6">
                    <h4 class="text-md font-medium text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-user text-blue-600 mr-2"></i>
                        Informations personnelles
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prénom</label>
                            <input type="text" name="prenom_client" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="Jean">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                            <input type="text" name="nom_client" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="Dupont">
                        </div>
                    </div>
                </div>

                <!-- Section Contact -->
                <div class="mb-6">
                    <h4 class="text-md font-medium text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-phone text-blue-600 mr-2"></i>
                        Contact
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                            <input type="tel" name="telephone" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="+257 XX XX XX XX">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="email@example.com">
                        </div>
                    </div>
                </div>

                <!-- Section Configuration -->
                <div class="mb-6">
                    <h4 class="text-md font-medium text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-cog text-blue-600 mr-2"></i>
                        Configuration
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                            <select name="type_client" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="particulier">🧑 Particulier</option>
                                <option value="entreprise">🏢 Entreprise</option>
                                <option value="credit">💳 Crédit</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Limite de crédit</label>
                            <div class="relative">
                                <input type="number" name="limite_credit" step="0.01" min="0" value="0" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent pr-12"
                                       placeholder="0">
                                <span class="absolute right-3 top-2 text-gray-500 text-sm">BIF</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Adresse (optionnelle, compacte) -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Adresse (optionnel)</label>
                    <input type="text" name="adresse" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="Quartier, Avenue, Numéro...">
                </div>

                <!-- Boutons d'action -->
                <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                    <button type="button" onclick="closeModal('ajouterModal')" 
                            class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 flex items-center">
                        <i class="fas fa-save mr-2"></i>
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Modifier Client - Version Améliorée -->
    <div id="modifierModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
            <!-- En-tête du modal -->
            <div class="bg-gradient-to-r from-orange-600 to-orange-700 text-white px-6 py-4 rounded-t-xl">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <i class="fas fa-user-edit mr-3 text-lg"></i>
                        <h3 class="text-lg font-semibold">Modifier Client</h3>
                    </div>
                    <button onclick="closeModal('modifierModal')" class="text-white hover:text-gray-200 text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Corps du modal -->
            <form method="POST" id="modifierForm" class="p-6">
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="id_client" id="edit_id_client">
                
                <!-- Section Informations personnelles -->
                <div class="mb-6">
                    <h4 class="text-md font-medium text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-user text-orange-600 mr-2"></i>
                        Informations personnelles
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prénom</label>
                            <input type="text" name="prenom_client" id="edit_prenom_client"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                            <input type="text" name="nom_client" id="edit_nom_client"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                    </div>
                </div>

                <!-- Section Contact -->
                <div class="mb-6">
                    <h4 class="text-md font-medium text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-phone text-orange-600 mr-2"></i>
                        Contact
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                            <input type="tel" name="telephone" id="edit_telephone"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" id="edit_email"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                    </div>
                </div>

                <!-- Section Configuration -->
                <div class="mb-6">
                    <h4 class="text-md font-medium text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-cog text-orange-600 mr-2"></i>
                        Configuration
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                            <select name="type_client" id="edit_type_client" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                                <option value="particulier">🧑 Particulier</option>
                                <option value="entreprise">🏢 Entreprise</option>
                                <option value="credit">💳 Crédit</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Limite de crédit</label>
                            <div class="relative">
                                <input type="number" name="limite_credit" id="edit_limite_credit" step="0.01" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent pr-12">
                                <span class="absolute right-3 top-2 text-gray-500 text-sm">BIF</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Adresse -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                    <input type="text" name="adresse" id="edit_adresse"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                </div>

                <!-- Boutons d'action -->
                <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                    <button type="button" onclick="closeModal('modifierModal')" 
                            class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-6 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 flex items-center">
                        <i class="fas fa-save mr-2"></i>
                        Mettre à jour
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Gestion des modales
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.body.style.overflow = 'hidden'; // Empêche le scroll de la page
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.style.overflow = 'auto'; // Restaure le scroll
        }

        // Fonction pour éditer un client
        function editClient(client) {
            document.getElementById('edit_id_client').value = client.id_client;
            document.getElementById('edit_prenom_client').value = client.prenom_client || '';
            document.getElementById('edit_nom_client').value = client.nom_client || '';
            document.getElementById('edit_telephone').value = client.telephone || '';
            document.getElementById('edit_email').value = client.email || '';
            document.getElementById('edit_adresse').value = client.adresse || '';
            document.getElementById('edit_type_client').value = client.type_client;
            document.getElementById('edit_limite_credit').value = client.limite_credit;
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

        // Fermer les modales avec la touche Escape
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal('ajouterModal');
                closeModal('modifierModal');
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