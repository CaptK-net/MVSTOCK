<?php
session_start();
require_once 'config.php';

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$nom_utilisateur = $_SESSION['user_nom'];
$role            = $_SESSION['user_role'];

// --- Stat 1: number of sales today ---
$res = mysqli_query($conn,
    "SELECT COUNT(*) AS nb FROM vente WHERE DATE(date_vente) = CURDATE()"
);
$nb_ventes_aujourd_hui = mysqli_fetch_assoc($res)['nb'];

// --- Stat 2: revenue today ---
$res = mysqli_query($conn,
    "SELECT SUM(montant_total) AS ca FROM vente WHERE DATE(date_vente) = CURDATE()"
);
// SUM() returns NULL if there are no sales — we use a ternary instead of ?? (PHP 5.6 compatible)
$row_ca = mysqli_fetch_assoc($res);
$ca_aujourd_hui = $row_ca['ca'] ? $row_ca['ca'] : 0;

// --- Stat 3: total number of clients ---
$res = mysqli_query($conn, "SELECT COUNT(*) AS nb FROM client");
$nb_clients = mysqli_fetch_assoc($res)['nb'];

// --- Stat 4: products below stock alert threshold ---
$res = mysqli_query($conn,
    "SELECT COUNT(*) AS nb FROM produit WHERE stock_actuel <= seuil_alerte"
);
$nb_alertes = mysqli_fetch_assoc($res)['nb'];

// --- Last 5 sales (joined with client and utilisateur for names) ---
$res_recentes = mysqli_query($conn,
    "SELECT v.id_vente, v.date_vente, v.montant_total, v.mode_paiement,
            CONCAT(c.prenom, ' ', c.nom) AS client_nom,
            CONCAT(u.prenom, ' ', u.nom) AS agent_nom
     FROM vente v
     LEFT JOIN client      c ON v.id_client      = c.id_client
     LEFT JOIN utilisateur u ON v.id_utilisateur = u.id_utilisateur
     ORDER BY v.date_vente DESC
     LIMIT 5"
);

// --- Products below stock threshold ---
$res_stock = mysqli_query($conn,
    "SELECT p.designation, p.stock_actuel, p.seuil_alerte, c.nom_categorie
     FROM produit p
     LEFT JOIN categorie c ON p.id_categorie = c.id_categorie
     WHERE p.stock_actuel <= p.seuil_alerte
     ORDER BY p.stock_actuel ASC
     LIMIT 6"
);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Tableau de bord</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="dashboard-layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <h1 style="font-size:1.4rem;">🏪 GestVente</h1>
            <p style="color:#64748B; font-size:0.78rem;">Gestion des ventes</p>
        </div>
        <nav>
            <a href="accueil.php" class="active"><span class="icon">🏠</span> Tableau de bord</a>
            <a href="produits.php"><span class="icon">📦</span> Produits</a>
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
        </nav>
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <div class="topbar">
            <h2>Tableau de bord</h2>
            <div class="user-info">
                <span>👋 Bonjour, <?php echo htmlspecialchars($nom_utilisateur); ?></span>
                <span class="badge-role"><?php echo htmlspecialchars($role); ?></span>
            </div>
        </div>

        <!-- Stat cards -->
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

        <!-- Two columns: recent sales + stock alerts -->
        <div class="two-col-grid">

            <!-- Recent sales -->
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
                    <?php while ($v = mysqli_fetch_assoc($res_recentes)): ?>
                        <tr>
                            <td>#<?php echo $v['id_vente']; ?></td>
                            <td>
                                <?php echo $v['client_nom']
                                    ? htmlspecialchars($v['client_nom'])
                                    : '<span class="badge badge-info">Anonyme</span>'; ?>
                            </td>
                            <td><?php echo htmlspecialchars($v['agent_nom']); ?></td>
                            <td><strong>$<?php echo number_format($v['montant_total'], 2); ?></strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($v['date_vente'])); ?></td>
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

            <!-- Stock alerts -->
            <div class="card">
                <div class="card-header">
                    <h3>⚠️ Alertes de stock</h3>
                    <a href="produits.php" class="btn btn-secondary">Gérer</a>
                </div>
                <?php if (mysqli_num_rows($res_stock) > 0): ?>
                    <?php while ($p = mysqli_fetch_assoc($res_stock)): ?>
                    <div class="alert-stock">
                        <div class="produit-info">
                            <strong><?php echo htmlspecialchars($p['designation']); ?></strong>
                            <span><?php echo htmlspecialchars($p['nom_categorie'] ? $p['nom_categorie'] : '—'); ?></span>
                        </div>
                        <?php if ($p['stock_actuel'] == 0): ?>
                            <span class="badge badge-danger">Rupture</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Stock: <?php echo $p['stock_actuel']; ?></span>
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
