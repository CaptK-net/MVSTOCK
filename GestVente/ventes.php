<?php
/*
 * ============================================================
 * FILE: ventes.php — SALES MODULE
 *
 * Key behaviours:
 *   1. Recording a new sale with a dynamic product line builder
 *   2. Per-row stock validation — only the out-of-stock row
 *      glows red; the rest of the order is preserved
 *   3. Automatically decreasing product stock after a sale
 *   4. Automatically adding loyalty points to the client
 *   5. Showing the list of recent sales
 *   6. Showing the full detail of a single sale
 *   7. Generating a downloadable PDF invoice
 *
 * STOCK VALIDATION STRATEGY
 * --------------------------
 * Client-side (JavaScript — primary layer):
 *   When the agent clicks "Enregistrer", JS checks every filled
 *   row against the STOCKS lookup table (embedded by PHP).
 *   If a row exceeds available stock, that row glows red and the
 *   form submission is blocked. All other rows stay intact.
 *   The agent simply fixes the quantity (or removes the row)
 *   and tries again — no data is lost.
 *
 * Server-side (PHP — safety-net layer):
 *   Even if JS is disabled, PHP re-validates every row.
 *   Instead of stopping at the first bad line (old behaviour),
 *   it now collects ALL problem lines into $stock_errors[].
 *   These error indices are passed back to JS so the rows can
 *   still be highlighted on the reloaded page.
 * ============================================================
 */

// Resume the PHP session so we can read $_SESSION variables
// (user_id, user_nom, user_role) that were set at login.
session_start();

// Include the database connection — gives us $conn for SQL queries.
require_once 'config.php';

// SECURITY GATE: redirect unauthenticated visitors to the login page.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Feedback variables shown to the user after a form action.
// $message = green success bar.  $erreur = red error bar.
$message      = "";
$erreur       = "";

/*
 * $stock_errors holds the form-row indices (0-based) of lines
 * that failed the server-side stock check.
 * It is encoded as JSON and passed to JavaScript so that those
 * rows can be highlighted red even on a PHP-reloaded page.
 * Example: [1, 3]  means rows 2 and 4 (0-indexed) had stock issues.
 */
$stock_errors = [];

/*
 * ══════════════════════════════════════════════════════════════
 * PROCESS A NEW SALE  (POST, action = 'vendre')
 * ══════════════════════════════════════════════════════════════
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST' &&
    isset($_POST['action']) && $_POST['action'] == 'vendre') {

    // ID of the logged-in agent — cast to int for SQL safety.
    $id_utilisateur = (int) $_SESSION['user_id'];

    // Client ID from dropdown, or SQL NULL for anonymous sales.
    $id_client = !empty($_POST['id_client'])
        ? (int) $_POST['id_client']
        : "NULL";

    // Sanitize payment method — escapes dangerous SQL characters.
    $mode_paiement = mysqli_real_escape_string($conn, $_POST['mode_paiement']);

    // $lignes_valides: rows that passed ALL validation checks.
    $lignes_valides = [];

    // Only process if at least one product row was submitted.
    if (isset($_POST['id_produit']) && is_array($_POST['id_produit'])) {

        foreach ($_POST['id_produit'] as $index => $id_produit) {

            $id_produit = (int) $id_produit;
            $quantite   = isset($_POST['quantite'][$index])
                ? (int) $_POST['quantite'][$index]
                : 0;

            // Skip blank rows (product not selected, or quantity ≤ 0).
            if ($id_produit == 0 || $quantite <= 0) {
                continue;
            }

            // Fetch the live price and current stock for this product.
            // We always trust the database price, not the displayed value,
            // to prevent any client-side tampering.
            $res     = mysqli_query($conn,
                "SELECT designation, prix_unitaire, stock_actuel
                 FROM produit WHERE id_produit = $id_produit"
            );
            $produit = mysqli_fetch_assoc($res);

            // ── SERVER-SIDE STOCK CHECK ───────────────────────────
            // If the requested quantity exceeds available stock,
            // record this row index in $stock_errors.
            // IMPORTANT: we do NOT break — we continue checking the
            // remaining rows so ALL problems are found in one pass.
            if ($produit['stock_actuel'] < $quantite) {
                $stock_errors[] = $index; // Remember which row failed
                continue;                 // Skip this row, check the next
            }

            // Row passed — add it to the validated list.
            $lignes_valides[] = [
                'id_produit'    => $id_produit,
                'quantite'      => $quantite,
                'prix_unitaire' => (float) $produit['prix_unitaire'],
                'designation'   => $produit['designation'],
            ];
        }
    }

    // If any rows had stock problems, tell the agent which ones failed.
    // The order is NOT saved — they must fix the highlighted rows first.
    if (!empty($stock_errors)) {
        $erreur = "Certains produits dépassent le stock disponible. "
                . "Veuillez corriger les lignes surlignées en rouge.";

    } elseif (count($lignes_valides) === 0) {
        // All rows were blank — no product was selected at all.
        $erreur = "Veuillez sélectionner au moins un produit.";

    } else {
        // ── ALL ROWS VALID — SAVE THE SALE ──────────────────────

        // STEP 1: Calculate the grand total.
        $montant_total = 0;
        foreach ($lignes_valides as $ligne) {
            $montant_total += $ligne['prix_unitaire'] * $ligne['quantite'];
        }

        // STEP 2: Insert the sale header into the "vente" table.
        // id_vente is auto-generated by MySQL (AUTO_INCREMENT).
        $sql_vente =
            "INSERT INTO vente (montant_total, mode_paiement, id_client, id_utilisateur)
             VALUES ($montant_total, '$mode_paiement', $id_client, $id_utilisateur)";

        if (mysqli_query($conn, $sql_vente)) {

            // mysqli_insert_id() returns the ID of the row we just inserted.
            // We need it to link all product lines to this sale.
            $id_vente = mysqli_insert_id($conn);

            foreach ($lignes_valides as $ligne) {

                // STEP 3: Insert each product line into ligne_vente.
                // Storing prix_unitaire here preserves the historical price —
                // if the product price changes tomorrow, old invoices stay correct.
                mysqli_query($conn,
                    "INSERT INTO ligne_vente
                         (quantite, prix_unitaire, id_vente, id_produit)
                     VALUES
                         ({$ligne['quantite']}, {$ligne['prix_unitaire']},
                          $id_vente, {$ligne['id_produit']})"
                );

                // STEP 4: Deduct the sold quantity from the product's stock.
                mysqli_query($conn,
                    "UPDATE produit
                     SET stock_actuel = stock_actuel - {$ligne['quantite']}
                     WHERE id_produit = {$ligne['id_produit']}"
                );
            }

            // STEP 5: Award loyalty points to the client (not for anonymous sales).
            // Rule: 1 point per monetary unit spent. (int) ensures whole numbers.
            if ($id_client != "NULL") {
                $points = (int) $montant_total;
                mysqli_query($conn,
                    "UPDATE client
                     SET points_fidelite = points_fidelite + $points
                     WHERE id_client = $id_client"
                );
            }

            $message = "Vente #$id_vente enregistrée ! Total : $"
                     . number_format($montant_total, 2);

        } else {
            $erreur = "Erreur lors de l'enregistrement : " . mysqli_error($conn);
        }
    }
}

/*
 * ══════════════════════════════════════════════════════════════
 * FETCH DATA FOR DROPDOWNS AND JAVASCRIPT LOOKUP TABLES
 * ══════════════════════════════════════════════════════════════
 */

// All clients for the client selector, ordered alphabetically.
$clients = mysqli_query($conn,
    "SELECT id_client, nom, prenom FROM client ORDER BY nom"
);

// All products with price AND stock level.
// Both are needed: price → real-time total; stock → client-side validation.
$produits_res   = mysqli_query($conn,
    "SELECT id_produit, designation, prix_unitaire, stock_actuel
     FROM produit ORDER BY designation"
);
$produits_array = [];
while ($p = mysqli_fetch_assoc($produits_res)) {
    $produits_array[] = $p;
}

/*
 * Build two parallel JSON objects for JavaScript:
 *
 *   PRICES  — maps product ID → unit price
 *             Used for: real-time total calculation
 *             Example: { "1": 13.49, "3": 0.99 }
 *
 *   STOCKS  — maps product ID → current stock level
 *             Used for: per-row stock validation before form submission
 *             Example: { "1": 12, "3": 45 }
 *
 * json_encode() converts the PHP associative arrays to valid JS syntax.
 */
$prix_json   = [];
$stocks_json = [];
foreach ($produits_array as $p) {
    $prix_json[$p['id_produit']]   = (float) $p['prix_unitaire'];
    $stocks_json[$p['id_produit']] = (int)   $p['stock_actuel'];
}
$prix_js    = json_encode($prix_json);
$stocks_js  = json_encode($stocks_json);

// Encode the PHP $stock_errors array as JSON so JavaScript can read it.
// If no errors occurred (successful save or fresh page load), this is "[]".
$stock_errors_js = json_encode($stock_errors);

// 20 most recent sales for the table at the bottom of the page.
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

// Sale detail view: triggered when ?voir=ID is in the URL.
$detail_vente  = null;
$detail_lignes = null;
if (isset($_GET['voir'])) {
    $id = (int) $_GET['voir'];
    $res_detail = mysqli_query($conn,
        "SELECT v.*,
                CONCAT(c.prenom, ' ', c.nom) AS client_nom,
                CONCAT(u.prenom, ' ', u.nom) AS agent_nom
         FROM vente v
         LEFT JOIN client      c ON v.id_client      = c.id_client
         LEFT JOIN utilisateur u ON v.id_utilisateur = u.id_utilisateur
         WHERE v.id_vente = $id"
    );
    $detail_vente  = mysqli_fetch_assoc($res_detail);
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
        /* ============================================================
           INLINE STYLES — SALE FORM
           These complement style.css and cover the dynamic form
           elements that are specific to this page.
           ============================================================ */

        /* ── Product row container ─────────────────────────────── */
        .product-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            padding: 14px 16px;
            background: #111;
            border: 1px solid #2A2A2A;
            border-radius: 4px;
            /* Smooth transitions for border color and box-shadow
               so the red glow appears and disappears gradually. */
            transition: border-color 0.25s, box-shadow 0.25s;
            position: relative; /* Needed so the error message can be positioned inside */
        }

        /* Gold border when any field inside the row has keyboard focus */
        .product-row:focus-within {
            border-color: #D4AF37;
        }

        /* ── ROW ERROR STATE (out-of-stock warning) ────────────── */
        /*
         * Applied by JavaScript when a row's quantity exceeds stock.
         * The red glowing border draws the agent's eye to exactly
         * which line needs to be fixed — all other rows are unaffected.
         *
         * The :focus-within rule above is overridden by specificity
         * so a focused error row stays red, not gold.
         */
        .product-row.row-error {
            border-color: #EF4444 !important; /* Solid red border */
            /* box-shadow creates the "glow" effect:
               - First shadow: tight red ring (3px spread)
               - Second shadow: wider, softer red glow (12px spread)
               The pulsing animation alternates between two glow intensities. */
            box-shadow:
                0 0 0 3px rgba(239, 68, 68, 0.20),
                0 0 12px rgba(239, 68, 68, 0.15);
            animation: pulse-red 1.6s ease-in-out infinite;
            background: #1A0808; /* Very dark red background tint */
        }

        /* Keyframe animation: the red glow pulses between two intensity levels */
        @keyframes pulse-red {
            0%, 100% {
                box-shadow:
                    0 0 0 3px rgba(239, 68, 68, 0.20),
                    0 0 12px rgba(239, 68, 68, 0.12);
            }
            50% {
                /* Glow becomes more intense at the midpoint of each cycle */
                box-shadow:
                    0 0 0 4px rgba(239, 68, 68, 0.38),
                    0 0 22px rgba(239, 68, 68, 0.28);
            }
        }

        /* Small error message shown inside the row below the fields.
           It explains exactly what is wrong (e.g. "Only 3 in stock"). */
        .stock-error-msg {
            position: absolute;    /* Positioned relative to the .product-row */
            bottom: 4px;           /* Sit near the bottom edge of the row */
            left: 50px;            /* Indent past the row number */
            font-size: 0.7rem;
            font-weight: 700;
            color: #F87171;        /* Red text matching the border */
            letter-spacing: 0.3px;
            /* Fade in smoothly when the error is added */
            animation: fadeIn 0.2s ease;
        }

        /* Simple fade-in animation for the error message */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(3px); }
            to   { opacity: 1; transform: translateY(0);   }
        }

        /* Add extra bottom padding to rows that show an error message
           so the message doesn't overlap the row content */
        .product-row.row-error {
            padding-bottom: 26px;
        }

        /* ── Row number label ──────────────────────────────────── */
        .row-number {
            color: #555;
            font-size: 0.8rem;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
        }

        /* ── Product dropdown ──────────────────────────────────── */
        .product-row select {
            flex: 2;
            padding: 9px 12px;
            background: #0D0D0D;
            color: #E0E0E0;
            border: 1px solid #333;
            border-radius: 3px;
            font-size: 0.88rem;
            outline: none;
            transition: border-color 0.15s;
        }
        .product-row select:focus    { border-color: #D4AF37; }
        .product-row select option   { background: #171717; color: #E0E0E0; }

        /* ── Unit price read-only display ──────────────────────── */
        .unit-price-box {
            flex: 1;
            padding: 9px 12px;
            background: #0D0D0D;
            border: 1px solid #333;
            border-radius: 3px;
            color: #D4AF37;        /* Gold — visually prominent */
            font-size: 0.88rem;
            font-weight: 700;
            text-align: right;
            min-width: 90px;
            letter-spacing: 0.5px;
        }

        /* ── Quantity number input ─────────────────────────────── */
        .product-row input[type="number"] {
            flex: 0 0 80px;
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

        /* ── Trash (delete row) button ─────────────────────────── */
        .btn-remove-row {
            flex: 0 0 auto;
            background: transparent;
            border: 1px solid #3A1010;
            color: #F87171;
            border-radius: 3px;
            padding: 8px 10px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.15s;
            line-height: 1;
        }
        .btn-remove-row:hover    { background: #1A0808; color: #FCA5A5; }
        .btn-remove-row:disabled { opacity: 0.25; cursor: not-allowed; }

        /* ── "Add product" dashed button ───────────────────────── */
        .btn-add-row {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: transparent;
            border: 1px dashed #444;
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
            border-color: #D4AF37;
            color: #D4AF37;
            background: #111;
        }

        /* ── Real-time total box ───────────────────────────────── */
        .total-box {
            margin-top: 16px;
            padding: 16px 20px;
            background: #111;
            border: 1px solid #2A2A2A;
            border-left: 3px solid #D4AF37;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .total-label    { font-size: 0.75rem; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 1.5px; }
        .total-amount   { font-size: 1.5rem;  font-weight: 800; color: #D4AF37; letter-spacing: 1px; }
        .total-breakdown{ font-size: 0.75rem; color: #555; text-align: right; }

        /* ── Column header labels above the rows ──────────────── */
        .product-row-headers { display: flex; gap: 12px; padding: 0 16px; margin-bottom: 6px; }
        .product-row-headers span { font-size: 0.65rem; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: 1px; }

        /* ── Form action bar (Save button) ─────────────────────── */
        .form-actions {
            display: flex;
            justify-content: flex-end; /* Button aligned to the right */
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #1E1E1E;
        }
    </style>
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
            <a href="ventes.php" class="active"><span class="icon">🛒</span> Ventes</a>
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

        <div class="topbar">
            <h2>🛒 Ventes</h2>
            <div class="user-info">
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- Success message (green) — shown after a sale is saved -->
        <?php if ($message != ""): ?>
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Error message (red) — shown when stock validation fails etc. -->
        <?php if ($erreur != ""): ?>
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>

        <?php if ($detail_vente): ?>
        <!-- ══════════════════════════════════════════════════
             SALE DETAIL VIEW  (?voir=ID)
             ══════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <h3>🧾 Détail — Vente #<?php echo $detail_vente['id_vente']; ?></h3>
                <div style="display:flex; gap:10px;">
                    <a href="ventes.php" class="btn btn-secondary">← Retour</a>
                    <a href="facture.php?id=<?php echo $detail_vente['id_vente']; ?>"
                       class="btn btn-primary"
                       style="background:#C9A227; color:#1A1A1A; font-weight:700; border:none;">
                        ⬇️ Télécharger la facture PDF
                    </a>
                </div>
            </div>
            <div style="padding:24px;">
                <!-- 4-column info grid -->
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
                        <p style="font-weight:600;"><?php echo ucfirst($detail_vente['mode_paiement']); ?></p>
                    </div>
                    <div>
                        <p style="color:#888; font-size:0.8rem;">DATE</p>
                        <p style="font-weight:600;">
                            <?php echo date('d/m/Y à H:i', strtotime($detail_vente['date_vente'])); ?>
                        </p>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th><th>Prix unitaire</th>
                            <th>Quantité</th><th>Sous-total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($l = mysqli_fetch_assoc($detail_lignes)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($l['designation']); ?></td>
                            <td>$<?php echo number_format($l['prix_unitaire'], 2); ?></td>
                            <td><?php echo $l['quantite']; ?></td>
                            <td><strong>$<?php echo number_format($l['sous_total'], 2); ?></strong></td>
                        </tr>
                    <?php endwhile; ?>
                    <tr style="background:#111;">
                        <td colspan="3" style="text-align:right;font-weight:700;padding:14px 24px;color:#F0F0F0;">TOTAL</td>
                        <td style="font-weight:700;font-size:1.1rem;color:#D4AF37;">
                            $<?php echo number_format($detail_vente['montant_total'], 2); ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <!-- ══════════════════════════════════════════════════
             NEW SALE FORM  +  RECENT SALES LIST
             ══════════════════════════════════════════════════ -->

        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3>➕ Nouvelle vente</h3>
            </div>
            <div style="padding:24px;">

                <!--
                    id="sale-form" used by:
                      - beforeunload (unsaved-changes warning)
                      - validateStock() (stock check on submit)
                -->
                <form method="POST" action="ventes.php" id="sale-form">
                    <input type="hidden" name="action" value="vendre">

                    <!-- Client + payment method selectors -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
                        <div class="form-group">
                            <label>Client (optionnel — anonyme si vide)</label>
                            <select name="id_client">
                                <option value="">-- Vente anonyme --</option>
                                <?php while ($c = mysqli_fetch_assoc($clients)): ?>
                                    <option value="<?php echo $c['id_client']; ?>">
                                        <?php echo htmlspecialchars($c['prenom'].' '.$c['nom']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Mode de paiement *</label>
                            <select name="mode_paiement">
                                <option value="especes">💵 Espèces</option>
                                <option value="carte">💳 Carte</option>
                                <option value="virement">🏦 Virement</option>
                            </select>
                        </div>
                    </div>

                    <p style="font-size:0.75rem;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;">
                        Produits vendus
                    </p>

                    <!-- Column header labels -->
                    <div class="product-row-headers">
                        <span style="min-width:20px;">#</span>
                        <span style="flex:2;">Produit</span>
                        <span style="flex:1;min-width:90px;text-align:right;">Prix unitaire</span>
                        <span style="flex:0 0 80px;text-align:center;">Qté</span>
                        <span style="flex:0 0 auto;min-width:38px;"></span>
                    </div>

                    <!-- Dynamic product rows container -->
                    <div id="product-lines">

                        <!--
                            FIRST PRODUCT ROW (rendered by PHP).
                            Additional rows are cloned from this one by JavaScript.

                            Each option carries:
                              data-price  — used to show the unit price box
                              data-stock  — used by validateStock() to check quantity
                        -->
                        <div class="product-row" data-index="0">
                            <span class="row-number">1</span>

                            <select name="id_produit[]" style="flex:2;"
                                    onchange="updateRow(this)">
                                <option value="0">-- Sélectionner un produit --</option>
                                <?php foreach ($produits_array as $p): ?>
                                    <option value="<?php echo $p['id_produit']; ?>"
                                            data-price="<?php echo $p['prix_unitaire']; ?>"
                                            data-stock="<?php echo $p['stock_actuel']; ?>"
                                            <?php echo $p['stock_actuel'] == 0 ? 'disabled' : ''; ?>>
                                        <?php echo htmlspecialchars($p['designation']); ?>
                                        <?php echo $p['stock_actuel'] == 0 ? ' (rupture)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Unit price: filled automatically by updateRow() when a product is chosen -->
                            <div class="unit-price-box" id="price-display-0">—</div>

                            <!-- Quantity: oninput triggers both total recalc AND stock re-check -->
                            <input type="number" name="quantite[]"
                                   value="1" min="1" style="flex:0 0 80px;"
                                   oninput="onQuantityChange(this)">

                            <!-- Trash button disabled on the first row (need at least 1 row) -->
                            <button type="button" class="btn-remove-row"
                                    onclick="removeRow(this)" disabled
                                    title="Vous devez garder au moins une ligne">
                                🗑️
                            </button>
                        </div>
                    </div>

                    <!-- Add-row button -->
                    <button type="button" class="btn-add-row" onclick="addRow()">
                        ＋ Ajouter un produit
                    </button>

                    <!-- Real-time total display -->
                    <div class="total-box">
                        <div>
                            <div class="total-label">Total estimé</div>
                            <div class="total-breakdown" id="total-items">0 article</div>
                        </div>
                        <div class="total-amount" id="total-amount">$0.00</div>
                    </div>

                    <!-- Save button — right-aligned -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"
                                style="padding:10px 28px; font-size:0.85rem;">
                            💾 Enregistrer la vente
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <!-- ── RECENT SALES TABLE ── -->
        <div class="card">
            <div class="card-header"><h3>📋 Ventes récentes</h3></div>
            <?php if (mysqli_num_rows($ventes) == 0): ?>
                <div class="empty-state">
                    <div class="empty-icon">🛒</div>
                    <p>Aucune vente enregistrée pour l'instant.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th><th>Client</th><th>Agent</th>
                        <th>Paiement</th><th>Total</th><th>Date</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($v = mysqli_fetch_assoc($ventes)): ?>
                    <tr>
                        <td>#<?php echo $v['id_vente']; ?></td>
                        <td>
                            <?php echo $v['client_nom']
                                ? htmlspecialchars($v['client_nom'])
                                : '<span class="badge badge-info">Anonyme</span>'; ?>
                        </td>
                        <td><?php echo htmlspecialchars($v['agent_nom']); ?></td>
                        <td><?php echo ucfirst($v['mode_paiement']); ?></td>
                        <td><strong>$<?php echo number_format($v['montant_total'], 2); ?></strong></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($v['date_vente'])); ?></td>
                        <td style="display:flex; gap:6px;">
                            <a href="ventes.php?voir=<?php echo $v['id_vente']; ?>"
                               class="btn btn-secondary">🔍 Voir</a>
                            <a href="facture.php?id=<?php echo $v['id_vente']; ?>"
                               class="btn btn-primary"
                               style="background:#C9A227;color:#1A1A1A;font-weight:700;border:none;font-size:0.8rem;">
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
</div>

<!--
    ================================================================
    JAVASCRIPT SECTION
    ================================================================

    WHAT IS JAVASCRIPT?
    -------------------
    PHP runs on the SERVER (your computer running EasyPHP).
    PHP builds the HTML page and sends it to the browser. Once sent,
    PHP is done — it cannot react to what the user does next.

    JavaScript runs in the BROWSER (Chrome, Firefox, etc.) AFTER the
    page has loaded. It can react to things the user does in real time:
    typing, clicking, changing a dropdown — without sending anything to
    the server and without reloading the page.

    In this file, JavaScript is used for three things:
      1. Show the unit price instantly when a product is selected.
      2. Calculate the running order total as the agent fills the form.
      3. Check stock levels when Save is clicked and highlight any row
         that exceeds available stock — WITHOUT wiping the rest of the form.

    HOW IS THE DATA SHARED BETWEEN PHP AND JAVASCRIPT?
    ---------------------------------------------------
    PHP runs first and "prints" JavaScript code into the page.
    For example, <?php echo $prix_js; ?> is replaced by PHP with
    something like: { "1": 13.49, "3": 0.99 }
    The browser then reads that as a JavaScript object.
    This is how PHP passes database values into JavaScript.

    FUNCTIONS DEFINED IN THIS BLOCK:
      validateStock()       — checks all rows before the form submits
      checkRowStock(row)    — checks one row; returns true=OK / false=error
      setRowError(row, msg) — adds red glow + error text to a row
      clearRowError(row)    — removes red glow and error text from a row
      updateRow(select)     — fills unit price when a product is chosen
      onQuantityChange(inp) — recalculates total + re-checks stock on qty change
      recalculateTotal()    — updates the live total display
      addRow()              — adds a new blank product row
      removeRow(button)     — deletes the row containing the clicked trash button
      renumberRows()        — keeps row numbers (1,2,3…) and buttons in sync
    ================================================================
-->
<script>

    // ================================================================
    // PART 1 — DATA LOOKUP TABLES
    // ================================================================
    // These three lines create JavaScript "objects" — think of them
    // like dictionaries or lookup tables. Each one maps a product ID
    // number to a value. They are filled in by PHP before the browser
    // reads this file, so the browser already has the database values
    // without needing to contact the server.

    // ── PRICES: maps each product ID → its unit price ────────────
    // "const" means this variable can never be reassigned.
    // It is a constant — its value is fixed for the lifetime of the page.
    // The <?php echo $prix_js; ?> part is replaced by PHP with something like:
    //   { "1": 13.49, "2": 9.49, "3": 0.99 }
    // So PRICES["1"] gives us 13.49 — the price of product #1.
    const PRICES = <?php echo $prix_js; ?>;

    // ── STOCKS: maps each product ID → its current stock level ───
    // Same idea as PRICES but for stock quantities.
    // STOCKS["1"] might be 12 — meaning 12 units of product #1 in stock.
    // JavaScript uses this to check if the agent is ordering more than available.
    const STOCKS = <?php echo $stocks_js; ?>;

    // ── SERVER_ERRORS: which row indices PHP flagged as over-stock ─
    // When PHP validates the form on the server (safety net), it records
    // which rows had stock problems as an array of numbers.
    // Example: [1, 3] means rows #2 and #4 (counting from 0) had issues.
    // On a fresh page or successful save, PHP sends back [] (empty array).
    const SERVER_ERRORS = <?php echo $stock_errors_js; ?>;


    // ================================================================
    // PART 2 — UNSAVED-CHANGES TRACKING
    // ================================================================

    // "let" declares a variable whose value CAN change later.
    // formDirty starts as false (no changes made yet).
    // It becomes true as soon as the agent types or clicks anything.
    // We use it to warn the agent before they leave the page mid-order.
    let formDirty = false;

    // document.getElementById('sale-form') searches the entire HTML page
    // for the element whose id attribute equals "sale-form" — that is our
    // <form> tag. The result is stored in saleForm so we can work with it.
    const saleForm = document.getElementById('sale-form');

    // "if (saleForm)" checks whether the element was actually found.
    // The form only exists on the "new sale" view, not on the detail view.
    // Without this check, the code below would crash on the detail page.
    if (saleForm) {

        // addEventListener() tells the browser: "when THIS event happens
        // on THIS element, run THIS piece of code".
        //
        // The 'input' event fires whenever the user types into any field.
        // () => { formDirty = true; } is an "arrow function" — a short way
        // to write a small anonymous function with no name.
        // It just sets formDirty to true, meaning "the form has been touched".
        saleForm.addEventListener('input', () => { formDirty = true; });

        // The 'change' event fires when the user picks an option from a
        // dropdown (<select>) or changes a checkbox. Same effect: mark dirty.
        saleForm.addEventListener('change', () => { formDirty = true; });

        // The 'submit' event fires when the user clicks the Save button.
        // We intercept this event to run our stock check BEFORE the form
        // data is sent to the PHP server.
        // "function(e)" — "e" is the event object the browser passes to us.
        // It represents the submit action itself and lets us cancel it.
        saleForm.addEventListener('submit', function(e) {

            // Call validateStock() which checks every row and returns:
            //   true  → all rows are fine, allow the form to submit
            //   false → at least one row has a stock problem, block submit
            if (!validateStock()) {
                // "!" means NOT. So "if (!validateStock())" means
                // "if validateStock() returned false (there were errors)".

                // e.preventDefault() cancels the default behaviour of the event.
                // The default behaviour of a form submit is to send data to
                // the server. By calling preventDefault(), we stop that from
                // happening — the page stays as-is and the red rows are shown.
                e.preventDefault();

            } else {
                // validateStock() returned true — no errors.
                // Allow the form to submit normally (do NOT call preventDefault).
                // Also reset formDirty to false so the unsaved-changes warning
                // does not appear after a successful save.
                formDirty = false;
            }
        });
    }

    // ── Page-leave warning ────────────────────────────────────────
    // window represents the browser window itself (not just our page).
    // The 'beforeunload' event fires any time the user is about to leave:
    //   - clicking a navigation link
    //   - refreshing the page
    //   - closing the browser tab
    // We use it to warn the agent before they lose their unsaved order.
    window.addEventListener('beforeunload', function(e) {

        // Only show the warning if the agent has actually started filling in
        // the form (formDirty is true). No point warning on a blank form.
        if (formDirty) {

            // e.preventDefault() is required for the warning to appear
            // in some browsers.
            e.preventDefault();

            // e.returnValue is the message shown in the dialog box.
            // Note: modern browsers (Chrome, Firefox) ignore this custom text
            // and show their own standardised message like "Leave site?".
            // But setting it is still required to trigger the dialog at all.
            e.returnValue = 'Do you wish to save the current query? Your unsaved sale will be lost.';
        }
    });


    // ================================================================
    // PART 3 — STOCK VALIDATION FUNCTIONS
    // ================================================================

    // ── validateStock() ──────────────────────────────────────────
    // This function is called when the agent clicks Save.
    // It loops through ALL product rows, checks each one, and returns:
    //   true  → every row is valid → allow form submission
    //   false → at least one row is over-stock → block form submission
    //
    // The word "function" declares a reusable block of code with a name.
    // "validateStock" is the name. The () means it takes no input parameters.
    function validateStock() {

        // document.querySelectorAll('.product-row') finds ALL elements in the
        // HTML page that have the CSS class "product-row".
        // It returns a NodeList — a list of HTML elements, similar to an array.
        const rows = document.querySelectorAll('.product-row');

        // hasError starts as false. If ANY row fails the check below,
        // it becomes true and the function will return false at the end.
        let hasError = false;

        // rows.forEach() loops through the rows list one by one.
        // For each row, it calls the anonymous function(row) { ... }
        // where "row" is the current element being processed.
        rows.forEach(function(row) {

            // Call checkRowStock() for this individual row.
            // It returns true if the row is OK, false if over-stock.
            // "!" flips the value: !true = false, !false = true.
            // So "if (!checkRowStock(row))" means "if the row has an error".
            if (!checkRowStock(row)) {
                hasError = true; // Mark that at least one error was found
            }
        });

        // If at least one row had an error, scroll the page to the first
        // red row so the agent can see it without having to scroll manually.
        if (hasError) {

            // '.product-row.row-error' is a CSS selector meaning:
            // "find an element that has BOTH the class 'product-row'
            //  AND the class 'row-error'".
            // querySelector() (without All) returns only the FIRST match.
            const firstError = document.querySelector('.product-row.row-error');

            // "if (firstError)" checks that we actually found an element.
            // (querySelector returns null if nothing matched.)
            if (firstError) {

                // scrollIntoView() scrolls the page so this element is visible.
                // { behavior: 'smooth' } makes the scroll animated rather than
                // jumping instantly.
                // { block: 'center' } positions the element in the middle of
                // the visible area rather than at the top edge.
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // "return" sends a value back to whoever called this function.
        // "!hasError" is the opposite of hasError:
        //   hasError = false → !hasError = true  → no errors → allow submit
        //   hasError = true  → !hasError = false → has errors → block submit
        return !hasError;
    }


    // ── checkRowStock(row) ────────────────────────────────────────
    // Validates the stock for ONE product row.
    // Takes one input: "row" — the HTML element for one product row.
    // Returns true if the row is fine, false if it has a stock problem.
    function checkRowStock(row) {

        // row.querySelector('select') searches INSIDE the given row element
        // for the first <select> (dropdown) element.
        // This is the product dropdown in that row.
        const select = row.querySelector('select');

        // row.querySelector('input[type="number"]') finds the quantity input
        // field inside this row. [type="number"] is an attribute selector —
        // it matches only <input> elements that have type="number".
        const qtyInput = row.querySelector('input[type="number"]');

        // If either element is missing (broken/malformed row), return true
        // to avoid crashing. "!" means NOT, so "!select" means "select is null".
        // "||" means OR — either condition being true causes the early return.
        if (!select || !qtyInput) return true;

        // .value reads the current value from a form element.
        // For a <select>, .value is the "value" attribute of the selected <option>.
        // This gives us the product's ID number (as a string like "3").
        const productId = select.value;

        // parseInt() converts a string like "5" into the number 5.
        // We need a number to do maths comparisons with STOCKS values.
        // "|| 0" is a safety fallback: if the field is empty (NaN), use 0.
        // NaN means "Not a Number" — what parseInt returns on an empty string.
        const quantity = parseInt(qtyInput.value) || 0;

        // Check if this row is blank (no product selected, or zero quantity).
        // "!" before productId means "productId is empty/null/undefined/0".
        // "===" is strict equality — "0" (string) equals '0' (also string).
        // "<= 0" means zero or negative — both invalid quantities.
        // If ANY of these are true, the row is considered blank — skip it.
        if (!productId || productId === '0' || quantity <= 0) {
            clearRowError(row); // Remove any previous error state on this row
            return true;        // Blank row is always valid
        }

        // STOCKS[productId] looks up the stock level for this product ID.
        // Example: if productId is "3", STOCKS["3"] might be 45.
        // "STOCKS[productId] !== undefined" checks that the product exists
        // in our lookup table (undefined means "key not found in the object").
        // The ternary operator: condition ? valueIfTrue : valueIfFalse
        // So: if STOCKS has this productId, use its value; otherwise use 0.
        const available = (STOCKS[productId] !== undefined)
            ? parseInt(STOCKS[productId])
            : 0;

        // Compare what the agent wants to sell vs what is available.
        if (quantity > available) {

            // Over-stock: the agent wants more than we have.
            // Call setRowError() to make this row glow red and show a message.
            // We concatenate the available number into the message string
            // using '+' — in JavaScript, '+' with strings means "join together".
            setRowError(row, 'Only ' + available + ' in stock');
            return false; // This row has an error

        } else {

            // Within stock: the quantity is acceptable.
            // Make sure any previous error state is removed from this row.
            clearRowError(row);
            return true; // This row is fine
        }
    }


    // ── setRowError(row, message) ─────────────────────────────────
    // Marks a product row as having a stock error:
    //   1. Adds the CSS class "row-error" → triggers the red glow animation
    //   2. Creates (or updates) a small red warning text inside the row
    //
    // Parameters:
    //   row     — the HTML element for the product row to mark
    //   message — the error text to display (e.g. "Only 3 in stock")
    function setRowError(row, message) {

        // row.classList is a list of all CSS classes on the element.
        // .add('row-error') adds the class "row-error" to that list.
        // The CSS block at the top of this file defines what "row-error" looks
        // like: red border + pulsing red glow animation + dark red background.
        row.classList.add('row-error');

        // Check if a stock error message element already exists inside this row.
        // We don't want to create a duplicate if setRowError is called twice.
        // querySelector returns null if nothing was found.
        let existing = row.querySelector('.stock-error-msg');

        if (!existing) {
            // No message exists yet — create a new one from scratch.

            // document.createElement('span') creates a new <span> HTML element
            // in memory. It is not yet visible — we need to add it to the page.
            const msg = document.createElement('span');

            // .className sets the CSS class(es) of the new element.
            // 'stock-error-msg' is styled in the <style> block at the top:
            // small red text, positioned at the bottom of the row.
            msg.className = 'stock-error-msg';

            // .textContent sets the visible text inside the element.
            // '⚠ ' + message produces something like "⚠ Only 3 in stock".
            msg.textContent = '⚠ ' + message;

            // row.appendChild(msg) inserts the new <span> INSIDE the row element.
            // The browser will now show it as part of that row.
            row.appendChild(msg);

        } else {
            // A message element already exists — just update its text.
            // This handles the case where the available stock changed
            // (e.g. the agent switched to a different product).
            existing.textContent = '⚠ ' + message;
        }
    }


    // ── clearRowError(row) ────────────────────────────────────────
    // Removes all error styling from a row:
    //   1. Removes the "row-error" CSS class → stops the red glow
    //   2. Deletes the inline error message element from the row
    //
    // Called when the agent fixes the issue (lowers the quantity or
    // selects a different product with enough stock).
    //
    // Parameter:
    //   row — the HTML element for the product row to clear
    function clearRowError(row) {

        // .remove('row-error') takes the class "row-error" off the element.
        // As soon as the class is gone, the CSS rules stop applying
        // and the red border + glow animation disappear immediately.
        row.classList.remove('row-error');

        // Find the error message element (if it exists) inside this row.
        const msg = row.querySelector('.stock-error-msg');

        // "if (msg)" checks that querySelector actually found something.
        // If no error message exists, we skip this line safely.
        if (msg) {
            // .remove() deletes the element from the HTML page entirely.
            // The red warning text disappears from the screen.
            msg.remove();
        }
    }


    // ================================================================
    // PART 4 — REAL-TIME PRICE AND TOTAL FUNCTIONS
    // ================================================================

    // ── updateRow(selectElement) ──────────────────────────────────
    // Called in the HTML via: onchange="updateRow(this)"
    // "this" refers to the <select> element the agent just changed.
    // Two things happen when the agent picks a product from a dropdown:
    //   1. The unit price box next to the dropdown is filled in.
    //   2. The stock is re-checked (in case the new product has less stock).
    //
    // Parameter:
    //   selectElement — the <select> dropdown that the agent just changed
    function updateRow(selectElement) {

        // .closest('.product-row') walks UP the HTML tree from the dropdown
        // until it finds a parent element with the class "product-row".
        // This gives us the entire row container that wraps this dropdown.
        const row = selectElement.closest('.product-row');

        // .value reads which product the agent selected (its ID as a string).
        const productId = selectElement.value;

        // Find the unit price display box inside this row.
        // This is the gold-coloured read-only box that shows "$13.49" etc.
        const priceBox = row.querySelector('.unit-price-box');

        // Check whether a real product was selected (not the "---" placeholder).
        // "productId !== '0'" means the agent picked a real product.
        // "PRICES[productId] !== undefined" means we have a price for it.
        if (productId && productId !== '0' && PRICES[productId] !== undefined) {

            // parseFloat() converts the stored price (which may be a string)
            // to a proper decimal number.
            // .toFixed(2) formats it to exactly 2 decimal places: 13.5 → "13.50"
            // We build the display string: "$" + "13.49" = "$13.49"
            priceBox.textContent = '$' + parseFloat(PRICES[productId]).toFixed(2);

        } else {
            // No real product selected — show a dash as placeholder.
            priceBox.textContent = '—';
        }

        // Re-check this row's stock with the newly selected product.
        // If the previous product was over-stock but the new one has enough,
        // this clears the red error from the row automatically.
        checkRowStock(row);

        // Recalculate the running order total at the bottom of the form.
        recalculateTotal();
    }


    // ── onQuantityChange(inputElement) ───────────────────────────
    // Called in the HTML via: oninput="onQuantityChange(this)"
    // "oninput" fires every time the value inside the input field changes —
    // this includes typing, using arrow keys, or clicking the spinner buttons.
    // "this" refers to the number input that changed.
    //
    // Two things happen when the agent changes a quantity:
    //   1. The running total at the bottom is recalculated.
    //   2. The stock for this row is re-checked — if the agent reduced
    //      the quantity to a valid level, the red error disappears instantly.
    //
    // Parameter:
    //   inputElement — the <input type="number"> that the agent just changed
    function onQuantityChange(inputElement) {

        // Find the row that contains this quantity input.
        const row = inputElement.closest('.product-row');

        // Update the total display at the bottom of the form.
        recalculateTotal();

        // Re-validate just this specific row (not all rows — that would be slow).
        // If the new quantity is within stock, the red glow disappears immediately.
        checkRowStock(row);
    }


    // ── recalculateTotal() ────────────────────────────────────────
    // Loops through every product row on the page, reads the selected
    // product ID and quantity from each one, looks up the price, and
    // calculates the total order amount. Then updates the display.
    //
    // This function is called every time anything changes in the form
    // so the total is always up to date.
    function recalculateTotal() {

        // Get all current product rows.
        const rows = document.querySelectorAll('.product-row');

        // These two variables accumulate the totals as we loop.
        // "let" is used (not "const") because their values change inside the loop.
        let total = 0; // Total monetary amount (e.g. 37.47)
        let items = 0; // Total number of individual units across all rows

        // Loop through each product row.
        rows.forEach(function(row) {

            // Read which product is selected in this row's dropdown.
            const select    = row.querySelector('select');
            const qtyInput  = row.querySelector('input[type="number"]');

            // Ternary operator: condition ? valueIfTrue : valueIfFalse
            // If select exists, use select.value; otherwise use '0'.
            const productId = select ? select.value : '0';

            // parseInt converts the quantity string to a number.
            // "|| 0" handles empty fields: parseInt('') returns NaN,
            // and NaN || 0 returns 0 (NaN is falsy in JavaScript).
            const quantity = qtyInput ? (parseInt(qtyInput.value) || 0) : 0;

            // Look up the price for this product.
            // If no product is selected or it's not in PRICES, use 0.
            const price = (productId && PRICES[productId])
                ? parseFloat(PRICES[productId])
                : 0;

            // "+=" means "add to the existing value".
            // total += price * quantity is the same as: total = total + (price * quantity)
            total += price * quantity;
            items += quantity;
        });

        // Update the large gold price display at the bottom of the form.
        // document.getElementById('total-amount') finds the <div id="total-amount"> element.
        // .textContent sets the visible text inside it.
        // total.toFixed(2) formats the number: 37.4700... becomes "37.47"
        document.getElementById('total-amount').textContent = '$' + total.toFixed(2);

        // Update the small "X articles" breakdown text below the total.
        // Ternary: if items is 0 or 1, use "article" (singular), otherwise "articles".
        const label = items <= 1 ? 'article' : 'articles';
        document.getElementById('total-items').textContent = items + ' ' + label;
    }


    // ================================================================
    // PART 5 — DYNAMIC ROW MANAGEMENT FUNCTIONS
    // ================================================================

    // ── addRow() ──────────────────────────────────────────────────
    // Called when the agent clicks the "＋ Ajouter un produit" button.
    // Creates a new blank product row by copying the first row,
    // clearing all its values, and adding it at the bottom of the list.
    function addRow() {

        // Find the container <div id="product-lines"> that holds all rows.
        const container = document.getElementById('product-lines');

        // Count how many rows currently exist.
        // .querySelectorAll returns a list; .length counts items in that list.
        // This count becomes the index number for the new row.
        const newIndex = container.querySelectorAll('.product-row').length;

        // Find the very first product row inside the container.
        // We use it as a template to copy from.
        const firstRow = container.querySelector('.product-row');

        // .cloneNode(true) makes an exact deep copy of the first row —
        // including ALL its child elements (dropdown, price box, input, button).
        // "true" means "deep clone" (include children).
        // "false" would only copy the outer element, not the children inside.
        const newRow = firstRow.cloneNode(true);

        // setAttribute() sets or updates an HTML attribute on an element.
        // Here we update data-index to reflect the new row's position.
        newRow.setAttribute('data-index', newIndex);

        // Clean up: the cloned row may carry over the error state from the
        // source row if it was glowing red. Remove any error state.
        newRow.classList.remove('row-error');
        const oldMsg = newRow.querySelector('.stock-error-msg');
        if (oldMsg) oldMsg.remove(); // Delete the error message if present

        // Reset the product dropdown back to "-- Sélectionner un produit --".
        const select = newRow.querySelector('select');
        // .selectedIndex = 0 selects the first option in the dropdown (index 0).
        // The first option is always the placeholder "Sélectionner un produit".
        select.selectedIndex = 0;
        // Update the onchange attribute so it still calls the right function.
        select.setAttribute('onchange', 'updateRow(this)');

        // Reset the unit price box to a dash (no product selected = no price).
        const priceBox = newRow.querySelector('.unit-price-box');
        priceBox.textContent = '—';
        // Give the price box a unique ID so it can be found individually if needed.
        // The ID becomes e.g. "price-display-2" for the third row (index 2).
        priceBox.id = 'price-display-' + newIndex;

        // Reset the quantity field back to 1.
        const qtyInput = newRow.querySelector('input[type="number"]');
        qtyInput.value = 1;
        // Make sure the oninput attribute still points to the right handler.
        qtyInput.setAttribute('oninput', 'onQuantityChange(this)');

        // Enable the trash button on the new row.
        // New rows can always be deleted.
        const removeBtn = newRow.querySelector('.btn-remove-row');
        removeBtn.disabled = false; // false = enabled (button is clickable)
        removeBtn.title    = '';    // Remove the tooltip text

        // container.appendChild(newRow) inserts the new row at the END of the
        // container div. The browser immediately displays it on the page.
        container.appendChild(newRow);

        // Re-number all rows so the labels (1, 2, 3…) are still in order.
        renumberRows();

        // Mark the form as dirty (a row was added = the form was changed).
        formDirty = true;

        // Update the total (adding a blank row contributes $0 but updates the count).
        recalculateTotal();
    }


    // ── removeRow(button) ─────────────────────────────────────────
    // Called when the agent clicks a trash 🗑️ button.
    // Deletes the entire product row that contains the clicked button.
    // The form always keeps at least one row.
    //
    // Parameter:
    //   button — the trash button element that was clicked
    function removeRow(button) {

        // .closest('.product-row') walks UP the HTML tree from the button
        // until it reaches the enclosing row div. That is the row to delete.
        const row = button.closest('.product-row');

        // We also need the container to count how many rows remain.
        const container = document.getElementById('product-lines');

        // Safety check: never delete the last remaining row.
        // "<= 1" means "one or fewer". "return" exits the function early
        // without doing anything — the row is NOT removed.
        if (container.querySelectorAll('.product-row').length <= 1) {
            return;
        }

        // row.remove() deletes this element from the HTML page entirely.
        // The row disappears immediately from the screen.
        row.remove();

        // Re-number remaining rows (1, 2, 3…) and update trash button states.
        renumberRows();

        // Recalculate the total now that this row's contribution is gone.
        recalculateTotal();
    }


    // ── renumberRows() ────────────────────────────────────────────
    // After adding or removing rows, the visible numbers (1, 2, 3…)
    // on the left side of each row and the data-index attributes may
    // be out of order. This function fixes that.
    //
    // It also manages which trash buttons are disabled:
    //   - If only 1 row remains → its trash button is disabled (can't delete last row)
    //   - If multiple rows exist → all trash buttons are enabled
    function renumberRows() {

        // Get the fresh list of all current rows (after any add/remove).
        const rows = document.querySelectorAll('.product-row');

        // forEach with two parameters: (item, index)
        // "row" = the current HTML element; "index" = its position (0, 1, 2…)
        rows.forEach(function(row, index) {

            // Find the row number label (the "1", "2", "3" span on the left).
            const numLabel = row.querySelector('.row-number');

            // If the label exists, update its text.
            // "index + 1" converts 0-based index to 1-based display number:
            //   index 0 → shows "1", index 1 → shows "2", etc.
            if (numLabel) {
                numLabel.textContent = (index + 1);
            }

            // Update the data-index attribute to match the new position.
            row.setAttribute('data-index', index);

            // Find the trash button inside this row.
            const removeBtn = row.querySelector('.btn-remove-row');

            if (removeBtn) {
                // rows.length gives the total number of rows.
                // "===" is strict equality (no type coercion).
                if (rows.length === 1) {
                    // Only one row left — disable the trash button.
                    // .disabled = true makes the button unclickable and greyed out.
                    removeBtn.disabled = true;
                    // .title is the tooltip text shown when hovering over the button.
                    removeBtn.title = 'Vous devez garder au moins une ligne';
                } else {
                    // Multiple rows — all trash buttons should be active.
                    removeBtn.disabled = false;
                    removeBtn.title    = ''; // Clear the tooltip
                }
            }
        });
    }


    // ================================================================
    // PART 6 — PAGE INITIALISATION (runs immediately on page load)
    // ================================================================
    // These lines run as soon as the browser finishes reading this script.
    // They set up the initial state of the page.

    // Calculate and display the starting total ($0.00 on a fresh page).
    recalculateTotal();

    // Check whether PHP sent back any stock error indices.
    // SERVER_ERRORS.length is 0 on a fresh page; greater than 0 after a
    // server-side rejection.
    if (SERVER_ERRORS.length > 0) {

        // Get all current product rows.
        const rows = document.querySelectorAll('.product-row');

        // Loop through the error indices PHP sent back.
        // Each errorIndex is a number like 1 or 3 — the 0-based position
        // of the row that had a stock problem.
        SERVER_ERRORS.forEach(function(errorIndex) {

            // rows[errorIndex] accesses the row at that position in the list.
            // The check "if (rows[errorIndex])" prevents a crash if the index
            // is somehow out of range.
            if (rows[errorIndex]) {
                setRowError(rows[errorIndex], 'Insufficient stock — reduce quantity');
            }
        });

        // Scroll to the first red row so the agent can see it immediately.
        const firstError = document.querySelector('.product-row.row-error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

</script>
</body>
</html>
