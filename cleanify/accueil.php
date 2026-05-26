<?php
session_start();
require_once 'config.php';

// Rediriger vers login si pas connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$nom_utilisateur = $_SESSION['user_nom'];
$role            = $_SESSION['user_role'];

// --- Stat 1 : nombre de ventes aujourd'hui ---
$res_ventes = mysqli_query($conn,
    "SELECT COUNT(*) AS nb FROM ventes WHERE DATE(date_vente) = CURDATE()"
);
$nb_ventes_aujourd_hui = mysqli_fetch_assoc($res_ventes)['nb'];

// --- Stat 2 : chiffre d'affaires aujourd'hui ---
$res_ca = mysqli_query($conn,
    "SELECT SUM(total) AS ca FROM ventes WHERE DATE(date_vente) = CURDATE()"
);
$ca_aujourd_hui = mysqli_fetch_assoc($res_ca)['ca'] ?? 0;

// --- Stat 3 : nombre de clients ---
$res_clients = mysqli_query($conn,
    "SELECT COUNT(*) AS nb FROM users WHERE role = 'client'"
);
$nb_clients = mysqli_fetch_assoc($res_clients)['nb'];

// --- Stat 4 : produits en alerte de stock ---
$res_alertes = mysqli_query($conn,
    "SELECT COUNT(*) AS nb FROM produits WHERE stock <= seuil_alerte"
);
$nb_alertes = mysqli_fetch_assoc($res_alertes)['nb'];

// --- 5 dernières ventes ---
$res_recentes = mysqli_query($conn,
    "SELECT v.id, v.date_vente, v.total,
            CONCAT(c.prenom, ' ', c.nom) AS client_nom,
            CONCAT(a.prenom, ' ', a.nom) AS agent_nom
     FROM ventes v
     LEFT JOIN users c ON v.client_id = c.id
     LEFT JOIN users a ON v.agent_id  = a.id
     ORDER BY v.date_vente DESC
     LIMIT 5"
);

// --- Produits en alerte de stock ---
$res_stock = mysqli_query($conn,
    "SELECT nom, stock, seuil_alerte, categorie
     FROM produits
     WHERE stock <= seuil_alerte
     ORDER BY stock ASC
     LIMIT 6"
);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Cleanify - Tableau de bord</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="dashboard-layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <img src="https://cleanifyleb.com/cdn/shop/files/Untitled_design_66.jpg" alt="Cleanify Logo">
            <h1>Cleanify</h1>
        </div>

        <nav>
            <a href="accueil.php" class="active">
                <span class="icon">🏠</span> Tableau de bord
            </a>
            <a href="produits.php">
                <span class="icon">📦</span> Produits
            </a>
            <a href="clients.php">
                <span class="icon">👥</span> Clients
            </a>
            <a href="ventes.php">
                <span class="icon">🛒</span> Ventes
            </a>
            <a href="stats.php">
                <span class="icon">📊</span> Statistiques
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="deconnexion.php">
                <span>🚪</span> Déconnexion
            </a>
        </div>
    </aside>

    <!-- ===== CONTENU PRINCIPAL ===== -->
    <main class="main-content">

        <!-- Barre supérieure -->
        <div class="topbar">
            <h2>Tableau de bord</h2>
            <div class="user-info">
                <span>👋 Bonjour, <?php echo htmlspecialchars($nom_utilisateur); ?></span>
                <span class="badge-role"><?php echo htmlspecialchars($role); ?></span>
            </div>
        </div>

        <!-- Cartes de statistiques -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon green">🛒</div>
                <div class="stat-info">
                    <h3><?php echo $nb_ventes_aujourd_hui; ?></h3>
                    <p>Ventes aujourd'hui</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue">💰</div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($ca_aujourd_hui, 2); ?></h3>
                    <p>Chiffre d'affaires</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">👥</div>
                <div class="stat-info">
                    <h3><?php echo $nb_clients; ?></h3>
                    <p>Clients enregistrés</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red">⚠️</div>
                <div class="stat-info">
                    <h3><?php echo $nb_alertes; ?></h3>
                    <p>Alertes de stock</p>
                </div>
            </div>
        </div>

        <!-- Grille 2 colonnes : ventes récentes + alertes stock -->
        <div class="two-col-grid">

            <!-- Dernières ventes -->
            <div class="card">
                <div class="card-header">
                    <h3>🛒 Dernières ventes</h3>
                    <a href="ventes.php" class="btn btn-secondary">Voir tout</a>
                </div>
                <?php if (mysqli_num_rows($res_recentes) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Client</th>
                            <th>Agent</th>
                            <th>Total</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($vente = mysqli_fetch_assoc($res_recentes)): ?>
                        <tr>
                            <td>#<?php echo $vente['id']; ?></td>
                            <td><?php echo $vente['client_nom'] ? htmlspecialchars($vente['client_nom']) : '<span class="badge badge-info">Anonyme</span>'; ?></td>
                            <td><?php echo htmlspecialchars($vente['agent_nom']); ?></td>
                            <td><strong>$<?php echo number_format($vente['total'], 2); ?></strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($vente['date_vente'])); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">🛒</div>
                    <p>Aucune vente enregistrée pour l'instant.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Alertes de stock -->
            <div class="card">
                <div class="card-header">
                    <h3>⚠️ Alertes de stock</h3>
                    <a href="produits.php" class="btn btn-secondary">Gérer</a>
                </div>
                <?php if (mysqli_num_rows($res_stock) > 0): ?>
                    <?php while ($produit = mysqli_fetch_assoc($res_stock)): ?>
                    <div class="alert-stock">
                        <div class="produit-info">
                            <strong><?php echo htmlspecialchars($produit['nom']); ?></strong>
                            <span><?php echo htmlspecialchars($produit['categorie']); ?></span>
                        </div>
                        <?php if ($produit['stock'] == 0): ?>
                            <span class="badge badge-danger">Rupture</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Stock: <?php echo $produit['stock']; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <p>Tous les stocks sont suffisants.</p>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>
</body>
</html>
