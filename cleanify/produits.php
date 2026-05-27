<?php
// Start the session so we can access $_SESSION variables
session_start();

// Include the database connection file
require_once 'config.php';

// ── ACCESS CONTROL ─────────────────────────────────────────
// If the user is not logged in OR is a client, send them back to login
// Clients are not allowed to manage products
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] == 'client') {
    header("Location: index.php");
    exit();
}

// Variables to hold success or error messages shown to the user
$message = "";
$erreur  = "";

// ── DELETE A PRODUCT ───────────────────────────────────────
// If the URL contains ?delete=ID, we delete that product from the database
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete']; // (int) makes sure the ID is a number, not harmful text
    $sql = "DELETE FROM produits WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $message = "Produit supprimé avec succès.";
    } else {
        $erreur = "Erreur lors de la suppression.";
    }
}

// ── ADD A PRODUCT ──────────────────────────────────────────
// If the form was submitted with action = "ajouter"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'ajouter') {

    // Retrieve and clean each form field
    // mysqli_real_escape_string prevents SQL injection (bad characters in text)
    $nom          = mysqli_real_escape_string($conn, $_POST['nom']);
    $description  = mysqli_real_escape_string($conn, $_POST['description']);
    $prix         = (float) $_POST['prix'];   // (float) ensures it's a decimal number
    $stock        = (int)   $_POST['stock'];  // (int) ensures it's a whole number
    $seuil_alerte = (int)   $_POST['seuil_alerte'];
    $categorie    = mysqli_real_escape_string($conn, $_POST['categorie']);
    $image_url    = mysqli_real_escape_string($conn, $_POST['image_url']);

    // Insert the new product into the database
    $sql = "INSERT INTO produits (nom, description, prix, stock, seuil_alerte, categorie, image_url)
            VALUES ('$nom', '$description', $prix, $stock, $seuil_alerte, '$categorie', '$image_url')";

    if (mysqli_query($conn, $sql)) {
        $message = "Produit \"$nom\" ajouté avec succès.";
    } else {
        $erreur = "Erreur lors de l'ajout : " . mysqli_error($conn);
    }
}

// ── EDIT A PRODUCT ─────────────────────────────────────────
// If the form was submitted with action = "modifier"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'modifier') {

    $id           = (int)   $_POST['id'];
    $nom          = mysqli_real_escape_string($conn, $_POST['nom']);
    $description  = mysqli_real_escape_string($conn, $_POST['description']);
    $prix         = (float) $_POST['prix'];
    $stock        = (int)   $_POST['stock'];
    $seuil_alerte = (int)   $_POST['seuil_alerte'];
    $categorie    = mysqli_real_escape_string($conn, $_POST['categorie']);
    $image_url    = mysqli_real_escape_string($conn, $_POST['image_url']);

    // Update the existing product row in the database
    $sql = "UPDATE produits
            SET nom = '$nom',
                description = '$description',
                prix = $prix,
                stock = $stock,
                seuil_alerte = $seuil_alerte,
                categorie = '$categorie',
                image_url = '$image_url'
            WHERE id = $id";

    if (mysqli_query($conn, $sql)) {
        // Redirect after saving so refreshing the page doesn't re-submit the form
        header("Location: produits.php?message=Produit modifié avec succès.");
        exit();
    } else {
        $erreur = "Erreur lors de la modification : " . mysqli_error($conn);
    }
}

// ── LOAD PRODUCT TO EDIT ───────────────────────────────────
// If the URL contains ?edit=ID, fetch that product's data to pre-fill the form
$produit_edit = null;
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $res = mysqli_query($conn, "SELECT * FROM produits WHERE id = $id");
    $produit_edit = mysqli_fetch_assoc($res);
}

// ── GET MESSAGE FROM URL ───────────────────────────────────
// After a redirect, the success message is passed in the URL
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

// ── FETCH ALL PRODUCTS ─────────────────────────────────────
// Get all products ordered by category then name
$produits = mysqli_query($conn, "SELECT * FROM produits ORDER BY categorie, nom");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Cleanify - Produits</title>
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
            <a href="produits.php" class="active"><span class="icon">📦</span> Produits</a>
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
        </nav>
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- Page title + logged-in user info -->
        <div class="topbar">
            <h2>📦 Produits</h2>
            <div class="user-info">
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- Success or error message -->
        <?php if ($message != ""): ?>
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($erreur != ""): ?>
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>

        <!-- ── ADD / EDIT FORM ── -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <!-- The title changes depending on whether we are adding or editing -->
                <h3><?php echo $produit_edit ? '✏️ Modifier le produit' : '➕ Ajouter un produit'; ?></h3>
                <?php if ($produit_edit): ?>
                    <!-- If editing, show a Cancel button that goes back to the normal page -->
                    <a href="produits.php" class="btn btn-secondary">Annuler</a>
                <?php endif; ?>
            </div>

            <!-- The form is hidden by default (only shown when adding or editing) -->
            <div id="form-produit" style="padding: 24px; <?php echo $produit_edit ? '' : 'display:none;'; ?>">
                <form method="POST" action="produits.php">

                    <!-- Hidden field to tell PHP whether we are adding or editing -->
                    <input type="hidden" name="action" value="<?php echo $produit_edit ? 'modifier' : 'ajouter'; ?>">

                    <!-- If editing, also send the product ID so we know which row to update -->
                    <?php if ($produit_edit): ?>
                        <input type="hidden" name="id" value="<?php echo $produit_edit['id']; ?>">
                    <?php endif; ?>

                    <!-- Form fields in a 2-column grid -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                        <div class="form-group">
                            <label>Nom du produit *</label>
                            <input type="text" name="nom" required
                                value="<?php echo $produit_edit ? htmlspecialchars($produit_edit['nom']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Catégorie</label>
                            <input type="text" name="categorie"
                                value="<?php echo $produit_edit ? htmlspecialchars($produit_edit['categorie']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Prix ($) *</label>
                            <input type="number" name="prix" step="0.01" min="0" required
                                value="<?php echo $produit_edit ? $produit_edit['prix'] : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Stock actuel *</label>
                            <input type="number" name="stock" min="0" required
                                value="<?php echo $produit_edit ? $produit_edit['stock'] : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Seuil d'alerte (alerte si stock ≤ cette valeur)</label>
                            <input type="number" name="seuil_alerte" min="0"
                                value="<?php echo $produit_edit ? $produit_edit['seuil_alerte'] : '5'; ?>">
                        </div>

                        <div class="form-group">
                            <label>URL de l'image</label>
                            <input type="text" name="image_url" placeholder="https://..."
                                value="<?php echo $produit_edit ? htmlspecialchars($produit_edit['image_url']) : ''; ?>">
                        </div>

                        <!-- Description spans both columns -->
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Description</label>
                            <textarea name="description" rows="2"><?php echo $produit_edit ? htmlspecialchars($produit_edit['description']) : ''; ?></textarea>
                        </div>

                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo $produit_edit ? '💾 Enregistrer les modifications' : '➕ Ajouter le produit'; ?>
                    </button>

                </form>
            </div>
        </div>

        <!-- ── PRODUCTS TABLE ── -->
        <div class="card">
            <div class="card-header">
                <h3>Liste des produits (<?php echo mysqli_num_rows($produits); ?>)</h3>
                <!-- This button shows the add form when clicked -->
                <button class="btn btn-primary" onclick="document.getElementById('form-produit').style.display='block'">
                    ➕ Nouveau produit
                </button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Produit</th>
                        <th>Catégorie</th>
                        <th>Prix</th>
                        <th>Stock</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Loop through every product and display it as a table row
                while ($p = mysqli_fetch_assoc($produits)):
                ?>
                    <tr>
                        <!-- Product image (or a placeholder icon if no image) -->
                        <td>
                            <?php if ($p['image_url']): ?>
                                <img src="<?php echo htmlspecialchars($p['image_url']); ?>"
                                     alt="<?php echo htmlspecialchars($p['nom']); ?>"
                                     style="width:48px; height:48px; object-fit:cover; border-radius:8px;">
                            <?php else: ?>
                                <span style="font-size:1.8rem;">📦</span>
                            <?php endif; ?>
                        </td>

                        <!-- Name + short description -->
                        <td>
                            <strong><?php echo htmlspecialchars($p['nom']); ?></strong><br>
                            <small style="color:#94A3B8;">
                                <?php
                                // Show only the first 50 characters of the description
                                echo htmlspecialchars(mb_strimwidth($p['description'], 0, 50, '...'));
                                ?>
                            </small>
                        </td>

                        <td><?php echo htmlspecialchars($p['categorie']); ?></td>

                        <td><strong>$<?php echo number_format($p['prix'], 2); ?></strong></td>

                        <td><?php echo $p['stock']; ?> unités</td>

                        <!-- Stock status badge -->
                        <td>
                            <?php if ($p['stock'] == 0): ?>
                                <span class="badge badge-danger">Rupture</span>
                            <?php elseif ($p['stock'] <= $p['seuil_alerte']): ?>
                                <span class="badge badge-warning">Stock bas</span>
                            <?php else: ?>
                                <span class="badge badge-success">En stock</span>
                            <?php endif; ?>
                        </td>

                        <!-- Edit and Delete buttons -->
                        <td>
                            <a href="produits.php?edit=<?php echo $p['id']; ?>" class="btn btn-warning">✏️ Modifier</a>
                            <a href="produits.php?delete=<?php echo $p['id']; ?>"
                               class="btn btn-danger"
                               onclick="return confirm('Voulez-vous vraiment supprimer ce produit ?')">
                               🗑️ Supprimer
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>

        </div>
    </main>
</div>

<!-- Small styles just for this page's alert messages -->
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
