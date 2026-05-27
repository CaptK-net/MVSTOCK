<?php
session_start();
require_once 'config.php';

$erreur = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $mdp   = $_POST['mot_de_passe'];

    // Query the utilisateur table (clients don't log in — they are a separate table)
    $sql    = "SELECT * FROM utilisateur WHERE email = '$email' AND mot_de_passe = MD5('$mdp')";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);

        // Store the logged-in user's info in the session
        $_SESSION['user_id']   = $user['id_utilisateur'];
        $_SESSION['user_nom']  = $user['nom'];
        $_SESSION['user_role'] = $user['role'];

        // Log this connection in the journal_connexion table
        $id_user = $user['id_utilisateur'];
        $ip      = $_SERVER['REMOTE_ADDR']; // Get the user's IP address
        $sql_log = "INSERT INTO journal_connexion (action, adresse_ip, id_utilisateur)
                    VALUES ('connexion', '$ip', $id_user)";
        mysqli_query($conn, $sql_log);

        header("Location: accueil.php");
        exit();
    } else {
        $erreur = "Email ou mot de passe incorrect.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-box">

            <div class="login-logo">
                <h1 style="color:#0D9488; font-size:1.8rem;">🏪 GestVente</h1>
                <p style="color:#94A3B8; font-size:0.9rem;">Système de gestion des ventes</p>
            </div>

            <h2>Connexion</h2>

            <?php if ($erreur != ""): ?>
                <p class="erreur"><?php echo $erreur; ?></p>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <label>Email</label>
                <input type="email" name="email" placeholder="votre@email.com" required />

                <label>Mot de passe</label>
                <input type="password" name="mot_de_passe" placeholder="••••••••" required />

                <button type="submit">Se connecter</button>
            </form>

        </div>
    </div>
</body>
</html>
