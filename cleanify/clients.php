<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$erreur  = "";

// ── DELETE A CLIENT ────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id  = (int) $_GET['delete'];
    $sql = "DELETE FROM client WHERE id_client = $id";
    if (mysqli_query($conn, $sql)) {
        $message = "Client supprimé avec succès.";
    } else {
        $erreur = "Erreur lors de la suppression.";
    }
}

// ── ADD A CLIENT ───────────────────────────────────────────
// Note: clients have NO password — they don't log into the system
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'ajouter') {
    $nom       = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom    = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email     = mysqli_real_escape_string($conn, $_POST['email']);
    $telephone = mysqli_real_escape_string($conn, $_POST['telephone']);
    $adresse   = mysqli_real_escape_string($conn, $_POST['adresse']);

    $sql = "INSERT INTO client (nom, prenom, email, telephone, adresse)
            VALUES ('$nom', '$prenom', '$email', '$telephone', '$adresse')";

    if (mysqli_query($conn, $sql)) {
        $message = "Client $prenom $nom ajouté avec succès.";
    } else {
        $erreur = "Erreur lors de l'ajout : " . mysqli_error($conn);
    }
}

// ── EDIT A CLIENT ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'modifier') {
    $id        = (int)   $_POST['id_client'];
    $nom       = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom    = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email     = mysqli_real_escape_string($conn, $_POST['email']);
    $telephone = mysqli_real_escape_string($conn, $_POST['telephone']);
    $adresse   = mysqli_real_escape_string($conn, $_POST['adresse']);
    $points    = (int) $_POST['points_fidelite'];

    $sql = "UPDATE client
            SET nom             = '$nom',
                prenom          = '$prenom',
                email           = '$email',
                telephone       = '$telephone',
                adresse         = '$adresse',
                points_fidelite = $points
            WHERE id_client = $id";

    if (mysqli_query($conn, $sql)) {
        header("Location: clients.php?message=Client modifié avec succès.");
        exit();
    } else {
        $erreur = "Erreur lors de la modification : " . mysqli_error($conn);
    }
}

// ── LOAD CLIENT TO EDIT ────────────────────────────────────
$client_edit = null;
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $res = mysqli_query($conn, "SELECT * FROM client WHERE id_client = $id");
    $client_edit = mysqli_fetch_assoc($res);
}

if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

// ── FETCH ALL CLIENTS ──────────────────────────────────────
$clients = mysqli_query($conn, "SELECT * FROM client ORDER BY date_inscription DESC");
$total   = mysqli_num_rows($clients);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Clients</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="dashboard-layout">

    <aside class="sidebar">
        <div class="sidebar-logo">
            <h1 style="font-size:1.4rem;">🏪 GestVente</h1>
            <p style="color:#64748B; font-size:0.78rem;">Gestion des ventes</p>
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

    <main class="main-content">

        <div class="topbar">
            <h2>👥 Clients</h2>
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
                <h3><?php echo $client_edit ? '✏️ Modifier le client' : '➕ Ajouter un client'; ?></h3>
                <?php if ($client_edit): ?>
                    <a href="clients.php" class="btn btn-secondary">Annuler</a>
                <?php endif; ?>
            </div>
            <div id="form-client" style="padding:24px; <?php echo $client_edit ? '' : 'display:none;'; ?>">
                <form method="POST" action="clients.php">
                    <input type="hidden" name="action" value="<?php echo $client_edit ? 'modifier' : 'ajouter'; ?>">
                    <?php if ($client_edit): ?>
                        <input type="hidden" name="id_client" value="<?php echo $client_edit['id_client']; ?>">
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
                            <label>Email</label>
                            <input type="email" name="email"
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['email']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Téléphone</label>
                            <input type="text" name="telephone"
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['telephone']) : ''; ?>">
                        </div>

                        <div class="form-group" style="grid-column:span 2;">
                            <label>Adresse</label>
                            <input type="text" name="adresse"
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['adresse']) : ''; ?>">
                        </div>

                        <!-- Loyalty points only shown when editing -->
                        <?php if ($client_edit): ?>
                        <div class="form-group">
                            <label>Points de fidélité</label>
                            <input type="number" name="points_fidelite" min="0"
                                value="<?php echo $client_edit['points_fidelite']; ?>">
                        </div>
                        <?php endif; ?>

                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo $client_edit ? '💾 Enregistrer' : '➕ Ajouter le client'; ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- CLIENTS TABLE -->
        <div class="card">
            <div class="card-header">
                <h3>Liste des clients (<?php echo $total; ?>)</h3>
                <button class="btn btn-primary"
                    onclick="document.getElementById('form-client').style.display='block'">
                    ➕ Nouveau client
                </button>
            </div>

            <?php if ($total == 0): ?>
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
                <?php while ($c = mysqli_fetch_assoc($clients)): ?>
                    <tr>
                        <td><?php echo $c['id_client']; ?></td>
                        <td><strong><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></strong></td>
                        <td><?php echo $c['email']     ? htmlspecialchars($c['email'])     : '—'; ?></td>
                        <td><?php echo $c['telephone'] ? htmlspecialchars($c['telephone']) : '—'; ?></td>
                        <td><?php echo $c['adresse']   ? htmlspecialchars($c['adresse'])   : '—'; ?></td>
                        <td><span style="color:#D97706; font-weight:600;">⭐ <?php echo $c['points_fidelite']; ?> pts</span></td>
                        <td><?php echo date('d/m/Y', strtotime($c['date_inscription'])); ?></td>
                        <td>
                            <a href="clients.php?edit=<?php echo $c['id_client']; ?>" class="btn btn-warning">✏️ Modifier</a>
                            <a href="clients.php?delete=<?php echo $c['id_client']; ?>"
                               class="btn btn-danger"
                               onclick="return confirm('Supprimer ce client ?')">🗑️ Supprimer</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
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
