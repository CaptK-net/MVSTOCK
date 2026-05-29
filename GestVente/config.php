<?php 

// database connection settings
$host = "localhost";
$dbname = "cleanify_db";
$user = "root";
$password = ""; // easyphp uses an empty password by default

// connecting to the database
$conn= mysqli_connect ($host, $user, $password, $dbname);

// checking if the connection worked
if (!$conn) {
    die ("Erreur de connexion:".mysqli_connect_error());
}

?>