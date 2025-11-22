-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : mer. 15 oct. 2025 à 21:14
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `gestion_boutique_quartier`
--

-- --------------------------------------------------------

--
-- Structure de la table `achats`
--

CREATE TABLE `achats` (
  `id_achat` int(11) NOT NULL,
  `numero_facture` varchar(50) DEFAULT NULL,
  `id_utilisateur` int(11) NOT NULL,
  `id_fournisseur` int(11) DEFAULT NULL,
  `date_achat` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_livraison_prevue` date DEFAULT NULL,
  `date_creation` datetime NOT NULL,
  `montant_total_ht` decimal(12,2) DEFAULT 0.00,
  `montant_tva` decimal(10,2) DEFAULT 0.00,
  `montant_total_ttc` decimal(12,2) DEFAULT 0.00,
  `statut` enum('en_attente','validee','livree','annulee') DEFAULT 'en_attente',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `achats`
--

INSERT INTO `achats` (`id_achat`, `numero_facture`, `id_utilisateur`, `id_fournisseur`, `date_achat`, `date_livraison_prevue`, `date_creation`, `montant_total_ht`, `montant_tva`, `montant_total_ttc`, `statut`, `notes`) VALUES
(2, 'FACT-001', 1, 2, '2025-08-15 22:00:00', '2025-08-16', '2025-08-16 14:08:20', 450000.00, 0.00, 450000.00, 'validee', 'Riz tanzanienne');

--
-- Déclencheurs `achats`
--
DELIMITER $$
CREATE TRIGGER `trigger_tresorerie_achat` AFTER INSERT ON `achats` FOR EACH ROW BEGIN
    IF NEW.statut = 'validee' AND NEW.montant_total_ttc > 0 THEN
        INSERT INTO tresorerie (type_mouvement, categorie, montant, mode_paiement, reference_operation, description, id_utilisateur)
        VALUES ('sortie', 'achat', NEW.montant_total_ttc, 'especes', 
                CONCAT('ACHAT_', NEW.id_achat), CONCAT('Achat facture #', NEW.numero_facture), NEW.id_utilisateur);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `categories`
--

CREATE TABLE `categories` (
  `id_categorie` int(11) NOT NULL,
  `nom_categorie` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `statut` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `categories`
--

INSERT INTO `categories` (`id_categorie`, `nom_categorie`, `description`, `date_creation`, `statut`) VALUES
(1, 'Alimentaire', 'HFDJHDSJKK', '2025-08-16 10:04:03', 'active');

-- --------------------------------------------------------

--
-- Structure de la table `clients`
--

CREATE TABLE `clients` (
  `id_client` int(11) NOT NULL,
  `nom_client` varchar(100) DEFAULT NULL,
  `prenom_client` varchar(100) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `adresse` text DEFAULT NULL,
  `type_client` enum('particulier','entreprise','credit') DEFAULT 'particulier',
  `limite_credit` decimal(10,2) DEFAULT 0.00,
  `solde_compte` decimal(10,2) DEFAULT 0.00,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `statut` enum('actif','inactif','bloque') DEFAULT 'actif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `clients`
--

INSERT INTO `clients` (`id_client`, `nom_client`, `prenom_client`, `telephone`, `email`, `adresse`, `type_client`, `limite_credit`, `solde_compte`, `date_creation`, `statut`) VALUES
(1, 'YANTEYITEKA', 'Génifa', '+257 67554411', 'geneifayanteyiteka@gmail.com', 'KIRIRI', 'particulier', 10000.00, 0.00, '2025-08-16 13:08:15', 'actif'),
(2, 'NZAMBIMANA', 'Janvier', '+25762407719', 'janviernzambimana91@gmail.com', 'BUJUMBURA', 'credit', 25000.00, 0.00, '2025-08-16 16:27:38', 'actif'),
(3, 'Gyslene', 'KAMARIZA', '+257 62407717', 'estellanduwimana@gmail.com', 'KIRIRI', 'credit', 50000.00, 0.00, '2025-08-20 12:06:07', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `details_achats`
--

CREATE TABLE `details_achats` (
  `id_detail_achat` int(11) NOT NULL,
  `id_achat` int(11) NOT NULL,
  `id_produit` int(11) NOT NULL,
  `quantite_commandee` int(11) NOT NULL,
  `quantite_recue` int(11) DEFAULT 0,
  `prix_achat_unitaire` decimal(10,2) NOT NULL,
  `montant_ligne` decimal(12,2) GENERATED ALWAYS AS (`quantite_commandee` * `prix_achat_unitaire`) STORED,
  `date_peremption` date DEFAULT NULL,
  `numero_lot` varchar(50) DEFAULT NULL,
  `total_ligne` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `details_achats`
--

INSERT INTO `details_achats` (`id_detail_achat`, `id_achat`, `id_produit`, `quantite_commandee`, `quantite_recue`, `prix_achat_unitaire`, `date_peremption`, `numero_lot`, `total_ligne`) VALUES
(1, 2, 1, 100, 100, 4500.00, NULL, NULL, 450000);

--
-- Déclencheurs `details_achats`
--
DELIMITER $$
CREATE TRIGGER `trigger_achat_stock` AFTER UPDATE ON `details_achats` FOR EACH ROW BEGIN
    -- Si la quantité reçue a changé et que l'achat est livré
    IF NEW.quantite_recue != OLD.quantite_recue AND 
       (SELECT statut FROM achats WHERE id_achat = NEW.id_achat) = 'livree' THEN
        
        -- Augmenter le stock
        UPDATE stocks 
        SET quantite_actuelle = quantite_actuelle + (NEW.quantite_recue - OLD.quantite_recue),
            date_derniere_entree = NOW()
        WHERE id_produit = NEW.id_produit;
        
        -- Enregistrer le mouvement de stock
        INSERT INTO mouvements_stock (id_produit, type_mouvement, quantite, prix_unitaire, reference_operation, motif, id_utilisateur)
        SELECT NEW.id_produit, 'entree', (NEW.quantite_recue - OLD.quantite_recue), NEW.prix_achat_unitaire,
               CONCAT('ACHAT_', NEW.id_achat), 'Réception marchandise', a.id_utilisateur
        FROM achats a WHERE a.id_achat = NEW.id_achat;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `details_ventes`
--

CREATE TABLE `details_ventes` (
  `id_detail_vente` int(11) NOT NULL,
  `id_vente` int(11) NOT NULL,
  `id_produit` int(11) NOT NULL,
  `quantite` int(11) NOT NULL,
  `prix_vente_unitaire` decimal(10,2) NOT NULL,
  `prix_achat_unitaire` decimal(10,2) DEFAULT NULL,
  `montant_ligne` decimal(12,2) GENERATED ALWAYS AS (`quantite` * `prix_vente_unitaire`) STORED,
  `marge_ligne` decimal(12,2) GENERATED ALWAYS AS (case when `prix_achat_unitaire` is not null then (`prix_vente_unitaire` - `prix_achat_unitaire`) * `quantite` else 0 end) STORED,
  `remise` decimal(5,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `details_ventes`
--

INSERT INTO `details_ventes` (`id_detail_vente`, `id_vente`, `id_produit`, `quantite`, `prix_vente_unitaire`, `prix_achat_unitaire`, `remise`) VALUES
(1, 1, 1, 3, 5000.00, 4500.00, 0.00),
(2, 2, 1, 2, 5000.00, 4500.00, 0.00),
(3, 3, 1, 1, 5000.00, 4500.00, 0.00),
(4, 4, 1, 1, 5000.00, 4500.00, 0.00),
(5, 5, 1, 1, 5000.00, 4500.00, 0.00),
(6, 6, 1, 1, 5000.00, 4500.00, 0.00),
(7, 7, 1, 1, 5000.00, 4500.00, 0.00),
(8, 8, 1, 1, 5000.00, 4500.00, 0.00),
(9, 9, 1, 1, 5000.00, 4500.00, 0.00),
(10, 10, 1, 1, 5000.00, 4500.00, 0.00),
(11, 11, 1, 1, 5000.00, 4500.00, 0.00),
(12, 12, 1, 1, 5000.00, 4500.00, 0.00),
(13, 13, 1, 1, 5000.00, 4500.00, 0.00),
(14, 14, 1, 1, 5000.00, 4500.00, 0.00),
(15, 15, 1, 1, 5000.00, 4500.00, 0.00),
(16, 16, 1, 1, 5000.00, 4500.00, 0.00),
(17, 17, 1, 3, 5000.00, 4500.00, 0.00),
(18, 18, 1, 2, 5000.00, 4500.00, 0.00);

--
-- Déclencheurs `details_ventes`
--
DELIMITER $$
CREATE TRIGGER `trigger_vente_stock` AFTER INSERT ON `details_ventes` FOR EACH ROW BEGIN
    -- Vérifier si la vente est validée
    IF (SELECT statut FROM ventes WHERE id_vente = NEW.id_vente) = 'validee' THEN
        -- Diminuer le stock
        UPDATE stocks 
        SET quantite_actuelle = quantite_actuelle - NEW.quantite,
            date_derniere_sortie = NOW()
        WHERE id_produit = NEW.id_produit;
        
        -- Enregistrer le mouvement de stock
        INSERT INTO mouvements_stock (id_produit, type_mouvement, quantite, prix_unitaire, reference_operation, motif, id_utilisateur)
        SELECT NEW.id_produit, 'sortie', NEW.quantite, NEW.prix_vente_unitaire, 
               CONCAT('VENTE_', NEW.id_vente), 'Vente produit', v.id_utilisateur
        FROM ventes v WHERE v.id_vente = NEW.id_vente;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `fournisseurs`
--

CREATE TABLE `fournisseurs` (
  `id_fournisseur` int(11) NOT NULL,
  `nom_fournisseur` varchar(100) NOT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `adresse` text DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `statut` enum('actif','inactif') DEFAULT 'actif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `fournisseurs`
--

INSERT INTO `fournisseurs` (`id_fournisseur`, `nom_fournisseur`, `contact`, `telephone`, `email`, `adresse`, `date_creation`, `statut`) VALUES
(1, 'NZAMBIMANA Janvier', 'KABURA CLAUDE', '62407719', 'janviernzambimana91@gmail.com', 'BUJUMBURA', '2025-08-16 10:08:56', 'actif'),
(2, 'IRADUKUNDA Ismael', 'KABURA CLAUDE', '62407819', 'iradukundaismael01@gmail.com', 'BUJUMBURA', '2025-08-16 10:10:57', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `logs_activites`
--

CREATE TABLE `logs_activites` (
  `id_log` int(11) NOT NULL,
  `id_utilisateur` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `date_action` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `logs_connexion`
--

CREATE TABLE `logs_connexion` (
  `id` int(11) NOT NULL,
  `id_utilisateur` int(11) DEFAULT NULL,
  `ip_adresse` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `date_connexion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `logs_connexion`
--

INSERT INTO `logs_connexion` (`id`, `id_utilisateur`, `ip_adresse`, `user_agent`, `date_connexion`) VALUES
(1, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-16 22:53:00'),
(2, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-17 05:32:13'),
(3, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-17 06:36:51'),
(4, 2, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-17 11:04:35'),
(5, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-17 16:46:26'),
(6, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-08-20 09:10:30'),
(7, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-08-31 05:34:24'),
(8, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-09 03:01:10'),
(9, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-09 03:04:50'),
(10, 2, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-09 03:12:01'),
(11, 2, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-09 03:22:40'),
(12, 2, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-09 03:28:21');

-- --------------------------------------------------------

--
-- Structure de la table `mouvements_stock`
--

CREATE TABLE `mouvements_stock` (
  `id_mouvement` int(11) NOT NULL,
  `id_produit` int(11) NOT NULL,
  `type_mouvement` enum('entree','sortie','ajustement','perte','transfert') NOT NULL,
  `quantite` int(11) NOT NULL,
  `prix_unitaire` decimal(10,2) DEFAULT NULL,
  `reference_operation` varchar(100) DEFAULT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `id_utilisateur` int(11) NOT NULL,
  `date_mouvement` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `mouvements_stock`
--

INSERT INTO `mouvements_stock` (`id_mouvement`, `id_produit`, `type_mouvement`, `quantite`, `prix_unitaire`, `reference_operation`, `motif`, `id_utilisateur`, `date_mouvement`) VALUES
(1, 1, 'entree', 100, 4500.00, '2', 'Réception achat', 1, '2025-08-16 12:11:28'),
(2, 1, 'sortie', 3, 5000.00, 'VENTE_1', 'Vente produit', 1, '2025-08-16 13:09:57'),
(3, 1, 'sortie', 3, 5000.00, 'VENTE-T20250816-7712', 'Vente', 1, '2025-08-16 13:09:58'),
(4, 1, 'sortie', 2, 5000.00, 'VENTE_2', 'Vente produit', 1, '2025-08-16 16:03:23'),
(5, 1, 'sortie', 2, 5000.00, 'VENTE-T20250816-5772', 'Vente', 1, '2025-08-16 16:03:24'),
(6, 1, 'sortie', 1, 5000.00, 'VENTE_3', 'Vente produit', 1, '2025-08-16 16:17:16'),
(7, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-9849', 'Vente', 1, '2025-08-16 16:17:16'),
(8, 1, 'sortie', 1, 5000.00, 'VENTE_4', 'Vente produit', 1, '2025-08-16 17:25:15'),
(9, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-9400', 'Vente', 1, '2025-08-16 17:25:18'),
(10, 1, 'sortie', 1, 5000.00, 'VENTE_5', 'Vente produit', 1, '2025-08-16 18:43:07'),
(11, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-8606', 'Vente', 1, '2025-08-16 18:43:07'),
(12, 1, 'sortie', 1, 5000.00, 'VENTE_6', 'Vente produit', 1, '2025-08-16 18:46:33'),
(13, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-5378', 'Vente', 1, '2025-08-16 18:46:33'),
(14, 1, 'sortie', 1, 5000.00, 'VENTE_7', 'Vente produit', 1, '2025-08-16 18:46:45'),
(15, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-8958', 'Vente', 1, '2025-08-16 18:46:45'),
(16, 1, 'sortie', 1, 5000.00, 'VENTE_8', 'Vente produit', 1, '2025-08-16 18:47:19'),
(17, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-0671', 'Vente', 1, '2025-08-16 18:47:19'),
(18, 1, 'sortie', 1, 5000.00, 'VENTE_9', 'Vente produit', 1, '2025-08-16 18:49:25'),
(19, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-9699', 'Vente', 1, '2025-08-16 18:49:25'),
(20, 1, 'sortie', 1, 5000.00, 'VENTE_10', 'Vente produit', 1, '2025-08-16 18:49:59'),
(21, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-5429', 'Vente', 1, '2025-08-16 18:49:59'),
(22, 1, 'sortie', 1, 5000.00, 'VENTE_11', 'Vente produit', 1, '2025-08-16 18:58:53'),
(23, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-7495', 'Vente', 1, '2025-08-16 18:58:54'),
(24, 1, 'sortie', 1, 5000.00, 'VENTE_12', 'Vente produit', 1, '2025-08-16 19:45:06'),
(25, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-2586', 'Vente', 1, '2025-08-16 19:45:06'),
(26, 1, 'sortie', 1, 5000.00, 'VENTE_13', 'Vente produit', 1, '2025-08-16 20:00:12'),
(27, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-2710', 'Vente', 1, '2025-08-16 20:00:12'),
(28, 1, 'sortie', 1, 5000.00, 'VENTE_14', 'Vente produit', 1, '2025-08-16 20:02:07'),
(29, 1, 'sortie', 1, 5000.00, 'VENTE-T20250816-7132', 'Vente', 1, '2025-08-16 20:02:07'),
(30, 1, 'sortie', 1, 5000.00, 'VENTE_15', 'Vente produit', 1, '2025-08-17 16:47:11'),
(31, 1, 'sortie', 1, 5000.00, 'VENTE-T20250817-7111', 'Vente', 1, '2025-08-17 16:47:12'),
(32, 1, 'sortie', 1, 5000.00, 'VENTE_16', 'Vente produit', 1, '2025-08-20 09:15:32'),
(33, 1, 'sortie', 1, 5000.00, 'VENTE-T20250820-1924', 'Vente', 1, '2025-08-20 09:15:32'),
(34, 1, 'sortie', 3, 5000.00, 'VENTE_17', 'Vente produit', 1, '2025-08-20 12:07:14'),
(35, 1, 'sortie', 3, 5000.00, 'VENTE-T20250820-3560', 'Vente', 1, '2025-08-20 12:07:14'),
(36, 1, 'sortie', 2, 5000.00, 'VENTE_18', 'Vente produit', 2, '2025-09-03 07:36:27'),
(37, 1, 'sortie', 2, 5000.00, 'VENTE-T20250903-7865', 'Vente', 2, '2025-09-03 07:36:27');

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

CREATE TABLE `notifications` (
  `id_notification` int(11) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `destinataire_role` varchar(50) DEFAULT NULL,
  `destinataire_id` int(11) DEFAULT NULL,
  `statut` enum('lu','non_lu') DEFAULT 'non_lu',
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_lecture` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `parametres_systeme`
--

CREATE TABLE `parametres_systeme` (
  `id_parametre` int(11) NOT NULL,
  `cle_parametre` varchar(100) NOT NULL,
  `valeur_parametre` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type_valeur` enum('text','number','boolean','json') DEFAULT 'text',
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `produits`
--

CREATE TABLE `produits` (
  `id_produit` int(11) NOT NULL,
  `nom_produit` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `code_barre` varchar(50) DEFAULT NULL,
  `prix_achat` decimal(10,2) NOT NULL DEFAULT 0.00,
  `prix_vente` decimal(10,2) NOT NULL,
  `marge_beneficiaire` decimal(5,2) DEFAULT NULL,
  `stock_minimum` int(11) DEFAULT 0,
  `stock_maximum` int(11) DEFAULT NULL,
  `unite_mesure` varchar(20) DEFAULT 'pièce',
  `id_categorie` int(11) NOT NULL,
  `id_fournisseur` int(11) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `statut` enum('actif','inactif','rupture') DEFAULT 'actif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `produits`
--

INSERT INTO `produits` (`id_produit`, `nom_produit`, `description`, `code_barre`, `prix_achat`, `prix_vente`, `marge_beneficiaire`, `stock_minimum`, `stock_maximum`, `unite_mesure`, `id_categorie`, `id_fournisseur`, `date_creation`, `date_modification`, `statut`) VALUES
(1, 'Riz', 'hhhhhhhhhhhhhhhhhhhhhhhhhhhh', 'RIZ/001-BUR', 4500.00, 5000.00, 11.11, 20, NULL, 'kg', 1, 2, '2025-08-16 10:15:28', '2025-08-20 11:53:08', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `stocks`
--

CREATE TABLE `stocks` (
  `id_stock` int(11) NOT NULL,
  `id_produit` int(11) NOT NULL,
  `quantite_actuelle` int(11) NOT NULL DEFAULT 0,
  `quantite_reservee` int(11) DEFAULT 0,
  `quantite_disponible` int(11) GENERATED ALWAYS AS (`quantite_actuelle` - `quantite_reservee`) VIRTUAL,
  `valeur_stock` decimal(12,2) DEFAULT 0.00,
  `date_derniere_entree` timestamp NULL DEFAULT NULL,
  `date_derniere_sortie` timestamp NULL DEFAULT NULL,
  `date_mise_a_jour` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `stocks`
--

INSERT INTO `stocks` (`id_stock`, `id_produit`, `quantite_actuelle`, `quantite_reservee`, `valeur_stock`, `date_derniere_entree`, `date_derniere_sortie`, `date_mise_a_jour`) VALUES
(1, 1, 52, 0, 234000.00, '2025-08-16 12:11:28', '2025-09-03 07:36:27', '2025-09-03 07:36:27');

--
-- Déclencheurs `stocks`
--
DELIMITER $$
CREATE TRIGGER `trigger_calcul_valeur_stock` BEFORE INSERT ON `stocks` FOR EACH ROW BEGIN
    DECLARE prix_achat DECIMAL(10,2);
    
    SELECT p.prix_achat INTO prix_achat 
    FROM produits p 
    WHERE p.id_produit = NEW.id_produit;
    
    SET NEW.valeur_stock = NEW.quantite_actuelle * IFNULL(prix_achat, 0);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trigger_update_valeur_stock` BEFORE UPDATE ON `stocks` FOR EACH ROW BEGIN
    DECLARE prix_achat DECIMAL(10,2);
    
    SELECT p.prix_achat INTO prix_achat 
    FROM produits p 
    WHERE p.id_produit = NEW.id_produit;
    
    SET NEW.valeur_stock = NEW.quantite_actuelle * IFNULL(prix_achat, 0);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `tentatives_connexion_echouees`
--

CREATE TABLE `tentatives_connexion_echouees` (
  `id` int(11) NOT NULL,
  `nom_utilisateur` varchar(100) DEFAULT NULL,
  `ip_adresse` varchar(45) DEFAULT NULL,
  `date_tentative` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `tentatives_connexion_echouees`
--

INSERT INTO `tentatives_connexion_echouees` (`id`, `nom_utilisateur`, `ip_adresse`, `date_tentative`) VALUES
(1, 'Genifa', '::1', '2025-09-09 03:00:38'),
(2, 'Desire', '::1', '2025-09-09 03:00:55'),
(3, 'Genifa', '::1', '2025-09-09 03:04:27');

-- --------------------------------------------------------

--
-- Structure de la table `tresorerie`
--

CREATE TABLE `tresorerie` (
  `id_mouvement` int(11) NOT NULL,
  `type_mouvement` enum('entree','sortie') NOT NULL,
  `categorie` enum('vente','achat','frais','salaire','autre') NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `mode_paiement` enum('especes','carte','mobile','cheque','virement') DEFAULT 'especes',
  `reference_operation` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `id_utilisateur` int(11) NOT NULL,
  `date_mouvement` timestamp NOT NULL DEFAULT current_timestamp(),
  `valide` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `tresorerie`
--

INSERT INTO `tresorerie` (`id_mouvement`, `type_mouvement`, `categorie`, `montant`, `mode_paiement`, `reference_operation`, `description`, `id_utilisateur`, `date_mouvement`, `valide`) VALUES
(1, 'entree', 'vente', 15000.00, 'especes', 'VENTE_1', 'Vente ticket #T20250816-7712', 1, '2025-08-16 13:09:55', 1),
(2, 'entree', 'vente', 15000.00, 'especes', 'VENTE-T20250816-7712', 'Vente ticket: T20250816-7712', 1, '2025-08-16 13:09:58', 1),
(3, 'entree', 'vente', 10000.00, 'especes', 'VENTE_2', 'Vente ticket #T20250816-5772', 1, '2025-08-16 16:03:22', 1),
(4, 'entree', 'vente', 10000.00, 'especes', 'VENTE-T20250816-5772', 'Vente ticket: T20250816-5772', 1, '2025-08-16 16:03:24', 1),
(5, 'entree', 'vente', 5000.00, 'especes', 'VENTE_3', 'Vente ticket #T20250816-9849', 1, '2025-08-16 16:17:16', 1),
(6, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-9849', 'Vente ticket: T20250816-9849', 1, '2025-08-16 16:17:16', 1),
(7, 'entree', 'vente', 5000.00, 'especes', 'VENTE_4', 'Vente ticket #T20250816-9400', 1, '2025-08-16 17:25:15', 1),
(8, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-9400', 'Vente ticket: T20250816-9400', 1, '2025-08-16 17:25:18', 1),
(9, 'entree', 'vente', 5000.00, 'especes', 'VENTE_5', 'Vente ticket #T20250816-8606', 1, '2025-08-16 18:43:07', 1),
(10, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-8606', 'Vente ticket: T20250816-8606', 1, '2025-08-16 18:43:07', 1),
(11, 'entree', 'vente', 5000.00, 'especes', 'VENTE_6', 'Vente ticket #T20250816-5378', 1, '2025-08-16 18:46:33', 1),
(12, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-5378', 'Vente ticket: T20250816-5378', 1, '2025-08-16 18:46:33', 1),
(13, 'entree', 'vente', 5000.00, 'especes', 'VENTE_7', 'Vente ticket #T20250816-8958', 1, '2025-08-16 18:46:45', 1),
(14, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-8958', 'Vente ticket: T20250816-8958', 1, '2025-08-16 18:46:45', 1),
(15, 'entree', 'vente', 5000.00, 'especes', 'VENTE_8', 'Vente ticket #T20250816-0671', 1, '2025-08-16 18:47:18', 1),
(16, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-0671', 'Vente ticket: T20250816-0671', 1, '2025-08-16 18:47:19', 1),
(17, 'entree', 'vente', 5000.00, 'especes', 'VENTE_9', 'Vente ticket #T20250816-9699', 1, '2025-08-16 18:49:25', 1),
(18, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-9699', 'Vente ticket: T20250816-9699', 1, '2025-08-16 18:49:25', 1),
(19, 'entree', 'vente', 5000.00, 'especes', 'VENTE_10', 'Vente ticket #T20250816-5429', 1, '2025-08-16 18:49:59', 1),
(20, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-5429', 'Vente ticket: T20250816-5429', 1, '2025-08-16 18:49:59', 1),
(21, 'entree', 'vente', 5000.00, 'especes', 'VENTE_11', 'Vente ticket #T20250816-7495', 1, '2025-08-16 18:58:53', 1),
(22, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-7495', 'Vente ticket: T20250816-7495', 1, '2025-08-16 18:58:54', 1),
(23, 'entree', 'vente', 5000.00, 'especes', 'VENTE_12', 'Vente ticket #T20250816-2586', 1, '2025-08-16 19:45:06', 1),
(24, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-2586', 'Vente ticket: T20250816-2586', 1, '2025-08-16 19:45:06', 1),
(25, 'entree', 'vente', 5000.00, 'especes', 'VENTE_13', 'Vente ticket #T20250816-2710', 1, '2025-08-16 20:00:12', 1),
(26, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-2710', 'Vente ticket: T20250816-2710', 1, '2025-08-16 20:00:12', 1),
(27, 'entree', 'vente', 5000.00, 'especes', 'VENTE_14', 'Vente ticket #T20250816-7132', 1, '2025-08-16 20:02:06', 1),
(28, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250816-7132', 'Vente ticket: T20250816-7132', 1, '2025-08-16 20:02:07', 1),
(29, 'entree', 'vente', 5000.00, 'especes', 'VENTE_15', 'Vente ticket #T20250817-7111', 1, '2025-08-17 16:47:11', 1),
(30, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250817-7111', 'Vente ticket: T20250817-7111', 1, '2025-08-17 16:47:12', 1),
(31, 'entree', 'vente', 5000.00, 'especes', 'VENTE_16', 'Vente ticket #T20250820-1924', 1, '2025-08-20 09:15:32', 1),
(32, 'entree', 'vente', 5000.00, 'especes', 'VENTE-T20250820-1924', 'Vente ticket: T20250820-1924', 1, '2025-08-20 09:15:32', 1),
(33, 'entree', 'vente', 15000.00, 'especes', 'VENTE_17', 'Vente ticket #T20250820-3560', 1, '2025-08-20 12:07:14', 1),
(34, 'entree', 'vente', 15000.00, 'especes', 'VENTE-T20250820-3560', 'Vente ticket: T20250820-3560', 1, '2025-08-20 12:07:14', 1),
(35, 'entree', 'vente', 10000.00, 'especes', 'VENTE_18', 'Vente ticket #T20250903-7865', 2, '2025-09-03 07:36:27', 1),
(36, 'entree', 'vente', 10000.00, 'especes', 'VENTE-T20250903-7865', 'Vente ticket: T20250903-7865', 2, '2025-09-03 07:36:27', 1);

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

CREATE TABLE `utilisateurs` (
  `id_utilisateur` int(11) NOT NULL,
  `nom_utilisateur` varchar(50) NOT NULL,
  `mot_de_passe` text NOT NULL,
  `role` enum('proprietaire','vendeur') NOT NULL DEFAULT 'vendeur',
  `email` text NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `statut` enum('actif','inactif') DEFAULT 'actif',
  `reset_token` text NOT NULL,
  `reset_expires` text NOT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `derniere_connexion` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id_utilisateur`, `nom_utilisateur`, `mot_de_passe`, `role`, `email`, `nom`, `prenom`, `telephone`, `statut`, `reset_token`, `reset_expires`, `date_creation`, `derniere_connexion`) VALUES
(1, 'Desire', '$2y$10$PWXuY2GwtiqSiEEl4L3XEumlTArTEEpsh0sba1VjGDq6gmc9nPLue', 'proprietaire', '', 'NSABIMANA', 'Désiré', '61571726', 'actif', '', '', '2025-08-15 17:51:56', '2025-08-15 20:00:52'),
(2, 'Genifa', '$2y$10$3nzf1vUFzAn6WpFcY3HbT.2SDBDAu9FHIIPiSEpyT6Ea8QO4V8EG2', 'vendeur', '', 'YANTEYITEKA', 'Génifa', '69885511', 'actif', '', '', '2025-08-17 11:01:15', '0000-00-00 00:00:00');

-- --------------------------------------------------------

--
-- Structure de la table `ventes`
--

CREATE TABLE `ventes` (
  `id_vente` int(11) NOT NULL,
  `numero_ticket` varchar(50) DEFAULT NULL,
  `id_utilisateur` int(11) NOT NULL,
  `id_client` int(11) DEFAULT NULL,
  `date_vente` timestamp NOT NULL DEFAULT current_timestamp(),
  `montant_total_ht` decimal(12,2) DEFAULT 0.00,
  `montant_tva` decimal(10,2) DEFAULT 0.00,
  `montant_total_ttc` decimal(12,2) DEFAULT 0.00,
  `montant_paye` decimal(10,2) DEFAULT 0.00,
  `montant_rendu` decimal(10,2) DEFAULT 0.00,
  `mode_paiement` enum('especes','carte','mobile','credit','cheque') DEFAULT 'especes',
  `statut` enum('en_cours','validee','annulee','retournee') DEFAULT 'en_cours',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `ventes`
--

INSERT INTO `ventes` (`id_vente`, `numero_ticket`, `id_utilisateur`, `id_client`, `date_vente`, `montant_total_ht`, `montant_tva`, `montant_total_ttc`, `montant_paye`, `montant_rendu`, `mode_paiement`, `statut`, `notes`) VALUES
(1, 'T20250816-7712', 1, 1, '2025-08-16 13:09:55', 0.00, 0.00, 30000.00, 30000.00, 0.00, 'especes', 'validee', NULL),
(2, 'T20250816-5772', 1, 1, '2025-08-16 16:03:22', 0.00, 0.00, 20000.00, 23000.00, 3000.00, 'especes', 'validee', NULL),
(3, 'T20250816-9849', 1, 1, '2025-08-16 16:17:16', 0.00, 0.00, 10000.00, 10000.00, 0.00, 'especes', 'validee', NULL),
(4, 'T20250816-9400', 1, 2, '2025-08-16 17:25:15', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(5, 'T20250816-8606', 1, 2, '2025-08-16 18:43:07', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(6, 'T20250816-5378', 1, 1, '2025-08-16 18:46:33', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(7, 'T20250816-8958', 1, 1, '2025-08-16 18:46:45', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(8, 'T20250816-0671', 1, 1, '2025-08-16 18:47:18', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(9, 'T20250816-9699', 1, 2, '2025-08-16 18:49:25', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(10, 'T20250816-5429', 1, 1, '2025-08-16 18:49:59', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(11, 'T20250816-7495', 1, 2, '2025-08-16 18:58:53', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(12, 'T20250816-2586', 1, 1, '2025-08-16 19:45:06', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(13, 'T20250816-2710', 1, 2, '2025-08-16 20:00:12', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(14, 'T20250816-7132', 1, 1, '2025-08-16 20:02:06', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(15, 'T20250817-7111', 1, 2, '2025-08-17 16:47:11', 0.00, 0.00, 5000.00, 5000.00, 0.00, 'especes', 'validee', NULL),
(16, 'T20250820-1924', 1, 1, '2025-08-20 09:15:32', 0.00, 0.00, 5000.00, 10000.00, 5000.00, 'especes', 'validee', NULL),
(17, 'T20250820-3560', 1, 3, '2025-08-20 12:07:14', 0.00, 0.00, 15000.00, 20000.00, 5000.00, 'especes', 'validee', NULL),
(18, 'T20250903-7865', 2, 3, '2025-09-03 07:36:27', 0.00, 0.00, 10000.00, 20000.00, 10000.00, 'especes', 'validee', NULL);

--
-- Déclencheurs `ventes`
--
DELIMITER $$
CREATE TRIGGER `trigger_tresorerie_vente` AFTER INSERT ON `ventes` FOR EACH ROW BEGIN
    IF NEW.statut = 'validee' AND NEW.montant_total_ttc > 0 THEN
        INSERT INTO tresorerie (type_mouvement, categorie, montant, mode_paiement, reference_operation, description, id_utilisateur)
        VALUES ('entree', 'vente', NEW.montant_total_ttc, NEW.mode_paiement, 
                CONCAT('VENTE_', NEW.id_vente), CONCAT('Vente ticket #', NEW.numero_ticket), NEW.id_utilisateur);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Doublure de structure pour la vue `v_dashboard_stats`
-- (Voir ci-dessous la vue réelle)
--
CREATE TABLE `v_dashboard_stats` (
`total_produits` bigint(21)
,`produits_stock_faible` bigint(21)
,`valeur_stock_total` decimal(34,2)
,`ventes_jour` bigint(21)
,`ca_jour` decimal(34,2)
,`recettes_jour` decimal(34,2)
,`depenses_jour` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Structure de la vue `v_dashboard_stats`
--
DROP TABLE IF EXISTS `v_dashboard_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_dashboard_stats`  AS SELECT (select count(0) from `produits` where `produits`.`statut` = 'actif') AS `total_produits`, (select count(0) from (`produits` `p` join `stocks` `s` on(`p`.`id_produit` = `s`.`id_produit`)) where `s`.`quantite_actuelle` <= `p`.`stock_minimum` and `p`.`statut` = 'actif') AS `produits_stock_faible`, (select sum(`s`.`valeur_stock`) from (`stocks` `s` join `produits` `p` on(`s`.`id_produit` = `p`.`id_produit`)) where `p`.`statut` = 'actif') AS `valeur_stock_total`, (select count(0) from `ventes` where cast(`ventes`.`date_vente` as date) = curdate() and `ventes`.`statut` = 'validee') AS `ventes_jour`, (select coalesce(sum(`ventes`.`montant_total_ttc`),0) from `ventes` where cast(`ventes`.`date_vente` as date) = curdate() and `ventes`.`statut` = 'validee') AS `ca_jour`, (select coalesce(sum(`tresorerie`.`montant`),0) from `tresorerie` where `tresorerie`.`type_mouvement` = 'entree' and cast(`tresorerie`.`date_mouvement` as date) = curdate()) AS `recettes_jour`, (select coalesce(sum(`tresorerie`.`montant`),0) from `tresorerie` where `tresorerie`.`type_mouvement` = 'sortie' and cast(`tresorerie`.`date_mouvement` as date) = curdate()) AS `depenses_jour` ;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `achats`
--
ALTER TABLE `achats`
  ADD PRIMARY KEY (`id_achat`),
  ADD KEY `idx_utilisateur_achat` (`id_utilisateur`),
  ADD KEY `idx_fournisseur_achat` (`id_fournisseur`),
  ADD KEY `idx_date_achat` (`date_achat`);

--
-- Index pour la table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id_categorie`);

--
-- Index pour la table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id_client`);

--
-- Index pour la table `details_achats`
--
ALTER TABLE `details_achats`
  ADD PRIMARY KEY (`id_detail_achat`),
  ADD KEY `idx_achat` (`id_achat`),
  ADD KEY `idx_produit_detail_achat` (`id_produit`);

--
-- Index pour la table `details_ventes`
--
ALTER TABLE `details_ventes`
  ADD PRIMARY KEY (`id_detail_vente`),
  ADD KEY `idx_vente` (`id_vente`),
  ADD KEY `idx_produit_detail_vente` (`id_produit`);

--
-- Index pour la table `fournisseurs`
--
ALTER TABLE `fournisseurs`
  ADD PRIMARY KEY (`id_fournisseur`);

--
-- Index pour la table `logs_activites`
--
ALTER TABLE `logs_activites`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `idx_utilisateur` (`id_utilisateur`),
  ADD KEY `idx_date` (`date_action`);

--
-- Index pour la table `logs_connexion`
--
ALTER TABLE `logs_connexion`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `mouvements_stock`
--
ALTER TABLE `mouvements_stock`
  ADD PRIMARY KEY (`id_mouvement`),
  ADD KEY `idx_produit_mouvement` (`id_produit`),
  ADD KEY `idx_utilisateur_mouvement` (`id_utilisateur`),
  ADD KEY `idx_date_mouvement_stock` (`date_mouvement`),
  ADD KEY `idx_type_mouvement` (`type_mouvement`);

--
-- Index pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id_notification`),
  ADD KEY `idx_destinataire` (`destinataire_role`,`destinataire_id`),
  ADD KEY `idx_statut` (`statut`);

--
-- Index pour la table `parametres_systeme`
--
ALTER TABLE `parametres_systeme`
  ADD PRIMARY KEY (`id_parametre`),
  ADD UNIQUE KEY `cle_parametre` (`cle_parametre`);

--
-- Index pour la table `produits`
--
ALTER TABLE `produits`
  ADD PRIMARY KEY (`id_produit`),
  ADD UNIQUE KEY `code_barre` (`code_barre`),
  ADD KEY `idx_categorie` (`id_categorie`),
  ADD KEY `idx_fournisseur` (`id_fournisseur`),
  ADD KEY `idx_code_barre` (`code_barre`);

--
-- Index pour la table `stocks`
--
ALTER TABLE `stocks`
  ADD PRIMARY KEY (`id_stock`),
  ADD UNIQUE KEY `uk_produit` (`id_produit`);

--
-- Index pour la table `tentatives_connexion_echouees`
--
ALTER TABLE `tentatives_connexion_echouees`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `tresorerie`
--
ALTER TABLE `tresorerie`
  ADD PRIMARY KEY (`id_mouvement`),
  ADD KEY `idx_utilisateur_tresorerie` (`id_utilisateur`),
  ADD KEY `idx_date_mouvement` (`date_mouvement`),
  ADD KEY `idx_type_categorie` (`type_mouvement`,`categorie`);

--
-- Index pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD PRIMARY KEY (`id_utilisateur`),
  ADD UNIQUE KEY `nom_utilisateur` (`nom_utilisateur`);

--
-- Index pour la table `ventes`
--
ALTER TABLE `ventes`
  ADD PRIMARY KEY (`id_vente`),
  ADD KEY `idx_utilisateur_vente` (`id_utilisateur`),
  ADD KEY `idx_client_vente` (`id_client`),
  ADD KEY `idx_date_vente` (`date_vente`),
  ADD KEY `idx_numero_ticket` (`numero_ticket`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `achats`
--
ALTER TABLE `achats`
  MODIFY `id_achat` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `categories`
--
ALTER TABLE `categories`
  MODIFY `id_categorie` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `clients`
--
ALTER TABLE `clients`
  MODIFY `id_client` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `details_achats`
--
ALTER TABLE `details_achats`
  MODIFY `id_detail_achat` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `details_ventes`
--
ALTER TABLE `details_ventes`
  MODIFY `id_detail_vente` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT pour la table `fournisseurs`
--
ALTER TABLE `fournisseurs`
  MODIFY `id_fournisseur` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `logs_activites`
--
ALTER TABLE `logs_activites`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `logs_connexion`
--
ALTER TABLE `logs_connexion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT pour la table `mouvements_stock`
--
ALTER TABLE `mouvements_stock`
  MODIFY `id_mouvement` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id_notification` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `parametres_systeme`
--
ALTER TABLE `parametres_systeme`
  MODIFY `id_parametre` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `produits`
--
ALTER TABLE `produits`
  MODIFY `id_produit` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `stocks`
--
ALTER TABLE `stocks`
  MODIFY `id_stock` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `tentatives_connexion_echouees`
--
ALTER TABLE `tentatives_connexion_echouees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `tresorerie`
--
ALTER TABLE `tresorerie`
  MODIFY `id_mouvement` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `id_utilisateur` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `ventes`
--
ALTER TABLE `ventes`
  MODIFY `id_vente` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `achats`
--
ALTER TABLE `achats`
  ADD CONSTRAINT `fk_achat_fournisseur` FOREIGN KEY (`id_fournisseur`) REFERENCES `fournisseurs` (`id_fournisseur`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_achat_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id_utilisateur`);

--
-- Contraintes pour la table `details_achats`
--
ALTER TABLE `details_achats`
  ADD CONSTRAINT `fk_detail_achat_achat` FOREIGN KEY (`id_achat`) REFERENCES `achats` (`id_achat`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_detail_achat_produit` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`) ON DELETE CASCADE;

--
-- Contraintes pour la table `details_ventes`
--
ALTER TABLE `details_ventes`
  ADD CONSTRAINT `fk_detail_vente_produit` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_detail_vente_vente` FOREIGN KEY (`id_vente`) REFERENCES `ventes` (`id_vente`) ON DELETE CASCADE;

--
-- Contraintes pour la table `mouvements_stock`
--
ALTER TABLE `mouvements_stock`
  ADD CONSTRAINT `fk_mouvement_produit` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_mouvement_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id_utilisateur`);

--
-- Contraintes pour la table `produits`
--
ALTER TABLE `produits`
  ADD CONSTRAINT `fk_produit_categorie` FOREIGN KEY (`id_categorie`) REFERENCES `categories` (`id_categorie`),
  ADD CONSTRAINT `fk_produit_fournisseur` FOREIGN KEY (`id_fournisseur`) REFERENCES `fournisseurs` (`id_fournisseur`) ON DELETE SET NULL;

--
-- Contraintes pour la table `stocks`
--
ALTER TABLE `stocks`
  ADD CONSTRAINT `fk_stock_produit` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`) ON DELETE CASCADE;

--
-- Contraintes pour la table `tresorerie`
--
ALTER TABLE `tresorerie`
  ADD CONSTRAINT `fk_tresorerie_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id_utilisateur`);

--
-- Contraintes pour la table `ventes`
--
ALTER TABLE `ventes`
  ADD CONSTRAINT `fk_vente_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id_client`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vente_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id_utilisateur`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
