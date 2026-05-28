-- ============================================================
-- Système de Gestion des Ventes
-- Base de données — Modèle relationnel
-- ============================================================

DROP DATABASE IF EXISTS cleanify_db;
CREATE DATABASE cleanify_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cleanify_db;

-- ============================================================
-- TABLE: categorie
-- Stocke les catégories de produits (ex: Nettoyants, Hygiene...)
-- Un produit appartient à une catégorie (0,1)
-- Une catégorie contient plusieurs produits (0,n)
-- ============================================================
CREATE TABLE categorie (
    id_categorie  INT AUTO_INCREMENT PRIMARY KEY,
    nom_categorie VARCHAR(100) NOT NULL,
    description   TEXT DEFAULT NULL
);

-- ============================================================
-- TABLE: utilisateur
-- Les personnes qui se connectent au système : admin et agent
-- CLIENT est une table séparée — les clients ne se connectent PAS
-- ============================================================
CREATE TABLE utilisateur (
    id_utilisateur INT AUTO_INCREMENT PRIMARY KEY,
    nom            VARCHAR(100) NOT NULL,
    prenom         VARCHAR(100) NOT NULL,
    email          VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe   VARCHAR(255) NOT NULL,
    role           ENUM('admin', 'agent') NOT NULL DEFAULT 'agent',
    date_creation  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: client
-- Les clients de la boutique — ils n'ont PAS de compte de connexion
-- Ils sont enregistrés dans le système par un utilisateur
-- ============================================================
CREATE TABLE client (
    id_client       INT AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(100) NOT NULL,
    prenom          VARCHAR(100) NOT NULL,
    telephone       VARCHAR(20)  DEFAULT NULL,
    adresse         VARCHAR(255) DEFAULT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    date_inscription DATETIME    DEFAULT CURRENT_TIMESTAMP,
    points_fidelite  INT         NOT NULL DEFAULT 0
);

-- ============================================================
-- TABLE: produit
-- Catalogue des produits à vendre
-- Appartient à une catégorie (#id_categorie)
-- ============================================================
CREATE TABLE produit (
    id_produit    INT AUTO_INCREMENT PRIMARY KEY,
    designation   VARCHAR(150)  NOT NULL,
    description   TEXT          DEFAULT NULL,
    prix_unitaire DECIMAL(10,2) NOT NULL,
    stock_actuel  INT           NOT NULL DEFAULT 0,
    seuil_alerte  INT           NOT NULL DEFAULT 5,
    date_ajout    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    id_categorie  INT           DEFAULT NULL,
    FOREIGN KEY (id_categorie) REFERENCES categorie(id_categorie) ON DELETE SET NULL
);

-- ============================================================
-- TABLE: vente
-- Enregistre chaque vente effectuée
-- Liée à un client (#id_client) et à l'agent qui a vendu (#id_utilisateur)
-- mode_paiement : especes, carte ou virement
-- ============================================================
CREATE TABLE vente (
    id_vente       INT AUTO_INCREMENT PRIMARY KEY,
    date_vente     DATETIME      DEFAULT CURRENT_TIMESTAMP,
    montant_total  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    mode_paiement  ENUM('especes', 'carte', 'virement') NOT NULL DEFAULT 'especes',
    id_client      INT           DEFAULT NULL,
    id_utilisateur INT           NOT NULL,
    FOREIGN KEY (id_client)      REFERENCES client(id_client)           ON DELETE SET NULL,
    FOREIGN KEY (id_utilisateur) REFERENCES utilisateur(id_utilisateur) ON DELETE RESTRICT
);

-- ============================================================
-- TABLE: ligne_vente
-- Détail des produits pour chaque vente (relation Concerne)
-- Une vente peut contenir plusieurs produits
-- ============================================================
CREATE TABLE ligne_vente (
    id_ligne      INT AUTO_INCREMENT PRIMARY KEY,
    quantite      INT           NOT NULL,
    prix_unitaire DECIMAL(10,2) NOT NULL,
    id_vente      INT           NOT NULL,
    id_produit    INT           NOT NULL,
    FOREIGN KEY (id_vente)   REFERENCES vente(id_vente)     ON DELETE CASCADE,
    FOREIGN KEY (id_produit) REFERENCES produit(id_produit) ON DELETE RESTRICT
);

-- ============================================================
-- TABLE: journal_connexion
-- Enregistre chaque connexion et déconnexion du système
-- Liée à l'utilisateur qui s'est connecté (#id_utilisateur)
-- ============================================================
CREATE TABLE journal_connexion (
    id_journal      INT AUTO_INCREMENT PRIMARY KEY,
    date_connexion  DATETIME    DEFAULT CURRENT_TIMESTAMP,
    action          VARCHAR(50) NOT NULL,
    adresse_ip      VARCHAR(45) DEFAULT NULL,
    id_utilisateur  INT         DEFAULT NULL,
    FOREIGN KEY (id_utilisateur) REFERENCES utilisateur(id_utilisateur) ON DELETE SET NULL
);

-- ============================================================
-- DONNÉES PAR DÉFAUT
-- Un seul compte admin pour démarrer — mot de passe : admin123
-- Aucun produit hardcodé : chaque entreprise ajoute les siens
-- ============================================================
INSERT INTO utilisateur (nom, prenom, email, mot_de_passe, role) VALUES
('Admin', 'Système', 'admin@systeme.com', MD5('admin123'), 'admin');
