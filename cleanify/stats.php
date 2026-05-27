<?php
// Start the session
session_start();

// Include the database connection
require_once 'config.php';

// ── ACCESS CONTROL ─────────────────────────────────────────
// Only admins and agents can view statistics
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] == 'client') {
    header("Location: index.php");
    exit();
}

// ── PERIOD SELECTION ───────────────────────────────────────
// The user can switch between daily, monthly, and yearly views
// using links like stats.php?periode=jour
// Default is monthly if nothing is selected
$periode = isset($_GET['periode']) ? $_GET['periode'] : 'mois';

// ── FRENCH MONTH NAMES ─────────────────────────────────────
// Used to display month numbers as proper names (1 = Janvier, etc.)
$mois_noms = [
    1  => 'Janvier',  2  => 'Février',  3  => 'Mars',
    4  => 'Avril',    5  => 'Mai',       6  => 'Juin',
    7  => 'Juillet',  8  => 'Août',      9  => 'Septembre',
    10 => 'Octobre',  11 => 'Novembre',  12 => 'Décembre'
];

// ══════════════════════════════════════════════════════════
// SUMMARY CARDS (all-time totals shown at the top)
// ══════════════════════════════════════════════════════════

// Total number of sales ever recorded
$res = mysqli_query($conn, "SELECT COUNT(*) AS nb FROM ventes");
$total_ventes = mysqli_fetch_assoc($res)['nb'];

// Total revenue ever
$res = mysqli_query($conn, "SELECT SUM(total) AS ca FROM ventes");
$total_ca = mysqli_fetch_assoc($res)['ca'] ?? 0;

// Best-selling product of all time (the one sold in the most quantity)
$res = mysqli_query($conn,
    "SELECT p.nom, SUM(vp.quantite) AS total_vendu
     FROM vente_produits vp
     JOIN produits p ON vp.produit_id = p.id
     GROUP BY vp.produit_id
     ORDER BY total_vendu DESC
     LIMIT 1"
);
$meilleur_produit = mysqli_fetch_assoc($res);

// Top client by total amount spent
$res = mysqli_query($conn,
    "SELECT CONCAT(u.prenom, ' ', u.nom) AS nom_client, SUM(v.total) AS total_depense
     FROM ventes v
     JOIN users u ON v.client_id = u.id
     GROUP BY v.client_id
     ORDER BY total_depense DESC
     LIMIT 1"
);
$meilleur_client = mysqli_fetch_assoc($res);

// ══════════════════════════════════════════════════════════
// PERIOD STATS TABLE
// The query changes depending on the selected period
// ══════════════════════════════════════════════════════════

if ($periode == 'jour') {
    // Daily view: one row per day in the current month
    $stats = mysqli_query($conn,
        "SELECT DATE(date_vente) AS periode_label,
                COUNT(*) AS nb_ventes,
                SUM(total) AS chiffre_affaires
         FROM ventes
         WHERE MONTH(date_vente) = MONTH(CURDATE())
           AND YEAR(date_vente)  = YEAR(CURDATE())
         GROUP BY DATE(date_vente)
         ORDER BY DATE(date_vente) DESC"
    );
    $titre_periode = "Ventes journalières — " . $mois_noms[date('n')] . " " . date('Y');

} elseif ($periode == 'annee') {
    // Yearly view: one row per year
    $stats = mysqli_query($conn,
        "SELECT YEAR(date_vente) AS periode_label,
                COUNT(*) AS nb_ventes,
                SUM(total) AS chiffre_affaires
         FROM ventes
         GROUP BY YEAR(date_vente)
         ORDER BY YEAR(date_vente) DESC"
    );
    $titre_periode = "Ventes annuelles";

} else {
    // Monthly view (default): one row per month in the current year
    $stats = mysqli_query($conn,
        "SELECT MONTH(date_vente) AS periode_label,
                COUNT(*) AS nb_ventes,
                SUM(total) AS chiffre_affaires
         FROM ventes
         WHERE YEAR(date_vente) = YEAR(CURDATE())
         GROUP BY MONTH(date_vente)
         ORDER BY MONTH(date_vente) DESC"
    );
    $titre_periode = "Ventes mensuelles — " . date('Y');
}

// ══════════════════════════════════════════════════════════
// TOP 5 BEST-SELLING PRODUCTS
// ══════════════════════════════════════════════════════════
$top_produits = mysqli_query($conn,
    "SELECT p.nom, p.categorie,
            SUM(vp.quantite) AS total_vendu,
            SUM(vp.quantite * vp.prix_unitaire) AS revenu
     FROM vente_produits vp
     JOIN produits p ON vp.produit_id = p.id
     GROUP BY vp.produit_id
     ORDER BY total_vendu DESC
     LIMIT 5"
);

// ══════════════════════════════════════════════════════════
// TOP 5 BEST CLIENTS
// ══════════════════════════════════════════════════════════
$top_clients = mysqli_query($conn,
    "SELECT CONCAT(u.prenom, ' ', u.nom) AS nom_client,
            COUNT(v.id) AS nb_achats,
            SUM(v.total) AS total_depense,
            u.points_fidelite
     FROM ventes v
     JOIN users u ON v.client_id = u.id
     GROUP BY v.client_id
     ORDER BY total_depense DESC
     LIMIT 5"
);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Cleanify - Statistiques</title>
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
            <a href="accueil.php"><span class="icon">🏠</span> Tableau de bord</a>
            <a href="produits.php"><span class="icon">📦</span> Produits</a>
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>
            <a href="stats.php" class="active"><span class="icon">📊</span> Statistiques</a>
        </nav>
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <div class="topbar">
            <h2>📊 Statistiques</h2>
            <div class="user-info">
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- ── SUMMARY CARDS (all-time) ── -->
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
                    <h3 style="font-size:1rem;">
                        <?php echo $meilleur_produit ? htmlspecialchars($meilleur_produit['nom']) : '—'; ?>
                    </h3>
                    <p>Produit le plus vendu</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon red">⭐</div>
                <div class="stat-info">
                    <h3 style="font-size:1rem;">
                        <?php echo $meilleur_client ? htmlspecialchars($meilleur_client['nom_client']) : '—'; ?>
                    </h3>
                    <p>Meilleur client</p>
                </div>
            </div>

        </div>

        <!-- ── PERIOD SELECTOR ── -->
        <!-- These are simple links that reload the page with a different ?periode= value -->
        <div style="display:flex; gap:10px; margin-bottom:24px;">
            <a href="stats.php?periode=jour"
               class="btn <?php echo $periode == 'jour'  ? 'btn-primary' : 'btn-secondary'; ?>">
                📅 Journalier
            </a>
            <a href="stats.php?periode=mois"
               class="btn <?php echo $periode == 'mois'  ? 'btn-primary' : 'btn-secondary'; ?>">
                📆 Mensuel
            </a>
            <a href="stats.php?periode=annee"
               class="btn <?php echo $periode == 'annee' ? 'btn-primary' : 'btn-secondary'; ?>">
                🗓️ Annuel
            </a>
        </div>

        <!-- ── PERIOD STATS TABLE ── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3><?php echo $titre_periode; ?></h3>
            </div>

            <?php if (mysqli_num_rows($stats) == 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">📊</div>
                    <p>Aucune vente enregistrée pour cette période.</p>
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
                $grand_total_ca     = 0;
                $grand_total_ventes = 0;

                while ($row = mysqli_fetch_assoc($stats)):
                    $grand_total_ca     += $row['chiffre_affaires'];
                    $grand_total_ventes += $row['nb_ventes'];

                    // Format the period label depending on the selected view
                    if ($periode == 'mois') {
                        // Convert month number to French name (e.g. 5 → Mai)
                        $label = $mois_noms[(int) $row['periode_label']];
                    } elseif ($periode == 'jour') {
                        // Format date as dd/mm/yyyy
                        $label = date('d/m/Y', strtotime($row['periode_label']));
                    } else {
                        // Year view: just show the year number
                        $label = $row['periode_label'];
                    }

                    // Average sale value = total revenue / number of sales
                    $moyenne = $row['nb_ventes'] > 0
                        ? $row['chiffre_affaires'] / $row['nb_ventes']
                        : 0;
                ?>
                    <tr>
                        <td><strong><?php echo $label; ?></strong></td>
                        <td><?php echo $row['nb_ventes']; ?> ventes</td>
                        <td><strong>$<?php echo number_format($row['chiffre_affaires'], 2); ?></strong></td>
                        <td>$<?php echo number_format($moyenne, 2); ?></td>
                    </tr>
                <?php endwhile; ?>

                    <!-- Totals row at the bottom -->
                    <tr style="background:#F0FDF4; font-weight:700;">
                        <td>TOTAL</td>
                        <td><?php echo $grand_total_ventes; ?> ventes</td>
                        <td style="color:#0D9488;">$<?php echo number_format($grand_total_ca, 2); ?></td>
                        <td>—</td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ── TWO COLUMNS: top products + top clients ── -->
        <div class="two-col-grid">

            <!-- Top 5 best-selling products -->
            <div class="card">
                <div class="card-header">
                    <h3>🏆 Top 5 produits</h3>
                </div>

                <?php if (mysqli_num_rows($top_produits) == 0): ?>
                    <div class="empty-state">
                        <p>Aucune donnée disponible.</p>
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th>
                            <th>Qté vendue</th>
                            <th>Revenu</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $rang = 1; // Ranking counter
                    while ($p = mysqli_fetch_assoc($top_produits)):
                    ?>
                        <tr>
                            <td>
                                <!-- Show medal emoji for top 3 -->
                                <?php
                                if ($rang == 1)      echo '🥇 ';
                                elseif ($rang == 2)  echo '🥈 ';
                                elseif ($rang == 3)  echo '🥉 ';
                                ?>
                                <?php echo htmlspecialchars($p['nom']); ?>
                            </td>
                            <td><?php echo $p['total_vendu']; ?> unités</td>
                            <td><strong>$<?php echo number_format($p['revenu'], 2); ?></strong></td>
                        </tr>
                    <?php
                        $rang++;
                    endwhile;
                    ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Top 5 best clients -->
            <div class="card">
                <div class="card-header">
                    <h3>⭐ Top 5 clients</h3>
                </div>

                <?php if (mysqli_num_rows($top_clients) == 0): ?>
                    <div class="empty-state">
                        <p>Aucune donnée disponible.</p>
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Achats</th>
                            <th>Total dépensé</th>
                            <th>Points</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $rang = 1;
                    while ($c = mysqli_fetch_assoc($top_clients)):
                    ?>
                        <tr>
                            <td>
                                <?php
                                if ($rang == 1)      echo '🥇 ';
                                elseif ($rang == 2)  echo '🥈 ';
                                elseif ($rang == 3)  echo '🥉 ';
                                ?>
                                <?php echo htmlspecialchars($c['nom_client']); ?>
                            </td>
                            <td><?php echo $c['nb_achats']; ?></td>
                            <td><strong>$<?php echo number_format($c['total_depense'], 2); ?></strong></td>
                            <td>
                                <span style="color:#D97706; font-weight:600;">
                                    ⭐ <?php echo $c['points_fidelite']; ?>
                                </span>
                            </td>
                        </tr>
                    <?php
                        $rang++;
                    endwhile;
                    ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        </div>

    </main>
</div>
</body>
</html>
