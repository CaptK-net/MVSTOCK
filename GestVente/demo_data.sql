-- ============================================================
-- DONNÉES DE DÉMONSTRATION — GestVente
-- À importer APRÈS cleanify_db.sql (ne recrée pas la base)
-- Données basées sur la boutique Cleanify Lebanon
-- ============================================================

USE cleanify_db;

-- ============================================================
-- 1. CATÉGORIES
-- ============================================================
INSERT INTO categorie (nom_categorie, description) VALUES
('Kits',          'Kits de nettoyage complets pour appareils'),
('Hygiène',       'Produits d\'hygiène jetables'),
('Chiffons',      'Chiffons microfibre et superfibre'),
('Spray Bottles', 'Flacons spray de différentes tailles'),
('Bundles',       'Ensembles et packs promotionnels');

-- ============================================================
-- 2. AGENT (en plus de l'admin déjà existant)
-- Email : agent@gestvente.com | Mot de passe : agent123
-- ============================================================
INSERT INTO utilisateur (nom, prenom, email, mot_de_passe, role) VALUES
('Dupont', 'Jean', 'agent@gestvente.com', MD5('agent123'), 'agent');

-- ============================================================
-- 3. PRODUITS CLEANIFY
-- Le stock est déjà au niveau final (après toutes les ventes)
-- 3 produits volontairement bas pour démontrer les alertes
-- ============================================================
INSERT INTO produit (designation, description, prix_unitaire, stock_actuel, seuil_alerte, id_categorie) VALUES
('7-in-1 Cleaning Kit',         'Kit complet pour tous vos appareils. Disponible en noir, rose, bleu, vert.',  13.49, 12,  10, 1),
('Disposable Toilet Seat Cover','Pack de 10 couvercles emballés individuellement. Compacts et hygiéniques.',    9.49,  3,  10, 2),
('Microfiber Cloths',           'Chiffons microfibre réutilisables pour écrans et lentilles.',                  0.99, 45,  20, 3),
('Apple Superfiber Cloths',     'Chiffons superfibre premium, qualité Apple.',                                  1.99, 22,  20, 3),
('Spray Bottle 5ml',            'Flacon spray de poche, parfait pour les déplacements.',                        0.99, 25,  15, 4),
('Spray Bottle 30ml',           'Flacon spray compact pour usage quotidien.',                                   4.25,  8,  10, 4),
('Spray Bottle 60ml',           'Grand flacon spray pour la maison ou le bureau.',                              5.49, 18,  10, 4),
('Clean Eyewear Kit',           'Bundle lunettes — chiffon + spray.',                                           8.97,  0,   5, 5),
('Everyday Clear Kit',          'Bundle de nettoyage quotidien tout-en-un.',                                   16.48, 14,   8, 5);

-- ============================================================
-- 4. CLIENTS
-- Points de fidélité calculés d'après l'historique des ventes
-- ============================================================
INSERT INTO client (nom, prenom, telephone, email, points_fidelite) VALUES
('Martin',  'Sophie', '06 12 34 56 78', 'sophie.martin@email.com', 49),
('Haddad',  'Karim',  '07 23 45 67 89', 'karim.haddad@email.com',  27),
('Dupont',  'Marie',  '06 34 56 78 90', 'marie.dupont@email.com',  55),
('Khalil',  'Ahmed',  '07 45 67 89 01', 'ahmed.khalil@email.com',  15);

-- ============================================================
-- 5. HISTORIQUE DES VENTES
-- Réparties sur le mois pour que les statistiques soient riches
-- id_client : 1=Sophie  2=Karim  3=Marie  4=Ahmed  NULL=Anonyme
-- id_utilisateur : 1=Admin  2=Agent Jean
-- ============================================================

-- ── Il y a 21 jours ────────────────────────────────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(DATE_SUB(NOW(), INTERVAL 21 DAY), 32.47, 'carte', 1, 1);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(1, 13.49, 1, 1),   -- 7-in-1 Cleaning Kit
(2,  9.49, 1, 2);   -- Disposable Toilet Seat Cover x2

-- ── Il y a 14 jours ────────────────────────────────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(DATE_SUB(NOW(), INTERVAL 14 DAY), 19.45, 'especes', 3, 2);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(1, 16.48, 2, 9),   -- Everyday Clear Kit
(3,  0.99, 2, 3);   -- Microfiber Cloths x3

-- ── Il y a 10 jours ────────────────────────────────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(DATE_SUB(NOW(), INTERVAL 10 DAY), 11.47, 'especes', 2, 1);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(2, 4.25, 3, 6),    -- Spray Bottle 30ml x2
(3, 0.99, 3, 5);    -- Spray Bottle 5ml x3

-- ── Il y a 7 jours (vente anonyme) ─────────────────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(DATE_SUB(NOW(), INTERVAL 7 DAY), 8.97, 'carte', NULL, 2);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(1, 8.97, 4, 8);    -- Clean Eyewear Kit

-- ── Il y a 5 jours ─────────────────────────────────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(DATE_SUB(NOW(), INTERVAL 5 DAY), 15.47, 'virement', 4, 1);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(1, 13.49, 5, 1),   -- 7-in-1 Cleaning Kit
(2,  0.99, 5, 3);   -- Microfiber Cloths x2

-- ── Il y a 3 jours ─────────────────────────────────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(DATE_SUB(NOW(), INTERVAL 3 DAY), 16.95, 'carte', 3, 2);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(3, 1.99, 6, 4),    -- Apple Superfiber Cloths x3
(2, 5.49, 6, 7);    -- Spray Bottle 60ml x2

-- ── Hier ───────────────────────────────────────────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(DATE_SUB(NOW(), INTERVAL 1 DAY), 17.47, 'especes', 1, 1);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(1, 13.49, 7, 1),   -- 7-in-1 Cleaning Kit
(2,  1.99, 7, 4);   -- Apple Superfiber Cloths x2

-- ── Aujourd'hui — Vente 1 (pour le dashboard) ──────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(NOW(), 20.73, 'carte', 3, 2);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(1, 16.48, 8, 9),   -- Everyday Clear Kit
(1,  4.25, 8, 6);   -- Spray Bottle 30ml

-- ── Aujourd'hui — Vente 2 (pour le dashboard) ──────────────
INSERT INTO vente (date_vente, montant_total, mode_paiement, id_client, id_utilisateur) VALUES
(NOW(), 16.46, 'especes', 2, 1);
INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit) VALUES
(1, 13.49, 9, 1),   -- 7-in-1 Cleaning Kit
(3,  0.99, 9, 3);   -- Microfiber Cloths x3

-- ============================================================
-- Résumé de ce que la démo va afficher :
--
-- Dashboard  → 2 ventes aujourd'hui | CA $37.19 | 4 clients | 3 alertes stock
-- Produits   → 3 badges : Rupture (Eyewear Kit) | Stock bas (Toilet Cover, Spray 30ml)
-- Clients    → 4 clients avec points fidélité (Marie = meilleure cliente)
-- Ventes     → 9 ventes avec détails, modes de paiement variés
-- Stats      → Top produit : 7-in-1 Kit | Top client : Marie Dupont
-- ============================================================
