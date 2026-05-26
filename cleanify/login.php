<?php
session_start();
require_once 'config.php';

$erreur = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $mdp   = $_POST['mot_de_passe'];

    $sql    = "SELECT * FROM users WHERE email = '$email' AND mot_de_passe = MD5('$mdp')";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_nom']  = $user['nom'];
        $_SESSION['user_role'] = $user['role'];
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
    <title>Cleanify - Connexion</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-box">
            <div class="login-logo">
                <img src="https://cleanifyleb.com/cdn/shop/files/Untitled_design_66.jpg" alt="Cleanify Logo">
            </div>

            <h2>Connexion</h2>

            <?php if ($erreur != "") { ?>
                <p class="erreur"><?php echo $erreur; ?></p>
            <?php } ?>

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
