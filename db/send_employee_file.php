<?php
// db/send_employee_file.php
// Handles sending files to selected employees via email

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/audit_log.php';

header('Content-Type: text/plain; charset=utf-8');

// Check authentication
if (!isset($_SESSION['userID']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

// Only admins can send files to employees
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Validate file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo 'No file provided or upload error';
    exit;
}

// Get form data
$file = $_FILES['file'];
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$employeeIds = isset($_POST['employeeIds']) ? json_decode($_POST['employeeIds'], true) : [];

if (!is_array($employeeIds) || empty($employeeIds)) {
    http_response_code(400);
    echo 'No employees selected';
    exit;
}

// Validate file size (10MB max)
$maxFileSize = 10 * 1024 * 1024;
if ($file['size'] > $maxFileSize) {
    http_response_code(400);
    echo 'File size exceeds 10MB limit';
    exit;
}

// Validate file type
$allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'];
$fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($fileExtension, $allowedExtensions)) {
    http_response_code(400);
    echo 'File type not supported';
    exit;
}

// Create temporary directory for uploaded files if it doesn't exist
$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'employee_files';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename to prevent overwrites
$timestamp = time();
$fileName = $timestamp . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
$filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

// Move uploaded file to permanent location
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo 'Failed to save file';
    exit;
}

try {
    // Get employee emails
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    // Use snake_case columns that exist in the users table
    $query = "SELECT userID, email, username, first_name AS firstName, last_name AS lastName FROM users WHERE userID IN ($placeholders) AND role IN ('admin', 'cashier')";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . $conn->error);
    }
    
    // Bind parameters
    $types = str_repeat('i', count($employeeIds));
    $stmt->bind_param($types, ...$employeeIds);
    
    if (!$stmt->execute()) {
        throw new Exception('Database execute error: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $employees = [];
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
    
    if (empty($employees)) {
        // Clean up file
        @unlink($filePath);
        http_response_code(404);
        echo 'No employees found';
        exit;
    }
    
    // Generate download link
    // Get base URL (protocol + host + path to root)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Get the script directory path (e.g., /kapetimplados-main-final)
    $scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
    // Remove trailing slashes and ensure it starts with /
    $scriptDir = '/' . trim($scriptDir, '/');
    
    $baseUrl = rtrim($protocol . $host . $scriptDir, '/');
    $downloadUrl = $baseUrl . '/db/download_employee_file.php?file=' . urlencode($fileName);
    
    // Prepare email content
    $senderName = $_SESSION['username'] ?? 'System';
    $currentDateTime = date('F j, Y g:i A');
    
    $emailSubject = 'Document Shared from Kape Timplado POS';
    
    // Create HTML email body with download link
    $emailBodyHTML = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>";
    $emailBodyHTML .= "<h2 style='color: #7f5539;'>Document Shared from Kape Timplado POS</h2>";
    $emailBodyHTML .= "<p>Hello,</p>";
    $emailBodyHTML .= "<p>A document has been shared with you from the Kape Timplado POS system.</p>";
    
    if (!empty($message)) {
        $emailBodyHTML .= "<div style='background-color: #f5f5f5; padding: 15px; border-left: 4px solid #7f5539; margin: 20px 0;'>";
        $emailBodyHTML .= "<p style='margin: 0;'><strong>Message from {$senderName}:</strong></p>";
        $emailBodyHTML .= "<p style='margin: 10px 0 0 0;'>" . nl2br(htmlspecialchars($message)) . "</p>";
        $emailBodyHTML .= "</div>";
    }
    
    $emailBodyHTML .= "<div style='background-color: #fffaf5; border: 2px solid #7f5539; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center;'>";
    $emailBodyHTML .= "<p style='margin: 0 0 15px 0; font-weight: bold;'>File: " . htmlspecialchars($file['name']) . "</p>";
    $emailBodyHTML .= "<a href='{$downloadUrl}' style='display: inline-block; background-color: #7f5539; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;'>Download File</a>";
    $emailBodyHTML .= "</div>";
    
    $emailBodyHTML .= "<p style='color: #666; font-size: 12px;'>If the button doesn't work, copy and paste this link into your browser:</p>";
    $emailBodyHTML .= "<p style='color: #7f5539; font-size: 12px; word-break: break-all;'>{$downloadUrl}</p>";
    
    $emailBodyHTML .= "<hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>";
    $emailBodyHTML .= "<p style='color: #666; font-size: 12px;'>Sent at: {$currentDateTime}<br>";
    $emailBodyHTML .= "From: {$senderName}</p>";
    $emailBodyHTML .= "<p style='color: #666; font-size: 12px; margin-top: 20px;'>Best regards,<br>Kape Timplado POS System</p>";
    $emailBodyHTML .= "</body></html>";
    
    // Plain text version for email clients that don't support HTML
    $emailBodyText = "Hello,\n\n";
    $emailBodyText .= "A document has been shared with you from the Kape Timplado POS system.\n\n";
    
    if (!empty($message)) {
        $emailBodyText .= "Message from {$senderName}:\n";
        $emailBodyText .= "---\n";
        $emailBodyText .= $message . "\n";
        $emailBodyText .= "---\n\n";
    }
    
    $emailBodyText .= "File: {$file['name']}\n\n";
    $emailBodyText .= "Download Link:\n";
    $emailBodyText .= $downloadUrl . "\n\n";
    $emailBodyText .= "Sent at: {$currentDateTime}\n";
    $emailBodyText .= "From: {$senderName}\n\n";
    $emailBodyText .= "Best regards,\n";
    $emailBodyText .= "Kape Timplado POS System";
    
    // Send emails with attachment using PHPMailer for file attachment support
    $autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    
    $cfg = require __DIR__ . '/mail_config.php';
    $successCount = 0;
    $failureCount = 0;
    $failedEmails = [];
    
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && !empty($cfg['smtp_host']) && !empty($cfg['smtp_user'])) {
        // Use PHPMailer to send email with download link
        foreach ($employees as $emp) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                
                // Server settings
                $mail->isSMTP();
                $mail->Host = $cfg['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $cfg['smtp_user'];
                $mail->Password = $cfg['smtp_pass'];
                $mail->Port = (int)$cfg['smtp_port'];
                if (!empty($cfg['smtp_secure'])) {
                    $mail->SMTPSecure = $cfg['smtp_secure'];
                }
                
                $mail->setFrom($cfg['from_address'], $cfg['from_name']);
                $mail->addAddress($emp['email']);
                $mail->Subject = $emailSubject;
                $mail->CharSet = 'UTF-8';
                $mail->isHTML(true);
                $mail->Body = $emailBodyHTML;
                $mail->AltBody = $emailBodyText; // Plain text fallback
                
                // No file attachment - just the download link
                
                $mail->send();
                $successCount++;
                
                error_log("File download link sent successfully to: {$emp['email']} (filename: {$file['name']})");
            } catch (\Exception $e) {
                $failureCount++;
                $failedEmails[] = $emp['email'];
                error_log("Failed to send file link to {$emp['email']}: " . $e->getMessage());
            }
        }
    } else {
        // Fallback to php mail
        foreach ($employees as $emp) {
            $headers = "From: " . $cfg['from_address'] . "\r\n";
            $headers .= "Reply-To: " . $cfg['from_address'] . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            if (mail($emp['email'], $emailSubject, $emailBodyText, $headers)) {
                $successCount++;
                error_log("Email with download link sent to: {$emp['email']}");
            } else {
                $failureCount++;
                $failedEmails[] = $emp['email'];
                error_log("Failed to send email to: {$emp['email']}");
            }
        }
    }
    
    // Log audit trail
    try {
        $auditDetails = "Sent file '{$file['name']}' to " . $successCount . " employee(s)";
        if ($failureCount > 0) {
            $auditDetails .= ", " . $failureCount . " failed";
        }
        // Use centralized audit logger
        logAuditActivity($conn, $_SESSION['userID'], 'file_sent', $auditDetails);
    } catch (Exception $e) {
        error_log("Audit logging failed: " . $e->getMessage());
    }
    
    // Check results
    if ($successCount > 0) {
        // Keep file for audit/record purposes
        echo 'success';
    } else {
        // Clean up file if no emails were sent
        @unlink($filePath);
        http_response_code(500);
        echo 'Failed to send file to any employee';
        exit;
    }
    
} catch (Exception $e) {
    // Clean up file on error
    @unlink($filePath);
    
    http_response_code(500);
    error_log('Error sending file: ' . $e->getMessage());
    echo 'Error: ' . $e->getMessage();
    exit;
}
