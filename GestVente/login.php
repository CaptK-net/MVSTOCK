<?php
// ============================================================
//  login.php — LOGIN PAGE
//  This is the entry point of the application.
//  It shows a login form and checks the entered credentials
//  against the database. If correct, it starts a session and
//  redirects the user to the dashboard (accueil.php).
// ============================================================

// session_start() must be called at the very top of every page
// that uses sessions. It either creates a new session or resumes
// an existing one. Sessions let us remember who is logged in
// as the user navigates from page to page.
session_start();

// Include the database connection file so we can query the database.
// require_once means: include this file, but only once, and stop
// the page completely if the file is missing.
require_once 'config.php';

// $erreur will hold any error message to show the user (e.g. wrong password).
// It starts empty — we only fill it if something goes wrong.
$erreur = "";

// Check if the form was submitted. $_SERVER['REQUEST_METHOD'] tells us
// how the page was accessed. 'POST' means the user clicked the submit button.
// 'GET' means they just opened the page in their browser normally.
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Read the email and password that the user typed into the form.
    // $_POST is a PHP array that holds all form values sent via POST.
    $email = $_POST['email'];
    $mdp   = $_POST['mot_de_passe'];

    // Build the SQL query to check the credentials.
    // We look for a row in the "utilisateur" table where:
    //   - the email matches what the user typed
    //   - the password matches (stored as MD5 hash, so we hash the input too)
    // Note: clients (customers) are in a DIFFERENT table and cannot log in.
    $sql    = "SELECT * FROM utilisateur WHERE email = '$email' AND mot_de_passe = MD5('$mdp')";

    // mysqli_query() sends the SQL to the database and returns the result.
    $result = mysqli_query($conn, $sql);

    // mysqli_num_rows() counts how many rows match our query.
    // Exactly 1 means valid credentials. 0 means wrong email or password.
    if ($result && mysqli_num_rows($result) == 1) {

        // mysqli_fetch_assoc() reads the matching database row as an
        // associative array, so we can access values by column name.
        $user = mysqli_fetch_assoc($result);

        // Store the logged-in user's information in the session.
        // These values will be available on every page until logout.
        $_SESSION['user_id']   = $user['id_utilisateur']; // Unique ID of the user
        $_SESSION['user_nom']  = $user['nom'];             // Last name (used in the topbar greeting)
        $_SESSION['user_role'] = $user['role'];            // 'admin' or 'agent' — controls access rights

        // Record this login event in the journal_connexion table.
        // This gives the admin a full audit trail of who logged in, when, and from where.
        $id_user = $user['id_utilisateur'];

        // $_SERVER['REMOTE_ADDR'] gives the IP address of the user's computer.
        $ip = $_SERVER['REMOTE_ADDR'];

        // Build the SQL INSERT to create a log entry.
        $sql_log = "INSERT INTO journal_connexion (action, adresse_ip, id_utilisateur)
                    VALUES ('connexion', '$ip', $id_user)";

        // Execute the log INSERT query.
        mysqli_query($conn, $sql_log);

        // Send the user to the dashboard. header() sends an HTTP redirect.
        header("Location: accueil.php");

        // exit() stops any further code from running after the redirect.
        exit();

    } else {
        // Credentials did not match — show an error message to the user.
        $erreur = "Email ou mot de passe incorrect.";
    }
}
?>

<!DOCTYPE html>
<!-- Declares this as an HTML5 document -->
<html lang="fr">
<head>
    <!-- Set the character encoding to UTF-8 so accented letters display correctly -->
    <meta charset="UTF-8">

    <!-- The title shown in the browser tab -->
    <title>MVSTOCK — Connexion</title>

    <!-- Link to the external CSS file that styles the entire application -->
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- .login-wrapper is a full-screen centered container defined in style.css -->
    <div class="login-wrapper">

        <!-- .login-box is the white card in the middle of the screen -->
        <div class="login-box">

            <!-- Logo and slogan at the top of the login card -->
            <div class="login-logo">
                <h1>MVSTOCK</h1>
                <p>Sell fast, restock faster.</p>
            </div>

            <!-- Subtitle shown below the logo -->
            <h2>Connexion</h2>

            <!-- If $erreur is not empty (wrong password was entered),
                 display the error message in a styled red box -->
            <?php if ($erreur != ""): ?>
                <p class="erreur"><?php echo $erreur; ?></p>
            <?php endif; ?>

            <!-- The login form. method="POST" means form data is sent in the
                 request body (not visible in the URL). action="login.php" means
                 the form submits back to this same page. -->
            <form method="POST" action="login.php">

                <!-- Email field — required means the browser won't submit without it -->
                <label>Email</label>
                <input type="email" name="email" placeholder="votre@email.com" required />

                <!-- Password field — type="password" hides what the user types -->
                <label>Mot de passe</label>
                <input type="password" name="mot_de_passe" placeholder="••••••••" required />

                <!-- Submit button — clicking this triggers the POST request -->
                <button type="submit">Se connecter</button>

            </form>

        </div>
    </div>
</body>
</html>
