<?php
session_start();
require_once 'config.php';

// Only admin and agent can manage products
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$erreur  = "";

// ── DELETE A PRODUCT ───────────────────────────────────────
if (isset($_GET['delete'])) {
    $id  = (int) $_GET['delete'];
    $sql = "DELETE FROM produit WHERE id_produit = $id";
    if (mysqli_query($conn, $sql)) {
        $message = "Produit supprimé avec succès.";
    } else {
        $erreur = "Erreur lors de la suppression.";
    }
}

// ── ADD A PRODUCT ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'ajouter') {
    $designation  = mysqli_real_escape_string($conn, $_POST['designation']);
    $description  = mysqli_real_escape_string($conn, $_POST['description']);
    $prix         = (float) $_POST['prix_unitaire'];
    $stock        = (int)   $_POST['stock_actuel'];
    $seuil        = (int)   $_POST['seuil_alerte'];
    // If no category selected, store NULL
    $id_categorie = !empty($_POST['id_categorie']) ? (int) $_POST['id_categorie'] : "NULL";

    $sql = "INSERT INTO produit (designation, description, prix_unitaire, stock_actuel, seuil_alerte, id_categorie)
            VALUES ('$designation', '$description', $prix, $stock, $seuil, $id_categorie)";

    if (mysqli_query($conn, $sql)) {
        $message = "Produit \"$designation\" ajouté avec succès.";
    } else {
        $erreur = "Erreur lors de l'ajout : " . mysqli_error($conn);
    }
}

// ── EDIT A PRODUCT ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'modifier') {
    $id           = (int)   $_POST['id_produit'];
    $designation  = mysqli_real_escape_string($conn, $_POST['designation']);
    $description  = mysqli_real_escape_string($conn, $_POST['description']);
    $prix         = (float) $_POST['prix_unitaire'];
    $stock        = (int)   $_POST['stock_actuel'];
    $seuil        = (int)   $_POST['seuil_alerte'];
    $id_categorie = !empty($_POST['id_categorie']) ? (int) $_POST['id_categorie'] : "NULL";

    $sql = "UPDATE produit
            SET designation  = '$designation',
                description  = '$description',
                prix_unitaire = $prix,
                stock_actuel  = $stock,
                seuil_alerte  = $seuil,
                id_categorie  = $id_categorie
            WHERE id_produit = $id";

    if (mysqli_query($conn, $sql)) {
        header("Location: produits.php?message=Produit modifié avec succès.");
        exit();
    } else {
        $erreur = "Erreur lors de la modification : " . mysqli_error($conn);
    }
}

// ── LOAD PRODUCT TO EDIT ───────────────────────────────────
$produit_edit = null;
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $res = mysqli_query($conn, "SELECT * FROM produit WHERE id_produit = $id");
    $produit_edit = mysqli_fetch_assoc($res);
}

// ── GET MESSAGE FROM URL ───────────────────────────────────
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

// ── FETCH ALL CATEGORIES for the dropdown ─────────────────
$categories = mysqli_query($conn, "SELECT * FROM categorie ORDER BY nom_categorie");
$cat_array  = [];
while ($c = mysqli_fetch_assoc($categories)) {
    $cat_array[] = $c;
}

// ── FETCH ALL PRODUCTS (with category name via JOIN) ───────
$produits = mysqli_query($conn,
    "SELECT p.*, c.nom_categorie
     FROM produit p
     LEFT JOIN categorie c ON p.id_categorie = c.id_categorie
     ORDER BY c.nom_categorie, p.designation"
);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Produits</title>
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
            <a href="produits.php" class="active"><span class="icon">📦</span> Produits</a>
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>
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
            <h2>📦 Produits</h2>
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

        <!-- ADD / EDIT FORM -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3><?php echo $produit_edit ? '✏️ Modifier le produit' : '➕ Ajouter un produit'; ?></h3>
                <?php if ($produit_edit): ?>
                    <a href="produits.php" class="btn btn-secondary">Annuler</a>
                <?php endif; ?>
            </div>
            <div id="form-produit" style="padding:24px; <?php echo $produit_edit ? '' : 'display:none;'; ?>">
                <form method="POST" action="produits.php">
                    <input type="hidden" name="action" value="<?php echo $produit_edit ? 'modifier' : 'ajouter'; ?>">
                    <?php if ($produit_edit): ?>
                        <input type="hidden" name="id_produit" value="<?php echo $produit_edit['id_produit']; ?>">
                    <?php endif; ?>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                        <div class="form-group">
                            <label>Désignation *</label>
                            <input type="text" name="designation" required
                                value="<?php echo $produit_edit ? htmlspecialchars($produit_edit['designation']) : ''; ?>">
                        </div>

                        <!-- Category is now a dropdown (from the categorie table) -->
                        <div class="form-group">
                            <label>Catégorie</label>
                            <select name="id_categorie">
                                <option value="">-- Aucune catégorie --</option>
                                <?php foreach ($cat_array as $cat): ?>
                                    <option value="<?php echo $cat['id_categorie']; ?>"
                                        <?php echo ($produit_edit && $produit_edit['id_categorie'] == $cat['id_categorie']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['nom_categorie']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Prix unitaire ($) *</label>
                            <input type="number" name="prix_unitaire" step="0.01" min="0" required
                                value="<?php echo $produit_edit ? $produit_edit['prix_unitaire'] : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Stock actuel *</label>
                            <input type="number" name="stock_actuel" min="0" required
                                value="<?php echo $produit_edit ? $produit_edit['stock_actuel'] : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Seuil d'alerte</label>
                            <input type="number" name="seuil_alerte" min="0"
                                value="<?php echo $produit_edit ? $produit_edit['seuil_alerte'] : '5'; ?>">
                        </div>

                        <div class="form-group" style="grid-column:span 2;">
                            <label>Description</label>
                            <textarea name="description" rows="2"><?php echo $produit_edit ? htmlspecialchars($produit_edit['description']) : ''; ?></textarea>
                        </div>

                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo $produit_edit ? '💾 Enregistrer' : '➕ Ajouter le produit'; ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- PRODUCTS TABLE -->
        <div class="card">
            <div class="card-header">
                <h3>Liste des produits (<?php echo mysqli_num_rows($produits); ?>)</h3>
                <button class="btn btn-primary"
                    onclick="document.getElementById('form-produit').style.display='block'">
                    ➕ Nouveau produit
                </button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Désignation</th>
                        <th>Catégorie</th>
                        <th>Prix unitaire</th>
                        <th>Stock actuel</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($p = mysqli_fetch_assoc($produits)): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($p['designation']); ?></strong><br>
                            <small style="color:#94A3B8;">
                                <?php echo htmlspecialchars(mb_strimwidth($p['description'] ? $p['description'] : '', 0, 50, '...')); ?>
                            </small>
                        </td>
                        <td><?php echo $p['nom_categorie'] ? htmlspecialchars($p['nom_categorie']) : '—'; ?></td>
                        <td><strong>$<?php echo number_format($p['prix_unitaire'], 2); ?></strong></td>
                        <td><?php echo $p['stock_actuel']; ?> unités</td>
                        <td>
                            <?php if ($p['stock_actuel'] == 0): ?>
                                <span class="badge badge-danger">Rupture</span>
                            <?php elseif ($p['stock_actuel'] <= $p['seuil_alerte']): ?>
                                <span class="badge badge-warning">Stock bas</span>
                            <?php else: ?>
                                <span class="badge badge-success">En stock</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="produits.php?edit=<?php echo $p['id_produit']; ?>" class="btn btn-warning">✏️ Modifier</a>
                            <a href="produits.php?delete=<?php echo $p['id_produit']; ?>"
                               class="btn btn-danger"
                               onclick="return confirm('Supprimer ce produit ?')">🗑️ Supprimer</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<style>
.alert-msg { padding:12px 18px; border-radius:8px; margin-bottom:20px; font-weight:500; font-size:0.9rem; }
.alert-msg.success { background:#D1FAE5; color:#065F46; border-left:4px solid #059669; }
.alert-msg.danger  { background:#FEE2E2; color:#991B1B; border-left:4px solid #DC2626; }
</style>
</body>
</html>
