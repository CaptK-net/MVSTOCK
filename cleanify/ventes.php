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

    // Get the client ID (can be empty if it's an anonymous sale)
    $client_id = !empty($_POST['client_id']) ? (int) $_POST['client_id'] : "NULL";

    // The agent is always the currently logged-in user
    $agent_id  = (int) $_SESSION['user_id'];

    // These are arrays — one value per product line in the form
    $produit_ids    = $_POST['produit_id'];    // e.g. [3, 7]
    $quantites      = $_POST['quantite'];      // e.g. [2, 1]
    $prix_unitaires = $_POST['prix_unitaire']; // e.g. [4.25, 13.49]

    // Calculate the grand total of the sale
    $total = 0;
    for ($i = 0; $i < count($produit_ids); $i++) {
        $total += (float)$prix_unitaires[$i] * (int)$quantites[$i];
    }

    // ── Step 1: Insert the sale header into the ventes table ──
    $sql_vente = "INSERT INTO ventes (client_id, agent_id, total)
                  VALUES ($client_id, $agent_id, $total)";

    if (mysqli_query($conn, $sql_vente)) {

        // Get the ID of the sale we just created
        $vente_id = mysqli_insert_id($conn);

        // ── Step 2: Insert each product line into vente_produits ──
        for ($i = 0; $i < count($produit_ids); $i++) {
            $produit_id    = (int)   $produit_ids[$i];
            $quantite      = (int)   $quantites[$i];
            $prix_unitaire = (float) $prix_unitaires[$i];

            // Insert this product line
            $sql_ligne = "INSERT INTO vente_produits (vente_id, produit_id, quantite, prix_unitaire)
                          VALUES ($vente_id, $produit_id, $quantite, $prix_unitaire)";
            mysqli_query($conn, $sql_ligne);

            // ── Step 3: Decrease stock for this product ──
            $sql_stock = "UPDATE produits
                          SET stock = stock - $quantite
                          WHERE id = $produit_id";
            mysqli_query($conn, $sql_stock);
        }

        // ── Step 4: Add loyalty points to the client (if not anonymous) ──
        // Rule: 1 point for every dollar spent (rounded down)
        if ($client_id != "NULL") {
            $points_gagnes = (int) $total; // e.g. $13.49 = 13 points
            $sql_points = "UPDATE users
                           SET points_fidelite = points_fidelite + $points_gagnes
                           WHERE id = $client_id";
            mysqli_query($conn, $sql_points);
        }

        $message = "Vente #$vente_id enregistrée avec succès ! Total : $" . number_format($total, 2);

    } else {
        $erreur = "Erreur lors de l'enregistrement : " . mysqli_error($conn);
    }
}

// ── FETCH ALL CLIENTS for the dropdown ────────────────────
$clients = mysqli_query($conn, "SELECT id, nom, prenom FROM users WHERE role = 'client' ORDER BY nom");

// ── FETCH ALL PRODUCTS for the dropdown ───────────────────
$produits_res = mysqli_query($conn, "SELECT id, nom, prix, stock FROM produits ORDER BY nom");

// Build an array of products to pass to JavaScript (for auto-filling prices)
$produits_array = [];
while ($p = mysqli_fetch_assoc($produits_res)) {
    $produits_array[] = $p;
}
// json_encode converts the PHP array into a JavaScript-readable format
$produits_json = json_encode($produits_array);

// ── FETCH RECENT SALES ─────────────────────────────────────
// Join with users table twice: once for client, once for agent
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

// ── VIEW SALE DETAILS ──────────────────────────────────────
// If ?voir=ID is in the URL, load the detail of that sale
$detail_vente    = null;
$detail_produits = null;
if (isset($_GET['voir'])) {
    $id = (int) $_GET['voir'];

    // Get the sale header info
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

    // Get the products that were in this sale
    $detail_produits = mysqli_query($conn,
        "SELECT p.nom, p.image_url, vp.quantite, vp.prix_unitaire,
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
        <!-- SALE DETAIL VIEW (shown when ?voir=ID is in URL) -->
        <!-- ════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <h3>🧾 Détail de la vente #<?php echo $detail_vente['id']; ?></h3>
                <a href="ventes.php" class="btn btn-secondary">← Retour</a>
            </div>
            <div style="padding:24px;">

                <!-- Sale summary info -->
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:24px;">
                    <div>
                        <p style="color:#94A3B8; font-size:0.8rem;">CLIENT</p>
                        <p style="font-weight:600;">
                            <?php echo $detail_vente['client_nom'] ?? 'Anonyme'; ?>
                        </p>
                    </div>
                    <div>
                        <p style="color:#94A3B8; font-size:0.8rem;">AGENT</p>
                        <p style="font-weight:600;">
                            <?php echo htmlspecialchars($detail_vente['agent_nom']); ?>
                        </p>
                    </div>
                    <div>
                        <p style="color:#94A3B8; font-size:0.8rem;">DATE</p>
                        <p style="font-weight:600;">
                            <?php echo date('d/m/Y à H:i', strtotime($detail_vente['date_vente'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Products in this sale -->
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
                            <td><strong><?php echo htmlspecialchars($ligne['nom']); ?></strong></td>
                            <td>$<?php echo number_format($ligne['prix_unitaire'], 2); ?></td>
                            <td><?php echo $ligne['quantite']; ?></td>
                            <td><strong>$<?php echo number_format($ligne['sous_total'], 2); ?></strong></td>
                        </tr>
                    <?php endwhile; ?>
                        <!-- Grand total row -->
                        <tr style="background:#F8FAFC;">
                            <td colspan="3" style="text-align:right; font-weight:700; padding:14px 24px;">
                                TOTAL
                            </td>
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
        <!-- NORMAL VIEW: new sale form + recent sales list  -->
        <!-- ════════════════════════════════════════════════ -->

        <!-- ── NEW SALE FORM ── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3>➕ Nouvelle vente</h3>
                <button class="btn btn-secondary" onclick="toggleForm()">Réduire ▲</button>
            </div>

            <div id="form-vente" style="padding:24px;">
                <form method="POST" action="ventes.php">
                    <input type="hidden" name="action" value="vendre">

                    <!-- Client selection (optional) -->
                    <div class="form-group" style="max-width:400px;">
                        <label>Client (optionnel — laisser vide pour une vente anonyme)</label>
                        <select name="client_id">
                            <option value="">-- Vente anonyme --</option>
                            <?php
                            // Show all clients in the dropdown
                            while ($c = mysqli_fetch_assoc($clients)):
                            ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Products table -->
                    <table id="table-produits" style="margin-bottom:16px;">
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Prix unitaire</th>
                                <th>Quantité</th>
                                <th>Sous-total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="lignes-produits">
                            <!-- The first product row (always visible) -->
                            <tr class="ligne-produit">
                                <td>
                                    <select name="produit_id[]" class="select-produit" onchange="remplirPrix(this)" required>
                                        <option value="">-- Choisir un produit --</option>
                                        <?php
                                        // Loop through products to build the dropdown options
                                        foreach ($produits_array as $p):
                                        ?>
                                            <option value="<?php echo $p['id']; ?>"
                                                    data-prix="<?php echo $p['prix']; ?>">
                                                <?php echo htmlspecialchars($p['nom']); ?>
                                                (stock: <?php echo $p['stock']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <!-- Price is filled automatically when a product is selected -->
                                    <input type="number" name="prix_unitaire[]" class="prix-input"
                                           step="0.01" min="0" readonly
                                           style="width:90px; background:#F8FAFC;">
                                </td>
                                <td>
                                    <input type="number" name="quantite[]" class="quantite-input"
                                           min="1" value="1" style="width:70px;"
                                           onchange="calculerTotal()">
                                </td>
                                <td class="sous-total">$0.00</td>
                                <td>
                                    <!-- Can't remove the first row -->
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Button to add another product row -->
                    <button type="button" class="btn btn-secondary" onclick="ajouterLigne()" style="margin-bottom:20px;">
                        ➕ Ajouter un produit
                    </button>

                    <!-- Grand total display -->
                    <div style="text-align:right; font-size:1.2rem; font-weight:700; color:#0D9488; margin-bottom:20px;">
                        Total : <span id="total-affiche">$0.00</span>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        💾 Enregistrer la vente
                    </button>

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
                            <a href="ventes.php?voir=<?php echo $v['id']; ?>" class="btn btn-secondary">
                                🔍 Voir
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php endif; // end of normal view ?>

    </main>
</div>

<!-- Alert message styles -->
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

/* Style for product dropdown in the form */
#table-produits select { width: 220px; }
</style>

<script>
// Pass all product data from PHP into a JavaScript array
// This lets us look up a product's price instantly when selected
var produits = <?php echo $produits_json; ?>;

// ── When a product is selected, auto-fill its price ────────
function remplirPrix(selectElement) {
    // Get the selected option's data-prix attribute
    var selectedOption = selectElement.options[selectElement.selectedIndex];
    var prix = selectedOption.getAttribute('data-prix');

    // Find the price input in the same row and set its value
    var row = selectElement.closest('tr');
    var prixInput = row.querySelector('.prix-input');
    prixInput.value = prix ? parseFloat(prix).toFixed(2) : '';

    // Recalculate the total
    calculerTotal();
}

// ── Recalculate all subtotals and the grand total ──────────
function calculerTotal() {
    var lignes = document.querySelectorAll('.ligne-produit');
    var total = 0;

    lignes.forEach(function(ligne) {
        var prix     = parseFloat(ligne.querySelector('.prix-input').value)    || 0;
        var quantite = parseInt(ligne.querySelector('.quantite-input').value)  || 0;
        var sousTotalCell = ligne.querySelector('.sous-total');

        var sousTotal = prix * quantite;
        sousTotalCell.textContent = '$' + sousTotal.toFixed(2);
        total += sousTotal;
    });

    // Update the grand total display
    document.getElementById('total-affiche').textContent = '$' + total.toFixed(2);
}

// ── Add a new product row to the form ─────────────────────
function ajouterLigne() {
    // Copy the first row as a template for the new row
    var tbody = document.getElementById('lignes-produits');
    var premiereLigne = tbody.querySelector('.ligne-produit');
    var nouvelleLigne = premiereLigne.cloneNode(true); // cloneNode copies the HTML

    // Reset the values in the new row
    nouvelleLigne.querySelector('.select-produit').value = '';
    nouvelleLigne.querySelector('.prix-input').value    = '';
    nouvelleLigne.querySelector('.quantite-input').value = 1;
    nouvelleLigne.querySelector('.sous-total').textContent = '$0.00';

    // Add a Remove button to this new row
    nouvelleLigne.querySelector('td:last-child').innerHTML =
        '<button type="button" class="btn btn-danger" onclick="supprimerLigne(this)">✕</button>';

    tbody.appendChild(nouvelleLigne);
}

// ── Remove a product row ───────────────────────────────────
function supprimerLigne(bouton) {
    var ligne = bouton.closest('tr');
    ligne.remove();
    calculerTotal(); // Recalculate after removing
}

// ── Show/hide the new sale form ───────────────────────────
function toggleForm() {
    var f = document.getElementById('form-vente');
    f.style.display = (f.style.display === 'none') ? 'block' : 'none';
}
</script>

</body>
</html>
