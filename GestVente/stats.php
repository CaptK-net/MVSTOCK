<?php
/*
 * ============================================================
 * FILE: stats.php — STATISTICS & REPORTING (ADMIN ONLY)
 * This page shows business performance data:
 *   - Total number of sales and total revenue (all time)
 *   - Best-selling product and top-spending client
 *   - Sales breakdown by day, month, or year (user's choice)
 *   - Top 5 products by quantity sold
 *   - Top 5 clients by total amount spent
 *
 * ACCESS: Restricted to administrators only.
 *         Agents are redirected to the dashboard if they try to visit this page.
 * ============================================================
 */

// Start the session so we can read $_SESSION variables (user_id, user_role, etc.)
session_start();

// Include the database connection file (gives us the $conn variable).
require_once 'config.php';

// SECURITY GATE 1: Check if the user is logged in.
// If no user_id exists in the session, redirect to the login page.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// SECURITY GATE 2: Check if the user is an admin.
// Agents are NOT allowed to view financial statistics.
// If the role is anything other than 'admin', send them back to the dashboard.
if ($_SESSION['user_role'] != 'admin') {
    header("Location: accueil.php"); // Redirect to dashboard
    exit();                          // Stop executing any further code on this page
}

/*
 * ── PERIOD SELECTOR ──────────────────────────────────────────
 * The user can switch between three views by clicking buttons:
 *   - "Journalier" (daily)   → ?periode=jour
 *   - "Mensuel"   (monthly)  → ?periode=mois  (default)
 *   - "Annuel"    (yearly)   → ?periode=annee
 * $_GET['periode'] reads the "periode" parameter from the URL.
 * If nothing is in the URL, we default to 'mois' (monthly).
 */
$periode = isset($_GET['periode']) ? $_GET['periode'] : 'mois';

// Array mapping month numbers (1–12) to their French names.
// Used to display "Janvier", "Février" etc. instead of raw numbers.
$mois_noms = [
    1=>'Janvier', 2=>'Février',  3=>'Mars',      4=>'Avril',
    5=>'Mai',     6=>'Juin',     7=>'Juillet',   8=>'Août',
    9=>'Septembre', 10=>'Octobre', 11=>'Novembre', 12=>'Décembre'
];

/*
 * ── SUMMARY CARDS (all-time totals) ─────────────────────────
 * These four values are shown at the top in stat cards,
 * regardless of which period is selected.
 */

// Count ALL sales ever recorded (no date filter).
$res          = mysqli_query($conn, "SELECT COUNT(*) AS nb FROM vente");
$total_ventes = mysqli_fetch_assoc($res)['nb']; // Read the count value

// Sum ALL sale totals ever recorded to get the cumulative revenue.
$res      = mysqli_query($conn, "SELECT SUM(montant_total) AS ca FROM vente");
$row_ca   = mysqli_fetch_assoc($res);
// If SUM() returns NULL (no sales yet), use 0 to avoid errors.
$total_ca = $row_ca['ca'] ? $row_ca['ca'] : 0;

// Find the single best-selling product of all time.
// SUM(lv.quantite) adds up all quantities sold for each product.
// GROUP BY groups results by product ID so each product gets its own total.
// ORDER BY total_vendu DESC puts the highest-selling product first.
// LIMIT 1 returns only the top result.
$res = mysqli_query($conn,
    "SELECT p.designation, SUM(lv.quantite) AS total_vendu
     FROM ligne_vente lv
     JOIN produit p ON lv.id_produit = p.id_produit
     GROUP BY lv.id_produit
     ORDER BY total_vendu DESC
     LIMIT 1"
);
$meilleur_produit = mysqli_fetch_assoc($res); // Could be null if no sales exist

// Find the single client who has spent the most money overall.
// SUM(v.montant_total) totals all sale amounts linked to each client.
// CONCAT() joins first name and last name into one display string.
$res = mysqli_query($conn,
    "SELECT CONCAT(c.prenom, ' ', c.nom) AS nom_client, SUM(v.montant_total) AS total_depense
     FROM vente v
     JOIN client c ON v.id_client = c.id_client
     GROUP BY v.id_client
     ORDER BY total_depense DESC
     LIMIT 1"
);
$meilleur_client = mysqli_fetch_assoc($res); // Could be null if no sales with clients

/*
 * ── PERIOD-BASED STATS TABLE ─────────────────────────────────
 * Depending on which period button the user clicked, we run
 * a different SQL query that groups sales by day, month, or year.
 * All three queries return the same column structure:
 *   - periode_label : the label to display (date, month number, or year)
 *   - nb_ventes     : number of sales in that period
 *   - chiffre_affaires : total revenue in that period
 */
if ($periode == 'jour') {

    // DAILY VIEW: show one row per day in the current month.
    // DATE(date_vente) extracts just the date part (drops the time).
    // MONTH() and YEAR() filter to only the current month and year.
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
    // Title shown above the table — e.g. "Ventes journalières — Juin 2026"
    $titre_periode = "Ventes journalières — " . $mois_noms[date('n')] . " " . date('Y');

} elseif ($periode == 'annee') {

    // YEARLY VIEW: show one row per year across all years in the database.
    // YEAR() extracts the year from the sale date.
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

    // MONTHLY VIEW (default): show one row per month in the current year.
    // MONTH() extracts the month number (1–12) from the sale date.
    $stats = mysqli_query($conn,
        "SELECT MONTH(date_vente) AS periode_label,
                COUNT(*) AS nb_ventes,
                SUM(montant_total) AS chiffre_affaires
         FROM vente
         WHERE YEAR(date_vente) = YEAR(CURDATE())
         GROUP BY MONTH(date_vente)
         ORDER BY MONTH(date_vente) DESC"
    );
    // Title includes the current year — e.g. "Ventes mensuelles — 2026"
    $titre_periode = "Ventes mensuelles — " . date('Y');
}

/*
 * ── TOP 5 PRODUCTS ───────────────────────────────────────────
 * Shows the 5 best-performing products by quantity sold.
 * LEFT JOIN brings in the category name alongside each product.
 * SUM(lv.quantite * lv.prix_unitaire) calculates the revenue per product.
 */
$top_produits = mysqli_query($conn,
    "SELECT p.designation, c.nom_categorie,
            SUM(lv.quantite) AS total_vendu,
            SUM(lv.quantite * lv.prix_unitaire) AS revenu
     FROM ligne_vente lv
     JOIN produit  p ON lv.id_produit  = p.id_produit
     LEFT JOIN categorie c ON p.id_categorie = c.id_categorie
     GROUP BY lv.id_produit
     ORDER BY total_vendu DESC
     LIMIT 5"
);

/*
 * ── TOP 5 CLIENTS ────────────────────────────────────────────
 * Shows the 5 clients who have spent the most money.
 * COUNT(v.id_vente) counts how many times each client has bought.
 * SUM(v.montant_total) sums up how much they've spent in total.
 */
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

    <!-- ===== SIDEBAR ===== -->
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

            <!-- Stats and Users are admin-only links -->
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <a href="stats.php" class="active"><span class="icon">📊</span> Statistiques</a>
            <?php endif; ?>
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <a href="utilisateurs.php"><span class="icon">👤</span> Utilisateurs</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- Page title + logged-in user display -->
        <div class="topbar">
            <h2>📊 Statistiques</h2>
            <div class="user-info">
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- ── 4 SUMMARY CARDS ── -->
        <!-- .stats-grid is a 4-column CSS grid defined in style.css -->
        <div class="stats-grid" style="margin-bottom:30px;">

            <!-- Card: total number of sales ever -->
            <div class="stat-card">
                <div class="stat-icon blue">🛒</div>
                <div class="stat-info">
                    <h3><?php echo $total_ventes; ?></h3>
                    <p>Ventes au total</p>
                </div>
            </div>

            <!-- Card: cumulative revenue (all time) -->
            <div class="stat-card">
                <div class="stat-icon green">💰</div>
                <div class="stat-info">
                    <!-- number_format() displays the number with 2 decimal places -->
                    <h3>$<?php echo number_format($total_ca, 2); ?></h3>
                    <p>Chiffre d'affaires total</p>
                </div>
            </div>

            <!-- Card: best-selling product name -->
            <div class="stat-card">
                <div class="stat-icon orange">🏆</div>
                <div class="stat-info">
                    <!-- Show product name or "—" if there are no sales yet -->
                    <h3 style="font-size:1rem;">
                        <?php echo $meilleur_produit ? htmlspecialchars($meilleur_produit['designation']) : '—'; ?>
                    </h3>
                    <p>Produit le plus vendu</p>
                </div>
            </div>

            <!-- Card: top-spending client name -->
            <div class="stat-card">
                <div class="stat-icon red">⭐</div>
                <div class="stat-info">
                    <!-- Show client name or "—" if no sales linked to a client -->
                    <h3 style="font-size:1rem;">
                        <?php echo $meilleur_client ? htmlspecialchars($meilleur_client['nom_client']) : '—'; ?>
                    </h3>
                    <p>Meilleur client</p>
                </div>
            </div>
        </div>

        <!-- ── PERIOD SELECTOR BUTTONS ── -->
        <!-- Clicking each button reloads the page with a different ?periode= value -->
        <div style="display:flex; gap:10px; margin-bottom:24px;">

            <!-- Daily button: active (gold) when ?periode=jour -->
            <a href="stats.php?periode=jour"
               class="btn <?php echo $periode=='jour'  ? 'btn-primary' : 'btn-secondary'; ?>">
               📅 Journalier
            </a>

            <!-- Monthly button: active by default -->
            <a href="stats.php?periode=mois"
               class="btn <?php echo $periode=='mois'  ? 'btn-primary' : 'btn-secondary'; ?>">
               📆 Mensuel
            </a>

            <!-- Yearly button -->
            <a href="stats.php?periode=annee"
               class="btn <?php echo $periode=='annee' ? 'btn-primary' : 'btn-secondary'; ?>">
               🗓️ Annuel
            </a>
        </div>

        <!-- ── PERIOD STATS TABLE ── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <!-- Dynamic title changes based on selected period -->
                <h3><?php echo $titre_periode; ?></h3>
            </div>

            <!-- If there are no sales in this period, show an empty-state message -->
            <?php if (mysqli_num_rows($stats) == 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">📊</div>
                    <p>Aucune vente pour cette période.</p>
                </div>
            <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Période</th>            <!-- Day / month / year label -->
                        <th>Nombre de ventes</th>   <!-- Count of sales -->
                        <th>Chiffre d'affaires</th> <!-- Total revenue -->
                        <th>Moyenne par vente</th>  <!-- Average sale value -->
                    </tr>
                </thead>
                <tbody>
                <?php
                // Running totals for the TOTAL footer row at the bottom.
                $grand_total_ca = 0;
                $grand_total_nb = 0;

                // Loop through each period row returned by the SQL query.
                while ($row = mysqli_fetch_assoc($stats)):
                    // Accumulate grand totals.
                    $grand_total_ca += $row['chiffre_affaires'];
                    $grand_total_nb += $row['nb_ventes'];

                    // Format the period label depending on which view is active.
                    if ($periode == 'mois') {
                        // Convert month number (e.g. 6) to name (e.g. "Juin")
                        $label = $mois_noms[(int) $row['periode_label']];
                    } elseif ($periode == 'jour') {
                        // Reformat date from YYYY-MM-DD to DD/MM/YYYY
                        $label = date('d/m/Y', strtotime($row['periode_label']));
                    } else {
                        // Yearly: the label is just the year number (e.g. 2026)
                        $label = $row['periode_label'];
                    }

                    // Calculate average sale value for this period.
                    // Guard against division by zero if nb_ventes = 0.
                    $moyenne = $row['nb_ventes'] > 0 ? $row['chiffre_affaires'] / $row['nb_ventes'] : 0;
                ?>
                    <tr>
                        <td><strong><?php echo $label; ?></strong></td>
                        <td><?php echo $row['nb_ventes']; ?> ventes</td>
                        <td><strong>$<?php echo number_format($row['chiffre_affaires'], 2); ?></strong></td>
                        <td>$<?php echo number_format($moyenne, 2); ?></td>
                    </tr>
                <?php endwhile; ?>

                <!-- Grand total row with dark background -->
                <tr style="background:#111; font-weight:700;">
                    <td style="color:#F0F0F0;">TOTAL</td>
                    <td style="color:#F0F0F0;"><?php echo $grand_total_nb; ?> ventes</td>
                    <!-- Grand total revenue shown in gold -->
                    <td style="color:#D4AF37;">$<?php echo number_format($grand_total_ca, 2); ?></td>
                    <td style="color:#888;">—</td>
                </tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ── TWO-COLUMN GRID: Top products + Top clients ── -->
        <div class="two-col-grid">

            <!-- LEFT: Top 5 products -->
            <div class="card">
                <div class="card-header"><h3>🏆 Top 5 produits</h3></div>

                <?php if (mysqli_num_rows($top_produits) == 0): ?>
                    <div class="empty-state"><p>Aucune donnée disponible.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th>    <!-- Product name -->
                            <th>Qté vendue</th> <!-- Total units sold -->
                            <th>Revenu</th>     <!-- Total revenue from this product -->
                        </tr>
                    </thead>
                    <tbody>
                    <!-- $rang tracks the rank (1st, 2nd, 3rd...) for medal emojis -->
                    <?php $rang = 1; while ($p = mysqli_fetch_assoc($top_produits)): ?>
                        <tr>
                            <td>
                                <!-- Show gold/silver/bronze medal for top 3 -->
                                <?php if ($rang==1) echo '🥇 ';
                                      elseif ($rang==2) echo '🥈 ';
                                      elseif ($rang==3) echo '🥉 '; ?>
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

            <!-- RIGHT: Top 5 clients -->
            <div class="card">
                <div class="card-header"><h3>⭐ Top 5 clients</h3></div>

                <?php if (mysqli_num_rows($top_clients) == 0): ?>
                    <div class="empty-state"><p>Aucune donnée disponible.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Client</th>        <!-- Full name -->
                            <th>Achats</th>         <!-- Number of purchases -->
                            <th>Total dépensé</th>  <!-- Total amount spent -->
                            <th>Points</th>         <!-- Loyalty points -->
                        </tr>
                    </thead>
                    <tbody>
                    <?php $rang = 1; while ($c = mysqli_fetch_assoc($top_clients)): ?>
                        <tr>
                            <td>
                                <?php if ($rang==1) echo '🥇 ';
                                      elseif ($rang==2) echo '🥈 ';
                                      elseif ($rang==3) echo '🥉 '; ?>
                                <?php echo htmlspecialchars($c['nom_client']); ?>
                            </td>
                            <td><?php echo $c['nb_achats']; ?></td>
                            <td><strong>$<?php echo number_format($c['total_depense'], 2); ?></strong></td>
                            <!-- Loyalty points shown in orange/gold -->
                            <td><span style="color:#D97706; font-weight:600;">⭐ <?php echo $c['points_fidelite']; ?></span></td>
                        </tr>
                    <?php $rang++; endwhile; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        </div><!-- end .two-col-grid -->
    </main>
</div><!-- end .dashboard-layout -->
</body>
</html>
