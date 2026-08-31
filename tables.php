<?php
    $conn = mysqli_connect("localhost", "admin_hopital", "Admin@123", "hopital_db");
    
    if (!$conn) {
        die("Erreur de connexion : " . mysqli_connect_error());
    }

    $result = mysqli_query($conn, "show tables");

    echo "<h2>Tables de hopital_db</h2>";

    while ($table = mysqli_fetch_array($result)) {
        echo $table[0] . "<br>";
    }
?>