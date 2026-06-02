<?php
/*
 * ============================================================
 * FILE: clients.php
 * PURPOSE: This page lets logged-in staff manage customers
 *          (called "clients" in French). Staff can view all
 *          clients, add new ones, and edit existing ones.
 *          Only administrators are allowed to DELETE a client.
 *
 * NOTE: Clients are customers who buy products. They do NOT
 *       have login accounts — they are only stored as records
 *       in the database so that sales can be linked to them.
 * ============================================================
 */

// Start (or resume) the session so we can read who is logged in.
// A "session" is like a memory box the server keeps per visitor.
// Without starting the session, $_SESSION variables are not accessible.
session_start();

// Load the database connection file.
// "require_once" includes the file right now, exactly once, and stops
// the entire script with a fatal error if the file is missing —
// because this app cannot work at all without a database connection.
require_once 'config.php';

/*
 * ── SECURITY GATE ────────────────────────────────────────────
 * Always check authentication before doing anything else.
 * If the visitor has not logged in, redirect them immediately
 * to the login page and stop all further code execution.
 */

// isset() checks whether the key 'user_id' exists inside the session.
// If no user_id is in the session, the person has not logged in.
if (!isset($_SESSION['user_id'])) {

    // header("Location: ...") sends an HTTP redirect instruction to the browser.
    // The browser will automatically navigate to login.php.
    header("Location: login.php");

    // exit() stops PHP from running any more code on this page.
    // Without this, the code below would continue executing even after the redirect.
    exit();
}

/*
 * ── FEEDBACK VARIABLES ───────────────────────────────────────
 * Initialise the two variables used to display feedback to the user.
 * $message = green success notification.
 * $erreur  = red error notification.
 * Both start as empty strings; they are only filled if an action occurs.
 */

// Empty string means "no success message to show yet".
$message = "";

// Empty string means "no error message to show yet".
$erreur  = "";

/*
 * ── DELETE A CLIENT (ADMIN ONLY) ─────────────────────────────
 * When an admin clicks "Delete" on a client row, the browser
 * appends "?delete=ID" to the URL (e.g. clients.php?delete=4).
 * We check if that parameter is present, then verify the user
 * is an admin before actually deleting the record.
 *
 * Regular employees (non-admins) are NOT allowed to delete clients.
 */

// Check if the "delete" URL parameter exists (e.g. ?delete=4).
if (isset($_GET['delete'])) {

    // Check the role stored in the session.
    // Only admins may proceed; everyone else gets a permission error.
    if ($_SESSION['user_role'] != 'admin') {

        // Store a red error message explaining the access is denied.
        // This message will be displayed to the non-admin user on screen.
        $erreur = "Accès refusé : seul un administrateur peut supprimer un client.";

    } else {

        // The logged-in user IS an admin, so we can proceed with deletion.

        // Convert the ID from the URL to a safe integer.
        // If someone types "abc" in the URL, (int) converts it to 0,
        // which will not match any real client ID — a safety measure.
        $id  = (int) $_GET['delete'];

        // Build the SQL DELETE statement.
        // This tells the database: "remove the row in the 'client' table
        // where the id_client column equals the number we received."
        $sql = "DELETE FROM client WHERE id_client = $id";

        // Execute the DELETE query and check if it succeeded.
        // mysqli_query() returns TRUE on success, FALSE on failure.
        if (mysqli_query($conn, $sql)) {

            // Deletion was successful — store a green success message.
            $message = "Client supprimé avec succès.";

        } else {

            // Something went wrong with the database — store a red error message.
            $erreur = "Erreur lors de la suppression.";
        }
    }
}

/*
 * ── ADD A NEW CLIENT ──────────────────────────────────────────
 * When the user fills in the "Add client" form and submits it,
 * the browser sends the data via HTTP POST.
 * We verify two things before processing:
 *   1. The request is a POST (not a regular page visit).
 *   2. The hidden "action" field equals "ajouter" (add).
 *
 * NOTE: Clients do NOT have passwords — they are just contact records.
 *       There is no login process for clients.
 */

// Check: was the form submitted via POST, AND is the action "ajouter"?
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'ajouter') {

    // Sanitize the client's last name.
    // mysqli_real_escape_string() escapes special characters (like quotes) in
    // the text so they cannot break the SQL query or allow a hacker to inject
    // malicious SQL (this is called an SQL injection attack).
    $nom       = mysqli_real_escape_string($conn, $_POST['nom']);

    // Sanitize the client's first name in the same way.
    $prenom    = mysqli_real_escape_string($conn, $_POST['prenom']);

    // Sanitize the email address.
    $email     = mysqli_real_escape_string($conn, $_POST['email']);

    // Sanitize the phone number.
    $telephone = mysqli_real_escape_string($conn, $_POST['telephone']);

    // Sanitize the street address.
    $adresse   = mysqli_real_escape_string($conn, $_POST['adresse']);

    // Build the SQL INSERT statement.
    // This adds a brand-new row to the "client" table with all the fields
    // collected from the form. The date_inscription column is set automatically
    // by the database (it has a DEFAULT CURRENT_TIMESTAMP or similar).
    $sql = "INSERT INTO client (nom, prenom, email, telephone, adresse)
            VALUES ('$nom', '$prenom', '$email', '$telephone', '$adresse')";

    // Execute the INSERT and check the result.
    if (mysqli_query($conn, $sql)) {

        // The client was saved — show the user a success message with the client's name.
        $message = "Client $prenom $nom ajouté avec succès.";

    } else {

        // The INSERT failed — show the user an error message plus the database's own
        // error description so a developer can understand the root cause.
        $erreur = "Erreur lors de l'ajout : " . mysqli_error($conn);
    }
}

/*
 * ── EDIT / UPDATE AN EXISTING CLIENT ─────────────────────────
 * Same idea as "add" above, but this time the hidden "action"
 * field equals "modifier" (French for "modify/edit").
 * We use SQL UPDATE to overwrite the existing row instead of
 * inserting a new one.
 */

// Check: POST request AND the hidden "action" field is "modifier"?
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'modifier') {

    // Get the ID of the client being edited, safely converted to integer.
    // This tells the UPDATE query exactly which client row to change.
    $id        = (int)   $_POST['id_client'];

    // Sanitize all text fields from the form before using them in SQL.
    $nom       = mysqli_real_escape_string($conn, $_POST['nom']);
    $prenom    = mysqli_real_escape_string($conn, $_POST['prenom']);
    $email     = mysqli_real_escape_string($conn, $_POST['email']);
    $telephone = mysqli_real_escape_string($conn, $_POST['telephone']);
    $adresse   = mysqli_real_escape_string($conn, $_POST['adresse']);

    // Convert the loyalty points value to a whole integer.
    // Loyalty points are whole numbers (you can't have 2.5 points).
    $points    = (int) $_POST['points_fidelite'];

    // Build the SQL UPDATE statement.
    // SET tells the database which columns to update and what the new values are.
    // WHERE id_client = $id makes sure ONLY this client's row is changed.
    // Without WHERE, every single client would be overwritten — a catastrophic mistake.
    $sql = "UPDATE client
            SET nom             = '$nom',
                prenom          = '$prenom',
                email           = '$email',
                telephone       = '$telephone',
                adresse         = '$adresse',
                points_fidelite = $points
            WHERE id_client     = $id";

    // Execute the UPDATE query.
    if (mysqli_query($conn, $sql)) {

        // After a successful update, redirect the browser back to this same page
        // but carry a success message in the URL (?message=...).
        // This is the Post/Redirect/Get pattern: it prevents the browser from
        // re-submitting the form data if the user hits the "Refresh" button.
        header("Location: clients.php?message=Client modifié avec succès.");

        // Stop PHP immediately so the redirect header takes effect.
        exit();

    } else {

        // The UPDATE query failed — show the user and developer an error message.
        $erreur = "Erreur lors de la modification : " . mysqli_error($conn);
    }
}

/*
 * ── LOAD A CLIENT FOR EDITING ─────────────────────────────────
 * When the user clicks the "Edit" button on a client row, the
 * browser navigates to clients.php?edit=ID.
 * We detect that parameter and fetch that client's data from the
 * database so the form can be pre-filled with their current details.
 */

// Start with nothing loaded for editing. null means "no client selected".
$client_edit = null;

// Check if "edit" was passed in the URL (e.g., clients.php?edit=3).
if (isset($_GET['edit'])) {

    // Convert the ID from the URL to a safe integer.
    $id = (int) $_GET['edit'];

    // Run a SELECT query to fetch this specific client's row.
    // SELECT * means "get all columns for this client".
    $res = mysqli_query($conn, "SELECT * FROM client WHERE id_client = $id");

    // Convert the database result into a PHP associative array.
    // After this, we can read values like $client_edit['nom'].
    $client_edit = mysqli_fetch_assoc($res);
}

/*
 * ── READ SUCCESS MESSAGE FROM URL ────────────────────────────
 * After a successful edit, the browser is redirected and the
 * success message is passed in the URL as ?message=...
 * We read it here and display it to the user.
 */

// Check if "message" is in the URL query string.
if (isset($_GET['message'])) {

    // Read the message from the URL and sanitize it.
    // htmlspecialchars() converts <, >, &, and " into safe HTML entities.
    // This prevents a malicious user from injecting HTML or JavaScript
    // into the message via a crafted URL (XSS — Cross-Site Scripting attack).
    $message = htmlspecialchars($_GET['message']);
}

/*
 * ── FETCH ALL CLIENTS ─────────────────────────────────────────
 * Load every client from the database to display in the table.
 * We order by date_inscription DESC so the newest clients appear first.
 */

// Run a SELECT query to get all clients, newest registrations first.
$clients = mysqli_query($conn, "SELECT * FROM client ORDER BY date_inscription DESC");

// Count the total number of clients returned by the query.
// mysqli_num_rows() counts the rows without fetching them.
$total   = mysqli_num_rows($clients);

/*
 * ============================================================
 * HTML OUTPUT STARTS HERE
 * Everything below produces the visible web page in the browser.
 * ============================================================
 */
?>

<!DOCTYPE html>
<!-- Declare this is a standard HTML5 document so the browser renders it correctly. -->
<html lang="fr">
<!-- lang="fr" tells assistive tools and browsers that the content is in French. -->
<head>
    <!-- The <head> block holds metadata and linked resources, not visible content. -->

    <!-- UTF-8 encoding ensures French characters (é, à, ç, etc.) display properly. -->
    <meta charset="UTF-8">

    <!-- Title shown in the browser tab and bookmarks bar. -->
    <title>Clients</title>

    <!-- Link the external CSS stylesheet. All visual styling lives in style.css. -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
<!-- Everything inside <body> is visible on the page. -->

<!-- Outer wrapper div that creates the two-column dashboard layout:
     left column = sidebar navigation, right column = main content. -->
<div class="dashboard-layout">

    <!-- ── LEFT SIDEBAR ───────────────────────────────────────── -->
    <!-- <aside> is an HTML landmark for secondary content — here it is the nav sidebar. -->
    <aside class="sidebar">

        <!-- Brand / logo block at the very top of the sidebar. -->
        <div class="sidebar-logo">
            <!-- Application name as a top-level heading inside the sidebar. -->
            <h1>MVSTOCK</h1>
            <!-- Marketing tagline underneath the app name. -->
            <p>Sell fast, restock faster.</p>
        </div>

        <!-- Primary navigation links. Each <a> takes the user to a different section. -->
        <nav>
            <!-- Dashboard / home page link. -->
            <a href="accueil.php"><span class="icon">🏠</span> Tableau de bord</a>

            <!-- Products page link. -->
            <a href="produits.php"><span class="icon">📦</span> Produits</a>

            <!-- Clients page link — marked "active" because this IS the clients page.
                 The CSS "active" class highlights this link so the user knows where they are. -->
            <a href="clients.php" class="active"><span class="icon">👥</span> Clients</a>

            <!-- Sales page link. -->
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>

            <!-- Statistics page link — visible to ALL logged-in users here. -->
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>

            <!-- Users management link — only show this to admins. -->
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <!-- This block only renders in the HTML if the logged-in user is an admin.
                 Regular staff will never see this link at all. -->
            <a href="utilisateurs.php"><span class="icon">👤</span> Utilisateurs</a>
            <?php endif; ?>
            <!-- End of the admin-only Users management link. -->
        </nav>

        <!-- Bottom section of the sidebar: the logout link. -->
        <div class="sidebar-footer">
            <!-- Clicking this navigates to deconnexion.php which destroys the session. -->
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>
    <!-- End of the left sidebar. -->

    <!-- ── MAIN CONTENT AREA ─────────────────────────────────── -->
    <!-- <main> is the primary content region. The CSS positions it to the right of the sidebar. -->
    <main class="main-content">

        <!-- Top bar: page title on the left, logged-in user's name and role on the right. -->
        <div class="topbar">
            <!-- Page heading — tells the user which section they are in. -->
            <h2>👥 Clients</h2>

            <!-- User info displayed in the top-right corner of the page. -->
            <div class="user-info">
                <!-- Greeting with the logged-in user's name.
                     htmlspecialchars() sanitizes the name to prevent XSS attacks —
                     for example if the name somehow contained HTML tags. -->
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>

                <!-- Role badge showing "admin" or "employe" etc. next to the name. -->
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>
        <!-- End of top bar. -->

        <!-- ── SUCCESS MESSAGE ───────────────────────────────── -->
        <!-- Only render the green box when there is actually a success message to show. -->
        <?php if ($message != ""): ?>
            <!-- Green notification box. The message was set earlier in the PHP section. -->
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>
        <!-- End of success message. -->

        <!-- ── ERROR MESSAGE ─────────────────────────────────── -->
        <!-- Only render the red box when there is an error message to show. -->
        <?php if ($erreur != ""): ?>
            <!-- Red notification box. The error was set earlier (e.g. permission denied). -->
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>
        <!-- End of error message. -->

        <!-- ── ADD / EDIT FORM CARD ───────────────────────────── -->
        <!-- White card containing the form used to add a new client or edit an existing one. -->
        <div class="card" style="margin-bottom:24px;">

            <!-- Card header: the title and an optional Cancel button. -->
            <div class="card-header">

                <!-- Show "Edit client" if we loaded a client for editing,
                     or "Add a client" if we are in fresh add mode. -->
                <h3><?php echo $client_edit ? '✏️ Modifier le client' : '➕ Ajouter un client'; ?></h3>

                <!-- In edit mode only: show a Cancel button that goes back to the normal list. -->
                <?php if ($client_edit): ?>
                    <!-- Clicking this link navigates back to clients.php without ?edit=...,
                         effectively cancelling the edit operation. -->
                    <a href="clients.php" class="btn btn-secondary">Annuler</a>
                <?php endif; ?>
                <!-- End of cancel button. -->
            </div>
            <!-- End of card header. -->

            <!-- Form wrapper div.
                 In normal (add) mode: hidden with CSS display:none until "New client" is clicked.
                 In edit mode: visible immediately so the pre-filled fields are shown. -->
            <div id="form-client" style="padding:24px; <?php echo $client_edit ? '' : 'display:none;'; ?>">

                <!-- The HTML form.
                     method="POST": sends data in the HTTP body (not visible in the URL).
                     action="clients.php": submits back to this same page for PHP to process. -->
                <form method="POST" action="clients.php">

                    <!-- Hidden field telling PHP whether to INSERT (ajouter) or UPDATE (modifier).
                         The user does not see this field but it is included when the form is sent. -->
                    <input type="hidden" name="action" value="<?php echo $client_edit ? 'modifier' : 'ajouter'; ?>">

                    <!-- In edit mode, also send the client's ID so PHP knows which row to update. -->
                    <?php if ($client_edit): ?>
                        <!-- Hidden field containing the database ID of the client being edited. -->
                        <input type="hidden" name="id_client" value="<?php echo $client_edit['id_client']; ?>">
                    <?php endif; ?>
                    <!-- End of hidden ID field. -->

                    <!-- Two-column CSS grid that arranges the form fields side by side. -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                        <!-- ── LAST NAME FIELD ──────────────────── -->
                        <div class="form-group">
                            <!-- Label: "Nom" is French for "Last name". The * means it is required. -->
                            <label>Nom *</label>
                            <!-- Text input for the last name.
                                 required makes the browser block submission if left empty.
                                 value= pre-fills the field when editing an existing client. -->
                            <input type="text" name="nom" required
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['nom']) : ''; ?>">
                        </div>

                        <!-- ── FIRST NAME FIELD ─────────────────── -->
                        <div class="form-group">
                            <!-- Label: "Prénom" is French for "First name". Required field. -->
                            <label>Prénom *</label>
                            <!-- Text input for the first name.
                                 required prevents submitting with an empty first name. -->
                            <input type="text" name="prenom" required
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['prenom']) : ''; ?>">
                        </div>

                        <!-- ── EMAIL FIELD ──────────────────────── -->
                        <div class="form-group">
                            <!-- Label for the email address. Not required — clients may not have email. -->
                            <label>Email</label>
                            <!-- Email input: type="email" makes the browser validate
                                 the format (must contain @ etc.) before allowing submission. -->
                            <input type="email" name="email"
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['email']) : ''; ?>">
                        </div>

                        <!-- ── PHONE FIELD ──────────────────────── -->
                        <div class="form-group">
                            <!-- Label for the phone number. Not required. -->
                            <label>Téléphone</label>
                            <!-- Text input for the phone number.
                                 We use type="text" (not type="tel") to allow
                                 various international formats freely. -->
                            <input type="text" name="telephone"
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['telephone']) : ''; ?>">
                        </div>

                        <!-- ── ADDRESS FIELD ────────────────────── -->
                        <!-- grid-column:span 2 makes this input stretch across the full width. -->
                        <div class="form-group" style="grid-column:span 2;">
                            <!-- Label for the street/mailing address. Not required. -->
                            <label>Adresse</label>
                            <!-- Text input for the address.
                                 Full width because addresses can be long. -->
                            <input type="text" name="adresse"
                                value="<?php echo $client_edit ? htmlspecialchars($client_edit['adresse']) : ''; ?>">
                        </div>

                        <!-- ── LOYALTY POINTS FIELD (EDIT MODE ONLY) ── -->
                        <!-- This field only appears when editing an existing client, never when adding.
                             Points are earned automatically via sales, not manually set on creation. -->
                        <?php if ($client_edit): ?>
                        <div class="form-group">
                            <!-- Label for the loyalty points counter. -->
                            <label>Points de fidélité</label>
                            <!-- Number input for loyalty points.
                                 min="0" prevents negative points.
                                 Pre-filled with the client's current points balance. -->
                            <input type="number" name="points_fidelite" min="0"
                                value="<?php echo $client_edit['points_fidelite']; ?>">
                        </div>
                        <?php endif; ?>
                        <!-- End of loyalty points field (edit-mode only block). -->

                    </div>
                    <!-- End of the two-column grid layout. -->

                    <!-- Submit button for the form.
                         Shows "Save" in edit mode, or "Add client" in add mode. -->
                    <button type="submit" class="btn btn-primary">
                        <?php echo $client_edit ? '💾 Enregistrer' : '➕ Ajouter le client'; ?>
                    </button>

                </form>
                <!-- End of the HTML form. -->
            </div>
            <!-- End of the collapsible form wrapper. -->
        </div>
        <!-- End of the add/edit form card. -->

        <!-- ── CLIENTS TABLE CARD ─────────────────────────────── -->
        <!-- White card containing the table that lists all registered clients. -->
        <div class="card">

            <!-- Card header: total client count and button to open the add form. -->
            <div class="card-header">
                <!-- Heading showing how many clients are in the database.
                     $total was set earlier using mysqli_num_rows(). -->
                <h3>Liste des clients (<?php echo $total; ?>)</h3>

                <!-- Button that reveals the hidden add-client form when clicked.
                     onclick uses inline JavaScript to change the form div's CSS
                     display property from "none" (hidden) to "block" (visible). -->
                <button class="btn btn-primary"
                    onclick="document.getElementById('form-client').style.display='block'">
                    ➕ Nouveau client
                </button>
            </div>
            <!-- End of table card header. -->

            <!-- Show a friendly "empty" message if there are no clients yet. -->
            <?php if ($total == 0): ?>
                <!-- Empty state block: shown only when the database has no client rows. -->
                <div class="empty-state">
                    <!-- Large icon for visual appeal in the empty state. -->
                    <div class="empty-icon">👥</div>
                    <!-- Explanatory text for the empty state. -->
                    <p>Aucun client enregistré pour l'instant.</p>
                </div>

            <?php else: ?>
            <!-- There IS at least one client: render the data table. -->
            <table>
                <!-- Table head defines the column headings. -->
                <thead>
                    <tr>
                        <!-- Column: the client's database ID number. -->
                        <th>#</th>
                        <!-- Column: first name and last name combined. -->
                        <th>Nom complet</th>
                        <!-- Column: email address. -->
                        <th>Email</th>
                        <!-- Column: phone number. -->
                        <th>Téléphone</th>
                        <!-- Column: street address. -->
                        <th>Adresse</th>
                        <!-- Column: accumulated loyalty points. -->
                        <th>Fidélité</th>
                        <!-- Column: the date the client was registered. -->
                        <th>Inscrit le</th>
                        <!-- Column: edit and (for admins) delete buttons. -->
                        <th>Actions</th>
                    </tr>
                </thead>
                <!-- End of table head. -->

                <!-- Table body: PHP generates one row per client. -->
                <tbody>

                <?php
                // Loop through every client row returned from the database.
                // Each pass through the loop, $c holds one client as an array.
                while ($c = mysqli_fetch_assoc($clients)):
                ?>
                    <!-- One <tr> (table row) for each client. -->
                    <tr>

                        <!-- Client's database ID number. -->
                        <td><?php echo $c['id_client']; ?></td>

                        <!-- Full name: first name + last name, in bold.
                             htmlspecialchars() prevents any HTML characters in the name
                             from being rendered as actual HTML tags (XSS prevention). -->
                        <td><strong><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></strong></td>

                        <!-- Email address, or a dash if none was provided.
                             The ternary operator ? : checks if the field is not empty. -->
                        <td><?php echo $c['email']     ? htmlspecialchars($c['email'])     : '—'; ?></td>

                        <!-- Phone number, or a dash if none was provided. -->
                        <td><?php echo $c['telephone'] ? htmlspecialchars($c['telephone']) : '—'; ?></td>

                        <!-- Street address, or a dash if none was provided. -->
                        <td><?php echo $c['adresse']   ? htmlspecialchars($c['adresse'])   : '—'; ?></td>

                        <!-- Loyalty points displayed in orange with a star icon.
                             The inline style makes the points visually stand out. -->
                        <td><span style="color:#D97706; font-weight:600;">⭐ <?php echo $c['points_fidelite']; ?> pts</span></td>

                        <!-- Registration date, reformatted from the database's YYYY-MM-DD format
                             to the human-friendly DD/MM/YYYY format using PHP's date() function.
                             strtotime() converts the text date into a Unix timestamp first. -->
                        <td><?php echo date('d/m/Y', strtotime($c['date_inscription'])); ?></td>

                        <!-- Action buttons: Edit is available to everyone; Delete is admin-only. -->
                        <td>
                            <!-- Edit button: navigates to clients.php?edit=ID which causes PHP to
                                 load this client's data into the form above for editing. -->
                            <a href="clients.php?edit=<?php echo $c['id_client']; ?>" class="btn btn-warning">✏️ Modifier</a>

                            <!-- Delete button: ONLY rendered in the HTML if the logged-in user is admin.
                                 Regular employees will not even see this button — not just a JS hide,
                                 the button is genuinely absent from the page source for non-admins. -->
                            <?php if ($_SESSION['user_role'] == 'admin'): ?>
                            <!-- Delete link with a JavaScript confirmation popup.
                                 "return confirm(...)" shows a dialog box asking the admin to confirm.
                                 If they click OK the navigation proceeds to ?delete=ID.
                                 If they click Cancel, return false stops the navigation entirely. -->
                            <a href="clients.php?delete=<?php echo $c['id_client']; ?>"
                               class="btn btn-danger"
                               onclick="return confirm('Supprimer ce client ?')">🗑️ Supprimer</a>
                            <?php endif; ?>
                            <!-- End of admin-only delete button. -->
                        </td>
                        <!-- End of actions cell. -->

                    </tr>
                    <!-- End of this client's table row. -->

                <?php endwhile; ?>
                <!-- End of the client loop. All client rows have been rendered. -->

                </tbody>
                <!-- End of table body. -->
            </table>
            <!-- End of the clients data table. -->

            <?php endif; ?>
            <!-- End of the if/else block for empty vs. populated client list. -->

        </div>
        <!-- End of the clients table card. -->

    </main>
    <!-- End of the main content area. -->

</div>
<!-- End of the dashboard layout wrapper div. -->

<!-- NOTE: All .alert-msg styles (green success box, red error box) are defined in style.css -->
</body>
<!-- End of the visible page content. -->
</html>
<!-- End of the HTML document. -->
