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
            case 'nouvel_achat':
                try {
                    $db->beginTransaction();
                    
                    // Insérer l'achat principal
                    $achatId = $db->query("
                        INSERT INTO achats (
                            id_fournisseur, numero_facture, date_achat, date_livraison_prevue,
                            montant_total_ht, montant_tva, montant_total_ttc, 
                            statut, notes, id_utilisateur, date_creation
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente', ?, ?, NOW())
                    ", [
                        $_POST['id_fournisseur'],
                        $_POST['numero_facture'],
                        $_POST['date_achat'],
                        $_POST['date_livraison_prevue'] ?: null,
                        $_POST['montant_total_ht'],
                        $_POST['montant_tva'],
                        $_POST['montant_total_ttc'],
                        $_POST['notes'],
                        $user['id_utilisateur']
                    ]);
                    
                    $achatId = $db->lastInsertId();
                    
                    // Insérer les détails de l'achat (produits)
                    if (!empty($_POST['produits'])) {
                        foreach ($_POST['produits'] as $produit) {
                            if (!empty($produit['id_produit']) && $produit['quantite'] > 0) {
                                $db->query("
                                    INSERT INTO details_achats (
                                        id_achat, id_produit, quantite_commandee, 
                                        quantite_recue, prix_achat_unitaire, total_ligne
                                    ) VALUES (?, ?, ?, ?, ?, ?)
                                ", [
                                    $achatId,
                                    $produit['id_produit'],
                                    $produit['quantite'],
                                    $produit['quantite'], // quantité reçue = quantité commandée par défaut
                                    $produit['prix_unitaire'],
                                    $produit['quantite'] * $produit['prix_unitaire']
                                ]);
                            }
                        }
                    }
                    
                    $db->commit();
                    setFlashMessage('Achat créé avec succès', 'success');
                } catch (Exception $e) {
                    $db->rollback();
                    setFlashMessage('Erreur lors de la création: ' . $e->getMessage(), 'error');
                }
                break;
                
            case 'valider_achat':
                try {
                    $db->beginTransaction();
                    
                    // Valider l'achat
                    $db->query("UPDATE achats SET statut = 'validee' WHERE id_achat = ?", [$_POST['id_achat']]);
                    
                    // Mettre à jour les stocks pour chaque produit
                    $details_achat = $db->fetchAll("
                        SELECT da.*, p.id_produit 
                        FROM details_achats da 
                        JOIN produits p ON da.id_produit = p.id_produit 
                        WHERE da.id_achat = ?
                    ", [$_POST['id_achat']]);
                    
                    foreach ($details_achat as $detail) {
                        // Vérifier si le produit a déjà un stock
                        $stock_existant = $db->fetch("SELECT * FROM stocks WHERE id_produit = ?", [$detail['id_produit']]);
                        
                        if ($stock_existant) {
                            // Mettre à jour le stock existant
                            $db->query("
                                UPDATE stocks 
                                SET quantite_actuelle = quantite_actuelle + ?, 
                                    date_derniere_entree = NOW(),
                                    date_mise_a_jour = NOW()
                                WHERE id_produit = ?
                            ", [$detail['quantite_recue'], $detail['id_produit']]);
                        } else {
                            // Créer un nouveau stock
                            $db->query("
                                INSERT INTO stocks (id_produit, quantite_actuelle, date_derniere_entree) 
                                VALUES (?, ?, NOW())
                            ", [$detail['id_produit'], $detail['quantite_recue']]);
                        }
                        
                        // Enregistrer le mouvement de stock
                        $db->query("
                            INSERT INTO mouvements_stock (id_produit, type_mouvement, quantite, prix_unitaire, reference_operation, motif, id_utilisateur) 
                            VALUES (?, 'entree', ?, ?, ?, 'Réception achat', ?)
                        ", [$detail['id_produit'], $detail['quantite_recue'], $detail['prix_achat_unitaire'], $_POST['id_achat'], $user['id_utilisateur']]);
                    }
                    
                    $db->commit();
                    setFlashMessage('Achat validé et stocks mis à jour avec succès', 'success');
                } catch (Exception $e) {
                    $db->rollback();
                    setFlashMessage('Erreur lors de la validation: ' . $e->getMessage(), 'error');
                }
                break;
                
            case 'annuler_achat':
                try {
                    $db->query("UPDATE achats SET statut = 'annulee' WHERE id_achat = ?", [$_POST['id_achat']]);
                    setFlashMessage('Achat annulé avec succès', 'success');
                } catch (Exception $e) {
                    setFlashMessage('Erreur lors de l\'annulation: ' . $e->getMessage(), 'error');
                }
                break;
        }
    }
    header('Location: achats.php');
    exit;
}

// Récupération des achats avec recherche et filtres
$search = $_GET['search'] ?? '';
$statut_filter = $_GET['statut'] ?? '';
$fournisseur_filter = $_GET['fournisseur'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';

$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(a.numero_facture LIKE ? OR f.nom_fournisseur LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param]);
}

if (!empty($statut_filter)) {
    $where_conditions[] = "a.statut = ?";
    $params[] = $statut_filter;
}

if (!empty($fournisseur_filter)) {
    $where_conditions[] = "a.id_fournisseur = ?";
    $params[] = $fournisseur_filter;
}

if (!empty($date_debut)) {
    $where_conditions[] = "DATE(a.date_achat) >= ?";
    $params[] = $date_debut;
}

if (!empty($date_fin)) {
    $where_conditions[] = "DATE(a.date_achat) <= ?";
    $params[] = $date_fin;
}

$where_clause = implode(' AND ', $where_conditions);

// Récupérer les achats
$achats = $db->fetchAll("
    SELECT a.*, 
           f.nom_fournisseur,
           u.prenom as acheteur_prenom,
           u.nom as acheteur_nom,
           COUNT(da.id_detail_achat) as nombre_produits,
           SUM(da.quantite_commandee) as quantite_totale
    FROM achats a
    LEFT JOIN fournisseurs f ON a.id_fournisseur = f.id_fournisseur
    LEFT JOIN utilisateurs u ON a.id_utilisateur = u.id_utilisateur
    LEFT JOIN details_achats da ON a.id_achat = da.id_achat
    WHERE $where_clause
    GROUP BY a.id_achat
    ORDER BY a.date_achat DESC
", $params);

// Liste des fournisseurs pour le filtre et modal
$fournisseurs = $db->fetchAll("SELECT id_fournisseur, nom_fournisseur FROM fournisseurs WHERE statut = 'actif' ORDER BY nom_fournisseur");

// Liste des produits pour le modal
$produits = $db->fetchAll("
    SELECT p.id_produit, p.nom_produit, p.prix_achat, p.unite_mesure, c.nom_categorie
    FROM produits p 
    LEFT JOIN categories c ON p.id_categorie = c.id_categorie 
    WHERE p.statut = 'actif' 
    ORDER BY p.nom_produit
");

// Statistiques achats
$stats_achats = $db->fetch("
    SELECT 
        COUNT(*) as total_achats,
        COUNT(CASE WHEN statut = 'en_attente' THEN 1 END) as achats_attente,
        COUNT(CASE WHEN statut = 'validee' THEN 1 END) as achats_valides,
        COALESCE(SUM(CASE WHEN statut = 'validee' THEN montant_total_ttc END), 0) as montant_total,
        COALESCE(SUM(CASE WHEN statut = 'validee' AND MONTH(date_achat) = MONTH(CURRENT_DATE()) THEN montant_total_ttc END), 0) as montant_mois
    FROM achats
");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Achats - <?= APP_NAME ?></title>
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
                    <a href="achats.php" class="flex items-center space-x-3 text-blue-600 bg-blue-50 px-3 py-2 rounded-lg">
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
                    <i class="fas fa-shopping-cart mr-3"></i>Gestion des Achats
                </h1>
                <button onclick="openModal('achatModal')" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center">
                    <i class="fas fa-plus mr-2"></i>Nouvel Achat
                </button>
            </div>

            <!-- Statistiques -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total Achats</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $stats_achats['total_achats'] ?></p>
                        </div>
                        <div class="bg-blue-100 rounded-full p-3">
                            <i class="fas fa-shopping-cart text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">En Attente</p>
                            <p class="text-2xl font-bold text-orange-600"><?= $stats_achats['achats_attente'] ?></p>
                        </div>
                        <div class="bg-orange-100 rounded-full p-3">
                            <i class="fas fa-clock text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Validés</p>
                            <p class="text-2xl font-bold text-green-600"><?= $stats_achats['achats_valides'] ?></p>
                        </div>
                        <div class="bg-green-100 rounded-full p-3">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Montant Total</p>
                            <p class="text-2xl font-bold text-purple-600"><?= formatMoney($stats_achats['montant_total']) ?></p>
                        </div>
                        <div class="bg-purple-100 rounded-full p-3">
                            <i class="fas fa-dollar-sign text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Ce Mois</p>
                            <p class="text-2xl font-bold text-red-600"><?= formatMoney($stats_achats['montant_mois']) ?></p>
                        </div>
                        <div class="bg-red-100 rounded-full p-3">
                            <i class="fas fa-calendar text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtres et recherche -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="N° facture ou fournisseur..." 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                            <select name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Tous les statuts</option>
                                <option value="en_attente" <?= $statut_filter === 'en_attente' ? 'selected' : '' ?>>En attente</option>
                                <option value="validee" <?= $statut_filter === 'validee' ? 'selected' : '' ?>>Validée</option>
                                <option value="livree" <?= $statut_filter === 'livree' ? 'selected' : '' ?>>Livrée</option>
                                <option value="annulee" <?= $statut_filter === 'annulee' ? 'selected' : '' ?>>Annulée</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fournisseur</label>
                            <select name="fournisseur" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Tous les fournisseurs</option>
                                <?php foreach ($fournisseurs as $fournisseur): ?>
                                    <option value="<?= $fournisseur['id_fournisseur'] ?>" <?= $fournisseur_filter == $fournisseur['id_fournisseur'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($fournisseur['nom_fournisseur']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date Début</label>
                            <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date Fin</label>
                            <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 mr-2">
                                <i class="fas fa-search mr-2"></i>Rechercher
                            </button>
                            <a href="achats.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Liste des achats -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto">
                            <thead>
                                <tr class="border-b">
                                    <th class="text-left py-3 px-4">Achat</th>
                                    <th class="text-left py-3 px-4">Fournisseur</th>
                                    <th class="text-left py-3 px-4">Date</th>
                                    <th class="text-left py-3 px-4">Produits</th>
                                    <th class="text-left py-3 px-4">Montant</th>
                                    <th class="text-left py-3 px-4">Statut</th>
                                    <th class="text-left py-3 px-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($achats)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-shopping-cart text-4xl mb-4"></i>
                                            <p>Aucun achat trouvé</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($achats as $achat): ?>
                                        <tr class="border-b hover:bg-gray-50">
                                            <td class="py-3 px-4">
                                                <div>
                                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($achat['numero_facture'] ?: 'N/A') ?></p>
                                                    <p class="text-sm text-gray-500">ID: <?= $achat['id_achat'] ?></p>
                                                    <p class="text-xs text-gray-400">Par: <?= htmlspecialchars($achat['acheteur_prenom'] . ' ' . $achat['acheteur_nom']) ?></p>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <p class="font-medium text-gray-800"><?= htmlspecialchars($achat['nom_fournisseur'] ?: 'N/A') ?></p>
                                            </td>
                                            <td class="py-3 px-4">
                                                <p class="text-sm"><?= formatDate($achat['date_achat']) ?></p>
                                                <?php if ($achat['date_livraison_prevue']): ?>
                                                    <p class="text-xs text-gray-500">Livraison: <?= formatDate($achat['date_livraison_prevue']) ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div>
                                                    <p class="font-medium"><?= $achat['nombre_produits'] ?> produit(s)</p>
                                                    <p class="text-sm text-gray-500"><?= $achat['quantite_totale'] ?> unités</p>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <p class="font-medium"><?= formatMoney($achat['montant_total_ttc']) ?></p>
                                                <?php if ($achat['montant_total_ht'] != $achat['montant_total_ttc']): ?>
                                                    <p class="text-sm text-gray-500">HT: <?= formatMoney($achat['montant_total_ht']) ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="inline-block px-2 py-1 text-xs rounded-full
                                                    <?= $achat['statut'] === 'en_attente' ? 'bg-orange-100 text-orange-800' : 
                                                        ($achat['statut'] === 'validee' ? 'bg-green-100 text-green-800' : 
                                                        ($achat['statut'] === 'livree' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800')) ?>">
                                                    <?= ucfirst($achat['statut']) ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="flex space-x-2">
                                                    <a href="achat_details.php?id=<?= $achat['id_achat'] ?>" 
                                                       class="bg-green-500 text-white px-2 py-1 rounded text-sm hover:bg-green-600">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($achat['statut'] === 'en_attente'): ?>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Valider cet achat? Cette action est irréversible.')">
                                                            <input type="hidden" name="action" value="valider_achat">
                                                            <input type="hidden" name="id_achat" value="<?= $achat['id_achat'] ?>">
                                                            <button type="submit" class="bg-blue-500 text-white px-2 py-1 rounded text-sm hover:bg-blue-600">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Annuler cet achat?')">
                                                            <input type="hidden" name="action" value="annuler_achat">
                                                            <input type="hidden" name="id_achat" value="<?= $achat['id_achat'] ?>">
                                                            <button type="submit" class="bg-red-500 text-white px-2 py-1 rounded text-sm hover:bg-red-600">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
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

    <!-- Modal Nouvel Achat -->
    <div id="achatModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <form method="POST" id="achatForm">
                <input type="hidden" name="action" value="nouvel_achat">
                
                <!-- En-tête modal -->
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-800">Nouvel Achat</h3>
                        <button type="button" onclick="closeModal('achatModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>

                <!-- Contenu modal -->
                <div class="p-4 space-y-4">
                    <!-- Informations générales -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Fournisseur *</label>
                                <select name="id_fournisseur" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                                    <option value="">Sélectionner un fournisseur</option>
                                    <?php foreach ($fournisseurs as $fournisseur): ?>
                                        <option value="<?= $fournisseur['id_fournisseur'] ?>"><?= htmlspecialchars($fournisseur['nom_fournisseur']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Numéro facture</label>
                                <input type="text" name="numero_facture" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date d'achat *</label>
                                <input type="date" name="date_achat" required value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date livraison prévue</label>
                                <input type="date" name="date_livraison_prevue" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>

                    <!-- Produits -->
                    <div>
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="text-md font-medium text-gray-800">Produits</h4>
                            <button type="button" onclick="ajouterLigneProduit()" class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
                                <i class="fas fa-plus mr-1"></i>Ajouter produit
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm" id="produitsTable">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase w-20">Qté</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase w-24">Prix unit.</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase w-24">Total</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase w-16">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="produitsTableBody">
                                    <!-- Les lignes seront ajoutées dynamiquement -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Totaux -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" placeholder="Notes sur cet achat..."></textarea>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Montant HT</label>
                                <input type="number" name="montant_total_ht" step="0.01" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-sm">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">TVA</label>
                                <input type="number" name="montant_tva" step="0.01" value="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" onchange="calculerTotaux()">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Montant TTC</label>
                                <input type="number" name="montant_total_ttc" step="0.01" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-sm font-bold">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pied modal -->
                <div class="p-4 border-t border-gray-200 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('achatModal')" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 text-sm">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                        Enregistrer l'achat
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let ligneProduitIndex = 0;
        const produits = <?= json_encode($produits) ?>;

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

        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.getElementById(modalId).classList.add('flex');
            if (modalId === 'achatModal') {
                ajouterLigneProduit(); // Ajouter une première ligne
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
            if (modalId === 'achatModal') {
                resetAchatForm();
            }
        }

        function resetAchatForm() {
            document.getElementById('achatForm').reset();
            document.getElementById('produitsTableBody').innerHTML = '';
            ligneProduitIndex = 0;
        }

        function ajouterLigneProduit() {
            const tbody = document.getElementById('produitsTableBody');
            const index = ligneProduitIndex++;
            
            const tr = document.createElement('tr');
            tr.dataset.index = index;
            tr.innerHTML = `
                <td class="px-3 py-2">
                    <select name="produits[${index}][id_produit]" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" onchange="updatePrixUnitaire(this, ${index})">
                        <option value="">Sélectionner un produit</option>
                        ${produits.map(p => `<option value="${p.id_produit}" data-prix="${p.prix_achat}">${p.nom_produit}</option>`).join('')}
                    </select>
                </td>
                <td class="px-3 py-2">
                    <input type="number" name="produits[${index}][quantite]" min="1" step="1" value="1" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" onchange="calculerLigneTotale(${index})" oninput="calculerLigneTotale(${index})">
                </td>
                <td class="px-3 py-2">
                    <input type="number" name="produits[${index}][prix_unitaire]" min="0" step="0.01" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" onchange="calculerLigneTotale(${index})" oninput="calculerLigneTotale(${index})">
                </td>
                <td class="px-3 py-2">
                    <span class="total-ligne font-medium text-sm">0.00</span>
                </td>
                <td class="px-3 py-2">
                    <button type="button" onclick="supprimerLigneProduit(this)" class="text-red-600 hover:text-red-800">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        }

        function supprimerLigneProduit(button) {
            button.closest('tr').remove();
            calculerTotaux();
        }

        function updatePrixUnitaire(select, index) {
            const selectedOption = select.options[select.selectedIndex];
            const prix = selectedOption.dataset.prix;
            if (prix) {
                const tr = document.querySelector(`tr[data-index="${index}"]`);
                const prixInput = tr.querySelector('input[name$="[prix_unitaire]"]');
                prixInput.value = prix;
                calculerLigneTotale(index);
            }
        }

        function calculerLigneTotale(index) {
            const tr = document.querySelector(`tr[data-index="${index}"]`);
            if (!tr) return;
            
            const quantite = parseFloat(tr.querySelector('input[name$="[quantite]"]').value) || 0;
            const prixUnitaire = parseFloat(tr.querySelector('input[name$="[prix_unitaire]"]').value) || 0;
            const total = quantite * prixUnitaire;
            
            tr.querySelector('.total-ligne').textContent = total.toFixed(2);
            calculerTotaux();
        }

        function calculerTotaux() {
            let totalHT = 0;
            document.querySelectorAll('.total-ligne').forEach(span => {
                totalHT += parseFloat(span.textContent) || 0;
            });
            
            const tva = parseFloat(document.querySelector('input[name="montant_tva"]').value) || 0;
            const totalTTC = totalHT + tva;
            
            document.querySelector('input[name="montant_total_ht"]').value = totalHT.toFixed(2);
            document.querySelector('input[name="montant_total_ttc"]').value = totalTTC.toFixed(2);
        }

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