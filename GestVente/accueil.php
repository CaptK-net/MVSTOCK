<?php
// ============================================================
//  accueil.php — MAIN DASHBOARD
//  This is the first page the user sees after logging in.
//  It shows 4 key statistics (sales today, revenue, clients,
//  stock alerts), the 5 most recent transactions, and a list
//  of products that are running low on stock.
// ============================================================

// Start (or resume) the PHP session so we can read $_SESSION variables
// like user_id, user_nom, user_role that were set during login.
session_start();

// Include the database connection. This gives us the $conn variable.
require_once 'config.php';

// Security check: if no user is logged in ($_SESSION['user_id'] is not set),
// redirect immediately to the login page. This prevents anyone from accessing
// the dashboard without being authenticated.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); // Send browser to login page
    exit();                        // Stop executing any more code on this page
}

// Read the logged-in user's name and role from the session.
// These were stored in $_SESSION when the user logged in (see login.php).
$nom_utilisateur = $_SESSION['user_nom'];  // e.g. "Martin"
$role            = $_SESSION['user_role']; // 'admin' or 'agent'

// ── STATISTIC 1: Number of sales today ────────────────────────
// COUNT(*) counts all matching rows.
// DATE(date_vente) extracts only the date part (ignoring the time).
// CURDATE() returns today's date (e.g. 2026-06-01).
// So this query counts all sales whose date matches today.
$res = mysqli_query($conn,
    "SELECT COUNT(*) AS nb FROM vente WHERE DATE(date_vente) = CURDATE()"
);
// mysqli_fetch_assoc() reads the result as an array.
// ['nb'] gets the value of the column we named "nb" in the query.
$nb_ventes_aujourd_hui = mysqli_fetch_assoc($res)['nb'];

// ── STATISTIC 2: Revenue today (chiffre d'affaires) ───────────
// SUM(montant_total) adds up the total amounts of all matching sales.
// If there are no sales today, SUM() returns NULL (not 0).
$res    = mysqli_query($conn,
    "SELECT SUM(montant_total) AS ca FROM vente WHERE DATE(date_vente) = CURDATE()"
);
$row_ca = mysqli_fetch_assoc($res); // Read the result row

// If ca is NULL (no sales today), use 0 instead to avoid errors.
// This is a ternary operator: if $row_ca['ca'] is truthy, use it; else use 0.
$ca_aujourd_hui = $row_ca['ca'] ? $row_ca['ca'] : 0;

// ── STATISTIC 3: Total number of registered clients ───────────
// COUNT(*) counts every row in the client table.
$res        = mysqli_query($conn, "SELECT COUNT(*) AS nb FROM client");
$nb_clients = mysqli_fetch_assoc($res)['nb'];

// ── STATISTIC 4: Number of products below stock alert threshold
// stock_actuel <= seuil_alerte means the product is running low.
// This count is displayed as a red warning on the dashboard.
$res        = mysqli_query($conn,
    "SELECT COUNT(*) AS nb FROM produit WHERE stock_actuel <= seuil_alerte"
);
$nb_alertes = mysqli_fetch_assoc($res)['nb'];

// ── LAST 5 SALES ───────────────────────────────────────────────
// This query joins 3 tables to get full sale details:
//   - vente: the sale itself (id, date, total, payment method)
//   - client: the customer (joined by id_client to get their name)
//   - utilisateur: the agent who made the sale (joined by id_utilisateur)
// LEFT JOIN means: include the sale even if there is no matching client
//   (anonymous sales where id_client is NULL).
// ORDER BY date_vente DESC sorts newest sales first.
// LIMIT 5 returns only the 5 most recent sales.
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

// ── PRODUCTS BELOW STOCK THRESHOLD ────────────────────────────
// This query finds products whose current stock is at or below the alert level.
// LEFT JOIN brings in the category name from the categorie table.
// ORDER BY stock_actuel ASC shows the most critical (lowest stock) items first.
// LIMIT 6 shows at most 6 alert items on the dashboard.
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
    <!-- Character encoding: allows accented letters and special characters -->
    <meta charset="UTF-8">

    <!-- Browser tab title -->
    <title>Tableau de bord</title>

    <!-- Link to the shared stylesheet that styles the whole application -->
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- .dashboard-layout is a CSS flex container that creates the
     two-column layout: sidebar on the left, main content on the right -->
<div class="dashboard-layout">

    <!-- ===== LEFT SIDEBAR ===== -->
    <!-- The <aside> tag is used for navigation panels. .sidebar applies
         the fixed dark sidebar styles defined in style.css -->
    <aside class="sidebar">

        <!-- Logo and slogan at the top of the sidebar -->
        <div class="sidebar-logo">
            <h1>MVSTOCK</h1>
            <p>Sell fast, restock faster.</p>
        </div>

        <!-- Navigation links. class="active" highlights the current page. -->
        <nav>
            <!-- Each link goes to a different module of the application -->
            <a href="accueil.php" class="active"><span class="icon">🏠</span> Tableau de bord</a>
            <a href="produits.php"><span class="icon">📦</span> Produits</a>
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>

            <!-- Statistics link is only shown to admins.
                 PHP checks the role stored in the session. -->
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
            <?php endif; ?>

            <!-- Utilisateurs link is also admin-only -->
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <a href="utilisateurs.php"><span class="icon">👤</span> Utilisateurs</a>
            <?php endif; ?>
        </nav>

        <!-- Logout link at the bottom of the sidebar -->
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT AREA ===== -->
    <!-- margin-left: 250px in CSS pushes this area past the fixed sidebar -->
    <main class="main-content">

        <!-- Top bar: shows the page title and the logged-in user's name/role -->
        <div class="topbar">
            <h2>Tableau de bord</h2>
            <div class="user-info">
                <!-- htmlspecialchars() converts characters like < > & to safe
                     HTML equivalents, preventing XSS (cross-site scripting) attacks -->
                <span>👋 Bonjour, <?php echo htmlspecialchars($nom_utilisateur); ?></span>
                <!-- Display the user's role (admin or agent) as a gold badge -->
                <span class="badge-role"><?php echo htmlspecialchars($role); ?></span>
            </div>
        </div>

        <!-- ── 4 STATISTICS CARDS ── -->
        <!-- .stats-grid is a 4-column CSS grid layout defined in style.css -->
        <div class="stats-grid">

            <!-- Card 1: Number of sales today -->
            <div class="stat-card">
                <div class="stat-icon green">🛒</div>
                <div class="stat-info">
                    <!-- Display the count we fetched from the database -->
                    <h3><?php echo $nb_ventes_aujourd_hui; ?></h3>
                    <p>Ventes aujourd'hui</p>
                </div>
            </div>

            <!-- Card 2: Revenue today -->
            <div class="stat-card">
                <div class="stat-icon blue">💰</div>
                <div class="stat-info">
                    <!-- number_format() formats the number with 2 decimal places -->
                    <h3>$<?php echo number_format($ca_aujourd_hui, 2); ?></h3>
                    <p>Chiffre d'affaires</p>
                </div>
            </div>

            <!-- Card 3: Total registered clients -->
            <div class="stat-card">
                <div class="stat-icon orange">👥</div>
                <div class="stat-info">
                    <h3><?php echo $nb_clients; ?></h3>
                    <p>Clients enregistrés</p>
                </div>
            </div>

            <!-- Card 4: Number of stock alerts (products below threshold) -->
            <div class="stat-card">
                <div class="stat-icon red">⚠️</div>
                <div class="stat-info">
                    <h3><?php echo $nb_alertes; ?></h3>
                    <p>Alertes de stock</p>
                </div>
            </div>
        </div>

        <!-- ── TWO-COLUMN SECTION: recent sales + stock alerts ── -->
        <!-- .two-col-grid creates a 2-column grid layout in CSS -->
        <div class="two-col-grid">

            <!-- LEFT COLUMN: 5 most recent sales -->
            <div class="card">
                <div class="card-header">
                    <h3>🛒 Dernières ventes</h3>
                    <!-- Link to see the full list of sales -->
                    <a href="ventes.php" class="btn btn-secondary">Voir tout</a>
                </div>

                <!-- Only show the table if there is at least one sale -->
                <?php if (mysqli_num_rows($res_recentes) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>       <!-- Sale ID -->
                            <th>Client</th>  <!-- Customer name -->
                            <th>Agent</th>   <!-- Agent who recorded the sale -->
                            <th>Total</th>   <!-- Sale total amount -->
                            <th>Date</th>    <!-- Date and time of sale -->
                        </tr>
                    </thead>
                    <tbody>
                    <!-- Loop through each sale result row until there are none left -->
                    <?php while ($v = mysqli_fetch_assoc($res_recentes)): ?>
                        <tr>
                            <!-- Sale ID prefixed with # for readability -->
                            <td>#<?php echo $v['id_vente']; ?></td>

                            <td>
                                <!-- If client_nom is not null/empty, show the name.
                                     Otherwise show an "Anonyme" badge (anonymous sale). -->
                                <?php echo $v['client_nom']
                                    ? htmlspecialchars($v['client_nom'])
                                    : '<span class="badge badge-info">Anonyme</span>'; ?>
                            </td>

                            <!-- Agent name — htmlspecialchars for XSS protection -->
                            <td><?php echo htmlspecialchars($v['agent_nom']); ?></td>

                            <!-- Total formatted with 2 decimals and a $ sign -->
                            <td><strong>$<?php echo number_format($v['montant_total'], 2); ?></strong></td>

                            <!-- date() reformats the database timestamp (YYYY-MM-DD HH:MM:SS)
                                 into a human-readable format (DD/MM/YYYY HH:MM).
                                 strtotime() converts the string to a Unix timestamp first. -->
                            <td><?php echo date('d/m/Y H:i', strtotime($v['date_vente'])); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>

                <?php else: ?>
                    <!-- If no sales exist yet, show this placeholder message -->
                    <div class="empty-state">
                        <div class="empty-icon">🛒</div>
                        <p>Aucune vente enregistrée pour l'instant.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN: Products below stock alert threshold -->
            <div class="card">
                <div class="card-header">
                    <h3>⚠️ Alertes de stock</h3>
                    <!-- Link to the products page to manage stock levels -->
                    <a href="produits.php" class="btn btn-secondary">Gérer</a>
                </div>

                <!-- Only show the list if there is at least one product in alert -->
                <?php if (mysqli_num_rows($res_stock) > 0): ?>
                    <!-- Loop through each product that is below its alert threshold -->
                    <?php while ($p = mysqli_fetch_assoc($res_stock)): ?>
                    <div class="alert-stock">
                        <!-- Product name and category -->
                        <div class="produit-info">
                            <strong><?php echo htmlspecialchars($p['designation']); ?></strong>
                            <!-- Show category, or "—" if no category is assigned -->
                            <span><?php echo htmlspecialchars($p['nom_categorie'] ? $p['nom_categorie'] : '—'); ?></span>
                        </div>

                        <!-- Show a red "Rupture" badge if stock is 0,
                             or an orange "Stock: X" badge if it's just low -->
                        <?php if ($p['stock_actuel'] == 0): ?>
                            <span class="badge badge-danger">Rupture</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Stock: <?php echo $p['stock_actuel']; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>

                <?php else: ?>
                    <!-- If all stocks are fine, show a green confirmation message -->
                    <div class="empty-state">
                        <div class="empty-icon">✅</div>
                        <p>Tous les stocks sont suffisants.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- end .two-col-grid -->
    </main>
</div><!-- end .dashboard-layout -->
</body>
</html>
