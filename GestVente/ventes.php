<?php
/*
 * ============================================================
 * FILE: ventes.php — SALES MODULE
 * This page handles everything related to sales:
 *   1. Recording a new sale (up to 5 products at once)
 *   2. Automatically decreasing product stock after a sale
 *   3. Automatically adding loyalty points to the client
 *   4. Showing the list of recent sales
 *   5. Showing the full detail of a single sale
 *   6. Generating a downloadable PDF invoice
 * ============================================================
 */

// Start the PHP session so we can read who is currently logged in.
// $_SESSION variables like user_id and user_role are set during login.
session_start();

// Include the database connection. This gives us the $conn variable
// needed to send SQL queries to the MySQL database.
require_once 'config.php';

// SECURITY GATE: if no user is logged in, redirect to login page immediately.
// isset() returns true only if $_SESSION['user_id'] exists and is not null.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); // Redirect the browser to the login page
    exit();                        // Stop executing this page immediately
}

// These two variables hold feedback messages shown to the user after an action.
// $message = green success box. $erreur = red error box.
$message = "";
$erreur  = "";

/*
 * ── RECORD A NEW SALE ────────────────────────────────────────
 * When the user fills in the "Nouvelle vente" form and clicks submit,
 * the browser sends data via HTTP POST with action = 'vendre'.
 * We process it in 4 atomic steps:
 *   Step 1 — Insert the sale header into the "vente" table
 *   Step 2 — Insert each product line into "ligne_vente"
 *   Step 3 — Decrease the stock of each sold product
 *   Step 4 — Add loyalty points to the client
 */

// Check: was the form submitted via POST, AND is the action "vendre" (sell)?
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'vendre') {

    // Read the ID of the logged-in agent who is recording this sale.
    // (int) converts the session value to an integer for SQL safety.
    $id_utilisateur = (int) $_SESSION['user_id'];

    // Read the selected client ID from the form.
    // If the field is empty (anonymous sale), we store the SQL keyword NULL.
    $id_client = !empty($_POST['id_client']) ? (int) $_POST['id_client'] : "NULL";

    // Sanitize the payment method string to prevent SQL injection.
    $mode_paiement = mysqli_real_escape_string($conn, $_POST['mode_paiement']);

    // $lignes_valides will collect the product lines that are actually filled in.
    // The form has 5 rows, but the agent may only fill in 1 or 2 of them.
    $lignes_valides = [];

    // Loop through all 5 product rows in the form (index 0 to 4).
    for ($i = 0; $i < 5; $i++) {

        // Read the product ID selected in this row. (int) makes it safe.
        $id_produit = (int) $_POST['id_produit'][$i];

        // Read the quantity entered for this row. (int) ensures it's a whole number.
        $quantite = (int) $_POST['quantite'][$i];

        // If the agent left this row on "Aucun" (id=0) or entered 0 quantity,
        // skip this row entirely — it means they don't want to add a product here.
        if ($id_produit == 0 || $quantite <= 0) {
            continue; // Jump to the next loop iteration
        }

        // Fetch this product's current price and available stock from the database.
        // We always use the live price (not what the agent types) for accuracy.
        $res     = mysqli_query($conn, "SELECT designation, prix_unitaire, stock_actuel FROM produit WHERE id_produit = $id_produit");
        $produit = mysqli_fetch_assoc($res); // Read the product row as an array

        // Check that there is enough stock to fulfil this quantity.
        // If the agent tries to sell more than what is in stock, refuse it.
        if ($produit['stock_actuel'] < $quantite) {

            // Store an error message naming the product that caused the problem.
            $erreur = "Stock insuffisant pour : " . $produit['designation'] .
                      " (disponible : " . $produit['stock_actuel'] . ")";

            // Stop checking other rows — the whole sale is rejected.
            break;
        }

        // This row is valid: add it to our list of confirmed product lines.
        $lignes_valides[] = [
            'id_produit'    => $id_produit,
            'quantite'      => $quantite,
            'prix_unitaire' => (float) $produit['prix_unitaire'], // Cast to decimal number
            'designation'   => $produit['designation']            // Product name (for messages)
        ];
    }

    // Only proceed if there were no errors AND at least one valid product line.
    if ($erreur == "" && count($lignes_valides) > 0) {

        // ── STEP 1: Calculate the grand total ─────────────────
        // Loop through all valid lines and add up (quantity × price) for each.
        $montant_total = 0;
        foreach ($lignes_valides as $ligne) {
            $montant_total += $ligne['prix_unitaire'] * $ligne['quantite'];
        }

        // ── STEP 2: Insert the sale header into the "vente" table ─
        // This creates one row that represents the entire transaction.
        // id_vente is auto-generated by the database (AUTO_INCREMENT).
        $sql_vente = "INSERT INTO vente (montant_total, mode_paiement, id_client, id_utilisateur)
                      VALUES ($montant_total, '$mode_paiement', $id_client, $id_utilisateur)";

        if (mysqli_query($conn, $sql_vente)) {

            // mysqli_insert_id() returns the auto-generated ID of the row we just inserted.
            // We MUST save this because we need to link all ligne_vente rows to this sale.
            $id_vente = mysqli_insert_id($conn);

            // ── STEP 3: Insert one line per product into "ligne_vente" ─
            // Each line records the product, quantity, and the price at the time of sale.
            // Storing the price here is important: if the product price changes later,
            // the historical sale record stays accurate.
            foreach ($lignes_valides as $ligne) {

                $sql_ligne = "INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit)
                              VALUES ({$ligne['quantite']}, {$ligne['prix_unitaire']}, $id_vente, {$ligne['id_produit']})";
                mysqli_query($conn, $sql_ligne);

                // ── STEP 4: Decrease the stock of this product ────────
                // stock_actuel = stock_actuel - quantity_sold
                // The WHERE clause ensures we only update the correct product.
                $sql_stock = "UPDATE produit
                              SET stock_actuel = stock_actuel - {$ligne['quantite']}
                              WHERE id_produit = {$ligne['id_produit']}";
                mysqli_query($conn, $sql_stock);
            }

            // ── STEP 5: Add loyalty points to the client ──────────────
            // Only runs if a client was selected (not an anonymous sale).
            // Rule: 1 loyalty point per dollar (or monetary unit) spent.
            // (int) rounds down the total so points are always whole numbers.
            if ($id_client != "NULL") {
                $points = (int) $montant_total;

                $sql_points = "UPDATE client
                               SET points_fidelite = points_fidelite + $points
                               WHERE id_client = $id_client";
                mysqli_query($conn, $sql_points);
            }

            // Show a success message with the sale ID and total amount.
            $message = "Vente #$id_vente enregistrée ! Total : $" . number_format($montant_total, 2);

        } else {
            // The INSERT into "vente" failed — show the database error.
            $erreur = "Erreur lors de l'enregistrement : " . mysqli_error($conn);
        }

    } elseif ($erreur == "" && count($lignes_valides) == 0) {
        // The agent submitted the form but left all 5 product rows on "Aucun".
        $erreur = "Veuillez sélectionner au moins un produit.";
    }
}

/*
 * ── FETCH DATA FOR THE DROPDOWNS ─────────────────────────────
 * We need the client list and product list to populate the
 * dropdown menus in the "Nouvelle vente" form.
 */

// Fetch all clients ordered alphabetically by last name.
// These fill the "Select a client" dropdown.
$clients = mysqli_query($conn, "SELECT id_client, nom, prenom FROM client ORDER BY nom");

// Fetch all products with their price and current stock level.
// These fill the 5 product selection dropdowns in the form.
$produits_res = mysqli_query($conn,
    "SELECT id_produit, designation, prix_unitaire, stock_actuel FROM produit ORDER BY designation"
);

// Store products in a PHP array so we can loop through them multiple times
// (once per product row in the form). We can't re-use a database result set.
$produits_array = [];
while ($p = mysqli_fetch_assoc($produits_res)) {
    $produits_array[] = $p; // Add each product row to the array
}

/*
 * ── FETCH RECENT SALES ───────────────────────────────────────
 * Load the 20 most recent sales to display in the bottom table.
 * We JOIN with client and utilisateur tables to get names
 * instead of just raw IDs.
 */
$ventes = mysqli_query($conn,
    "SELECT v.id_vente, v.date_vente, v.montant_total, v.mode_paiement,
            CONCAT(c.prenom, ' ', c.nom) AS client_nom,
            CONCAT(u.prenom, ' ', u.nom) AS agent_nom
     FROM vente v
     LEFT JOIN client      c ON v.id_client      = c.id_client
     LEFT JOIN utilisateur u ON v.id_utilisateur = u.id_utilisateur
     ORDER BY v.date_vente DESC
     LIMIT 20"
);

/*
 * ── VIEW DETAIL OF A SINGLE SALE (?voir=ID) ──────────────────
 * When a user clicks "Voir" on a sale row, the URL becomes
 * ventes.php?voir=5 (for example). We detect that here and
 * load the full details of that sale.
 */

// Start with null — no detail view by default.
$detail_vente  = null;
$detail_lignes = null;

// Check if the "voir" (view) parameter is present in the URL.
if (isset($_GET['voir'])) {

    // Convert the ID to a safe integer.
    $id = (int) $_GET['voir'];

    // Fetch the sale header plus client name and agent name via JOINs.
    $res_detail = mysqli_query($conn,
        "SELECT v.*,
                CONCAT(c.prenom, ' ', c.nom) AS client_nom,
                CONCAT(u.prenom, ' ', u.nom) AS agent_nom
         FROM vente v
         LEFT JOIN client      c ON v.id_client      = c.id_client
         LEFT JOIN utilisateur u ON v.id_utilisateur = u.id_utilisateur
         WHERE v.id_vente = $id"
    );

    // Read the single sale row into an associative array.
    $detail_vente = mysqli_fetch_assoc($res_detail);

    // Fetch all the individual product lines for this sale.
    // Each line contains the product name, quantity, unit price, and subtotal.
    $detail_lignes = mysqli_query($conn,
        "SELECT p.designation, lv.quantite, lv.prix_unitaire,
                (lv.quantite * lv.prix_unitaire) AS sous_total
         FROM ligne_vente lv
         JOIN produit p ON lv.id_produit = p.id_produit
         WHERE lv.id_vente = $id"
    );
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <!-- Character encoding — required for accented French characters -->
    <meta charset="UTF-8">

    <!-- Browser tab title -->
    <title>Ventes</title>

    <!-- Shared stylesheet for the whole application -->
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- .dashboard-layout is a CSS flexbox container: sidebar on the left,
     main content on the right (defined in style.css) -->
<div class="dashboard-layout">

    <!-- ===== SIDEBAR NAVIGATION ===== -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <h1>MVSTOCK</h1>
            <p>Sell fast, restock faster.</p>
        </div>
        <nav>
            <!-- Navigation links to each module of the app -->
            <a href="accueil.php"><span class="icon">🏠</span> Tableau de bord</a>
            <a href="produits.php"><span class="icon">📦</span> Produits</a>
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <!-- class="active" highlights this link as the current page -->
            <a href="ventes.php" class="active"><span class="icon">🛒</span> Ventes</a>

            <!-- Statistics and User Management are admin-only -->
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
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

        <!-- Top bar with page title and logged-in user info -->
        <div class="topbar">
            <h2>🛒 Ventes</h2>
            <div class="user-info">
                <!-- htmlspecialchars() prevents XSS by escaping < > & " characters -->
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- Show a green success message if $message is not empty -->
        <?php if ($message != ""): ?>
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Show a red error message if $erreur is not empty -->
        <?php if ($erreur != ""): ?>
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>

        <?php if ($detail_vente): ?>
        <!-- ══════════════════════════════════════════════════
             SALE DETAIL VIEW (shown when ?voir=ID is in URL)
             ══════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <!-- Show the sale ID in the header title -->
                <h3>🧾 Détail — Vente #<?php echo $detail_vente['id_vente']; ?></h3>

                <!-- Two buttons side by side using flexbox -->
                <div style="display:flex; gap:10px;">
                    <!-- Go back to the main sales list -->
                    <a href="ventes.php" class="btn btn-secondary">← Retour</a>

                    <!-- Download this sale as a PDF invoice.
                         Clicking this opens facture.php with the sale ID. -->
                    <a href="facture.php?id=<?php echo $detail_vente['id_vente']; ?>"
                       class="btn btn-primary"
                       style="background:#C9A227; color:#1A1A1A; font-weight:700; border:none;">
                        ⬇️ Télécharger la facture PDF
                    </a>
                </div>
            </div>

            <div style="padding:24px;">

                <!-- 4-column info grid: client, agent, payment method, date -->
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:16px; margin-bottom:24px;">

                    <!-- Client name, or "Anonyme" if no client was selected -->
                    <div>
                        <p style="color:#888; font-size:0.8rem;">CLIENT</p>
                        <p style="font-weight:600;">
                            <?php echo $detail_vente['client_nom']
                                ? htmlspecialchars($detail_vente['client_nom'])
                                : 'Anonyme'; ?>
                        </p>
                    </div>

                    <!-- Agent (employee) who recorded the sale -->
                    <div>
                        <p style="color:#888; font-size:0.8rem;">AGENT</p>
                        <p style="font-weight:600;"><?php echo htmlspecialchars($detail_vente['agent_nom']); ?></p>
                    </div>

                    <!-- Payment method: ucfirst() capitalizes the first letter -->
                    <div>
                        <p style="color:#888; font-size:0.8rem;">PAIEMENT</p>
                        <p style="font-weight:600;"><?php echo ucfirst($detail_vente['mode_paiement']); ?></p>
                    </div>

                    <!-- Sale date formatted as DD/MM/YYYY at HH:MM -->
                    <div>
                        <p style="color:#888; font-size:0.8rem;">DATE</p>
                        <p style="font-weight:600;">
                            <?php echo date('d/m/Y à H:i', strtotime($detail_vente['date_vente'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Table listing each product in this sale -->
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th>       <!-- Product name -->
                            <th>Prix unitaire</th>  <!-- Unit price at time of sale -->
                            <th>Quantité</th>       <!-- Number of units sold -->
                            <th>Sous-total</th>     <!-- Unit price × quantity -->
                        </tr>
                    </thead>
                    <tbody>

                    <!-- Loop through each product line of this sale -->
                    <?php while ($l = mysqli_fetch_assoc($detail_lignes)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($l['designation']); ?></td>
                            <!-- Format prices with 2 decimal places -->
                            <td>$<?php echo number_format($l['prix_unitaire'], 2); ?></td>
                            <td><?php echo $l['quantite']; ?></td>
                            <td><strong>$<?php echo number_format($l['sous_total'], 2); ?></strong></td>
                        </tr>
                    <?php endwhile; ?>

                    <!-- Final TOTAL row with dark background and gold text -->
                    <tr style="background:#111;">
                        <!-- colspan="3" makes this cell span 3 columns -->
                        <td colspan="3" style="text-align:right; font-weight:700; padding:14px 24px; color:#F0F0F0;">TOTAL</td>
                        <td style="font-weight:700; font-size:1.1rem; color:#D4AF37;">
                            $<?php echo number_format($detail_vente['montant_total'], 2); ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <!-- ══════════════════════════════════════════════════
             NEW SALE FORM + RECENT SALES LIST
             (shown by default when no ?voir= is in the URL)
             ══════════════════════════════════════════════════ -->

        <!-- ── NEW SALE FORM ── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3>➕ Nouvelle vente</h3>
            </div>
            <div style="padding:24px;">

                <!-- The form sends data via POST to this same page (ventes.php).
                     The hidden "action" field tells the PHP code what to do. -->
                <form method="POST" action="ventes.php">

                    <!-- Hidden field: tells the PHP code at the top that
                         this form submission is a sale to be recorded. -->
                    <input type="hidden" name="action" value="vendre">

                    <!-- Two-column grid for client and payment method selectors -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">

                        <!-- DROPDOWN: select client (or leave anonymous) -->
                        <div class="form-group">
                            <label>Client (optionnel — vente anonyme si vide)</label>
                            <select name="id_client">
                                <!-- Default option means no client = anonymous sale -->
                                <option value="">-- Vente anonyme --</option>

                                <!-- Loop through all clients from the database
                                     and create one <option> per client -->
                                <?php while ($c = mysqli_fetch_assoc($clients)): ?>
                                    <option value="<?php echo $c['id_client']; ?>">
                                        <?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- DROPDOWN: select payment method -->
                        <div class="form-group">
                            <label>Mode de paiement *</label>
                            <select name="mode_paiement">
                                <option value="especes">💵 Espèces</option>  <!-- Cash -->
                                <option value="carte">💳 Carte</option>       <!-- Card -->
                                <option value="virement">🏦 Virement</option> <!-- Bank transfer -->
                            </select>
                        </div>
                    </div>

                    <!-- Helper text explaining the 5 product rows -->
                    <p style="font-size:0.85rem; color:#888; margin-bottom:12px;">
                        Sélectionnez jusqu'à 5 produits — laissez les lignes inutiles sur "Aucun".
                    </p>

                    <!-- Table with 5 product selection rows -->
                    <table style="margin-bottom:20px;">
                        <thead>
                            <tr>
                                <th>#</th>             <!-- Row number -->
                                <th>Produit</th>       <!-- Product dropdown -->
                                <th>Prix unitaire</th> <!-- Price (display only) -->
                                <th>Quantité</th>      <!-- How many units to sell -->
                            </tr>
                        </thead>
                        <tbody>

                        <!-- PHP loop from 0 to 4 creates exactly 5 product rows -->
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr>
                                <!-- Row number (displayed as 1–5) -->
                                <td style="color:#888;"><?php echo $i + 1; ?></td>

                                <td>
                                    <!-- Product dropdown. name="id_produit[]" uses
                                         square brackets to create an array of values,
                                         one per row (id_produit[0], [1], [2]...) -->
                                    <select name="id_produit[]" style="width:280px;">
                                        <!-- "Aucun" (none) means this row is unused -->
                                        <option value="0">-- Aucun --</option>

                                        <!-- Loop through all products from the database -->
                                        <?php foreach ($produits_array as $p): ?>
                                            <option value="<?php echo $p['id_produit']; ?>">
                                                <!-- Show product name, price and current stock -->
                                                <?php echo htmlspecialchars($p['designation']); ?>
                                                — $<?php echo number_format($p['prix_unitaire'], 2); ?>
                                                (stock: <?php echo $p['stock_actuel']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <!-- This column just shows a note — price is shown in the dropdown -->
                                <td style="color:#888; font-size:0.85rem;">(affiché dans le menu)</td>

                                <td>
                                    <!-- Quantity input. name="quantite[]" also creates an array.
                                         min="1" prevents entering 0 or negative quantities. -->
                                    <input type="number" name="quantite[]" value="1" min="1" style="width:70px;">
                                </td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>

                    <!-- Submit button — sends the form data via POST -->
                    <button type="submit" class="btn btn-primary">💾 Enregistrer la vente</button>
                </form>
            </div>
        </div>

        <!-- ── RECENT SALES TABLE ── -->
        <div class="card">
            <div class="card-header">
                <h3>📋 Ventes récentes</h3>
            </div>

            <!-- If no sales have been recorded yet, show a placeholder -->
            <?php if (mysqli_num_rows($ventes) == 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">🛒</div>
                    <p>Aucune vente enregistrée pour l'instant.</p>
                </div>
            <?php else: ?>

            <!-- Table showing the 20 most recent sales -->
            <table>
                <thead>
                    <tr>
                        <th>#</th>          <!-- Sale ID -->
                        <th>Client</th>     <!-- Customer name or "Anonyme" -->
                        <th>Agent</th>      <!-- Employee who recorded the sale -->
                        <th>Paiement</th>   <!-- Payment method -->
                        <th>Total</th>      <!-- Grand total of the sale -->
                        <th>Date</th>       <!-- Date and time -->
                        <th>Détail</th>     <!-- Action buttons -->
                    </tr>
                </thead>
                <tbody>
                <!-- Loop through each sale row from the database -->
                <?php while ($v = mysqli_fetch_assoc($ventes)): ?>
                    <tr>
                        <td>#<?php echo $v['id_vente']; ?></td>

                        <td>
                            <!-- Show client name, or a blue "Anonyme" badge if none -->
                            <?php echo $v['client_nom']
                                ? htmlspecialchars($v['client_nom'])
                                : '<span class="badge badge-info">Anonyme</span>'; ?>
                        </td>

                        <td><?php echo htmlspecialchars($v['agent_nom']); ?></td>
                        <td><?php echo ucfirst($v['mode_paiement']); ?></td>
                        <td><strong>$<?php echo number_format($v['montant_total'], 2); ?></strong></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($v['date_vente'])); ?></td>

                        <!-- Two action buttons: View detail + Download PDF -->
                        <td style="display:flex; gap:6px;">

                            <!-- "Voir" link appends ?voir=ID to the URL,
                                 which triggers the detail view at the top of this page -->
                            <a href="ventes.php?voir=<?php echo $v['id_vente']; ?>"
                               class="btn btn-secondary">🔍 Voir</a>

                            <!-- "PDF" button opens facture.php to generate and download
                                 a PDF invoice for this specific sale -->
                            <a href="facture.php?id=<?php echo $v['id_vente']; ?>"
                               class="btn btn-primary"
                               style="background:#C9A227; color:#1A1A1A; font-weight:700; border:none; font-size:0.8rem;">
                                ⬇️ PDF
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </main>
</div><!-- end .dashboard-layout -->
</body>
</html>
