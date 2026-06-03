<?php
/*
 * ============================================================
 * FILE: produits.php
 * PURPOSE: This page lets logged-in staff manage products.
 *          Staff can see all products, add new ones, edit
 *          existing ones, and delete them.
 * ============================================================
 */

// Start (or resume) the session so we can read who is logged in.
// A "session" is like a memory box the server keeps open for each visitor.
session_start();

// Load the database connection settings from a separate file.
// "require_once" means: include that file right now, and only once even if
// this line is accidentally reached again — the app cannot work without it.
require_once 'config.php';

/*
 * ── SECURITY GATE ────────────────────────────────────────────
 * Before doing ANYTHING else, make sure the visitor is logged in.
 * If they are not, send them to the login page immediately.
 */

// Check whether a user ID has been stored in the session.
// If there is no user ID, the person is not logged in.
if (!isset($_SESSION['user_id'])) {

    // Send the browser to the login page using an HTTP redirect header.
    // "header()" tells the browser "go to this address instead".
    header("Location: login.php");

    // Stop all PHP execution right here so nothing else on this page runs.
    // Without exit(), the rest of the code would still execute in the background.
    exit();
}

/*
 * ── FEEDBACK VARIABLES ───────────────────────────────────────
 * These two variables will hold messages shown to the user.
 * We start them empty; they are filled in later if something happens.
 */

// $message holds a green "success" notice (e.g., "Product added successfully").
$message = "";

// $erreur holds a red "error" notice (e.g., "Could not delete product").
$erreur  = "";

/*
 * ── DELETE A PRODUCT ─────────────────────────────────────────
 * When the user clicks the "Delete" button, the browser adds
 * "?delete=5" (or whatever the product ID is) to the page URL.
 * We check if that parameter is present and, if so, delete the row.
 */

// Check if "delete" was passed in the URL (e.g., produits.php?delete=3).
if (isset($_GET['delete'])) {

    // ── PERMISSION CHECK: only admins may delete a product ──────────
    // Deleting a product is a destructive action reserved for admins.
    // We must check the role HERE on the server, not just hide the
    // button in the page. Otherwise an agent could still delete a
    // product simply by typing "produits.php?delete=5" in the address
    // bar. This server-side check is the real protection.
    if ($_SESSION['user_role'] != 'admin') {
        // The user is not an admin — refuse the deletion and show an error.
        // We do NOT run the DELETE query at all.
        $erreur = "Vous n'avez pas la permission de supprimer un produit.";
    } else {

    // Convert the value from the URL to a whole number (integer).
    // This is a safety measure: if someone types "abc" instead of "3",
    // casting it to (int) gives 0, which will not match any real product.
    $id  = (int) $_GET['delete'];

    // Build the SQL command that removes the product row from the database.
    // SQL DELETE FROM says: "in the 'produit' table, remove the row where
    // the column id_produit equals the number we just captured."
    $sql = "DELETE FROM produit WHERE id_produit = $id";

    // Send the SQL command to the database and check if it succeeded.
    // mysqli_query() returns TRUE on success or FALSE on failure.
    if (mysqli_query($conn, $sql)) {

        // The deletion worked — store a success message to show the user.
        $message = "Produit supprimé avec succès.";

    } else {

        // Something went wrong — store an error message to show the user.
        $erreur = "Erreur lors de la suppression.";
    }

    } // End of the admin-only permission block opened above.
}

/*
 * ── ADD A NEW PRODUCT ─────────────────────────────────────────
 * When the user fills in the "Add product" form and clicks the
 * submit button, the browser sends the data via HTTP POST.
 * We check that the request is a POST AND that the hidden field
 * "action" equals "ajouter" (French for "add").
 */

// Check: was this page reached by submitting a form (POST method)?
// AND does the hidden field "action" say "ajouter"?
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'ajouter') {

    // Sanitize the product name typed by the user.
    // mysqli_real_escape_string() makes the text safe to put inside an SQL
    // query by escaping special characters that could break the query or
    // allow a hacker to inject their own SQL commands (SQL injection attack).
    $designation  = mysqli_real_escape_string($conn, $_POST['designation']);

    // Sanitize the product description the same safe way.
    $description  = mysqli_real_escape_string($conn, $_POST['description']);

    // Convert the unit price to a floating-point (decimal) number.
    // (float) makes sure we store a real price like 9.99, not text.
    $prix         = (float) $_POST['prix_unitaire'];

    // Convert the current stock quantity to a whole number (integer).
    // We don't want half a unit in stock, so integer makes sense here.
    $stock        = (int)   $_POST['stock_actuel'];

    // Convert the alert threshold to a whole number as well.
    // When stock falls to or below this number, a warning badge is shown.
    $seuil        = (int)   $_POST['seuil_alerte'];

    // Handle the category selection.
    // !empty() checks whether the user actually chose a category.
    // If they did, cast it to integer for safety.
    // If they left it blank (no category), use the SQL keyword NULL
    // (meaning "no value") instead of inserting an empty string.
    $id_categorie = !empty($_POST['id_categorie']) ? (int) $_POST['id_categorie'] : "NULL";

    // Build the SQL INSERT command that adds a new row to the produit table.
    // Each column name is listed, and its matching value follows in order.
    $sql = "INSERT INTO produit (designation, description, prix_unitaire, stock_actuel, seuil_alerte, id_categorie)
            VALUES ('$designation', '$description', $prix, $stock, $seuil, $id_categorie)";

    // Run the INSERT query against the database.
    if (mysqli_query($conn, $sql)) {

        // The product was saved — show a success message including the product name.
        // Wrapping $designation in escaped quotes makes the name stand out in the message.
        $message = "Produit \"$designation\" ajouté avec succès.";

    } else {

        // The INSERT failed — show an error message AND include the exact database
        // error text so the developer can understand what went wrong.
        $erreur = "Erreur lors de l'ajout : " . mysqli_error($conn);
    }
}

/*
 * ── EDIT / UPDATE AN EXISTING PRODUCT ────────────────────────
 * Same idea as "add" above, but now the hidden field "action"
 * says "modifier" (French for "edit/modify").
 * We UPDATE the existing database row rather than INSERT a new one.
 */

// Check: POST request AND the hidden "action" field says "modifier"?
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'modifier') {

    // Get the ID of the product being edited, as a safe integer.
    // This tells the database WHICH product row to update.
    $id           = (int)   $_POST['id_produit'];

    // Sanitize all the text fields the user submitted in the edit form.
    $designation  = mysqli_real_escape_string($conn, $_POST['designation']);
    $description  = mysqli_real_escape_string($conn, $_POST['description']);

    // Convert prices and quantities to their correct numeric types.
    $prix         = (float) $_POST['prix_unitaire'];
    $stock        = (int)   $_POST['stock_actuel'];
    $seuil        = (int)   $_POST['seuil_alerte'];

    // Handle the category the same way as in the "add" section above.
    $id_categorie = !empty($_POST['id_categorie']) ? (int) $_POST['id_categorie'] : "NULL";

    // Build the SQL UPDATE command.
    // SET lists every column we want to change with its new value.
    // WHERE makes sure we only change the product with the right ID —
    // without WHERE, ALL products would be overwritten!
    $sql = "UPDATE produit
            SET designation   = '$designation',
                description   = '$description',
                prix_unitaire = $prix,
                stock_actuel  = $stock,
                seuil_alerte  = $seuil,
                id_categorie  = $id_categorie
            WHERE id_produit  = $id";

    // Run the UPDATE query.
    if (mysqli_query($conn, $sql)) {

        // After a successful edit, redirect the browser back to this same page
        // but with a success message in the URL (?message=...).
        // This is called Post/Redirect/Get (PRG) pattern — it prevents the
        // browser from re-submitting the form if the user refreshes the page.
        header("Location: produits.php?message=Produit modifié avec succès.");

        // Stop PHP here so the redirect takes effect immediately.
        exit();

    } else {

        // The UPDATE failed — show the database error message.
        $erreur = "Erreur lors de la modification : " . mysqli_error($conn);
    }
}

/*
 * ── LOAD A PRODUCT FOR EDITING ────────────────────────────────
 * When the user clicks "Edit" on a product row, the browser adds
 * "?edit=5" to the URL. We fetch that product's current data from
 * the database so we can pre-fill the form fields.
 */

// Start with no product loaded for editing (null means "nothing").
$produit_edit = null;

// Check if "edit" was passed in the URL (e.g., produits.php?edit=7).
if (isset($_GET['edit'])) {

    // Safely convert the ID from the URL to an integer.
    $id = (int) $_GET['edit'];

    // Fetch the matching product row from the database.
    // SELECT * means "get all columns".
    $res = mysqli_query($conn, "SELECT * FROM produit WHERE id_produit = $id");

    // Turn the database result row into a PHP associative array
    // so we can access values like $produit_edit['designation'].
    $produit_edit = mysqli_fetch_assoc($res);
}

/*
 * ── READ SUCCESS MESSAGE FROM URL ────────────────────────────
 * After a redirect (following a successful edit), the success
 * message is carried in the URL as ?message=...
 * We read it here and store it in $message.
 */

// Check if a "message" parameter was passed in the URL.
if (isset($_GET['message'])) {

    // Read the message and sanitize it with htmlspecialchars() to prevent
    // any malicious HTML or JavaScript that could have been crafted in the URL
    // from executing in the browser (this type of attack is called XSS).
    $message = htmlspecialchars($_GET['message']);
}

/*
 * ── LOAD ALL CATEGORIES ────────────────────────────────────────
 * We need the list of product categories to populate the dropdown
 * in the add/edit form.
 */

// Fetch all rows from the categorie table, sorted alphabetically by name.
$categories = mysqli_query($conn, "SELECT * FROM categorie ORDER BY nom_categorie");

// Create an empty PHP array to hold the categories.
// We build this array so we can loop over categories multiple times
// (a database result can only be looped once).
$cat_array  = [];

// Loop through each row returned by the query.
while ($c = mysqli_fetch_assoc($categories)) {

    // Add the current category row (as an associative array) to our list.
    $cat_array[] = $c;
}

/*
 * ── LOAD ALL PRODUCTS ─────────────────────────────────────────
 * Fetch every product from the database along with its category name.
 * We use LEFT JOIN so that products without a category still appear
 * (they just show no category name).
 * Products are sorted first by category, then alphabetically by name.
 */

// Run a SELECT query joining the produit and categorie tables.
// LEFT JOIN means: include all products, even if they have no matching category.
// p.* means: get all columns from the produit table.
// c.nom_categorie means: also get the category name from the categorie table.
$produits = mysqli_query($conn,
    "SELECT p.*, c.nom_categorie
     FROM produit p
     LEFT JOIN categorie c ON p.id_categorie = c.id_categorie
     ORDER BY c.nom_categorie, p.designation"
);

/*
 * ============================================================
 * HTML OUTPUT STARTS HERE
 * Everything below is the visible web page sent to the browser.
 * ============================================================
 */
?>
<!DOCTYPE html>
<!-- Tell the browser this is a standard HTML5 document. -->
<html lang="fr">
<!-- lang="fr" tells browsers and screen readers the page is in French. -->
<head>
    <!-- The <head> section contains page settings not shown directly on screen. -->

    <!-- Tell the browser to use UTF-8 encoding so French characters display correctly. -->
    <meta charset="UTF-8">

    <!-- Set the text shown in the browser tab. -->
    <title>Produits</title>

    <!-- Link to the external CSS stylesheet that controls colours, fonts, and layout. -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
<!-- <body> contains everything visible on the page. -->

<!-- This div wraps the whole page in a two-column layout: sidebar on the left, main content on the right. -->
<div class="dashboard-layout">

    <!-- ── LEFT SIDEBAR ───────────────────────────────────────── -->
    <!-- The <aside> element is the navigation panel on the left side of the screen. -->
    <aside class="sidebar">

        <!-- Logo / brand area at the top of the sidebar. -->
        <div class="sidebar-logo">
            <!-- Application name displayed as a large heading. -->
            <h1>MVSTOCK</h1>
            <!-- Tagline shown beneath the app name. -->
            <p>Sell fast, restock faster.</p>
        </div>

        <!-- Navigation links — one for each main section of the app. -->
        <nav>
            <!-- Link to the main dashboard / home page. -->
            <a href="accueil.php"><span class="icon">🏠</span> Tableau de bord</a>

            <!-- Link to this same page (Products). The "active" class highlights it in the menu. -->
            <a href="produits.php" class="active"><span class="icon">📦</span> Produits</a>

            <!-- Link to the Clients page. -->
            <a href="clients.php"><span class="icon">👥</span> Clients</a>

            <!-- Link to the Sales page. -->
            <a href="ventes.php"><span class="icon">🛒</span> Ventes</a>

            <!-- The Statistics link is only shown to admin users, not regular staff. -->
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <!-- Show this link only if the logged-in user's role is "admin". -->
            <a href="stats.php"><span class="icon">📊</span> Statistiques</a>
            <?php endif; ?>
            <!-- End of the admin-only Statistics link. -->

            <!-- The Users management link is also restricted to admins only. -->
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <!-- Show this link only if the logged-in user's role is "admin". -->
            <a href="utilisateurs.php"><span class="icon">👤</span> Utilisateurs</a>
            <?php endif; ?>
            <!-- End of the admin-only Users link. -->
        </nav>

        <!-- Bottom of the sidebar: logout link. -->
        <div class="sidebar-footer">
            <!-- Clicking this link logs the user out by going to deconnexion.php. -->
            <a href="deconnexion.php"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>
    <!-- End of sidebar. -->

    <!-- ── MAIN CONTENT AREA ─────────────────────────────────── -->
    <!-- The <main> element holds the central content of the page. -->
    <main class="main-content">

        <!-- Top bar: page title on the left, logged-in user info on the right. -->
        <div class="topbar">
            <!-- Page heading shown at the top of the content area. -->
            <h2>📦 Produits</h2>

            <!-- User info block displayed in the top-right corner. -->
            <div class="user-info">
                <!-- Display a greeting with the logged-in user's name.
                     htmlspecialchars() prevents any special characters in the
                     name from being interpreted as HTML (security measure). -->
                <span>👋 <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>

                <!-- Show the user's role (e.g., "admin" or "employe") as a badge. -->
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>
        <!-- End of top bar. -->

        <!-- ── SUCCESS MESSAGE ───────────────────────────────── -->
        <!-- Only display the green success box if $message is not empty. -->
        <?php if ($message != ""): ?>
            <!-- Green notification box showing the success message. -->
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>
        <!-- End of success message display. -->

        <!-- ── ERROR MESSAGE ─────────────────────────────────── -->
        <!-- Only display the red error box if $erreur is not empty. -->
        <?php if ($erreur != ""): ?>
            <!-- Red notification box showing the error message. -->
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>
        <!-- End of error message display. -->

        <!-- ── ADD / EDIT FORM CARD ───────────────────────────── -->
        <!-- This white card contains the form to add a new product or edit an existing one. -->
        <div class="card" style="margin-bottom:24px;">

            <!-- Card header: title changes depending on whether we are adding or editing. -->
            <div class="card-header">

                <!-- If $produit_edit is set (not null), we are in edit mode; show "Edit product".
                     Otherwise we are in add mode; show "Add a product". -->
                <h3><?php echo $produit_edit ? '✏️ Modifier le produit' : '➕ Ajouter un produit'; ?></h3>

                <!-- If we are in edit mode, show a "Cancel" button that goes back to the plain list. -->
                <?php if ($produit_edit): ?>
                    <!-- Cancel link — goes back to produits.php without the ?edit=... parameter. -->
                    <a href="produits.php" class="btn btn-secondary">Annuler</a>
                <?php endif; ?>
                <!-- End of cancel button for edit mode. -->
            </div>
            <!-- End of card header. -->

            <!-- The form wrapper div.
                 In add mode it is hidden (display:none) until the user clicks "New product".
                 In edit mode it is visible immediately so the user can see the pre-filled fields. -->
            <div id="form-produit" style="padding:24px; <?php echo $produit_edit ? '' : 'display:none;'; ?>">

                <!-- The HTML form. method="POST" means data is sent in the request body (not the URL).
                     action="produits.php" means the form submits back to this same page. -->
                <form method="POST" action="produits.php">

                    <!-- Hidden field that tells PHP which action to take: "ajouter" or "modifier".
                         The browser sends this along with the other form data but it is not shown. -->
                    <input type="hidden" name="action" value="<?php echo $produit_edit ? 'modifier' : 'ajouter'; ?>">

                    <!-- If editing, also send the product ID so PHP knows which row to UPDATE. -->
                    <?php if ($produit_edit): ?>
                        <!-- Hidden field carrying the product's database ID. -->
                        <input type="hidden" name="id_produit" value="<?php echo $produit_edit['id_produit']; ?>">
                    <?php endif; ?>
                    <!-- End of hidden product ID field. -->

                    <!-- Two-column grid layout for the form fields. -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                        <!-- ── DESIGNATION FIELD ────────────────── -->
                        <div class="form-group">
                            <!-- Label for the product name field. The asterisk (*) means required. -->
                            <label>Désignation *</label>
                            <!-- Text input for the product name.
                                 required means the browser will refuse to submit if this is empty.
                                 value= pre-fills the field when editing (otherwise left blank). -->
                            <input type="text" name="designation" required
                                value="<?php echo $produit_edit ? htmlspecialchars($produit_edit['designation']) : ''; ?>">
                        </div>

                        <!-- ── CATEGORY DROPDOWN ────────────────── -->
                        <div class="form-group">
                            <!-- Label for the category selector. -->
                            <label>Catégorie</label>
                            <!-- Dropdown list of categories. name="id_categorie" is what PHP reads. -->
                            <select name="id_categorie">
                                <!-- First option: no category selected. value="" means NULL in PHP. -->
                                <option value="">-- Aucune catégorie --</option>

                                <!-- Loop through all categories loaded earlier and make one option per category. -->
                                <?php foreach ($cat_array as $cat): ?>
                                    <option value="<?php echo $cat['id_categorie']; ?>"
                                        <?php
                                        // If we are editing, check if this option's ID matches the
                                        // product's current category. If yes, mark it as "selected"
                                        // so the dropdown shows the right category pre-selected.
                                        echo ($produit_edit && $produit_edit['id_categorie'] == $cat['id_categorie']) ? 'selected' : '';
                                        ?>>
                                        <?php echo htmlspecialchars($cat['nom_categorie']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <!-- End of category loop. -->
                            </select>
                        </div>

                        <!-- ── UNIT PRICE FIELD ─────────────────── -->
                        <div class="form-group">
                            <!-- Label for the unit price input. -->
                            <label>Prix unitaire ($) *</label>
                            <!-- Number input for the price.
                                 step="0.01" allows cents (two decimal places).
                                 min="0" prevents negative prices.
                                 required means the form cannot be submitted empty. -->
                            <input type="number" name="prix_unitaire" step="0.01" min="0" required
                                value="<?php echo $produit_edit ? $produit_edit['prix_unitaire'] : ''; ?>">
                        </div>

                        <!-- ── CURRENT STOCK FIELD ──────────────── -->
                        <div class="form-group">
                            <!-- Label for the stock quantity input. -->
                            <label>Stock actuel *</label>
                            <!-- Number input for current stock quantity.
                                 min="0" prevents negative stock.
                                 required prevents submitting the form empty. -->
                            <input type="number" name="stock_actuel" min="0" required
                                value="<?php echo $produit_edit ? $produit_edit['stock_actuel'] : ''; ?>">
                        </div>

                        <!-- ── ALERT THRESHOLD FIELD ────────────── -->
                        <div class="form-group">
                            <!-- Label for the low-stock alert threshold. -->
                            <label>Seuil d'alerte</label>
                            <!-- Number input for the minimum stock level before showing a warning badge.
                                 Default value is 5 for new products; shows existing value when editing. -->
                            <input type="number" name="seuil_alerte" min="0"
                                value="<?php echo $produit_edit ? $produit_edit['seuil_alerte'] : '5'; ?>">
                        </div>

                        <!-- ── DESCRIPTION FIELD ────────────────── -->
                        <!-- style="grid-column:span 2;" makes this field stretch across both columns. -->
                        <div class="form-group" style="grid-column:span 2;">
                            <!-- Label for the description text area. -->
                            <label>Description</label>
                            <!-- Multi-line text area for a product description.
                                 rows="2" sets the visible height to two lines.
                                 The text between the tags is the pre-filled content when editing. -->
                            <textarea name="description" rows="2"><?php echo $produit_edit ? htmlspecialchars($produit_edit['description']) : ''; ?></textarea>
                        </div>

                    </div>
                    <!-- End of the two-column grid. -->

                    <!-- Submit button for the form.
                         Label changes based on whether we are adding or saving an edit. -->
                    <button type="submit" class="btn btn-primary">
                        <?php echo $produit_edit ? '💾 Enregistrer' : '➕ Ajouter le produit'; ?>
                    </button>

                </form>
                <!-- End of the HTML form. -->
            </div>
            <!-- End of the form wrapper div. -->
        </div>
        <!-- End of the add/edit form card. -->

        <!-- ── PRODUCTS TABLE CARD ────────────────────────────── -->
        <!-- This white card contains the table listing all products. -->
        <div class="card">

            <!-- Card header: shows the total count and a button to open the add form. -->
            <div class="card-header">
                <!-- Heading with the total number of products in parentheses.
                     mysqli_num_rows() counts how many rows the database returned. -->
                <h3>Liste des produits (<?php echo mysqli_num_rows($produits); ?>)</h3>

                <!-- Button that makes the hidden add-form visible when clicked.
                     onclick uses JavaScript to change the div's CSS display property from "none" to "block". -->
                <button class="btn btn-primary"
                    onclick="document.getElementById('form-produit').style.display='block'">
                    ➕ Nouveau produit
                </button>
            </div>
            <!-- End of table card header. -->

            <!-- HTML table to display all products. -->
            <table>
                <!-- Table head: defines the column labels. -->
                <thead>
                    <tr>
                        <!-- Column heading: product name. -->
                        <th>Désignation</th>
                        <!-- Column heading: which category the product belongs to. -->
                        <th>Catégorie</th>
                        <!-- Column heading: the price per single unit. -->
                        <th>Prix unitaire</th>
                        <!-- Column heading: how many units are currently in the warehouse. -->
                        <th>Stock actuel</th>
                        <!-- Column heading: a badge showing if stock is OK, low, or gone. -->
                        <th>Statut</th>
                        <!-- Column heading: the edit and delete action buttons. -->
                        <th>Actions</th>
                    </tr>
                </thead>
                <!-- End of table head. -->

                <!-- Table body: one row per product, generated by PHP. -->
                <tbody>

                <?php
                // Loop through every product returned by the database query.
                // Each iteration, $p holds one product as an associative array.
                while ($p = mysqli_fetch_assoc($produits)):
                ?>
                    <!-- One table row per product. -->
                    <tr>

                        <!-- Product name and short description. -->
                        <td>
                            <!-- Display the product name in bold. htmlspecialchars() prevents HTML injection. -->
                            <strong><?php echo htmlspecialchars($p['designation']); ?></strong><br>
                            <!-- Display a trimmed version of the description (max 50 characters).
                                 mb_strimwidth() is a safe multi-byte function that handles accented characters.
                                 The '...' is appended if the description is cut short.
                                 The ternary ? : makes sure we pass an empty string if description is NULL. -->
                            <small style="color:#888;">
                                <?php echo htmlspecialchars(mb_strimwidth($p['description'] ? $p['description'] : '', 0, 50, '...')); ?>
                            </small>
                        </td>

                        <!-- Category name. If none is assigned, show a dash (—). -->
                        <td><?php echo $p['nom_categorie'] ? htmlspecialchars($p['nom_categorie']) : '—'; ?></td>

                        <!-- Unit price formatted to 2 decimal places with a $ prefix.
                             number_format() adds commas for thousands and fixes decimal places. -->
                        <td><strong>$<?php echo number_format($p['prix_unitaire'], 2); ?></strong></td>

                        <!-- Current stock quantity followed by "unités" (French for "units"). -->
                        <td><?php echo $p['stock_actuel']; ?> unités</td>

                        <!-- Stock status badge: colour-coded depending on stock level. -->
                        <td>
                            <?php if ($p['stock_actuel'] == 0): ?>
                                <!-- No units left at all: show a red "Out of stock" badge. -->
                                <span class="badge badge-danger">Rupture</span>

                            <?php elseif ($p['stock_actuel'] <= $p['seuil_alerte']): ?>
                                <!-- Stock is at or below the alert threshold: show an orange "Low stock" badge. -->
                                <span class="badge badge-warning">Stock bas</span>

                            <?php else: ?>
                                <!-- Stock is healthy and above the threshold: show a green "In stock" badge. -->
                                <span class="badge badge-success">En stock</span>
                            <?php endif; ?>
                        </td>
                        <!-- End of status badge cell. -->

                        <!-- Action buttons: Edit and Delete. -->
                        <td>
                            <!-- Edit button: clicking it reloads this page with ?edit=ID in the URL,
                                 which causes PHP to load that product's data into the form above. -->
                            <a href="produits.php?edit=<?php echo $p['id_produit']; ?>" class="btn btn-warning">✏️ Modifier</a>

                            <!-- Delete button: ONLY shown to admins.
                                 Deleting a product is an admin-only action, so the
                                 button is hidden for agents. (The server also refuses
                                 the deletion if a non-admin somehow reaches the URL,
                                 see the permission check near the top of this file —
                                 hiding the button alone is not enough security.)

                                 When an admin clicks it, a JavaScript popup asks for
                                 confirmation ("return confirm(...)"). If they click OK,
                                 the page reloads with ?delete=ID and PHP removes the
                                 product. If they click Cancel, nothing happens. -->
                            <?php if ($_SESSION['user_role'] == 'admin'): ?>
                            <a href="produits.php?delete=<?php echo $p['id_produit']; ?>"
                               class="btn btn-danger"
                               onclick="return confirm('Supprimer ce produit ?')">🗑️ Supprimer</a>
                            <?php endif; ?>
                            <!-- End of the admin-only Delete button. -->
                        </td>
                        <!-- End of action buttons cell. -->

                    </tr>
                    <!-- End of product row. -->

                <?php endwhile; ?>
                <!-- End of the product loop. All product rows have been rendered. -->

                </tbody>
                <!-- End of table body. -->
            </table>
            <!-- End of the products table. -->

        </div>
        <!-- End of the products table card. -->

    </main>
    <!-- End of main content area. -->

</div>
<!-- End of the dashboard layout wrapper. -->

</body>
<!-- End of the visible page content. -->
</html>
<!-- End of the HTML document. -->
