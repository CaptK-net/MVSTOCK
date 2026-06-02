<?php
// ============================================================
//  config.php — DATABASE CONNECTION FILE
//  This file is included at the top of every other PHP page.
//  It creates the $conn variable that all pages use to talk
//  to the MySQL database.
// ============================================================

// The address of the database server.
// "localhost" means the database is on the same computer as the website.
$host = "localhost";

// The name of the database we want to use inside MySQL.
$dbname = "cleanify_db";

// The MySQL username. EasyPHP creates a default user called "root".
$user = "root";

// The MySQL password. EasyPHP leaves it empty by default.
$password = "";

// mysqli_connect() tries to open a connection to the database.
// It takes the server address, username, password, and database name.
// The result (the connection link) is stored in $conn.
// Every other page will use $conn to send SQL queries to the database.
$conn = mysqli_connect($host, $user, $password, $dbname);

// If mysqli_connect() fails (wrong credentials, server off, etc.),
// $conn will be false. We check for that here.
if (!$conn) {
    // die() stops the entire page from loading and shows an error message.
    // mysqli_connect_error() returns the reason why the connection failed.
    die("Erreur de connexion : " . mysqli_connect_error());
}
?>
