<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Traitement des actions AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'search_product':
            $search = trim($_POST['search'] ?? '');
            $products = $db->fetchAll("
                SELECT 
                    p.*,
                    COALESCE(s.quantite_disponible, 0) as stock_disponible
                FROM produits p
                LEFT JOIN stocks s ON p.id_produit = s.id_produit
                WHERE p.statut = 'actif' 
                AND (p.nom_produit LIKE ? OR p.code_barre LIKE ?)
                ORDER BY p.nom_produit
                LIMIT 10
            ", ["%$search%", "%$search%"]);
            
            echo json_encode($products);
            exit;
            
        case 'get_product':
            $productId = $_POST['product_id'] ?? 0;
            $product = $db->fetch("
                SELECT 
                    p.*,
                    COALESCE(s.quantite_disponible, 0) as stock_disponible
                FROM produits p
                LEFT JOIN stocks s ON p.id_produit = s.id_produit
                WHERE p.id_produit = ? AND p.statut = 'actif'
            ", [$productId]);
            
            echo json_encode($product ?: []);
            exit;
            
        case 'validate_sale':
            try {
                $db->getConnection()->beginTransaction();
                
                $clientId = !empty($_POST['client_id']) ? $_POST['client_id'] : null;
                $items = json_decode($_POST['items'], true);
                $total = floatval($_POST['total']);
                $paid = floatval($_POST['paid']);
                $change = floatval($_POST['change']);
                $paymentMethod = $_POST['payment_method'] ?? 'especes';
                
                if (empty($items)) {
                    throw new Exception('Aucun article dans le panier');
                }
                
                // Créer la vente
                $ticketNumber = generateTicketNumber();
                $saleId = $db->query("
                    INSERT INTO ventes (
                        numero_ticket, id_utilisateur, id_client, 
                        montant_total_ttc, montant_paye, montant_rendu, 
                        mode_paiement, statut
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'validee')
                ", [
                    $ticketNumber, $user['id_utilisateur'], $clientId,
                    $total, $paid, $change, $paymentMethod
                ]);
                $saleId = $db->lastInsertId();
                
                // Ajouter les détails de vente et mettre à jour le stock
                foreach ($items as $item) {
                    $productId = intval($item['id']);
                    $quantity = intval($item['quantity']); 
                    $price = floatval($item['price']);
                    
                    // Vérifier le stock disponible
                    $stock = $db->fetch("
                        SELECT quantite_actuelle, quantite_disponible 
                        FROM stocks 
                        WHERE id_produit = ?
                    ", [$productId]);
                    
                    if (!$stock || $stock['quantite_actuelle'] < $quantity) {
                        throw new Exception("Stock insuffisant pour le produit ID: $productId (Stock: " . ($stock['quantite_actuelle'] ?? 0) . ", Demandé: $quantity)");
                    }
                    
                    // Obtenir le prix d'achat pour calculer la marge
                    $product = $db->fetch("SELECT prix_achat FROM produits WHERE id_produit = ?", [$productId]);
                    
                    // Insérer le détail de vente
                    $db->query("
                        INSERT INTO details_ventes (
                            id_vente, id_produit, quantite, 
                            prix_vente_unitaire, prix_achat_unitaire
                        ) VALUES (?, ?, ?, ?, ?)
                    ", [
                        $saleId, $productId, $quantity, 
                        $price, $product['prix_achat'] ?? 0
                    ]);
                    
                   $db->query("
                        UPDATE stocks 
                        SET quantite_actuelle = quantite_actuelle - ?,
                            date_derniere_sortie = NOW()
                        WHERE id_produit = ?
                    ", [$quantity, $productId]);
                    
                    $db->query("
                        UPDATE stocks 
                        SET quantite_disponible = quantite_actuelle
                        WHERE id_produit = ?
                    ", [$productId]);
                    
                    // CORRECTION: Enregistrer le mouvement de stock une seule fois
                    $db->query("
                        INSERT INTO mouvements_stock (
                            id_produit, type_mouvement, quantite, 
                            prix_unitaire, reference_operation, 
                            motif, id_utilisateur
                        ) VALUES (?, 'sortie', ?, ?, ?, 'Vente', ?)
                    ", [
                        $productId, $quantity, $price, 
                        "VENTE-$ticketNumber", $user['id_utilisateur']
                    ]);
                }
                
                // Enregistrer dans la trésorerie une seule fois
                $db->query("
                    INSERT INTO tresorerie (
                        type_mouvement, categorie, montant, 
                        mode_paiement, reference_operation, 
                        description, id_utilisateur
                    ) VALUES ('entree', 'vente', ?, ?, ?, ?, ?)
                ", [
                    $total, $paymentMethod, "VENTE-$ticketNumber",
                    "Vente ticket: $ticketNumber", $user['id_utilisateur']
                ]);
                
                $db->getConnection()->commit();
                
                echo json_encode([
                    'success' => true, 
                    'sale_id' => $saleId,
                    'ticket_number' => $ticketNumber,
                    'message' => 'Vente validée avec succès'
                ]);
                
            } catch (Exception $e) {
                $db->getConnection()->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
    }
}

// Récupérer les clients pour l'autocomplétion
$clients = $db->fetchAll("
    SELECT id_client, nom_client, prenom_client, telephone 
    FROM clients 
    WHERE statut = 'actif' 
    ORDER BY nom_client, prenom_client
    LIMIT 100
");

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point de vente - <?= APP_NAME ?></title>
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
                        <i class="fas fa-cash-register text-blue-600 mr-2"></i>Point de vente
                    </h1>
                </div>
                <div class="text-gray-600">
                    Vendeur: <?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto p-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Section recherche et produits -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Recherche produit -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Rechercher un produit</h3>
                    <div class="flex space-x-4">
                        <div class="flex-1">
                            <input type="text" id="productSearch" placeholder="Nom du produit ou code-barres..."
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <button onclick="clearSearch()" class="px-4 py-3 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- Résultats de recherche -->
                    <div id="searchResults" class="mt-4 space-y-2 max-h-60 overflow-y-auto hidden"></div>
                </div>

                <!-- Panier -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">Panier</h3>
                            <button onclick="clearCart()" class="text-red-600 hover:text-red-800">
                                <i class="fas fa-trash mr-1"></i>Vider
                            </button>
                        </div>
                    </div>
                    <div id="cartItems" class="p-6">
                        <p class="text-gray-500 text-center py-8">Panier vide</p>
                    </div>
                </div>
            </div>

            <!-- Section paiement -->
            <div class="space-y-6">
                <!-- Client -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Client</h3>
                    <select id="clientSelect" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Client anonyme</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id_client'] ?>">
                                <?= htmlspecialchars($client['prenom_client'] . ' ' . $client['nom_client']) ?>
                                <?= $client['telephone'] ? ' - ' . $client['telephone'] : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Résumé -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Résumé</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Articles:</span>
                            <span id="itemCount">0</span>
                        </div>
                        <div class="flex justify-between text-xl font-bold border-t pt-3">
                            <span>Total:</span>
                            <span id="totalAmount">0 BIF</span>
                        </div>
                    </div>
                </div>

                <!-- Paiement -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Paiement</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Mode de paiement</label>
                            <select id="paymentMethod" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
                                <option value="especes">Espèces</option>
                                <option value="carte">Carte</option>
                                <option value="mobile">Mobile Money</option>
                                <option value="cheque">Chèque</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Montant reçu</label>
                            <input type="number" id="amountPaid" placeholder="0" step="0.01"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Monnaie à rendre:</span>
                                <span id="changeAmount" class="font-semibold">0 BIF</span>
                            </div>
                        </div>
                        <button onclick="processSale()" id="processBtn" 
                                class="w-full bg-blue-600 text-white py-3 px-4 rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                disabled>
                            <i class="fas fa-credit-card mr-2"></i>Traiter la vente
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de succès -->
    <div id="successModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md mx-4">
            <div class="p-6 text-center">
                <i class="fas fa-check-circle text-green-500 text-6xl mb-4"></i>
                <h3 class="text-xl font-bold text-gray-800 mb-2">Vente réussie !</h3>
                <p class="text-gray-600 mb-4">Ticket: <span id="ticketNumber"></span></p>
                <div class="flex space-x-4 justify-center">
                    <button onclick="printReceipt()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-print mr-2"></i>Imprimer
                    </button>
                    <button onclick="newSale()" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
                        Nouvelle vente
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let cart = [];
        let currentSaleId = null;
        let currentTicketNumber = null;
        
        // Recherche de produits
        let searchTimeout;
        document.getElementById('productSearch').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length < 2) {
                document.getElementById('searchResults').classList.add('hidden');
                return;
            }
            
            searchTimeout = setTimeout(() => {
                searchProducts(query);
            }, 300);
        });
        
        function searchProducts(query) {
            const formData = new FormData();
            formData.append('action', 'search_product');
            formData.append('search', query);
            
            fetch('vente.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(products => {
                displaySearchResults(products);
            })
            .catch(error => {
                console.error('Erreur de recherche:', error);
            });
        }
        
        function displaySearchResults(products) {
            const resultsDiv = document.getElementById('searchResults');
            
            if (products.length === 0) {
                resultsDiv.innerHTML = '<p class="text-gray-500 text-center py-4">Aucun produit trouvé</p>';
                resultsDiv.classList.remove('hidden');
                return;
            }
            
            let html = '';
            products.forEach(product => {
                const stockClass = product.stock_disponible <= 0 ? 'text-red-600' : 
                                 product.stock_disponible <= product.stock_minimum ? 'text-orange-600' : 'text-green-600';
                
                html += `
                    <div class="border border-gray-200 rounded-lg p-4 cursor-pointer hover:bg-gray-50 ${product.stock_disponible <= 0 ? 'opacity-50' : ''}"
                         onclick="addToCart(${product.id_produit})">
                        <div class="flex justify-between items-center">
                            <div>
                                <h4 class="font-medium text-gray-800">${product.nom_produit}</h4>
                                <p class="text-sm text-gray-600">${formatMoney(product.prix_vente)}</p>
                                ${product.code_barre ? `<p class="text-xs text-gray-400">${product.code_barre}</p>` : ''}
                            </div>
                            <div class="text-right">
                                <p class="text-sm ${stockClass}">Stock: ${product.stock_disponible}</p>
                                ${product.stock_disponible <= 0 ? '<p class="text-xs text-red-600">Rupture</p>' : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
            resultsDiv.classList.remove('hidden');
        }
        
        function addToCart(productId) {
            const formData = new FormData();
            formData.append('action', 'get_product');
            formData.append('product_id', productId);
            
            fetch('vente.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(product => {
                if (product && product.stock_disponible > 0) {
                    const existingItem = cart.find(item => item.id === productId);
                    
                    if (existingItem) {
                        if (existingItem.quantity < product.stock_disponible) {
                            existingItem.quantity++;
                        } else {
                            alert('Stock insuffisant');
                            return;
                        }
                    } else {
                        cart.push({
                            id: productId,
                            name: product.nom_produit,
                            price: parseFloat(product.prix_vente),
                            quantity: 1,
                            maxStock: product.stock_disponible
                        });
                    }
                    
                    updateCartDisplay();
                    clearSearch();
                } else {
                    alert('Produit non disponible ou stock insuffisant');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors de l\'ajout du produit');
            });
        }
        
        function updateCartDisplay() {
            const cartDiv = document.getElementById('cartItems');
            
            if (cart.length === 0) {
                cartDiv.innerHTML = '<p class="text-gray-500 text-center py-8">Panier vide</p>';
                document.getElementById('itemCount').textContent = '0';
                document.getElementById('totalAmount').textContent = '0 BIF';
                document.getElementById('processBtn').disabled = true;
                return;
            }
            
            let html = '<div class="space-y-3">';
            let total = 0;
            let itemCount = 0;
            
            cart.forEach((item, index) => {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                itemCount += item.quantity;
                
                html += `
                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                        <div class="flex-1">
                            <h4 class="font-medium text-gray-800">${item.name}</h4>
                            <p class="text-sm text-gray-600">${formatMoney(item.price)} × ${item.quantity}</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button onclick="updateQuantity(${index}, -1)" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-minus"></i>
                            </button>
                            <span class="w-8 text-center">${item.quantity}</span>
                            <button onclick="updateQuantity(${index}, 1)" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-plus"></i>
                            </button>
                            <button onclick="removeFromCart(${index})" class="text-red-400 hover:text-red-600 ml-2">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <div class="w-20 text-right">
                            <span class="font-semibold">${formatMoney(itemTotal)}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            cartDiv.innerHTML = html;
            
            document.getElementById('itemCount').textContent = itemCount;
            document.getElementById('totalAmount').textContent = formatMoney(total);
            document.getElementById('processBtn').disabled = false;
            
            calculateChange();
        }
        
        function updateQuantity(index, change) {
            const item = cart[index];
            const newQuantity = item.quantity + change;
            
            if (newQuantity <= 0) {
                removeFromCart(index);
                return;
            }
            
            if (newQuantity > item.maxStock) {
                alert('Stock insuffisant');
                return;
            }
            
            item.quantity = newQuantity;
            updateCartDisplay();
        }
        
        function removeFromCart(index) {
            cart.splice(index, 1);
            updateCartDisplay();
        }
        
        function clearCart() {
            if (confirm('Vider le panier ?')) {
                cart = [];
                updateCartDisplay();
            }
        }
        
        function clearSearch() {
            document.getElementById('productSearch').value = '';
            document.getElementById('searchResults').classList.add('hidden');
        }
        
        // Calculer la monnaie
        document.getElementById('amountPaid').addEventListener('input', calculateChange);
        
        function calculateChange() {
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
            const change = paid - total;
            
            document.getElementById('changeAmount').textContent = formatMoney(Math.max(0, change));
            document.getElementById('changeAmount').className = change < 0 ? 'font-semibold text-red-600' : 'font-semibold text-green-600';
        }
        
        // Variable pour empêcher la double soumission
        let isProcessingSale = false;
        
        function processSale() {
            // CORRECTION PRINCIPALE: Empêcher complètement la double exécution
            if (isProcessingSale) {
                console.log('Traitement déjà en cours, requête ignorée');
                return false;
            }
            
            if (cart.length === 0) {
                alert('Panier vide');
                return false;
            }
            
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
            const change = paid - total;
            
            if (paid < total && document.getElementById('paymentMethod').value !== 'credit') {
                alert('Montant insuffisant');
                return false;
            }
            
            // CORRECTION: Verrouiller immédiatement
            isProcessingSale = true;
            
            const processBtn = document.getElementById('processBtn');
            processBtn.disabled = true;
            processBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Traitement...';
            
            // CORRECTION: Désactiver les boutons du panier
            disableCartInteractions(true);
            
            const formData = new FormData();
            formData.append('action', 'validate_sale');
            formData.append('client_id', document.getElementById('clientSelect').value);
            formData.append('items', JSON.stringify(cart));
            formData.append('total', total.toFixed(2));
            formData.append('paid', paid.toFixed(2));
            formData.append('change', Math.max(0, change).toFixed(2));
            formData.append('payment_method', document.getElementById('paymentMethod').value);
            
            fetch('vente.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erreur réseau: ' + response.status);
                }
                return response.json();
            })
            .then(result => {
                if (result.success) {
                    currentSaleId = result.sale_id;
                    currentTicketNumber = result.ticket_number;
                    document.getElementById('ticketNumber').textContent = result.ticket_number;
                    document.getElementById('successModal').classList.remove('hidden');
                    document.getElementById('successModal').classList.add('flex');
                } else {
                    alert('Erreur: ' + (result.error || 'Erreur inconnue'));
                    // CORRECTION: Réactiver en cas d'erreur
                    resetProcessingState();
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors du traitement de la vente: ' + error.message);
                // CORRECTION: Réactiver en cas d'erreur
                resetProcessingState();
            });
        }
        
        // CORRECTION: Fonction pour réinitialiser l'état de traitement
        function resetProcessingState() {
            isProcessingSale = false;
            const processBtn = document.getElementById('processBtn');
            processBtn.disabled = false;
            processBtn.innerHTML = '<i class="fas fa-credit-card mr-2"></i>Traiter la vente';
            disableCartInteractions(false);
        }
        
        // CORRECTION: Fonction pour désactiver/activer les interactions du panier
        function disableCartInteractions(disable) {
            const buttons = document.querySelectorAll('#cartItems button, #productSearch');
            buttons.forEach(btn => {
                btn.disabled = disable;
                if (disable) {
                    btn.style.pointerEvents = 'none';
                    btn.style.opacity = '0.5';
                } else {
                    btn.style.pointerEvents = '';
                    btn.style.opacity = '';
                }
            });
        }
        
        function newSale() {
            // CORRECTION: Réinitialiser complètement l'état
            isProcessingSale = false;
            cart = [];
            currentSaleId = null;
            currentTicketNumber = null;
            document.getElementById('clientSelect').value = '';
            document.getElementById('amountPaid').value = '';
            document.getElementById('paymentMethod').value = 'especes';
            updateCartDisplay();
            clearSearch();
            document.getElementById('successModal').classList.add('hidden');
            document.getElementById('successModal').classList.remove('flex');
            
            // CORRECTION: Réactiver tous les éléments
            resetProcessingState();
        }
        
        function printReceipt() {
            if (currentTicketNumber) {
                window.open(`print_receipt.php?ticket=${currentTicketNumber}`, '_blank');
            }
        }
        
        function formatMoney(amount) {
            return new Intl.NumberFormat('fr-FR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(amount) + ' BIF';
        }
        
        // Raccourcis clavier
        document.addEventListener('keydown', function(e) {
            // CORRECTION: Éviter les raccourcis pendant le traitement
            if (isProcessingSale) return;
            
            if (e.key === 'F1') {
                e.preventDefault();
                document.getElementById('productSearch').focus();
            }
            if (e.key === 'F2') {
                e.preventDefault();
                processSale();
            }
            if (e.key === 'Escape') {
                clearSearch();
            }
        });
        
        // CORRECTION: Empêcher la soumission multiple via l'événement beforeunload
        window.addEventListener('beforeunload', function(e) {
            if (isProcessingSale) {
                e.preventDefault();
                e.returnValue = 'Une vente est en cours de traitement...';
            }
        });
    </script>
</body>
</html>