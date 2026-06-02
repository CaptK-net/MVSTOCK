<?php
// ============================================================
//  deconnexion.php — LOGOUT PAGE
//  When a user clicks "Déconnexion", they are sent to this page.
//  It records the logout in the database, destroys the session,
//  and redirects the user back to the login page.
// ============================================================

// session_start() must be called before we can read or write
// any session variable. It resumes the existing session so we
// can still access $_SESSION['user_id'] before destroying it.
session_start();

// config.php gives us the $conn database connection variable,
// which we need to log the disconnection event.
require_once 'config.php';

// Check if a session actually exists (i.e. someone is logged in).
// We only log the disconnection if there is a valid user session.
if (isset($_SESSION['user_id'])) {

    // Cast the user ID to an integer for safety (prevents SQL injection).
    $id_user = (int) $_SESSION['user_id'];

    // $_SERVER['REMOTE_ADDR'] gives us the IP address of the user's device.
    // This is stored in the log so the admin can track who logged out and when.
    $ip = $_SERVER['REMOTE_ADDR'];

    // Insert a "deconnexion" event into the journal_connexion table.
    // This records: what happened (logout), from which IP, and which user.
    $sql_log = "INSERT INTO journal_connexion (action, adresse_ip, id_utilisateur)
                VALUES ('deconnexion', '$ip', $id_user)";

    // Execute the INSERT query using our database connection.
    mysqli_query($conn, $sql_log);
}

// session_destroy() completely erases all session data on the server.
// After this line, $_SESSION no longer exists — the user is logged out.
session_destroy();

// Send the user back to the login page immediately.
// header("Location: ...") sends an HTTP redirect instruction to the browser.
header("Location: login.php");

// exit() makes sure no more PHP code runs after the redirect.
exit();
?>
