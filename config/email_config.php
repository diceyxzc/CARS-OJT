<?php
function getEmailCredentials($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT email, password, host, port FROM tbl_noreply LIMIT 1");
        $stmt->execute();
        $credentials = $stmt->fetch();
        
        if (!$credentials) {
            throw new Exception("No email credentials found in tbl_noreply");
        }
        
        return $credentials;
    } catch (Exception $e) {
        error_log("Email config error: " . $e->getMessage());
        return null;
    }
}

function getDecryptedPassword($hashed_password) {
    return $hashed_password;
}
?>