<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Period selector — default is monthly
$periode = isset($_GET['periode']) ? $_GET['periode'] : 'mois';

// French month names array (index 1 to 12)
$mois_noms = [
    1=>'Janvier', 2=>'Février',  3=>'Mars',      4=>'Avril',
    5=>'Mai',     6=>'Juin',     7=>'Juillet',   8=>'Août',
    9=>'Septembre', 10=>'Octobre', 11=>'Novembre', 12=>'Décembre'
];

// ── SUMMARY CARDS (all-time) ───────────────────────────────

$res = mysqli_query($conn, "SELECT COUNT(*) AS nb FROM vente");
$total_ventes = mysqli_fetch_assoc($res)['nb'];

$res = mysqli_query($conn, "SELECT SUM(montant_total) AS ca FROM vente");
$row_ca   = mysqli_fetch_assoc($res);
$total_ca = $row_ca['ca'] ? $row_ca['ca'] : 0;

// Best-selling product
$res = mysqli_query($conn,
    "SELECT p.designation, SUM(lv.quantite) AS total_vendu
     FROM ligne_vente lv
     JOIN produit p ON lv.id_produit = p.id_produit
     GROUP BY lv.id_produit
     ORDER BY total_vendu DESC
     LIMIT 1"
);
$meilleur_produit = mysqli_fetch_assoc($res);

// Top client by total spent
$res = mysqli_query($conn,
    "SELECT CONCAT(c.prenom, ' ', c.nom) AS nom_client, SUM(v.montant_total) AS total_depense
     FROM vente v
     JOIN client c ON v.id_client = c.id_client
     GROUP BY v.id_client
     ORDER BY total_depense DESC
     LIMIT 1"
);
$meilleur_client = mysqli_fetch_assoc($res);

// ── PERIOD STATS TABLE ─────────────────────────────────────

if ($periode == 'jour') {
    $stats = mysqli_query($conn,
        "SELECT DATE(date_vente) AS periode_label,
                COUNT(*) AS nb_ventes,
                SUM(montant_total) AS chiffre_affaires
         FROM vente
         WHERE MONTH(date_vente) = MONTH(CURDATE())
           AND YEAR(date_vente)  = YEAR(CURDATE())
         GROUP BY DATE(date_vente)
         ORDER BY DATE(date_vente) DESC"
    );
    $titre_periode = "Ventes journalières — " . $mois_noms[date('n')] . " " . date('Y');

} elseif ($periode == 'annee') {
    $stats = mysqli_query($conn,
        "SELECT YEAR(date_vente) AS periode_label,
                COUNT(*) AS nb_ventes,
                SUM(montant_total) AS chiffre_affaires
         FROM vente
         GROUP BY YEAR(date_vente)
         ORDER BY YEAR(date_vente) DESC"
    );
    $titre_periode = "Ventes annuelles";

} else {
    // Default: monthly
    $stats = mysqli_query($conn,
        "SELECT MONTH(date_vente) AS periode_label,
                COUNT(*) AS nb_ventes,
                SUM(montant_total) AS chiffre_affaires
         FROM vente
         WHERE YEAR(date_vente) = YEAR(CURDATE())
         GROUP BY MONTH(date_vente)
         ORDER BY MONTH(date_vente) DESC"
    );
    $titre_periode = "Ventes mensuelles — " . date('Y');
}

// ── TOP 5 PRODUCTS ─────────────────────────────────────────
$top_produits = mysqli_query($conn,
    "SELECT p.designation, c.nom_categorie,
            SUM(lv.quantite) AS total_vendu,
            SUM(lv.quantite * lv.prix_unitaire) AS revenu
     FROM ligne_vente lv
     JOIN produit p  ON lv.id_produit  = p.id_produit
     LEFT JOIN categorie c ON p.id_categorie = c.id_categorie
     GROUP BY lv.id_produit
     ORDER BY total_vendu DESC
     LIMIT 5"
);

// ── TOP 5 CLIENTS ──────────────────────────────────────────
$top_clients = mysqli_query($conn,
    "SELECT CONCAT(c.prenom, ' ', c.nom) AS nom_client,
            COUNT(v.id_vente) AS nb_achats,
            SUM(v.montant_total) AS total_depense,
            c.points_fidelite
     FROM vente v
     JOIN client c ON v.id_client = c.id_client
     GROUP BY v.id_client
     ORDER BY total_depense DESC
     LIMIT 5"
);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Statistiques</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="dashboard-layout">

    <aside class="sidebar">
        <div class="sidebar-logo">
            <h1>MVSTOCK</h1>
            <p>Sell fast, restock faster.</p>
        </div>
        <nav>
            <a href="accueil.php"><span class="icon">🏠</span> Tableau de bord</a>
            <a href="produits.php"><span class="icon">📦</span> Produits</a>
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>
            <a href="stats.php" class="active"><span class="icon">📊</span> Statistiques</a>
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <a href="utilisateurs.php"><span class="icon">👤</span> Utilisateurs</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>

    <main class="main-content">

        <div class="topbar">
            <h2>📊 Statistiques</h2>
            <div class="user-info">
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="stats-grid" style="margin-bottom:30px;">
            <div class="stat-card">
                <div class="stat-icon blue">🛒</div>
                <div class="stat-info">
                    <h3><?php echo $total_ventes; ?></h3>
                    <p>Ventes au total</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">💰</div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($total_ca, 2); ?></h3>
                    <p>Chiffre d'affaires total</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">🏆</div>
                <div class="stat-info">
                    <h3 style="font-size:1rem;"><?php echo $meilleur_produit ? htmlspecialchars($meilleur_produit['designation']) : '—'; ?></h3>
                    <p>Produit le plus vendu</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red">⭐</div>
                <div class="stat-info">
                    <h3 style="font-size:1rem;"><?php echo $meilleur_client ? htmlspecialchars($meilleur_client['nom_client']) : '—'; ?></h3>
                    <p>Meilleur client</p>
                </div>
            </div>
        </div>

        <!-- Period selector -->
        <div style="display:flex; gap:10px; margin-bottom:24px;">
            <a href="stats.php?periode=jour"  class="btn <?php echo $periode=='jour'  ? 'btn-primary' : 'btn-secondary'; ?>">📅 Journalier</a>
            <a href="stats.php?periode=mois"  class="btn <?php echo $periode=='mois'  ? 'btn-primary' : 'btn-secondary'; ?>">📆 Mensuel</a>
            <a href="stats.php?periode=annee" class="btn <?php echo $periode=='annee' ? 'btn-primary' : 'btn-secondary'; ?>">🗓️ Annuel</a>
        </div>

        <!-- Period stats table -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3><?php echo $titre_periode; ?></h3>
            </div>
            <?php if (mysqli_num_rows($stats) == 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">📊</div>
                    <p>Aucune vente pour cette période.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Période</th>
                        <th>Nombre de ventes</th>
                        <th>Chiffre d'affaires</th>
                        <th>Moyenne par vente</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $grand_total_ca = 0;
                $grand_total_nb = 0;
                while ($row = mysqli_fetch_assoc($stats)):
                    $grand_total_ca += $row['chiffre_affaires'];
                    $grand_total_nb += $row['nb_ventes'];

                    if ($periode == 'mois') {
                        $label = $mois_noms[(int) $row['periode_label']];
                    } elseif ($periode == 'jour') {
                        $label = date('d/m/Y', strtotime($row['periode_label']));
                    } else {
                        $label = $row['periode_label'];
                    }

                    $moyenne = $row['nb_ventes'] > 0 ? $row['chiffre_affaires'] / $row['nb_ventes'] : 0;
                ?>
                    <tr>
                        <td><strong><?php echo $label; ?></strong></td>
                        <td><?php echo $row['nb_ventes']; ?> ventes</td>
                        <td><strong>$<?php echo number_format($row['chiffre_affaires'], 2); ?></strong></td>
                        <td>$<?php echo number_format($moyenne, 2); ?></td>
                    </tr>
                <?php endwhile; ?>
                    <tr style="background:#111; font-weight:700;">
                        <td style="color:#F0F0F0;">TOTAL</td>
                        <td style="color:#F0F0F0;"><?php echo $grand_total_nb; ?> ventes</td>
                        <td style="color:#D4AF37;">$<?php echo number_format($grand_total_ca, 2); ?></td>
                        <td style="color:#888;">—</td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Top products + top clients -->
        <div class="two-col-grid">

            <div class="card">
                <div class="card-header"><h3>🏆 Top 5 produits</h3></div>
                <?php if (mysqli_num_rows($top_produits) == 0): ?>
                    <div class="empty-state"><p>Aucune donnée disponible.</p></div>
                <?php else: ?>
                <table>
                    <thead><tr><th>Produit</th><th>Qté vendue</th><th>Revenu</th></tr></thead>
                    <tbody>
                    <?php $rang = 1; while ($p = mysqli_fetch_assoc($top_produits)): ?>
                        <tr>
                            <td>
                                <?php if ($rang==1) echo '🥇 '; elseif ($rang==2) echo '🥈 '; elseif ($rang==3) echo '🥉 '; ?>
                                <?php echo htmlspecialchars($p['designation']); ?>
                            </td>
                            <td><?php echo $p['total_vendu']; ?> unités</td>
                            <td><strong>$<?php echo number_format($p['revenu'], 2); ?></strong></td>
                        </tr>
                    <?php $rang++; endwhile; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header"><h3>⭐ Top 5 clients</h3></div>
                <?php if (mysqli_num_rows($top_clients) == 0): ?>
                    <div class="empty-state"><p>Aucune donnée disponible.</p></div>
                <?php else: ?>
                <table>
                    <thead><tr><th>Client</th><th>Achats</th><th>Total dépensé</th><th>Points</th></tr></thead>
                    <tbody>
                    <?php $rang = 1; while ($c = mysqli_fetch_assoc($top_clients)): ?>
                        <tr>
                            <td>
                                <?php if ($rang==1) echo '🥇 '; elseif ($rang==2) echo '🥈 '; elseif ($rang==3) echo '🥉 '; ?>
                                <?php echo htmlspecialchars($c['nom_client']); ?>
                            </td>
                            <td><?php echo $c['nb_achats']; ?></td>
                            <td><strong>$<?php echo number_format($c['total_depense'], 2); ?></strong></td>
                            <td><span style="color:#D97706; font-weight:600;">⭐ <?php echo $c['points_fidelite']; ?></span></td>
                        </tr>
                    <?php $rang++; endwhile; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>
</body>
</html>
