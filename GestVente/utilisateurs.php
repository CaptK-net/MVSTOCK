<?php
/*
 * ============================================================
 * FILE: utilisateurs.php — USER MANAGEMENT (ADMIN ONLY)
 * This page lets administrators manage the system accounts
 * (the people who can log in: admins and agents).
 *
 * IMPORTANT: These are NOT the shop's customers. Customers
 * (clients) are in a completely separate table (client).
 * Users here are the staff who operate MVSTOCK.
 *
 * Admins can:
 *   - See all user accounts
 *   - Add a new admin or agent account
 *   - Edit an existing account (name, email, role, password)
 *   - Delete an account (with two safety rules — see below)
 *
 * Safety rules for deletion:
 *   1. You cannot delete your own account (would lock yourself out).
 *   2. You cannot delete an account that has recorded sales
 *      (would break the foreign key relationship in the database).
 * ============================================================
 */

// Start the session so we can read $_SESSION variables.
session_start();

// Include the database connection (gives us $conn).
require_once 'config.php';

// SECURITY GATE: Only logged-in admins may access this page.
// The condition checks BOTH that a session exists AND that the role is 'admin'.
// If either condition fails, the user is redirected to the dashboard.
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header("Location: accueil.php"); // Go back to dashboard
    exit();                          // Stop all further code execution
}

// Feedback variables: $message = green success, $erreur = red error.
$message = "";
$erreur  = "";

/*
 * ── DELETE A USER ─────────────────────────────────────────────
 * Triggered when the admin clicks "Supprimer" on a user row.
 * The URL becomes utilisateurs.php?delete=ID.
 * Two safety checks are applied before deleting.
 */
if (isset($_GET['delete'])) {

    // Convert the ID from the URL to a safe integer.
    $id = (int) $_GET['delete'];

    // SAFETY CHECK 1: Prevent the admin from deleting their own account.
    // $_SESSION['user_id'] holds the currently logged-in admin's ID.
    // If the IDs match, it means the admin is trying to delete themselves.
    if ($id == $_SESSION['user_id']) {
        $erreur = "Vous ne pouvez pas supprimer votre propre compte.";

    } else {

        // SAFETY CHECK 2: Prevent deletion if this user has recorded sales.
        // The "vente" table has id_utilisateur as a FOREIGN KEY with ON DELETE RESTRICT.
        // This means MySQL will refuse to delete the user if they have sales.
        // We check in advance and show a friendly error message instead.
        $check     = mysqli_query($conn, "SELECT COUNT(*) AS nb FROM vente WHERE id_utilisateur = $id");
        $nb_ventes = mysqli_fetch_assoc($check)['nb']; // Number of sales by this user

        if ($nb_ventes > 0) {
            // Cannot delete — this user has sales linked to them.
            $erreur = "Impossible de supprimer cet utilisateur : il a enregistré $nb_ventes vente(s).";

        } else {
            // Both checks passed — safe to delete this user.
            $sql = "DELETE FROM utilisateur WHERE id_utilisateur = $id";

            if (mysqli_query($conn, $sql)) {
                $message = "Utilisateur supprimé avec succès.";
            } else {
                $erreur = "Erreur lors de la suppression.";
            }
        }
    }
}

/*
 * ── ADD A NEW USER ────────────────────────────────────────────
 * When the admin submits the "Add user" form, the hidden action
 * field equals "ajouter". We collect all the fields, hash the
 * password, and insert the new user into the database.
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'ajouter') {

    // Sanitize all text inputs against SQL injection.
    $nom    = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email  = mysqli_real_escape_string($conn, $_POST['email']);

    // Hash the password with MD5 before storing it.
    // NEVER store a password in plain text — the hash is a one-way transformation
    // so even if the database is leaked, the real passwords stay hidden.
    $mdp  = MD5($_POST['mot_de_passe']);

    // Sanitize the role value ('admin' or 'agent').
    $role = mysqli_real_escape_string($conn, $_POST['role']);

    // Check if this email address is already taken by another user.
    // Each user must have a unique email (it's their login identifier).
    $check = mysqli_query($conn, "SELECT id_utilisateur FROM utilisateur WHERE email = '$email'");

    if (mysqli_num_rows($check) > 0) {
        // Email already exists — show an error instead of inserting a duplicate.
        $erreur = "Cet email est déjà utilisé par un autre utilisateur.";

    } else {
        // Email is unique — proceed with the INSERT.
        $sql = "INSERT INTO utilisateur (nom, prenom, email, mot_de_passe, role)
                VALUES ('$nom', '$prenom', '$email', '$mdp', '$role')";

        if (mysqli_query($conn, $sql)) {
            $message = "Utilisateur $prenom $nom ajouté avec succès.";
        } else {
            $erreur = "Erreur lors de l'ajout : " . mysqli_error($conn);
        }
    }
}

/*
 * ── EDIT / UPDATE AN EXISTING USER ───────────────────────────
 * When the admin submits the edit form, action = "modifier".
 * We update the user's information. The password is only
 * changed if the admin types a new one — leaving the field
 * blank keeps the existing password unchanged.
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'modifier') {

    // Get the ID of the user being edited.
    $id     = (int) $_POST['id_utilisateur'];

    // Sanitize the updated field values.
    $nom    = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email  = mysqli_real_escape_string($conn, $_POST['email']);
    $role   = mysqli_real_escape_string($conn, $_POST['role']);

    // Check if a new password was typed.
    // empty() returns true if the field is blank or contains only spaces.
    if (!empty($_POST['mot_de_passe'])) {

        // A new password was provided — hash it and include it in the UPDATE.
        // The comma at the start of $mdp_sql allows it to be appended to the SET clause.
        $mdp_sql = ", mot_de_passe = '" . MD5($_POST['mot_de_passe']) . "'";

    } else {
        // No new password was typed — don't change it (empty string = no SQL fragment).
        $mdp_sql = "";
    }

    // Build the UPDATE query. $mdp_sql is either a password change or empty string.
    $sql = "UPDATE utilisateur
            SET nom    = '$nom',
                prenom = '$prenom',
                email  = '$email',
                role   = '$role'
                $mdp_sql
            WHERE id_utilisateur = $id";

    if (mysqli_query($conn, $sql)) {
        // Use Post/Redirect/Get pattern: redirect after success to prevent
        // the browser from re-submitting the form if the user refreshes.
        header("Location: utilisateurs.php?message=Utilisateur modifié avec succès.");
        exit();
    } else {
        $erreur = "Erreur lors de la modification : " . mysqli_error($conn);
    }
}

/*
 * ── LOAD A USER INTO THE EDIT FORM ───────────────────────────
 * When the admin clicks "Modifier" on a user row, the URL becomes
 * utilisateurs.php?edit=ID. We load that user's data so the
 * edit form is pre-filled with their current information.
 */
$user_edit = null; // null by default (no user being edited)

if (isset($_GET['edit'])) {
    $id        = (int) $_GET['edit'];
    $res       = mysqli_query($conn, "SELECT * FROM utilisateur WHERE id_utilisateur = $id");
    $user_edit = mysqli_fetch_assoc($res); // Store the user's current data
}

// If a success message was passed via URL (after a redirect), display it.
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']); // Escape for XSS safety
}

/*
 * ── FETCH ALL USERS FOR THE TABLE ────────────────────────────
 * Load every user in the system, along with a count of how many
 * sales they have recorded. The sales count is used to:
 *   1. Display "X vente(s)" next to each user
 *   2. Decide whether to show the Delete button (0 sales = deletable)
 * LEFT JOIN means: include users even if they have 0 sales.
 * GROUP BY groups the results so each user appears only once.
 */
$utilisateurs = mysqli_query($conn,
    "SELECT u.*, COUNT(v.id_vente) AS nb_ventes
     FROM utilisateur u
     LEFT JOIN vente v ON u.id_utilisateur = v.id_utilisateur
     GROUP BY u.id_utilisateur
     ORDER BY u.role, u.nom" // Admins first, then agents, alphabetically
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
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>
            <!-- This page is admin-only so these links are always visible here -->
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
            <a href="utilisateurs.php" class="active"><span class="icon">👤</span> Utilisateurs</a>
        </nav>
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <div class="topbar">
            <h2>👤 Utilisateurs</h2>
            <div class="user-info">
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- Success message (green) -->
        <?php if ($message != ""): ?>
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Error message (red) -->
        <?php if ($erreur != ""): ?>
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>

        <!-- ── ADD / EDIT FORM ── -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <!-- Title changes based on whether we're adding or editing -->
                <h3><?php echo $user_edit ? '✏️ Modifier l\'utilisateur' : '➕ Ajouter un utilisateur'; ?></h3>

                <!-- "Annuler" button only shows when editing (to cancel and go back) -->
                <?php if ($user_edit): ?>
                    <a href="utilisateurs.php" class="btn btn-secondary">Annuler</a>
                <?php endif; ?>
            </div>

            <!-- The form is hidden by default (display:none).
                 When adding, it stays hidden until the "Nouvel utilisateur" button is clicked.
                 When editing, it's shown immediately (no display:none style). -->
            <div id="form-user" style="padding:24px; <?php echo $user_edit ? '' : 'display:none;'; ?>">

                <form method="POST" action="utilisateurs.php">

                    <!-- Hidden field tells PHP which action to perform (add or edit) -->
                    <input type="hidden" name="action" value="<?php echo $user_edit ? 'modifier' : 'ajouter'; ?>">

                    <!-- When editing, pass the user's ID so we know which row to UPDATE -->
                    <?php if ($user_edit): ?>
                        <input type="hidden" name="id_utilisateur" value="<?php echo $user_edit['id_utilisateur']; ?>">
                    <?php endif; ?>

                    <!-- Two-column grid layout for the form fields -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                        <!-- Last name field — pre-filled when editing -->
                        <div class="form-group">
                            <label>Nom *</label>
                            <input type="text" name="nom" required
                                value="<?php echo $user_edit ? htmlspecialchars($user_edit['nom']) : ''; ?>">
                        </div>

                        <!-- First name field -->
                        <div class="form-group">
                            <label>Prénom *</label>
                            <input type="text" name="prenom" required
                                value="<?php echo $user_edit ? htmlspecialchars($user_edit['prenom']) : ''; ?>">
                        </div>

                        <!-- Email field — this is the login identifier -->
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required
                                value="<?php echo $user_edit ? htmlspecialchars($user_edit['email']) : ''; ?>">
                        </div>

                        <!-- Role dropdown: either 'agent' or 'admin' -->
                        <div class="form-group">
                            <label>Rôle *</label>
                            <select name="role">
                                <!-- "selected" marks the current role when editing -->
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

                        <!-- Password field. When editing, leaving blank = keep current password. -->
                        <div class="form-group" style="grid-column:span 2;">
                            <label>
                                Mot de passe
                                <?php echo $user_edit ? '(laisser vide = inchangé)' : '*'; ?>
                            </label>
                            <!-- "required" only applies when adding (not when editing) -->
                            <input type="password" name="mot_de_passe"
                                <?php echo $user_edit ? '' : 'required'; ?>>
                        </div>
                    </div>

                    <!-- Submit button text changes based on add vs edit mode -->
                    <button type="submit" class="btn btn-primary">
                        <?php echo $user_edit ? '💾 Enregistrer' : '➕ Ajouter l\'utilisateur'; ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ── USERS TABLE ── -->
        <div class="card">
            <div class="card-header">
                <!-- mysqli_num_rows() counts how many users are in the result -->
                <h3>Liste des utilisateurs (<?php echo mysqli_num_rows($utilisateurs); ?>)</h3>

                <!-- This button shows the hidden form above by setting its CSS to "block" -->
                <button class="btn btn-primary"
                    onclick="document.getElementById('form-user').style.display='block'">
                    ➕ Nouvel utilisateur
                </button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>                     <!-- User ID -->
                        <th>Nom complet</th>            <!-- Full name -->
                        <th>Email</th>                  <!-- Login email -->
                        <th>Rôle</th>                   <!-- Admin or Agent badge -->
                        <th>Ventes enregistrées</th>    <!-- How many sales they've made -->
                        <th>Créé le</th>                <!-- Account creation date -->
                        <th>Actions</th>                <!-- Edit / Delete buttons -->
                    </tr>
                </thead>
                <tbody>

                <!-- Loop through each user returned by the database query -->
                <?php while ($u = mysqli_fetch_assoc($utilisateurs)): ?>
                    <tr>
                        <td><?php echo $u['id_utilisateur']; ?></td>

                        <td>
                            <!-- Display full name in bold -->
                            <strong><?php echo htmlspecialchars($u['prenom'] . ' ' . $u['nom']); ?></strong>

                            <!-- Show a "Vous" (You) badge next to the currently logged-in admin -->
                            <?php if ($u['id_utilisateur'] == $_SESSION['user_id']): ?>
                                <span class="badge badge-info" style="margin-left:6px;">Vous</span>
                            <?php endif; ?>
                        </td>

                        <td><?php echo htmlspecialchars($u['email']); ?></td>

                        <td>
                            <!-- Show a red "Admin" badge or green "Agent" badge -->
                            <?php if ($u['role'] == 'admin'): ?>
                                <span class="badge badge-danger">Admin</span>
                            <?php else: ?>
                                <span class="badge badge-success">Agent</span>
                            <?php endif; ?>
                        </td>

                        <!-- Number of sales recorded by this user -->
                        <td><?php echo $u['nb_ventes']; ?> vente(s)</td>

                        <!-- Format creation date from YYYY-MM-DD to DD/MM/YYYY -->
                        <td><?php echo date('d/m/Y', strtotime($u['date_creation'])); ?></td>

                        <td>
                            <!-- Edit button: always visible -->
                            <a href="utilisateurs.php?edit=<?php echo $u['id_utilisateur']; ?>"
                               class="btn btn-warning">✏️ Modifier</a>

                            <!-- Delete button: only shown if:
                                 - It's NOT the logged-in admin's own account
                                 - AND the user has 0 recorded sales -->
                            <?php if ($u['id_utilisateur'] != $_SESSION['user_id'] && $u['nb_ventes'] == 0): ?>

                                <!-- Safe to delete — show the red delete button with a confirmation popup -->
                                <a href="utilisateurs.php?delete=<?php echo $u['id_utilisateur']; ?>"
                                   class="btn btn-danger"
                                   onclick="return confirm('Supprimer cet utilisateur ?')">
                                   🗑️ Supprimer
                                </a>

                            <?php else: ?>

                                <!-- Cannot delete — show a greyed-out button.
                                     The title attribute shows a tooltip explaining why. -->
                                <span class="btn btn-secondary"
                                      style="opacity:0.4; cursor:not-allowed;"
                                      title="<?php echo $u['id_utilisateur'] == $_SESSION['user_id']
                                          ? 'Votre propre compte'
                                          : 'A des ventes enregistrées'; ?>">
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
</div><!-- end .dashboard-layout -->
</body>
</html>
