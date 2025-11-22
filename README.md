# GestionBoutique
Application web complète pour la gestion quotidienne d'une boutique de quartier. Permet de gérer facilement les ventes, les stocks, les clients, les fournisseurs et de suivre la rentabilité de votre commerce.

Gestion des Produits

Enregistrement des produits avec codes-barres
Catégorisation et classification
Gestion des prix (achat, vente, promotion)
Photos de produits
Suivi des dates de péremption

 Point de Vente (POS)

Interface rapide et intuitive
Scan de codes-barres
Vente au comptant et à crédit
Calcul automatique de la monnaie
Impression de tickets de caisse
Gestion des remises

 Gestion des Stocks

Suivi en temps réel des quantités
Alertes de rupture de stock
Historique des mouvements
Inventaire et réajustement
Gestion des entrées/sorties

 Gestion des Clients

Fichier client avec historique d'achats
Gestion des crédits clients
Suivi des paiements
Programme de fidélité (points)
Relances automatiques

 Gestion des Fournisseurs

Base de données fournisseurs
Gestion des commandes
Suivi des factures
Historique des achats
Gestion des dettes

 Gestion Financière

Caisse journalière
Suivi des dépenses
Rapports de ventes
Statistiques de rentabilité
Bilan mensuel/annuel

 Rapports et Statistiques

Tableau de bord en temps réel
Produits les plus vendus
Chiffre d'affaires par période
Analyse des marges
Export PDF/Excel

Gestion des Utilisateurs

Multi-utilisateurs (Gérant, Vendeur, Caissier)
Contrôle d'accès par rôle
Historique des opérations
Logs d'activités



 Installation
Prérequis
Logiciels nécessaires :

Serveur web local (XAMPP, WAMP, MAMP)
PHP 7.4 ou supérieur
MySQL 8.0 ou supérieur
Navigateur web moderne

Extensions PHP requises :
iniextension=pdo
extension=pdo_mysql
extension=mbstring
extension=gd
extension=json

Étapes d'installation
1. Télécharger le projet
bash# Cloner le dépôt
git clone https://github.com/Expendadeur/GestionBoutique.git

# Ou télécharger le ZIP depuis GitHub
2. Placer dans le serveur
Copiez le dossier dans votre répertoire serveur :

XAMPP : C:\xampp\htdocs\GestionBoutique
WAMP : C:\wamp64\www\GestionBoutique
MAMP : /Applications/MAMP/htdocs/GestionBoutique
Linux : /var/www/html/GestionBoutique

3. Créer la base de données
Option A : Via phpMyAdmin

Ouvrez phpMyAdmin : http://localhost/phpmyadmin
Cliquez sur "Nouvelle base de données"
Nom : boutique_db
Interclassement : utf8mb4_unicode_ci
Cliquez sur "Créer"
Sélectionnez la base créée
Onglet "Importer" → Choisir le fichier database/gestion_boutique_quartier.sql
Cliquez sur "Exécuter"
