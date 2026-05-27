<?php
// Start the session
session_start();

// Include the database connection
require_once 'config.php';

// ── ACCESS CONTROL ─────────────────────────────────────────
// Only admins and agents can record sales
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] == 'client') {
    header("Location: index.php");
    exit();
}

$message = "";
$erreur  = "";

// ── RECORD A NEW SALE ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'vendre') {

    // The agent is always the currently logged-in user
    $agent_id = (int) $_SESSION['user_id'];

    // Client ID is optional (empty = anonymous sale)
    $client_id = !empty($_POST['client_id']) ? (int) $_POST['client_id'] : "NULL";

    // The form sends 5 rows of products
    // We loop through them and only keep rows where a product was selected
    $lignes_valides = [];
    for ($i = 0; $i < 5; $i++) {
        $pid = (int) $_POST['produit_id'][$i];
        $qty = (int) $_POST['quantite'][$i];

        // Skip this row if no product was selected or quantity is 0
        if ($pid == 0 || $qty <= 0) {
            continue;
        }

        // Get the current price of this product from the database
        $res = mysqli_query($conn, "SELECT prix, stock, nom FROM produits WHERE id = $pid");
        $produit = mysqli_fetch_assoc($res);

        // Check there is enough stock
        if ($produit['stock'] < $qty) {
            $erreur = "Stock insuffisant pour : " . $produit['nom'] .
                      " (disponible : " . $produit['stock'] . ")";
            break; // Stop processing and show the error
        }

        // Store this valid line
        $lignes_valides[] = [
            'produit_id'    => $pid,
            'quantite'      => $qty,
            'prix_unitaire' => (float) $produit['prix'],
            'nom'           => $produit['nom']
        ];
    }

    // Only proceed if there was no error and at least one product was selected
    if ($erreur == "" && count($lignes_valides) > 0) {

        // Calculate the grand total
        $total = 0;
        foreach ($lignes_valides as $ligne) {
            $total += $ligne['prix_unitaire'] * $ligne['quantite'];
        }

        // ── Step 1: Insert the sale into the ventes table ──
        $sql_vente = "INSERT INTO ventes (client_id, agent_id, total)
                      VALUES ($client_id, $agent_id, $total)";

        if (mysqli_query($conn, $sql_vente)) {

            // Get the ID of the sale we just inserted
            $vente_id = mysqli_insert_id($conn);

            // ── Step 2: Insert each product line ──
            foreach ($lignes_valides as $ligne) {
                $sql_ligne = "INSERT INTO vente_produits (vente_id, produit_id, quantite, prix_unitaire)
                              VALUES ($vente_id, {$ligne['produit_id']}, {$ligne['quantite']}, {$ligne['prix_unitaire']})";
                mysqli_query($conn, $sql_ligne);

                // ── Step 3: Reduce the stock of this product ──
                $sql_stock = "UPDATE produits
                              SET stock = stock - {$ligne['quantite']}
                              WHERE id = {$ligne['produit_id']}";
                mysqli_query($conn, $sql_stock);
            }

            // ── Step 4: Add loyalty points to the client ──
            // Rule: 1 point per dollar spent (we cast to int to round down)
            if ($client_id != "NULL") {
                $points = (int) $total;
                $sql_points = "UPDATE users
                               SET points_fidelite = points_fidelite + $points
                               WHERE id = $client_id";
                mysqli_query($conn, $sql_points);
            }

            $message = "Vente #$vente_id enregistrée ! Total : $" . number_format($total, 2);

        } else {
            $erreur = "Erreur lors de l'enregistrement : " . mysqli_error($conn);
        }

    } elseif ($erreur == "" && count($lignes_valides) == 0) {
        $erreur = "Veuillez sélectionner au moins un produit.";
    }
}

// ── FETCH ALL CLIENTS for the dropdown ────────────────────
$clients = mysqli_query($conn,
    "SELECT id, nom, prenom FROM users WHERE role = 'client' ORDER BY nom"
);

// ── FETCH ALL PRODUCTS for the dropdowns ──────────────────
$produits_res = mysqli_query($conn,
    "SELECT id, nom, prix, stock FROM produits ORDER BY nom"
);
// Store in an array so we can reuse it across the 5 form rows
$produits_array = [];
while ($p = mysqli_fetch_assoc($produits_res)) {
    $produits_array[] = $p;
}

// ── FETCH RECENT SALES ─────────────────────────────────────
$ventes = mysqli_query($conn,
    "SELECT v.id, v.date_vente, v.total,
            CONCAT(c.prenom, ' ', c.nom) AS client_nom,
            CONCAT(a.prenom, ' ', a.nom) AS agent_nom
     FROM ventes v
     LEFT JOIN users c ON v.client_id = c.id
     LEFT JOIN users a ON v.agent_id  = a.id
     ORDER BY v.date_vente DESC
     LIMIT 20"
);

// ── VIEW SALE DETAILS (?voir=ID) ───────────────────────────
$detail_vente    = null;
$detail_produits = null;
if (isset($_GET['voir'])) {
    $id = (int) $_GET['voir'];

    $res_detail = mysqli_query($conn,
        "SELECT v.*,
                CONCAT(c.prenom, ' ', c.nom) AS client_nom,
                CONCAT(a.prenom, ' ', a.nom) AS agent_nom
         FROM ventes v
         LEFT JOIN users c ON v.client_id = c.id
         LEFT JOIN users a ON v.agent_id  = a.id
         WHERE v.id = $id"
    );
    $detail_vente = mysqli_fetch_assoc($res_detail);

    $detail_produits = mysqli_query($conn,
        "SELECT p.nom, vp.quantite, vp.prix_unitaire,
                (vp.quantite * vp.prix_unitaire) AS sous_total
         FROM vente_produits vp
         JOIN produits p ON vp.produit_id = p.id
         WHERE vp.vente_id = $id"
    );
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Cleanify - Ventes</title>
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
            <a href="ventes.php" class="active"><span class="icon">🛒</span> Ventes</a>
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
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

        <!-- Success or error messages -->
        <?php if ($message != ""): ?>
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($erreur != ""): ?>
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>

        <?php if ($detail_vente): ?>
        <!-- ════════════════════════════════════════════════ -->
        <!--  SALE DETAIL VIEW  (?voir=ID)                   -->
        <!-- ════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <h3>🧾 Détail de la vente #<?php echo $detail_vente['id']; ?></h3>
                <a href="ventes.php" class="btn btn-secondary">← Retour</a>
            </div>
            <div style="padding:24px;">

                <!-- Sale summary -->
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:24px;">
                    <div>
                        <p style="color:#94A3B8; font-size:0.8rem; margin-bottom:4px;">CLIENT</p>
                        <p style="font-weight:600;">
                            <?php echo $detail_vente['client_nom'] ? htmlspecialchars($detail_vente['client_nom']) : 'Anonyme'; ?>
                        </p>
                    </div>
                    <div>
                        <p style="color:#94A3B8; font-size:0.8rem; margin-bottom:4px;">AGENT</p>
                        <p style="font-weight:600;">
                            <?php echo htmlspecialchars($detail_vente['agent_nom']); ?>
                        </p>
                    </div>
                    <div>
                        <p style="color:#94A3B8; font-size:0.8rem; margin-bottom:4px;">DATE</p>
                        <p style="font-weight:600;">
                            <?php echo date('d/m/Y à H:i', strtotime($detail_vente['date_vente'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Product lines -->
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
                    <?php while ($ligne = mysqli_fetch_assoc($detail_produits)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ligne['nom']); ?></td>
                            <td>$<?php echo number_format($ligne['prix_unitaire'], 2); ?></td>
                            <td><?php echo $ligne['quantite']; ?></td>
                            <td><strong>$<?php echo number_format($ligne['sous_total'], 2); ?></strong></td>
                        </tr>
                    <?php endwhile; ?>
                    <tr style="background:#F8FAFC;">
                        <td colspan="3" style="text-align:right; font-weight:700; padding:14px 24px;">TOTAL</td>
                        <td style="font-weight:700; font-size:1.1rem; color:#0D9488;">
                            $<?php echo number_format($detail_vente['total'], 2); ?>
                        </td>
                    </tr>
                    </tbody>
                </table>

            </div>
        </div>

        <?php else: ?>
        <!-- ════════════════════════════════════════════════ -->
        <!--  NORMAL VIEW: new sale form + sales list        -->
        <!-- ════════════════════════════════════════════════ -->

        <!-- ── NEW SALE FORM ── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3>➕ Nouvelle vente</h3>
            </div>
            <div style="padding:24px;">
                <form method="POST" action="ventes.php">
                    <input type="hidden" name="action" value="vendre">

                    <!-- Client selection -->
                    <div class="form-group" style="max-width:400px; margin-bottom:24px;">
                        <label>Client (optionnel — laisser vide pour vente anonyme)</label>
                        <select name="client_id">
                            <option value="">-- Vente anonyme --</option>
                            <?php foreach (mysqli_fetch_all($clients, MYSQLI_ASSOC) as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Product rows -->
                    <!-- The form always shows 5 rows; empty rows are ignored by PHP -->
                    <p style="font-size:0.85rem; color:#94A3B8; margin-bottom:12px;">
                        Sélectionnez jusqu'à 5 produits — laissez les lignes inutiles vides.
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
                        <?php
                        // Generate 5 identical product rows using a loop
                        for ($i = 0; $i < 5; $i++):
                        ?>
                            <tr>
                                <td style="color:#94A3B8;"><?php echo $i + 1; ?></td>
                                <td>
                                    <select name="produit_id[]" style="width:250px;">
                                        <option value="0">-- Aucun --</option>
                                        <?php foreach ($produits_array as $p): ?>
                                            <option value="<?php echo $p['id']; ?>">
                                                <?php echo htmlspecialchars($p['nom']); ?>
                                                — $<?php echo number_format($p['prix'], 2); ?>
                                                (stock: <?php echo $p['stock']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <!-- Price is shown inside the dropdown, no separate field needed -->
                                <td style="color:#94A3B8; font-size:0.85rem;">
                                    (affiché dans le menu)
                                </td>
                                <td>
                                    <input type="number" name="quantite[]"
                                           value="1" min="1" style="width:70px;">
                                </td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>

                    <button type="submit" class="btn btn-primary">
                        💾 Enregistrer la vente
                    </button>

                </form>
            </div>
        </div>

        <!-- ── RECENT SALES LIST ── -->
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
                        <th>Total</th>
                        <th>Date</th>
                        <th>Détail</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($v = mysqli_fetch_assoc($ventes)): ?>
                    <tr>
                        <td>#<?php echo $v['id']; ?></td>
                        <td>
                            <?php if ($v['client_nom']): ?>
                                <?php echo htmlspecialchars($v['client_nom']); ?>
                            <?php else: ?>
                                <span class="badge badge-info">Anonyme</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($v['agent_nom']); ?></td>
                        <td><strong>$<?php echo number_format($v['total'], 2); ?></strong></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($v['date_vente'])); ?></td>
                        <td>
                            <a href="ventes.php?voir=<?php echo $v['id']; ?>"
                               class="btn btn-secondary">🔍 Voir</a>
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

<style>
.alert-msg {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
    font-size: 0.9rem;
}
.alert-msg.success { background:#D1FAE5; color:#065F46; border-left:4px solid #059669; }
.alert-msg.danger  { background:#FEE2E2; color:#991B1B; border-left:4px solid #DC2626; }
</style>

</body>
</html>
