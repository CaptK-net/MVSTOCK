<?php
// Start the session so we can access $_SESSION variables
session_start();

// Include the database connection file
require_once 'config.php';

// ── ACCESS CONTROL ─────────────────────────────────────────
// Only admins and agents can manage clients
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] == 'client') {
    header("Location: index.php");
    exit();
}

// Variables to hold success or error messages
$message = "";
$erreur  = "";

// ── DELETE A CLIENT ────────────────────────────────────────
// If the URL contains ?delete=ID, delete that client
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $sql = "DELETE FROM users WHERE id = $id AND role = 'client'";
    if (mysqli_query($conn, $sql)) {
        $message = "Client supprimé avec succès.";
    } else {
        $erreur = "Erreur lors de la suppression.";
    }
}

// ── ADD A CLIENT ───────────────────────────────────────────
// If the form was submitted with action = "ajouter"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'ajouter') {

    // Retrieve and clean each form field
    $nom       = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom    = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email     = mysqli_real_escape_string($conn, $_POST['email']);
    $mdp       = MD5($_POST['mot_de_passe']); // Hash the password with MD5
    $telephone = mysqli_real_escape_string($conn, $_POST['telephone']);
    $adresse   = mysqli_real_escape_string($conn, $_POST['adresse']);

    // Check if the email is already used by another user
    $check = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email'");
    if (mysqli_num_rows($check) > 0) {
        $erreur = "Cet email est déjà utilisé.";
    } else {
        // Insert the new client — role is always 'client', points start at 0
        $sql = "INSERT INTO users (nom, prenom, email, mot_de_passe, role, telephone, adresse, points_fidelite)
                VALUES ('$nom', '$prenom', '$email', '$mdp', 'client', '$telephone', '$adresse', 0)";

        if (mysqli_query($conn, $sql)) {
            $message = "Client $prenom $nom ajouté avec succès.";
        } else {
            $erreur = "Erreur lors de l'ajout : " . mysqli_error($conn);
        }
    }
}

// ── EDIT A CLIENT ──────────────────────────────────────────
// If the form was submitted with action = "modifier"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'modifier') {

    $id        = (int) $_POST['id'];
    $nom       = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom    = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email     = mysqli_real_escape_string($conn, $_POST['email']);
    $telephone = mysqli_real_escape_string($conn, $_POST['telephone']);
    $adresse   = mysqli_real_escape_string($conn, $_POST['adresse']);
    $points    = (int) $_POST['points_fidelite'];

    // Only update the password if the admin typed a new one
    // If left empty, keep the existing password unchanged
    if (!empty($_POST['mot_de_passe'])) {
        $mdp_sql = ", mot_de_passe = '" . MD5($_POST['mot_de_passe']) . "'";
    } else {
        $mdp_sql = ""; // No password change
    }

    $sql = "UPDATE users
            SET nom = '$nom',
                prenom = '$prenom',
                email = '$email',
                telephone = '$telephone',
                adresse = '$adresse',
                points_fidelite = $points
                $mdp_sql
            WHERE id = $id AND role = 'client'";

    if (mysqli_query($conn, $sql)) {
        header("Location: clients.php?message=Client modifié avec succès.");
        exit();
    } else {
        $erreur = "Erreur lors de la modification : " . mysqli_error($conn);
    }
}

// ── LOAD CLIENT TO EDIT ────────────────────────────────────
// If the URL contains ?edit=ID, fetch that client's data to pre-fill the form
$client_edit = null;
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $res = mysqli_query($conn, "SELECT * FROM users WHERE id = $id AND role = 'client'");
    $client_edit = mysqli_fetch_assoc($res);
}

// ── GET MESSAGE FROM URL ───────────────────────────────────
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

// ── FETCH ALL CLIENTS ──────────────────────────────────────
// Get all users with role = 'client', most recent first
$clients = mysqli_query($conn,
    "SELECT * FROM users WHERE role = 'client' ORDER BY date_inscription DESC"
);

// Count total clients
$total_clients = mysqli_num_rows($clients);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Cleanify - Clients</title>
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
            <a href="clients.php" class="active"><span class="icon">👥</span> Clients</a>
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
            <h2>👥 Clients</h2>
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
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <!-- Title changes depending on add or edit mode -->
                <h3><?php echo $client_edit ? '✏️ Modifier le client' : '➕ Ajouter un client'; ?></h3>
                <?php if ($client_edit): ?>
                    <a href="clients.php" class="btn btn-secondary">Annuler</a>
                <?php endif; ?>
            </div>

            <!-- Form is hidden by default, shown when editing or when button is clicked -->
            <div id="form-client" style="padding:24px; <?php echo $client_edit ? '' : 'display:none;'; ?>">
                <form method="POST" action="clients.php">

                    <!-- Hidden field: tells PHP if we are adding or editing -->
                    <input type="hidden" name="action" value="<?php echo $client_edit ? 'modifier' : 'ajouter'; ?>">

                    <!-- Send the client ID when editing -->
                    <?php if ($client_edit): ?>
                        <input type="hidden" name="id" value="<?php echo $client_edit['id']; ?>">
                    <?php endif; ?>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                        <div class="form-group">
                            <label>Nom *</label>
                            <input type="text" name="nom" required
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['nom']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Prénom *</label>
                            <input type="text" name="prenom" required
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['prenom']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['email']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Téléphone</label>
                            <input type="text" name="telephone"
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['telephone']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>
                                Mot de passe <?php echo $client_edit ? '(laisser vide = inchangé)' : '*'; ?>
                            </label>
                            <input type="password" name="mot_de_passe"
                                <?php echo $client_edit ? '' : 'required'; ?>>
                        </div>

                        <!-- Show loyalty points only when editing an existing client -->
                        <?php if ($client_edit): ?>
                        <div class="form-group">
                            <label>Points de fidélité</label>
                            <input type="number" name="points_fidelite" min="0"
                                value="<?php echo $client_edit['points_fidelite']; ?>">
                        </div>
                        <?php endif; ?>

                        <div class="form-group" style="grid-column:span 2;">
                            <label>Adresse</label>
                            <input type="text" name="adresse"
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['adresse']) : ''; ?>">
                        </div>

                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo $client_edit ? '💾 Enregistrer les modifications' : '➕ Ajouter le client'; ?>
                    </button>

                </form>
            </div>
        </div>

        <!-- ── CLIENTS TABLE ── -->
        <div class="card">
            <div class="card-header">
                <h3>Liste des clients (<?php echo $total_clients; ?>)</h3>
                <!-- Show the add form when this button is clicked -->
                <button class="btn btn-primary"
                    onclick="document.getElementById('form-client').style.display='block'">
                    ➕ Nouveau client
                </button>
            </div>

            <?php if ($total_clients == 0): ?>
                <!-- Shown when there are no clients yet -->
                <div class="empty-state">
                    <div class="empty-icon">👥</div>
                    <p>Aucun client enregistré pour l'instant.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nom complet</th>
                        <th>Email</th>
                        <th>Téléphone</th>
                        <th>Adresse</th>
                        <th>Fidélité</th>
                        <th>Inscrit le</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Loop through every client and display one row per client
                while ($c = mysqli_fetch_assoc($clients)):
                ?>
                    <tr>
                        <td><?php echo $c['id']; ?></td>

                        <td>
                            <strong>
                                <?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?>
                            </strong>
                        </td>

                        <td><?php echo htmlspecialchars($c['email']); ?></td>

                        <td><?php echo $c['telephone'] ? htmlspecialchars($c['telephone']) : '—'; ?></td>

                        <td><?php echo $c['adresse'] ? htmlspecialchars($c['adresse']) : '—'; ?></td>

                        <!-- Loyalty points with a gold star -->
                        <td>
                            <span style="color:#D97706; font-weight:600;">
                                ⭐ <?php echo $c['points_fidelite']; ?> pts
                            </span>
                        </td>

                        <!-- Format the date nicely -->
                        <td><?php echo date('d/m/Y', strtotime($c['date_inscription'])); ?></td>

                        <!-- Edit and Delete buttons -->
                        <td>
                            <a href="clients.php?edit=<?php echo $c['id']; ?>"
                               class="btn btn-warning">✏️ Modifier</a>
                            <a href="clients.php?delete=<?php echo $c['id']; ?>"
                               class="btn btn-danger"
                               onclick="return confirm('Supprimer ce client ?')">
                               🗑️ Supprimer
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- Alert message styles (same as produits.php) -->
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
