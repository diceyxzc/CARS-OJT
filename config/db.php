<?php
$host = 'localhost';
$dbname = 'cars_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

function getPassengers($pdo, $user_id, $role) {
    if ($role == 'admin') {
        $stmt = $pdo->prepare("SELECT * FROM tbl_passengers ORDER BY passenger_name");
        $stmt->execute();
        return $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM tbl_passengers WHERE created_by = ? OR created_by IS NULL ORDER BY passenger_name");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
}

function addPassenger($pdo, $name, $contact, $user_id) {
    $stmt = $pdo->prepare("INSERT INTO tbl_passengers (passenger_name, contact, created_by) VALUES (?, ?, ?)");
    $stmt->execute([$name, $contact, $user_id]);
    return $pdo->lastInsertId();
}
?>