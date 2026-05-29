<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$erreur  = "";

// ── RECORD A NEW SALE ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'vendre') {

    $id_utilisateur = (int) $_SESSION['user_id'];
    $id_client      = !empty($_POST['id_client']) ? (int) $_POST['id_client'] : "NULL";
    $mode_paiement  = mysqli_real_escape_string($conn, $_POST['mode_paiement']);

    // Loop through the 5 product rows, skip empty ones
    $lignes_valides = [];
    for ($i = 0; $i < 5; $i++) {
        $id_produit = (int) $_POST['id_produit'][$i];
        $quantite   = (int) $_POST['quantite'][$i];

        if ($id_produit == 0 || $quantite <= 0) {
            continue; // Skip empty rows
        }

        // Get this product's current price and stock from the database
        $res     = mysqli_query($conn, "SELECT designation, prix_unitaire, stock_actuel FROM produit WHERE id_produit = $id_produit");
        $produit = mysqli_fetch_assoc($res);

        // Check there is enough stock before accepting this line
        if ($produit['stock_actuel'] < $quantite) {
            $erreur = "Stock insuffisant pour : " . $produit['designation'] .
                      " (disponible : " . $produit['stock_actuel'] . ")";
            break;
        }

        $lignes_valides[] = [
            'id_produit'    => $id_produit,
            'quantite'      => $quantite,
            'prix_unitaire' => (float) $produit['prix_unitaire'],
            'designation'   => $produit['designation']
        ];
    }

    if ($erreur == "" && count($lignes_valides) > 0) {

        // Calculate the grand total
        $montant_total = 0;
        foreach ($lignes_valides as $ligne) {
            $montant_total += $ligne['prix_unitaire'] * $ligne['quantite'];
        }

        // Step 1: Insert the sale header
        $sql_vente = "INSERT INTO vente (montant_total, mode_paiement, id_client, id_utilisateur)
                      VALUES ($montant_total, '$mode_paiement', $id_client, $id_utilisateur)";

        if (mysqli_query($conn, $sql_vente)) {
            $id_vente = mysqli_insert_id($conn); // Get the ID of the sale just created

            foreach ($lignes_valides as $ligne) {

                // Step 2: Insert each product line into ligne_vente
                $sql_ligne = "INSERT INTO ligne_vente (quantite, prix_unitaire, id_vente, id_produit)
                              VALUES ({$ligne['quantite']}, {$ligne['prix_unitaire']}, $id_vente, {$ligne['id_produit']})";
                mysqli_query($conn, $sql_ligne);

                // Step 3: Decrease the stock
                $sql_stock = "UPDATE produit
                              SET stock_actuel = stock_actuel - {$ligne['quantite']}
                              WHERE id_produit = {$ligne['id_produit']}";
                mysqli_query($conn, $sql_stock);
            }

            // Step 4: Add loyalty points to the client (1 point per dollar)
            if ($id_client != "NULL") {
                $points = (int) $montant_total;
                $sql_points = "UPDATE client
                               SET points_fidelite = points_fidelite + $points
                               WHERE id_client = $id_client";
                mysqli_query($conn, $sql_points);
            }

            $message = "Vente #$id_vente enregistrée ! Total : $" . number_format($montant_total, 2);

        } else {
            $erreur = "Erreur lors de l'enregistrement : " . mysqli_error($conn);
        }

    } elseif ($erreur == "" && count($lignes_valides) == 0) {
        $erreur = "Veuillez sélectionner au moins un produit.";
    }
}

// ── FETCH CLIENTS for dropdown ─────────────────────────────
$clients = mysqli_query($conn, "SELECT id_client, nom, prenom FROM client ORDER BY nom");

// ── FETCH PRODUCTS for dropdown ────────────────────────────
$produits_res = mysqli_query($conn,
    "SELECT id_produit, designation, prix_unitaire, stock_actuel FROM produit ORDER BY designation"
);
$produits_array = [];
while ($p = mysqli_fetch_assoc($produits_res)) {
    $produits_array[] = $p;
}

// ── FETCH RECENT SALES ─────────────────────────────────────
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

// ── VIEW SALE DETAIL (?voir=ID) ────────────────────────────
$detail_vente    = null;
$detail_lignes   = null;
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
    $detail_vente = mysqli_fetch_assoc($res_detail);

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
    <title>Ventes</title>
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
            <a href="ventes.php" class="active"><span class="icon">🛒</span> Ventes</a>
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
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
            <h2>🛒 Ventes</h2>
            <div class="user-info">
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <?php if ($message != ""): ?>
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($erreur != ""): ?>
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>

        <?php if ($detail_vente): ?>
        <!-- ── SALE DETAIL VIEW ── -->
        <div class="card">
            <div class="card-header">
                <h3>🧾 Détail — Vente #<?php echo $detail_vente['id_vente']; ?></h3>
                <a href="ventes.php" class="btn btn-secondary">← Retour</a>
            </div>
            <div style="padding:24px;">
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:16px; margin-bottom:24px;">
                    <div>
                        <p style="color:#888; font-size:0.8rem;">CLIENT</p>
                        <p style="font-weight:600;"><?php echo $detail_vente['client_nom'] ? htmlspecialchars($detail_vente['client_nom']) : 'Anonyme'; ?></p>
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
                        <p style="font-weight:600;"><?php echo date('d/m/Y à H:i', strtotime($detail_vente['date_vente'])); ?></p>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr><th>Produit</th><th>Prix unitaire</th><th>Quantité</th><th>Sous-total</th></tr>
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
        <!-- ── NEW SALE FORM ── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3>➕ Nouvelle vente</h3>
            </div>
            <div style="padding:24px;">
                <form method="POST" action="ventes.php">
                    <input type="hidden" name="action" value="vendre">

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">

                        <!-- Client dropdown -->
                        <div class="form-group">
                            <label>Client (optionnel — vente anonyme si vide)</label>
                            <select name="id_client">
                                <option value="">-- Vente anonyme --</option>
                                <?php while ($c = mysqli_fetch_assoc($clients)): ?>
                                    <option value="<?php echo $c['id_client']; ?>">
                                        <?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Payment method dropdown (new field from professor's model) -->
                        <div class="form-group">
                            <label>Mode de paiement *</label>
                            <select name="mode_paiement">
                                <option value="especes">💵 Espèces</option>
                                <option value="carte">💳 Carte</option>
                                <option value="virement">🏦 Virement</option>
                            </select>
                        </div>

                    </div>

                    <!-- 5 fixed product rows — PHP skips rows left on "Aucun" -->
                    <p style="font-size:0.85rem; color:#888; margin-bottom:12px;">
                        Sélectionnez jusqu'à 5 produits — laissez les lignes inutiles sur "Aucun".
                    </p>

                    <table style="margin-bottom:20px;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Produit</th>
                                <th>Prix unitaire</th>
                                <th>Quantité</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr>
                                <td style="color:#888;"><?php echo $i + 1; ?></td>
                                <td>
                                    <select name="id_produit[]" style="width:280px;">
                                        <option value="0">-- Aucun --</option>
                                        <?php foreach ($produits_array as $p): ?>
                                            <option value="<?php echo $p['id_produit']; ?>">
                                                <?php echo htmlspecialchars($p['designation']); ?>
                                                — $<?php echo number_format($p['prix_unitaire'], 2); ?>
                                                (stock: <?php echo $p['stock_actuel']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="color:#888; font-size:0.85rem;">(affiché dans le menu)</td>
                                <td>
                                    <input type="number" name="quantite[]" value="1" min="1" style="width:70px;">
                                </td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>

                    <button type="submit" class="btn btn-primary">💾 Enregistrer la vente</button>
                </form>
            </div>
        </div>

        <!-- RECENT SALES LIST -->
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
                        <th>Détail</th>
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
                        <td><a href="ventes.php?voir=<?php echo $v['id_vente']; ?>" class="btn btn-secondary">🔍 Voir</a></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- alert-msg styles are in style.css -->
</body>
</html>
