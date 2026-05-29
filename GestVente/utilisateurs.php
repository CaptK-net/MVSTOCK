<?php
session_start();
require_once 'config.php';

// This page is admin-only — agents cannot manage system users
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header("Location: accueil.php");
    exit();
}

$message = "";
$erreur  = "";

// ── DELETE A USER ──────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    // Prevent deleting yourself — that would lock you out
    if ($id == $_SESSION['user_id']) {
        $erreur = "Vous ne pouvez pas supprimer votre propre compte.";
    } else {
        // Check if this user has recorded sales — can't delete if they do
        // because vente.id_utilisateur has ON DELETE RESTRICT
        $check = mysqli_query($conn, "SELECT COUNT(*) AS nb FROM vente WHERE id_utilisateur = $id");
        $nb_ventes = mysqli_fetch_assoc($check)['nb'];

        if ($nb_ventes > 0) {
            $erreur = "Impossible de supprimer cet utilisateur : il a enregistré $nb_ventes vente(s).";
        } else {
            $sql = "DELETE FROM utilisateur WHERE id_utilisateur = $id";
            if (mysqli_query($conn, $sql)) {
                $message = "Utilisateur supprimé avec succès.";
            } else {
                $erreur = "Erreur lors de la suppression.";
            }
        }
    }
}

// ── ADD A USER ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'ajouter') {
    $nom    = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email  = mysqli_real_escape_string($conn, $_POST['email']);
    $mdp    = MD5($_POST['mot_de_passe']); // Hash the password with MD5
    $role   = mysqli_real_escape_string($conn, $_POST['role']);

    // Check if the email is already taken
    $check = mysqli_query($conn, "SELECT id_utilisateur FROM utilisateur WHERE email = '$email'");
    if (mysqli_num_rows($check) > 0) {
        $erreur = "Cet email est déjà utilisé par un autre utilisateur.";
    } else {
        $sql = "INSERT INTO utilisateur (nom, prenom, email, mot_de_passe, role)
                VALUES ('$nom', '$prenom', '$email', '$mdp', '$role')";

        if (mysqli_query($conn, $sql)) {
            $message = "Utilisateur $prenom $nom ajouté avec succès.";
        } else {
            $erreur = "Erreur lors de l'ajout : " . mysqli_error($conn);
        }
    }
}

// ── EDIT A USER ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'modifier') {
    $id     = (int)   $_POST['id_utilisateur'];
    $nom    = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email  = mysqli_real_escape_string($conn, $_POST['email']);
    $role   = mysqli_real_escape_string($conn, $_POST['role']);

    // Only update password if the admin typed a new one
    if (!empty($_POST['mot_de_passe'])) {
        $mdp_sql = ", mot_de_passe = '" . MD5($_POST['mot_de_passe']) . "'";
    } else {
        $mdp_sql = ""; // Keep existing password
    }

    $sql = "UPDATE utilisateur
            SET nom    = '$nom',
                prenom = '$prenom',
                email  = '$email',
                role   = '$role'
                $mdp_sql
            WHERE id_utilisateur = $id";

    if (mysqli_query($conn, $sql)) {
        header("Location: utilisateurs.php?message=Utilisateur modifié avec succès.");
        exit();
    } else {
        $erreur = "Erreur lors de la modification : " . mysqli_error($conn);
    }
}

// ── LOAD USER TO EDIT ──────────────────────────────────────
$user_edit = null;
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $res = mysqli_query($conn, "SELECT * FROM utilisateur WHERE id_utilisateur = $id");
    $user_edit = mysqli_fetch_assoc($res);
}

// ── GET MESSAGE FROM URL ───────────────────────────────────
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

// ── FETCH ALL USERS ────────────────────────────────────────
$utilisateurs = mysqli_query($conn,
    "SELECT u.*, COUNT(v.id_vente) AS nb_ventes
     FROM utilisateur u
     LEFT JOIN vente v ON u.id_utilisateur = v.id_utilisateur
     GROUP BY u.id_utilisateur
     ORDER BY u.role, u.nom"
);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Utilisateurs</title>
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
            <a href="clients.php"><span class="icon">👥</span> Clients</a>
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
            <!-- Only admins can see this link -->
            <a href="utilisateurs.php" class="active"><span class="icon">👤</span> Utilisateurs</a>
        </nav>
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>

    <main class="main-content">

        <div class="topbar">
            <h2>👤 Utilisateurs</h2>
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
                <h3><?php echo $user_edit ? '✏️ Modifier l\'utilisateur' : '➕ Ajouter un utilisateur'; ?></h3>
                <?php if ($user_edit): ?>
                    <a href="utilisateurs.php" class="btn btn-secondary">Annuler</a>
                <?php endif; ?>
            </div>
            <div id="form-user" style="padding:24px; <?php echo $user_edit ? '' : 'display:none;'; ?>">
                <form method="POST" action="utilisateurs.php">
                    <input type="hidden" name="action" value="<?php echo $user_edit ? 'modifier' : 'ajouter'; ?>">
                    <?php if ($user_edit): ?>
                        <input type="hidden" name="id_utilisateur" value="<?php echo $user_edit['id_utilisateur']; ?>">
                    <?php endif; ?>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                        <div class="form-group">
                            <label>Nom *</label>
                            <input type="text" name="nom" required
                                value="<?php echo $user_edit ? htmlspecialchars($user_edit['nom']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Prénom *</label>
                            <input type="text" name="prenom" required
                                value="<?php echo $user_edit ? htmlspecialchars($user_edit['prenom']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required
                                value="<?php echo $user_edit ? htmlspecialchars($user_edit['email']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Rôle *</label>
                            <select name="role">
                                <option value="agent"
                                    <?php echo ($user_edit && $user_edit['role'] == 'agent') ? 'selected' : ''; ?>>
                                    Agent de vente
                                </option>
                                <option value="admin"
                                    <?php echo ($user_edit && $user_edit['role'] == 'admin') ? 'selected' : ''; ?>>
                                    Administrateur
                                </option>
                            </select>
                        </div>

                        <div class="form-group" style="grid-column:span 2;">
                            <label>
                                Mot de passe <?php echo $user_edit ? '(laisser vide = inchangé)' : '*'; ?>
                            </label>
                            <input type="password" name="mot_de_passe"
                                <?php echo $user_edit ? '' : 'required'; ?>>
                        </div>

                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo $user_edit ? '💾 Enregistrer' : '➕ Ajouter l\'utilisateur'; ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- USERS TABLE -->
        <div class="card">
            <div class="card-header">
                <h3>Liste des utilisateurs (<?php echo mysqli_num_rows($utilisateurs); ?>)</h3>
                <button class="btn btn-primary"
                    onclick="document.getElementById('form-user').style.display='block'">
                    ➕ Nouvel utilisateur
                </button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nom complet</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Ventes enregistrées</th>
                        <th>Créé le</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($u = mysqli_fetch_assoc($utilisateurs)): ?>
                    <tr>
                        <td><?php echo $u['id_utilisateur']; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($u['prenom'] . ' ' . $u['nom']); ?></strong>
                            <!-- Mark the currently logged-in user -->
                            <?php if ($u['id_utilisateur'] == $_SESSION['user_id']): ?>
                                <span class="badge badge-info" style="margin-left:6px;">Vous</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <?php if ($u['role'] == 'admin'): ?>
                                <span class="badge badge-danger">Admin</span>
                            <?php else: ?>
                                <span class="badge badge-success">Agent</span>
                            <?php endif; ?>
                        </td>
                        <!-- Number of sales this user has recorded -->
                        <td><?php echo $u['nb_ventes']; ?> vente(s)</td>
                        <td><?php echo date('d/m/Y', strtotime($u['date_creation'])); ?></td>
                        <td>
                            <a href="utilisateurs.php?edit=<?php echo $u['id_utilisateur']; ?>"
                               class="btn btn-warning">✏️ Modifier</a>

                            <!-- Cannot delete yourself or someone with sales -->
                            <?php if ($u['id_utilisateur'] != $_SESSION['user_id'] && $u['nb_ventes'] == 0): ?>
                                <a href="utilisateurs.php?delete=<?php echo $u['id_utilisateur']; ?>"
                                   class="btn btn-danger"
                                   onclick="return confirm('Supprimer cet utilisateur ?')">🗑️ Supprimer</a>
                            <?php else: ?>
                                <!-- Show a greyed-out button to explain why deletion is blocked -->
                                <span class="btn btn-secondary" style="opacity:0.4; cursor:not-allowed;"
                                      title="<?php echo $u['id_utilisateur'] == $_SESSION['user_id'] ? 'Votre propre compte' : 'A des ventes enregistrées'; ?>">
                                    🗑️
                                </span>
                            <?php endif; ?>
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
