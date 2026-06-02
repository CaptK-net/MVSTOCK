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

<!-- ============================================================
     JAVASCRIPT
     ============================================================
     Functions defined here:
       validateStock()      — runs before form submits; highlights bad rows
       checkRowStock(row)   — validates one row and sets/clears its error state
       setRowError(row,msg) — adds the red glow + error message to a row
       clearRowError(row)   — removes the red glow and error message from a row
       updateRow(select)    — fills unit price when a product is chosen;
                              also triggers a stock check on that row
       onQuantityChange(input)— recalculates total AND re-checks stock on change
       recalculateTotal()   — updates the live total display
       addRow()             — clones the first row and appends it
       removeRow(button)    — removes the row containing the clicked button
       renumberRows()       — keeps row numbers and trash-button states in sync
     ============================================================ -->
<script>

    /*
     * PRICES — product ID → unit price
     * Generated by PHP from the database. Used for real-time total.
     * Example: { "1": 13.49, "3": 0.99 }
     */
    const PRICES = <?php echo $prix_js; ?>;

    /*
     * STOCKS — product ID → current stock level
     * Generated by PHP from the database.
     * Used by validateStock() and checkRowStock() to detect overages.
     * Example: { "1": 12, "2": 3, "3": 45 }
     */
    const STOCKS = <?php echo $stocks_js; ?>;

    /*
     * SERVER_ERRORS — row indices that PHP flagged as over-stock.
     * On a fresh page load or successful save this is an empty array [].
     * After a PHP-level rejection it may be e.g. [1, 3], meaning rows
     * 2 and 4 (0-based indices) had stock problems.
     * JavaScript reads this on DOMContentLoaded to highlight those rows.
     */
    const SERVER_ERRORS = <?php echo $stock_errors_js; ?>;

    /*
     * formDirty — tracks whether the agent has typed anything.
     * Set to true on first interaction; reset to false on successful submit.
     * The beforeunload handler uses this to show an unsaved-changes warning.
     */
    let formDirty = false;

    // ── Attach form-level event listeners ──────────────────────
    const saleForm = document.getElementById('sale-form');
    if (saleForm) {
        // Any input/change anywhere in the form marks it as dirty.
        saleForm.addEventListener('input',  () => { formDirty = true; });
        saleForm.addEventListener('change', () => { formDirty = true; });

        // On submit: run stock validation FIRST.
        // If validateStock() returns false, the submit is cancelled.
        saleForm.addEventListener('submit', function(e) {
            if (!validateStock()) {
                // Prevent the form from being sent to the server.
                e.preventDefault();
            } else {
                // All rows are valid — allow submission and clear dirty flag.
                formDirty = false;
            }
        });
    }

    /*
     * UNSAVED-CHANGES WARNING
     * If the agent tries to navigate away while formDirty is true,
     * the browser shows a "Leave site?" confirmation dialog.
     * Modern browsers ignore the custom message and show their own text.
     */
    window.addEventListener('beforeunload', function(e) {
        if (formDirty) {
            e.preventDefault();
            e.returnValue = 'Do you wish to save the current query? Your unsaved sale will be lost.';
        }
    });


    /*
     * ── validateStock() ─────────────────────────────────────────
     * Called just before the form is submitted.
     * Loops through every product row and checks whether the entered
     * quantity exceeds the available stock for the chosen product.
     *
     * Rows that are over stock get the red glow error state.
     * Rows that are fine have any previous error state cleared.
     *
     * Returns:
     *   true  — all rows are valid; form submission may proceed
     *   false — at least one row is invalid; form submission is blocked
     */
    function validateStock() {
        const rows = document.querySelectorAll('.product-row');
        let   hasError = false;

        rows.forEach(function(row) {
            // checkRowStock() returns true if the row is OK, false if over-stock.
            if (!checkRowStock(row)) {
                hasError = true;
            }
        });

        if (hasError) {
            // Scroll smoothly to the first red row so the agent sees it immediately.
            const firstError = document.querySelector('.product-row.row-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Return the inverse of hasError:
        //   true  = no errors  = allow submit
        //   false = has errors = block submit
        return !hasError;
    }


    /*
     * ── checkRowStock(row) ──────────────────────────────────────
     * Validates the stock for a single product row.
     *
     * Logic:
     *   - If no product is selected, always OK (blank row — ignored on save).
     *   - If STOCKS[productId] >= quantity, the row is fine → clear error.
     *   - If STOCKS[productId] < quantity, the row is over-stock → set error.
     *
     * @param  row  A .product-row DOM element
     * @return true if the row is valid (or blank), false if over-stock
     */
    function checkRowStock(row) {
        const select    = row.querySelector('select');
        const qtyInput  = row.querySelector('input[type="number"]');

        if (!select || !qtyInput) return true; // Malformed row — skip safely

        const productId = select.value;
        const quantity  = parseInt(qtyInput.value) || 0;

        // Blank row — no product selected or zero quantity — always fine.
        if (!productId || productId === '0' || quantity <= 0) {
            clearRowError(row);
            return true;
        }

        const available = (STOCKS[productId] !== undefined)
            ? parseInt(STOCKS[productId])
            : 0;

        if (quantity > available) {
            // Over-stock: show the red glow with the exact available quantity.
            setRowError(row, 'Only ' + available + ' in stock');
            return false;
        } else {
            // Within stock: make sure any previous error is removed.
            clearRowError(row);
            return true;
        }
    }


    /*
     * ── setRowError(row, message) ───────────────────────────────
     * Adds the red-glow error state to a product row and shows
     * an inline message explaining what is wrong.
     *
     * @param row      The .product-row DOM element to mark as invalid
     * @param message  The error text to show (e.g. "Only 3 in stock")
     */
    function setRowError(row, message) {

        // Add the CSS class that triggers the red border and pulsing glow animation.
        row.classList.add('row-error');

        // Check whether an error message element already exists inside this row.
        // If not, create one so we don't duplicate messages.
        let existing = row.querySelector('.stock-error-msg');
        if (!existing) {
            const msg = document.createElement('span');
            // .stock-error-msg is styled in the <style> block above:
            // small red text, absolutely positioned at the bottom of the row.
            msg.className   = 'stock-error-msg';
            msg.textContent = '⚠ ' + message;
            row.appendChild(msg); // Add the message inside the row container
        } else {
            // Update the text of the existing message if it changed.
            existing.textContent = '⚠ ' + message;
        }
    }


    /*
     * ── clearRowError(row) ──────────────────────────────────────
     * Removes the red-glow error state and the inline error message
     * from a product row. Called when the agent fixes the issue.
     *
     * @param row  The .product-row DOM element to clear
     */
    function clearRowError(row) {
        // Remove the CSS class — this stops the red border and glow animation.
        row.classList.remove('row-error');

        // Remove the error message element if it exists.
        const msg = row.querySelector('.stock-error-msg');
        if (msg) msg.remove();
    }


    /*
     * ── updateRow(selectElement) ────────────────────────────────
     * Called when the agent changes the product selection in a row.
     * Two things happen:
     *   1. The unit price box is updated with the selected product's price.
     *   2. The row stock is re-checked (clearing any previous error if the
     *      new product has enough stock for the current quantity).
     *
     * @param selectElement  The <select> element that triggered the change
     */
    function updateRow(selectElement) {
        const row       = selectElement.closest('.product-row');
        const productId = selectElement.value;
        const priceBox  = row.querySelector('.unit-price-box');

        // Update the unit price display.
        if (productId && productId !== '0' && PRICES[productId] !== undefined) {
            priceBox.textContent = '$' + parseFloat(PRICES[productId]).toFixed(2);
        } else {
            priceBox.textContent = '—';
        }

        // Re-validate this row's stock with the new product selection.
        checkRowStock(row);

        // Recalculate the running order total.
        recalculateTotal();
    }


    /*
     * ── onQuantityChange(inputElement) ──────────────────────────
     * Called whenever the agent changes the quantity in a row.
     * Two things happen:
     *   1. The running total is recalculated.
     *   2. The row's stock is re-checked so the error clears as soon
     *      as the agent reduces the quantity to a valid number.
     *
     * @param inputElement  The <input type="number"> that changed
     */
    function onQuantityChange(inputElement) {
        const row = inputElement.closest('.product-row');
        recalculateTotal();     // Update the total display
        checkRowStock(row);     // Re-validate stock for this specific row
    }


    /*
     * ── recalculateTotal() ──────────────────────────────────────
     * Iterates every product row and sums up (price × quantity)
     * to produce a live running total displayed below the rows.
     */
    function recalculateTotal() {
        const rows = document.querySelectorAll('.product-row');
        let total  = 0;
        let items  = 0;

        rows.forEach(function(row) {
            const select    = row.querySelector('select');
            const qtyInput  = row.querySelector('input[type="number"]');
            const productId = select ? select.value : '0';
            const quantity  = qtyInput ? (parseInt(qtyInput.value) || 0) : 0;
            // Look up the price; default to 0 if the product is not selected.
            const price     = (productId && PRICES[productId])
                ? parseFloat(PRICES[productId]) : 0;

            total += price * quantity;
            items += quantity;
        });

        // Update the large gold amount display.
        document.getElementById('total-amount').textContent = '$' + total.toFixed(2);

        // Update the small item-count label (French singular/plural).
        const label = items <= 1 ? 'article' : 'articles';
        document.getElementById('total-items').textContent = items + ' ' + label;
    }


    /*
     * ── addRow() ────────────────────────────────────────────────
     * Adds a new blank product row by deep-cloning the first row,
     * resetting all its values, and appending it to #product-lines.
     */
    function addRow() {
        const container = document.getElementById('product-lines');
        const newIndex  = container.querySelectorAll('.product-row').length;
        const firstRow  = container.querySelector('.product-row');
        const newRow    = firstRow.cloneNode(true); // Deep clone

        newRow.setAttribute('data-index', newIndex);

        // Clear any error state that may have been carried over from the clone source.
        newRow.classList.remove('row-error');
        const oldMsg = newRow.querySelector('.stock-error-msg');
        if (oldMsg) oldMsg.remove();

        // Reset dropdown to "Sélectionner un produit".
        const select = newRow.querySelector('select');
        select.selectedIndex = 0;
        select.setAttribute('onchange', 'updateRow(this)');

        // Reset price display.
        const priceBox = newRow.querySelector('.unit-price-box');
        priceBox.textContent = '—';
        priceBox.id = 'price-display-' + newIndex;

        // Reset quantity to 1.
        const qtyInput = newRow.querySelector('input[type="number"]');
        qtyInput.value = 1;
        // Ensure the oninput still points to the right handler.
        qtyInput.setAttribute('oninput', 'onQuantityChange(this)');

        // Enable the trash button (new rows can always be deleted).
        const removeBtn = newRow.querySelector('.btn-remove-row');
        removeBtn.disabled = false;
        removeBtn.title    = '';

        container.appendChild(newRow);
        renumberRows();
        formDirty = true;
        recalculateTotal();
    }


    /*
     * ── removeRow(button) ───────────────────────────────────────
     * Removes the product row that contains the clicked trash button.
     * Refuses to remove the last remaining row.
     */
    function removeRow(button) {
        const row       = button.closest('.product-row');
        const container = document.getElementById('product-lines');

        // Safety: never remove the only remaining row.
        if (container.querySelectorAll('.product-row').length <= 1) return;

        row.remove();
        renumberRows();
        recalculateTotal();
    }


    /*
     * ── renumberRows() ──────────────────────────────────────────
     * After adding or removing rows, re-assigns sequential numbers
     * (1, 2, 3 …) to the row labels and updates data-index attributes.
     * Also controls which trash buttons are enabled/disabled.
     */
    function renumberRows() {
        const rows = document.querySelectorAll('.product-row');

        rows.forEach(function(row, index) {
            const numLabel = row.querySelector('.row-number');
            if (numLabel) numLabel.textContent = (index + 1);
            row.setAttribute('data-index', index);

            const removeBtn = row.querySelector('.btn-remove-row');
            if (removeBtn) {
                if (rows.length === 1) {
                    // Only one row — disable trash button.
                    removeBtn.disabled = true;
                    removeBtn.title    = 'Vous devez garder au moins une ligne';
                } else {
                    removeBtn.disabled = false;
                    removeBtn.title    = '';
                }
            }
        });
    }


    /*
     * PAGE LOAD — INITIALISATION
     * Run immediately when the script executes:
     *   1. Recalculate the total (starts at $0.00).
     *   2. If PHP returned stock errors (SERVER_ERRORS array is not empty),
     *      highlight the corresponding rows so the agent sees them right away
     *      even on a server-side reloaded page.
     */
    recalculateTotal();

    // Apply server-side error highlights (if any).
    // SERVER_ERRORS is [] on a fresh page or after a successful save.
    if (SERVER_ERRORS.length > 0) {
        const rows = document.querySelectorAll('.product-row');
        SERVER_ERRORS.forEach(function(errorIndex) {
            // errorIndex is the 0-based row index from PHP.
            if (rows[errorIndex]) {
                // We don't know the exact stock message here, so we use a generic one.
                // The agent can see the exact available stock in the product dropdown label.
                setRowError(rows[errorIndex], 'Insufficient stock — reduce quantity');
            }
        });
        // Scroll to the first highlighted row.
        const firstError = document.querySelector('.product-row.row-error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

</script>
</body>
</html>
