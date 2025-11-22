<?php
require_once 'config.php';
requireLogin();

$db = Database::getInstance();
$user = getCurrentUser();

// Récupérer le numéro de ticket
$ticketNumber = $_GET['ticket'] ?? '';

if (empty($ticketNumber)) {
    die('Numéro de ticket manquant');
}

// Récupérer les informations de la vente
$sale = $db->fetch("
    SELECT 
        v.*,
        u.prenom as vendeur_prenom,
        u.nom as vendeur_nom,
        c.prenom_client,
        c.nom_client,
        c.telephone as client_telephone
    FROM ventes v
    LEFT JOIN utilisateurs u ON v.id_utilisateur = u.id_utilisateur
    LEFT JOIN clients c ON v.id_client = c.id_client
    WHERE v.numero_ticket = ?
", [$ticketNumber]);

if (!$sale) {
    die('Vente non trouvée');
}

// Récupérer les détails de la vente
$details = $db->fetchAll("
    SELECT 
        dv.*,
        p.nom_produit,
        p.code_barre,
        (dv.quantite * dv.prix_vente_unitaire) as sous_total
    FROM details_ventes dv
    JOIN produits p ON dv.id_produit = p.id_produit
    WHERE dv.id_vente = ?
    ORDER BY p.nom_produit
", [$sale['id_vente']]);

// Configuration du magasin (vous pouvez adapter selon vos besoins)
$store_config = [
    'name' => APP_NAME ?? 'Mon Magasin',
    'address' => ADRESSE ?? 'Adresse du magasin',
    'phone' => PHONE ?? '+257 XX XX XX XX',
    'email' => 'contact@magasin.bi',
    'website' => 'www.magasin.bi'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reçu - <?= htmlspecialchars($ticketNumber) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
            background: #fff;
            max-width: 80mm;
            margin: 0 auto;
            padding: 10px;
        }
        
        .receipt {
            width: 100%;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .store-name {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .store-info {
            font-size: 10px;
            color: #555;
        }
        
        .section {
            margin: 10px 0;
            padding: 5px 0;
        }
        
        .section-title {
            font-weight: bold;
            text-transform: uppercase;
            border-bottom: 1px solid #ccc;
            padding-bottom: 2px;
            margin-bottom: 5px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        
        .items-table th,
        .items-table td {
            text-align: left;
            padding: 3px 2px;
            border-bottom: 1px dotted #ccc;
        }
        
        .items-table th {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .total-section {
            border-top: 2px solid #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 14px;
            margin: 5px 0;
        }
        
        .payment-info {
            border-top: 1px solid #ccc;
            border-bottom: 1px solid #ccc;
            padding: 8px 0;
            margin: 10px 0;
        }
        
        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 10px;
            color: #555;
        }
        
        .barcode {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 10px;
            margin: 10px 0;
            letter-spacing: 2px;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 5px;
            }
            
            .no-print {
                display: none !important;
            }
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .print-button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">
        🖨️ Imprimer
    </button>

    <div class="receipt">
        <!-- En-tête du magasin -->
        <div class="header">
            <div class="store-name"><?= htmlspecialchars($store_config['name']) ?></div>
            <div class="store-info">
                <?= htmlspecialchars($store_config['address']) ?><br>
                Tél: <?= htmlspecialchars($store_config['phone']) ?><br>
                <?= htmlspecialchars($store_config['email']) ?>
            </div>
        </div>

        <!-- Informations de la vente -->
        <div class="section">
            <div class="info-row">
                <span>Ticket N°:</span>
                <span><strong><?= htmlspecialchars($ticketNumber) ?></strong></span>
            </div>
            <div class="info-row">
                <span>Date:</span>
                <span><?= date('d/m/Y H:i', strtotime($sale['date_vente'])) ?></span>
            </div>
            <div class="info-row">
                <span>Vendeur:</span>
                <span><?= htmlspecialchars($sale['vendeur_prenom'] . ' ' . $sale['vendeur_nom']) ?></span>
            </div>
            <?php if ($sale['nom_client'] || $sale['prenom_client']): ?>
            <div class="info-row">
                <span>Client:</span>
                <span><?= htmlspecialchars(trim($sale['prenom_client'] . ' ' . $sale['nom_client'])) ?></span>
            </div>
            <?php if ($sale['client_telephone']): ?>
            <div class="info-row">
                <span>Tél Client:</span>
                <span><?= htmlspecialchars($sale['client_telephone']) ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Articles vendus -->
        <div class="section">
            <div class="section-title">Articles</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Article</th>
                        <th>Qté</th>
                        <th>P.U</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_articles = 0;
                    foreach ($details as $item): 
                        $total_articles += $item['quantite'];
                    ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($item['nom_produit']) ?>
                            <?php if ($item['code_barre']): ?>
                            <br><small><?= htmlspecialchars($item['code_barre']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $item['quantite'] ?></td>
                        <td><?= number_format($item['prix_vente_unitaire'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($item['sous_total'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totaux -->
        <div class="total-section">
            <div class="info-row">
                <span>Nombre d'articles:</span>
                <span><?= $total_articles ?></span>
            </div>
            <div class="total-row">
                <span>TOTAL À PAYER:</span>
                <span><?= number_format($sale['montant_total_ttc'], 0, ',', ' ') ?> BIF</span>
            </div>
        </div>

        <!-- Informations de paiement -->
        <div class="payment-info">
            <div class="info-row">
                <span>Mode de paiement:</span>
                <span>
                    <?php 
                    $payment_methods = [
                        'especes' => 'Espèces',
                        'carte' => 'Carte bancaire',
                        'mobile' => 'Mobile Money',
                        'cheque' => 'Chèque',
                        'credit' => 'Crédit'
                    ];
                    echo htmlspecialchars($payment_methods[$sale['mode_paiement']] ?? ucfirst($sale['mode_paiement']));
                    ?>
                </span>
            </div>
            <?php if ($sale['mode_paiement'] === 'especes'): ?>
            <div class="info-row">
                <span>Montant reçu:</span>
                <span><?= number_format($sale['montant_paye'], 0, ',', ' ') ?> BIF</span>
            </div>
            <?php if ($sale['montant_rendu'] > 0): ?>
            <div class="info-row">
                <span>Monnaie rendue:</span>
                <span><?= number_format($sale['montant_rendu'], 0, ',', ' ') ?> BIF</span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Code-barres du ticket (simulé) -->
        <div class="barcode">
            |||| | |||| || | |||| | ||||<br>
            <?= htmlspecialchars($ticketNumber) ?>
        </div>

        <!-- Pied de page -->
        <div class="footer">
            <div style="margin: 10px 0;">
                ================================
            </div>
            <div>
                <strong>MERCI DE VOTRE VISITE</strong><br>
                À bientôt !
            </div>
            <div style="margin: 10px 0;">
                Service client: <?= htmlspecialchars($store_config['phone']) ?><br>
                <?= htmlspecialchars($store_config['website']) ?>
            </div>
            <div style="margin-top: 15px; font-size: 9px;">
                Ticket généré le <?= date('d/m/Y à H:i:s') ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-print après chargement
        window.onload = function() {
            // Optionnel : impression automatique
            // setTimeout(() => window.print(), 1000);
        }
        
        // Fermer la fenêtre après impression
        window.onafterprint = function() {
            // Optionnel : fermer automatiquement
            // window.close();
        }
    </script>
</body>
</html>