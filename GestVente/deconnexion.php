<?php
session_start();
require_once 'config.php';

// Log the disconnection before destroying the session
if (isset($_SESSION['user_id'])) {
    $id_user = (int) $_SESSION['user_id'];
    $ip      = $_SERVER['REMOTE_ADDR'];
    $sql_log = "INSERT INTO journal_connexion (action, adresse_ip, id_utilisateur)
                VALUES ('deconnexion', '$ip', $id_user)";
    mysqli_query($conn, $sql_log);
}

// Destroy the session — the user is now logged out
session_destroy();
header("Location: login.php");
exit();
?>
