<?php
// audit_logs.php - Creates audit logs table if it doesn't exist
require_once __DIR__ . '/db_connect.php';

try {
    $createTableSQL = "
    CREATE TABLE IF NOT EXISTS audit_logs (
        logID INT AUTO_INCREMENT PRIMARY KEY,
        userID INT NOT NULL,
        action VARCHAR(50) NOT NULL,
        details TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (userID) REFERENCES users(userID) ON DELETE CASCADE
    )";
    
    $conn->query($createTableSQL);
    echo "Audit logs table initialized successfully.";
    
} catch (Exception $e) {
    echo "Error creating audit log table: " . $e->getMessage();
}
?>
