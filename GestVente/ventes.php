<?php
/*
 * ============================================================
 * FILE: ventes.php — SALES MODULE (REDESIGNED FORM)
 * This page handles everything related to sales:
 *   1. Recording a new sale with a dynamic product line builder
 *   2. Automatically decreasing product stock after a sale
 *   3. Automatically adding loyalty points to the client
 *   4. Showing the list of recent sales
 *   5. Showing the full detail of a single sale
 *   6. Generating a downloadable PDF invoice
 *
 * NEW FEATURES:
 *   - Dynamic product rows: start with 1, add/remove with + / trash buttons
 *   - Real-time total price calculation as the agent fills the form
 *   - Unit price displayed clearly when a product is selected
 *   - Save button aligned to the right
 *   - Unsaved-changes warning if the agent tries to leave mid-form
 * ============================================================
 */

// Start the PHP session so we can read who is currently logged in.
// $_SESSION variables like user_id and user_role are stored during login.
session_start();

// Include the database connection file — gives us the $conn variable
// needed to run SQL queries against the MySQL database.
require_once 'config.php';

// SECURITY GATE: if no user_id exists in the session, the visitor is not
// logged in. Redirect them to the login page and stop execution immediately.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// $message holds a green success notification shown after a successful action.
// $erreur holds a red error notification shown when something goes wrong.
// Both start as empty strings — they are only filled when an action occurs.
$message = "";
$erreur  = "";

/*
 * ══════════════════════════════════════════════════════════════
 * PROCESS A NEW SALE (POST request with action = 'vendre')
 * ══════════════════════════════════════════════════════════════
 * When the agent submits the form, the browser sends data via POST.
 * We validate each product line and run 4 atomic database operations:
 *   Step 1 — Calculate the grand total
 *   Step 2 — Insert the sale header into the "vente" table
 *   Step 3 — Insert each product line into "ligne_vente"
 *   Step 4 — Decrease the stock for each sold product
 *   Step 5 — Add loyalty points to the client (if not anonymous)
 */

// Check: was the form submitted via POST, AND does the hidden "action" field
// equal "vendre"? This prevents other POST requests from triggering this block.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'vendre') {

    // Read the ID of the agent currently logged in.
    // (int) casts it to an integer — essential for SQL safety.
    $id_utilisateur = (int) $_SESSION['user_id'];

    // Read the selected client ID from the dropdown.
    // If the field is empty (anonymous sale), we use the SQL keyword NULL.
    $id_client = !empty($_POST['id_client']) ? (int) $_POST['id_client'] : "NULL";

    // Sanitize the payment method text to prevent SQL injection attacks.
    // mysqli_real_escape_string() escapes dangerous characters like quotes.
    $mode_paiement = mysqli_real_escape_string($conn, $_POST['mode_paiement']);

    // $lignes_valides will hold all the product lines that pass validation.
    // The agent can add as many rows as they want — we process all of them.
    $lignes_valides = [];

    // $_POST['id_produit'] is an array because the form inputs use name="id_produit[]".
    // We use isset() to check it exists before looping, in case 0 rows were submitted.
    if (isset($_POST['id_produit']) && is_array($_POST['id_produit'])) {

        // Loop through every product row submitted by the form.
        // count() gives the total number of rows sent.
        foreach ($_POST['id_produit'] as $index => $id_produit) {

            // Convert the product ID to a safe integer.
            $id_produit = (int) $id_produit;

            // Read the quantity for this row. Default to 0 if not set.
            $quantite = isset($_POST['quantite'][$index]) ? (int) $_POST['quantite'][$index] : 0;

            // Skip this row if no product was selected (id = 0) or quantity is zero/negative.
            if ($id_produit == 0 || $quantite <= 0) {
                continue; // Move on to the next row
            }

            // Fetch this product's live price and available stock from the database.
            // We always use the current database price (not the displayed value)
            // to prevent any tampering with the form data.
            $res     = mysqli_query($conn, "SELECT designation, prix_unitaire, stock_actuel FROM produit WHERE id_produit = $id_produit");
            $produit = mysqli_fetch_assoc($res);

            // Stock validation: refuse the sale if the requested quantity
            // exceeds what is currently available in stock.
            if ($produit['stock_actuel'] < $quantite) {
                $erreur = "Stock insuffisant pour : " . $produit['designation'] .
                          " (disponible : " . $produit['stock_actuel'] . ")";
                break; // Stop processing — the whole sale is rejected
            }

            // This row passed all checks — add it to our validated lines list.
            $lignes_valides[] = [
                'id_produit'    => $id_produit,
                'quantite'      => $quantite,
                'prix_unitaire' => (float) $produit['prix_unitaire'],
                'designation'   => $produit['designation']
            ];
        }
    }

    // Only proceed to save if there were no errors AND at least one valid line.
    if ($erreur == "" && count($lignes_valides) > 0) {

        // ── STEP 1: Calculate the grand total ─────────────────────
        // Loop through all valid lines and sum up quantity × unit_price.
        $montant_total = 0;
        foreach ($lignes_valides as $ligne) {
            $montant_total += $ligne['prix_unitaire'] * $ligne['quantite'];
        }

        // ── STEP 2: Insert the sale header into the "vente" table ─
        // One row in "vente" represents the entire transaction.
        // The id_vente is auto-generated by MySQL (AUTO_INCREMENT).
        $sql_vente = "INSERT INTO vente (montant_total, mode_paiement, id_client, id_utilisateur)
                      VALUES ($montant_total, '$mode_paiement', $id_client, $id_utilisateur)";

        if (mysqli_query($conn, $sql_vente)) {

            // mysqli_insert_id() returns the auto-generated id_vente we just created.
            // We MUST save this to link all the product lines to this sale.
            $id_vente = mysqli_insert_id($conn);

            // ── STEPS 3 & 4: Process each product line ─────────────
            foreach ($lignes_valides as $ligne) {

                // Insert this product line into ligne_vente.
                // Storing prix_unitaire here preserves the price history —
                // even if the product price changes later, the sale stays accurate.
                $sql_ligne = "INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit)
                              VALUES ({$ligne['quantite']}, {$ligne['prix_unitaire']}, $id_vente, {$ligne['id_produit']})";
                mysqli_query($conn, $sql_ligne);

                // ── STEP 4: Decrease the product's stock ─────────────
                // Subtract the quantity sold from the current stock.
                $sql_stock = "UPDATE produit
                              SET stock_actuel = stock_actuel - {$ligne['quantite']}
                              WHERE id_produit = {$ligne['id_produit']}";
                mysqli_query($conn, $sql_stock);
            }

            // ── STEP 5: Add loyalty points to the client ───────────
            // Only runs for named clients (not anonymous sales).
            // Rule: 1 loyalty point per monetary unit spent.
            // (int) rounds down the total so points are always whole numbers.
            if ($id_client != "NULL") {
                $points     = (int) $montant_total;
                $sql_points = "UPDATE client
                               SET points_fidelite = points_fidelite + $points
                               WHERE id_client = $id_client";
                mysqli_query($conn, $sql_points);
            }

            // Show a success message with the sale ID and formatted total.
            $message = "Vente #$id_vente enregistrée ! Total : $" . number_format($montant_total, 2);

        } else {
            // The INSERT into "vente" failed — show the MySQL error for debugging.
            $erreur = "Erreur lors de l'enregistrement : " . mysqli_error($conn);
        }

    } elseif ($erreur == "" && count($lignes_valides) == 0) {
        // The form was submitted but all product rows were empty.
        $erreur = "Veuillez sélectionner au moins un produit.";
    }
}

/*
 * ══════════════════════════════════════════════════════════════
 * FETCH DATA FOR THE FORM DROPDOWNS
 * ══════════════════════════════════════════════════════════════
 */

// Fetch all clients for the client selector dropdown, ordered alphabetically.
$clients = mysqli_query($conn, "SELECT id_client, nom, prenom FROM client ORDER BY nom");

// Fetch all products with their prices and stock levels.
// We fetch them into a PHP array so we can:
//   1. Build the dropdown options in HTML
//   2. Export the price data to JavaScript for real-time total calculation
$produits_res   = mysqli_query($conn,
    "SELECT id_produit, designation, prix_unitaire, stock_actuel FROM produit ORDER BY designation"
);
$produits_array = [];
while ($p = mysqli_fetch_assoc($produits_res)) {
    $produits_array[] = $p; // Build a PHP array, one entry per product
}

/*
 * ── EXPORT PRODUCT PRICES TO JAVASCRIPT ──────────────────────
 * We convert the PHP product array into a JSON object that
 * JavaScript can use to look up prices without calling the server.
 * Format: { "id_produit": prix_unitaire, "3": 13.49, "5": 0.99 ... }
 */
$prix_json = [];
foreach ($produits_array as $p) {
    // Key = product ID (as string), Value = unit price as a float number
    $prix_json[$p['id_produit']] = (float) $p['prix_unitaire'];
}
// json_encode() converts the PHP associative array to a JSON string
// that can be injected directly into JavaScript code.
$prix_js = json_encode($prix_json);

/*
 * ── FETCH THE 20 MOST RECENT SALES ───────────────────────────
 * JOIN with client and utilisateur tables to get names instead of raw IDs.
 * ORDER BY date DESC shows newest sales first.
 * LIMIT 20 keeps the list manageable.
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
 * Clicking "Voir" on a sale row appends ?voir=ID to the URL.
 * We detect that here and load the full sale details.
 */
$detail_vente  = null;
$detail_lignes = null;

if (isset($_GET['voir'])) {
    $id = (int) $_GET['voir'];

    // Fetch the sale header with client and agent names via LEFT JOINs.
    $res_detail = mysqli_query($conn,
        "SELECT v.*,
                CONCAT(c.prenom, ' ', c.nom) AS client_nom,
                CONCAT(u.prenom, ' ', u.nom) AS agent_nom
         FROM vente v
         LEFT JOIN client      c ON v.id_client      = c.id_client
         LEFT JOIN utilisateur u ON v.id_utilisateur = u.id_utilisateur
         WHERE v.id_vente = $id"
    );
    $detail_vente = mysqli_fetch_assoc($res_detail);

    // Fetch all the product lines (items) in this sale.
    // sous_total is calculated directly in SQL: quantity × unit_price.
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
    <meta charset="UTF-8">
    <title>Ventes — MVSTOCK</title>
    <link rel="stylesheet" href="style.css">

    <style>
        /*
         * ── INLINE STYLES FOR THE DYNAMIC SALE FORM ──────────────
         * These styles are specific to the new form design.
         * They complement the global style.css file.
         */

        /* ── Product line row container ──────────────────────── */
        /* Each product row is a horizontal flex container holding:
           a number label, the product dropdown, unit price, quantity,
           and the trash (delete) button. */
        .product-row {
            display: flex;
            align-items: center;
            gap: 12px;               /* Space between each element in the row */
            margin-bottom: 10px;     /* Space below each row */
            padding: 14px 16px;      /* Inner padding for breathing room */
            background: #111;        /* Dark background matching MVSTOCK theme */
            border: 1px solid #2A2A2A; /* Subtle dark border */
            border-radius: 4px;      /* Slightly rounded corners */
            transition: border-color 0.2s; /* Smooth border highlight on focus */
        }

        /* When any field inside a row is focused, highlight the whole row */
        .product-row:focus-within {
            border-color: #D4AF37;   /* Gold border — MVSTOCK brand color */
        }

        /* Row number label on the far left (e.g. "1", "2", "3") */
        .row-number {
            color: #555;             /* Dim gray — not meant to draw attention */
            font-size: 0.8rem;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
        }

        /* The product select dropdown inside each row */
        .product-row select {
            flex: 2;                 /* Takes 2 parts of available width */
            padding: 9px 12px;
            background: #0D0D0D;     /* Same dark as other form inputs */
            color: #E0E0E0;
            border: 1px solid #333;
            border-radius: 3px;
            font-size: 0.88rem;
            outline: none;
            transition: border-color 0.15s;
        }
        .product-row select:focus { border-color: #D4AF37; }
        .product-row select option { background: #171717; color: #E0E0E0; }

        /* Unit price display box (read-only, auto-filled when product selected) */
        .unit-price-box {
            flex: 1;                 /* Takes 1 part of available width */
            padding: 9px 12px;
            background: #0D0D0D;
            border: 1px solid #333;
            border-radius: 3px;
            color: #D4AF37;          /* Gold — makes the price visually prominent */
            font-size: 0.88rem;
            font-weight: 700;
            text-align: right;
            min-width: 90px;
            letter-spacing: 0.5px;
        }

        /* Quantity number input inside each row */
        .product-row input[type="number"] {
            flex: 0 0 80px;          /* Fixed width — doesn't grow or shrink */
            padding: 9px 10px;
            background: #0D0D0D;
            color: #E0E0E0;
            border: 1px solid #333;
            border-radius: 3px;
            font-size: 0.88rem;
            text-align: center;
            outline: none;
            transition: border-color 0.15s;
        }
        .product-row input[type="number"]:focus { border-color: #D4AF37; }

        /* Trash (delete row) button on the right of each row */
        .btn-remove-row {
            flex: 0 0 auto;          /* Fixed size, doesn't grow */
            background: transparent;
            border: 1px solid #3A1010;
            color: #F87171;          /* Red — signals a destructive action */
            border-radius: 3px;
            padding: 8px 10px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.15s;
            line-height: 1;
        }
        .btn-remove-row:hover {
            background: #1A0808;    /* Dark red background on hover */
            color: #FCA5A5;
        }
        /* The first row's trash button is disabled — you need at least 1 row */
        .btn-remove-row:disabled {
            opacity: 0.25;
            cursor: not-allowed;
        }

        /* "Add product" button below the rows */
        .btn-add-row {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: transparent;
            border: 1px dashed #444; /* Dashed border suggests "add something" */
            color: #888;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            transition: all 0.15s;
            margin-top: 4px;
        }
        .btn-add-row:hover {
            border-color: #D4AF37;  /* Gold on hover */
            color: #D4AF37;
            background: #111;
        }

        /* ── Real-time total display box ─────────────────────── */
        /* This box sits below all the product rows and shows the
           running total as the agent fills in the form. */
        .total-box {
            margin-top: 16px;
            padding: 16px 20px;
            background: #111;
            border: 1px solid #2A2A2A;
            border-left: 3px solid #D4AF37; /* Gold left accent bar */
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between; /* Label on left, amount on right */
        }

        /* "TOTAL ESTIMÉ" label on the left side of the total box */
        .total-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        /* The dollar amount on the right side of the total box */
        .total-amount {
            font-size: 1.5rem;
            font-weight: 800;
            color: #D4AF37;          /* Large gold number — the visual focal point */
            letter-spacing: 1px;
        }

        /* Small breakdown line below the total (shows "X items") */
        .total-breakdown {
            font-size: 0.75rem;
            color: #555;
            text-align: right;
        }

        /* Column header labels above the product rows */
        .product-row-headers {
            display: flex;
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 6px;
        }
        .product-row-headers span {
            font-size: 0.65rem;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        /* Match the flex widths of the actual row elements */
        .ph-num   { min-width: 20px; }
        .ph-prod  { flex: 2; }
        .ph-price { flex: 1; min-width: 90px; text-align: right; }
        .ph-qty   { flex: 0 0 80px; text-align: center; }
        .ph-del   { flex: 0 0 auto; min-width: 38px; }

        /* Form action bar: holds the save button aligned to the right */
        .form-actions {
            display: flex;
            justify-content: flex-end; /* Push button to the far right */
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #1E1E1E; /* Separator line above button */
        }
    </style>
</head>
<body>

<!-- .dashboard-layout is a flex container: sidebar on left, content on right -->
<div class="dashboard-layout">

    <!-- ===== SIDEBAR NAVIGATION ===== -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <h1>MVSTOCK</h1>
            <p>Sell fast, restock faster.</p>
        </div>
        <nav>
            <a href="accueil.php"><span class="icon">🏠</span> Tableau de bord</a>
            <a href="produits.php"><span class="icon">📦</span> Produits</a>
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <!-- class="active" highlights this as the current page -->
            <a href="ventes.php" class="active"><span class="icon">🛒</span> Ventes</a>
            <!-- Statistics and Users are admin-only -->
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

        <!-- Top bar: page title + logged-in user info -->
        <div class="topbar">
            <h2>🛒 Ventes</h2>
            <div class="user-info">
                <!-- htmlspecialchars() prevents XSS by escaping special HTML chars -->
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- Green success message — only shown when $message is not empty -->
        <?php if ($message != ""): ?>
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Red error message — only shown when $erreur is not empty -->
        <?php if ($erreur != ""): ?>
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>

        <?php if ($detail_vente): ?>
        <!-- ══════════════════════════════════════════════════
             SALE DETAIL VIEW (triggered by ?voir=ID in URL)
             ══════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <h3>🧾 Détail — Vente #<?php echo $detail_vente['id_vente']; ?></h3>
                <div style="display:flex; gap:10px;">
                    <!-- Back button: returns to the main sales list -->
                    <a href="ventes.php" class="btn btn-secondary">← Retour</a>

                    <!-- PDF download button: opens facture.php for this sale -->
                    <a href="facture.php?id=<?php echo $detail_vente['id_vente']; ?>"
                       class="btn btn-primary"
                       style="background:#C9A227; color:#1A1A1A; font-weight:700; border:none;">
                        ⬇️ Télécharger la facture PDF
                    </a>
                </div>
            </div>

            <div style="padding:24px;">
                <!-- 4-column grid: client, agent, payment, date -->
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:16px; margin-bottom:24px;">
                    <div>
                        <p style="color:#888; font-size:0.8rem;">CLIENT</p>
                        <p style="font-weight:600;">
                            <?php echo $detail_vente['client_nom']
                                ? htmlspecialchars($detail_vente['client_nom'])
                                : 'Anonyme'; ?>
                        </p>
                    </div>
                    <div>
                        <p style="color:#888; font-size:0.8rem;">AGENT</p>
                        <p style="font-weight:600;"><?php echo htmlspecialchars($detail_vente['agent_nom']); ?></p>
                    </div>
                    <div>
                        <p style="color:#888; font-size:0.8rem;">PAIEMENT</p>
                        <!-- ucfirst() capitalizes the first letter: "especes" → "Especes" -->
                        <p style="font-weight:600;"><?php echo ucfirst($detail_vente['mode_paiement']); ?></p>
                    </div>
                    <div>
                        <p style="color:#888; font-size:0.8rem;">DATE</p>
                        <!-- date() reformats the SQL timestamp into a readable format -->
                        <p style="font-weight:600;">
                            <?php echo date('d/m/Y à H:i', strtotime($detail_vente['date_vente'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Table of product lines for this sale -->
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th>
                            <th>Prix unitaire</th>
                            <th>Quantité</th>
                            <th>Sous-total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <!-- Loop through each product line fetched from ligne_vente -->
                    <?php while ($l = mysqli_fetch_assoc($detail_lignes)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($l['designation']); ?></td>
                            <td>$<?php echo number_format($l['prix_unitaire'], 2); ?></td>
                            <td><?php echo $l['quantite']; ?></td>
                            <td><strong>$<?php echo number_format($l['sous_total'], 2); ?></strong></td>
                        </tr>
                    <?php endwhile; ?>
                    <!-- Grand total row with dark background and gold text -->
                    <tr style="background:#111;">
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
             ══════════════════════════════════════════════════ -->

        <!-- ── NEW SALE FORM ── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3>➕ Nouvelle vente</h3>
            </div>
            <div style="padding:24px;">

                <!--
                    The form submits via POST to this same page.
                    id="sale-form" is used by JavaScript to detect unsaved changes
                    and to read all product rows when calculating the total.
                -->
                <form method="POST" action="ventes.php" id="sale-form">

                    <!-- Hidden field: tells the PHP code at the top that
                         this is a sale submission (not some other POST action). -->
                    <input type="hidden" name="action" value="vendre">

                    <!-- Two-column grid: client selector + payment method selector -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">

                        <!-- CLIENT DROPDOWN -->
                        <div class="form-group">
                            <label>Client (optionnel — anonyme si vide)</label>
                            <select name="id_client" id="client-select">
                                <option value="">-- Vente anonyme --</option>
                                <?php while ($c = mysqli_fetch_assoc($clients)): ?>
                                    <option value="<?php echo $c['id_client']; ?>">
                                        <?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- PAYMENT METHOD DROPDOWN -->
                        <div class="form-group">
                            <label>Mode de paiement *</label>
                            <select name="mode_paiement" id="payment-select">
                                <option value="especes">💵 Espèces</option>
                                <option value="carte">💳 Carte</option>
                                <option value="virement">🏦 Virement</option>
                            </select>
                        </div>
                    </div>

                    <!-- Section title for the product lines -->
                    <p style="font-size:0.75rem; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">
                        Produits vendus
                    </p>

                    <!-- Column headers above the product rows -->
                    <div class="product-row-headers">
                        <span class="ph-num">#</span>
                        <span class="ph-prod" style="flex:2;">Produit</span>
                        <span class="ph-price" style="flex:1; min-width:90px; text-align:right;">Prix unitaire</span>
                        <span class="ph-qty" style="flex:0 0 80px; text-align:center;">Qté</span>
                        <span class="ph-del" style="flex:0 0 auto; min-width:38px;"></span>
                    </div>

                    <!-- #product-lines: the container where rows are added/removed.
                         JavaScript reads this div to find all current product rows. -->
                    <div id="product-lines">
                        <!-- The first row is rendered by PHP (others are cloned by JS).
                             data-index="0" identifies this row's position. -->
                        <div class="product-row" data-index="0">

                            <!-- Row number label — updated automatically by JS when rows are added/removed -->
                            <span class="row-number">1</span>

                            <!-- Product dropdown — name uses [] to form an array on submission.
                                 onchange calls updateRow(0) to refresh the price and total. -->
                            <select name="id_produit[]" onchange="updateRow(this)" style="flex:2;">
                                <option value="0">-- Sélectionner un produit --</option>
                                <?php foreach ($produits_array as $p): ?>
                                    <option value="<?php echo $p['id_produit']; ?>"
                                            data-price="<?php echo $p['prix_unitaire']; ?>"
                                            <?php echo $p['stock_actuel'] == 0 ? 'disabled' : ''; ?>>
                                        <?php echo htmlspecialchars($p['designation']); ?>
                                        <?php echo $p['stock_actuel'] == 0 ? ' (rupture)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Unit price display: read-only, filled by JavaScript
                                 when the agent selects a product from the dropdown. -->
                            <div class="unit-price-box" id="price-display-0">—</div>

                            <!-- Quantity input. min="1" prevents entering 0 or negative.
                                 oninput fires every time the value changes (recalculates total). -->
                            <input type="number" name="quantite[]" value="1" min="1"
                                   oninput="recalculateTotal()" style="flex:0 0 80px;">

                            <!-- Trash button to remove this row.
                                 The first row's button is disabled (you need at least 1 row).
                                 onclick calls removeRow(this) which is defined in the JS below. -->
                            <button type="button" class="btn-remove-row" onclick="removeRow(this)" disabled title="Vous devez garder au moins une ligne">
                                🗑️
                            </button>
                        </div>
                    </div>

                    <!-- "Add product" button: clicking it calls addRow() in JavaScript -->
                    <button type="button" class="btn-add-row" onclick="addRow()">
                        ＋ Ajouter un produit
                    </button>

                    <!-- ── REAL-TIME TOTAL BOX ── -->
                    <!-- This box is updated live by JavaScript as the agent fills the form.
                         id="total-box" is referenced in the JS recalculateTotal() function. -->
                    <div class="total-box">
                        <div>
                            <div class="total-label">Total estimé</div>
                            <!-- id="total-items" shows something like "3 articles" -->
                            <div class="total-breakdown" id="total-items">0 article</div>
                        </div>
                        <!-- id="total-amount" is updated by JavaScript with the live total -->
                        <div class="total-amount" id="total-amount">$0.00</div>
                    </div>

                    <!-- ── FORM ACTION BAR ── -->
                    <!-- .form-actions uses justify-content: flex-end to push the button right -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" style="padding:10px 28px; font-size:0.85rem;">
                            💾 Enregistrer la vente
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <!-- ── RECENT SALES TABLE ── -->
        <div class="card">
            <div class="card-header">
                <h3>📋 Ventes récentes</h3>
            </div>

            <?php if (mysqli_num_rows($ventes) == 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">🛒</div>
                    <p>Aucune vente enregistrée pour l'instant.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Client</th>
                        <th>Agent</th>
                        <th>Paiement</th>
                        <th>Total</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($v = mysqli_fetch_assoc($ventes)): ?>
                    <tr>
                        <td>#<?php echo $v['id_vente']; ?></td>
                        <td>
                            <!-- Show client name, or an "Anonyme" badge if no client -->
                            <?php echo $v['client_nom']
                                ? htmlspecialchars($v['client_nom'])
                                : '<span class="badge badge-info">Anonyme</span>'; ?>
                        </td>
                        <td><?php echo htmlspecialchars($v['agent_nom']); ?></td>
                        <td><?php echo ucfirst($v['mode_paiement']); ?></td>
                        <td><strong>$<?php echo number_format($v['montant_total'], 2); ?></strong></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($v['date_vente'])); ?></td>
                        <td style="display:flex; gap:6px;">
                            <!-- View detail button: appends ?voir=ID to the URL -->
                            <a href="ventes.php?voir=<?php echo $v['id_vente']; ?>"
                               class="btn btn-secondary">🔍 Voir</a>

                            <!-- PDF download button: opens facture.php for this sale -->
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


<!-- ============================================================
     JAVASCRIPT — DYNAMIC FORM BEHAVIOUR
     All the interactive features of the new sale form are
     handled here:
       1. addRow()          — adds a new product line
       2. removeRow()       — removes a product line
       3. updateRow()       — fills in the unit price when a product is chosen
       4. recalculateTotal() — recalculates the live total
       5. renumberRows()    — keeps row numbers accurate after add/remove
       6. Unsaved-changes warning (beforeunload event)
     ============================================================ -->
<script>

    /*
     * PRODUCT PRICE LOOKUP TABLE
     * This JavaScript object is generated by PHP (see $prix_js above).
     * It maps product IDs to their unit prices.
     * Example: { "1": 13.49, "2": 9.49, "3": 0.99 }
     * JavaScript uses this to display prices instantly without contacting the server.
     */
    const PRICES = <?php echo $prix_js; ?>;

    /*
     * UNSAVED-CHANGES TRACKING
     * formDirty starts as false. It becomes true the moment the agent
     * interacts with any field in the form. If they try to leave the
     * page while formDirty is true, a warning dialog appears.
     */
    let formDirty = false;

    // Get a reference to the form element so we can attach event listeners to it.
    const saleForm = document.getElementById('sale-form');

    // If the sale form exists on this page (i.e. we are NOT in the detail view),
    // attach a listener that sets formDirty = true on any input or change event.
    if (saleForm) {

        // 'input' fires when the user types in a text or number field.
        // 'change' fires when the user selects a new option from a dropdown.
        // We listen to both at the form level — this catches all child elements.
        saleForm.addEventListener('input',  () => { formDirty = true; });
        saleForm.addEventListener('change', () => { formDirty = true; });

        // When the form is successfully submitted, reset formDirty to false.
        // This prevents the warning from appearing after a successful save.
        saleForm.addEventListener('submit', () => { formDirty = false; });
    }

    /*
     * BEFOREUNLOAD EVENT LISTENER
     * This fires any time the user tries to leave the page:
     * clicking a link, closing the tab, refreshing, or navigating back.
     * If the form has been touched (formDirty = true), we show a browser
     * confirmation dialog asking "Do you wish to save the current query?"
     *
     * Note: modern browsers show their own fixed message ("Changes you made
     * may not be saved") for security reasons. Setting e.returnValue is the
     * standard way to trigger this dialog.
     */
    window.addEventListener('beforeunload', function(e) {
        if (formDirty) {
            // This message may or may not be shown depending on the browser.
            // All modern browsers override it with their own standard message.
            e.preventDefault();
            e.returnValue = 'Do you wish to save the current query? Your unsaved sale will be lost.';
        }
    });


    /*
     * ── addRow() ────────────────────────────────────────────────
     * Called when the agent clicks "＋ Ajouter un produit".
     * Clones the first product row, resets its values, and appends
     * it to the #product-lines container.
     */
    function addRow() {

        // Get the container that holds all product rows.
        const container = document.getElementById('product-lines');

        // Count how many rows currently exist. This becomes the new row's index.
        const newIndex = container.querySelectorAll('.product-row').length;

        // Clone the FIRST row (index 0) as a template.
        // true = deep clone (includes all child elements inside the row).
        const firstRow  = container.querySelector('.product-row');
        const newRow    = firstRow.cloneNode(true);

        // Update the data-index attribute on the new row so each row has a unique ID.
        newRow.setAttribute('data-index', newIndex);

        // Reset the product dropdown to "Sélectionner un produit" (index 0).
        const select    = newRow.querySelector('select');
        select.selectedIndex = 0;

        // Update the onchange attribute to pass the correct row context.
        select.setAttribute('onchange', 'updateRow(this)');

        // Reset the unit price display to a dash (no product selected yet).
        const priceBox  = newRow.querySelector('.unit-price-box');
        priceBox.textContent = '—';
        priceBox.id = 'price-display-' + newIndex; // Unique ID for this row's price box

        // Reset the quantity input back to 1.
        const qtyInput  = newRow.querySelector('input[type="number"]');
        qtyInput.value  = 1;

        // Enable the trash button on the new row (it can always be deleted).
        const removeBtn = newRow.querySelector('.btn-remove-row');
        removeBtn.disabled = false;
        removeBtn.title    = '';

        // Add the new row to the container.
        container.appendChild(newRow);

        // Re-number all rows (1, 2, 3...) so the labels stay accurate.
        renumberRows();

        // Mark the form as dirty since a new row was added.
        formDirty = true;

        // Recalculate the total (new row adds 0 but updates the item count display).
        recalculateTotal();
    }


    /*
     * ── removeRow(button) ───────────────────────────────────────
     * Called when the agent clicks the trash icon on a product row.
     * Removes that row from the DOM, then renumbers and recalculates.
     *
     * @param button  The trash button element that was clicked.
     */
    function removeRow(button) {

        // .closest('.product-row') walks up the DOM tree from the button
        // until it finds the enclosing .product-row div — that's the row to delete.
        const row       = button.closest('.product-row');
        const container = document.getElementById('product-lines');

        // Safety check: never remove the last remaining row.
        // The form requires at least one product line.
        if (container.querySelectorAll('.product-row').length <= 1) {
            return; // Do nothing
        }

        // Remove the row from the page.
        row.remove();

        // Re-number the remaining rows so numbers are still consecutive.
        renumberRows();

        // Recalculate the total without the deleted row.
        recalculateTotal();
    }


    /*
     * ── updateRow(selectElement) ────────────────────────────────
     * Called when the agent changes the product selection in a dropdown.
     * Reads the selected product's price from the PRICES lookup table
     * and displays it in the unit price box for that row.
     * Then recalculates the running total.
     *
     * @param selectElement  The <select> element that changed.
     */
    function updateRow(selectElement) {

        // .closest('.product-row') finds the row container that wraps this select.
        const row = selectElement.closest('.product-row');

        // Read the selected product ID from the dropdown's current value.
        const productId = selectElement.value;

        // Find the price display box inside this specific row.
        const priceBox = row.querySelector('.unit-price-box');

        if (productId && productId != '0' && PRICES[productId] !== undefined) {
            // A valid product was selected — show its unit price formatted as "$X.XX".
            // toFixed(2) formats the number to exactly 2 decimal places.
            priceBox.textContent = '$' + parseFloat(PRICES[productId]).toFixed(2);
        } else {
            // No product selected (or "Sélectionner un produit") — show a dash.
            priceBox.textContent = '—';
        }

        // Recalculate the order total now that a product (and its price) has changed.
        recalculateTotal();
    }


    /*
     * ── recalculateTotal() ──────────────────────────────────────
     * Loops through all current product rows, reads their selected
     * product ID and quantity, looks up the price in the PRICES table,
     * and sums up the grand total. Updates the #total-amount display.
     * Called every time a product is selected or a quantity is changed.
     */
    function recalculateTotal() {

        // Get all product rows currently in the container.
        const rows  = document.querySelectorAll('.product-row');

        let total   = 0;   // Running total in monetary units
        let items   = 0;   // Total number of individual items (sum of all quantities)

        // Loop through every row.
        rows.forEach(function(row) {

            // Read the selected product ID from this row's dropdown.
            const select    = row.querySelector('select');
            const productId = select ? select.value : '0';

            // Read the quantity from this row's number input.
            // parseInt() converts the string to an integer. || 0 handles empty fields.
            const qtyInput  = row.querySelector('input[type="number"]');
            const quantity  = qtyInput ? (parseInt(qtyInput.value) || 0) : 0;

            // Look up the price from our PRICES table.
            // If the product ID is not in the table, use 0 (no price = no contribution).
            const price     = (productId && PRICES[productId]) ? parseFloat(PRICES[productId]) : 0;

            // Add this line's subtotal (price × quantity) to the running total.
            total += price * quantity;

            // Add this line's quantity to the total item count.
            items += quantity;
        });

        // Update the large total amount display.
        // toFixed(2) formats as "$37.19" — always shows 2 decimal places.
        document.getElementById('total-amount').textContent = '$' + total.toFixed(2);

        // Update the smaller "X article(s)" breakdown text.
        // French grammar: "1 article" (singular) vs "3 articles" (plural).
        const label = items <= 1 ? 'article' : 'articles';
        document.getElementById('total-items').textContent = items + ' ' + label;
    }


    /*
     * ── renumberRows() ──────────────────────────────────────────
     * After adding or removing a row, the row numbers (1, 2, 3...)
     * shown on the left may be out of sequence. This function
     * re-assigns consecutive numbers to all rows.
     * It also ensures only the first row's trash button is disabled.
     */
    function renumberRows() {

        // Get all current product rows.
        const rows = document.querySelectorAll('.product-row');

        rows.forEach(function(row, index) {

            // Update the visible number label (index is 0-based, display is 1-based).
            const numLabel = row.querySelector('.row-number');
            if (numLabel) numLabel.textContent = (index + 1);

            // Update the data-index attribute to match the new position.
            row.setAttribute('data-index', index);

            // The first row's trash button should be disabled (can't delete the last row).
            // All other rows' buttons should be enabled.
            const removeBtn = row.querySelector('.btn-remove-row');
            if (removeBtn) {
                if (rows.length === 1) {
                    // Only one row left — disable the trash button.
                    removeBtn.disabled = true;
                    removeBtn.title    = 'Vous devez garder au moins une ligne';
                } else {
                    // Multiple rows — all trash buttons are active.
                    removeBtn.disabled = false;
                    removeBtn.title    = '';
                }
            }
        });
    }

    // Run once on page load to make sure the initial state is correct.
    recalculateTotal();

</script>
</body>
</html>
