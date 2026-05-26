-- ============================================================
-- Cleanify - Base de données complète
-- Système intelligent de gestion des ventes
-- ============================================================

DROP DATABASE IF EXISTS cleanify_db;
CREATE DATABASE cleanify_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cleanify_db;

-- ============================================================
-- TABLE: users
-- Tous les utilisateurs : admin, agent, client
-- ============================================================
CREATE TABLE users (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    nom              VARCHAR(100)  NOT NULL,
    prenom           VARCHAR(100)  NOT NULL,
    email            VARCHAR(150)  NOT NULL UNIQUE,
    mot_de_passe     VARCHAR(255)  NOT NULL,
    role             ENUM('admin','agent','client') NOT NULL DEFAULT 'client',
    telephone        VARCHAR(20)   DEFAULT NULL,
    adresse          VARCHAR(255)  DEFAULT NULL,
    points_fidelite  INT           NOT NULL DEFAULT 0,
    date_inscription DATETIME      DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: produits
-- Catalogue des produits + stock + alertes
-- ============================================================
CREATE TABLE produits (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nom            VARCHAR(150)  NOT NULL,
    description    TEXT          DEFAULT NULL,
    prix           DECIMAL(10,2) NOT NULL,
    stock          INT           NOT NULL DEFAULT 0,
    seuil_alerte   INT           NOT NULL DEFAULT 5,
    categorie      VARCHAR(100)  DEFAULT NULL,
    image_url      VARCHAR(255)  DEFAULT NULL
);

-- ============================================================
-- TABLE: ventes
-- En-tête de chaque vente
-- ============================================================
CREATE TABLE ventes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT           DEFAULT NULL,
    agent_id    INT           DEFAULT NULL,
    date_vente  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    total       DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (agent_id)  REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE: vente_produits
-- Détail des produits par vente
-- ============================================================
CREATE TABLE vente_produits (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    vente_id       INT           NOT NULL,
    produit_id     INT           NOT NULL,
    quantite       INT           NOT NULL,
    prix_unitaire  DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (vente_id)   REFERENCES ventes(id)   ON DELETE CASCADE,
    FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE RESTRICT
);

-- ============================================================
-- UTILISATEURS PAR DÉFAUT
-- ============================================================

-- Administrateur  |  mot de passe : admin123
INSERT INTO users (nom, prenom, email, mot_de_passe, role) VALUES
('Admin', 'Cleanify', 'admin@cleanify.com', MD5('admin123'), 'admin');

-- Agent de vente  |  mot de passe : agent123
INSERT INTO users (nom, prenom, email, mot_de_passe, role) VALUES
('Dupont', 'Jean', 'agent@cleanify.com', MD5('agent123'), 'agent');

-- Client exemple  |  mot de passe : client123
INSERT INTO users (nom, prenom, email, mot_de_passe, role, telephone, adresse, points_fidelite) VALUES
('Martin', 'Sophie', 'client@cleanify.com', MD5('client123'), 'client', '06 12 34 56 78', '12 Rue de la Paix, Paris', 50);

-- ============================================================
-- PRODUITS CLEANIFY (données réelles du site)
-- ============================================================
INSERT INTO produits (nom, description, prix, stock, seuil_alerte, categorie, image_url) VALUES
('7-in-1 Cleaning Kit',         'Complete cleaning kit for all your devices. Available in Black, Pink, Blue, Dark Green.',  13.49,  50,  10, 'Kits',          'https://cleanifyleb.com/cdn/shop/files/8.jpg'),
('Disposable Toilet Seat Cover','Pack of 10 individually sealed covers. Compact and hygienic.',                              9.49,   80,  15, 'Hygiene',       'https://cleanifyleb.com/cdn/shop/files/1stpic.jpg'),
('Microfiber Cloths',           'Reusable microfiber cloths for screens and lenses.',                                        0.99,  200,  20, 'Cloths',        'https://cleanifyleb.com/cdn/shop/files/2.jpg'),
('Apple Superfiber Cloths',     'Premium superfiber cloths, Apple-style quality.',                                           1.99,  150,  20, 'Cloths',        'https://cleanifyleb.com/cdn/shop/files/1.jpg'),
('Spray Bottle 5ml',            'Pocket-sized spray bottle, perfect for on the go.',                                         0.99,  120,  15, 'Spray Bottles', 'https://cleanifyleb.com/cdn/shop/files/Untitled_design_68_231b9bec-726e-44cc-9cb4-58a6577c5e55.jpg'),
('Spray Bottle 30ml',           'Compact spray bottle for daily use.',                                                       4.25,  100,  15, 'Spray Bottles', 'https://cleanifyleb.com/cdn/shop/files/3.jpg'),
('Spray Bottle 60ml',           'Larger spray bottle for home or office.',                                                   5.49,   90,  15, 'Spray Bottles', 'https://cleanifyleb.com/cdn/shop/files/3.jpg'),
('Clean Eyewear Kit',           'Bundle for eyewear cleaning — cloth + spray.',                                              8.97,   40,   8, 'Bundles',       'https://cleanifyleb.com/cdn/shop/files/Picture2.png'),
('Everyday Clear Kit',          'All-in-one daily cleaning bundle.',                                                        16.48,   30,   8, 'Bundles',       'https://cleanifyleb.com/cdn/shop/files/Picture1.png');
