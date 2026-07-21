<?php
/**
 * Generate a secure hash using bcrypt
 * 
 * @param string $input The string to hash
 * @param int $cost The cost parameter for bcrypt (default: 12)
 * @return string The hashed string
 */
function genhash($input, $cost = 12) {
    // Generate a random salt
    $options = [
        'cost' => $cost,
    ];
    
    // Use password_hash with bcrypt algorithm
    return password_hash($input, PASSWORD_BCRYPT, $options);
}

// Example usage
$password = "password123";
$hash = genhash($password);
echo "Original: " . $password . "\n";
echo "Hash: " . $hash . "\n";

// Verify the hash
if (password_verify($password, $hash)) {
    echo "Password verified successfully!\n";
} else {
    echo "Invalid password!\n";
}
?>