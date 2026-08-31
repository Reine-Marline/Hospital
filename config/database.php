<?php

    $host = "localhost";
    $user = "admin_hopital";
    $password = "Admin@123";
    $database = "hopital_db";

    $conn = mysqli_connect(
        $host,
        $user,
        $password,
        $database
    );

    if (!$conn) {
        die("Erreur de connexion : " . mysqli_connect_error());
    }
    #echo "Connexion reussie a la base : " . $database;
?>
