<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if it's a POST request and language parameter exists
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    // Get the language from POST data
    $language = $_POST['language'];
    
    // Validate language (you might want to add more validation)
    $allowedLanguages = ['en', 'ar', 'fr']; // Add your supported languages here
    
    if (in_array($language, $allowedLanguages)) {
        try {
            // Database connection
            $DB_CONFIG = array(
                'host' => 'aws-0-eu-west-3.pooler.supabase.com',
                'port' => '6543',
                'dbname' => 'postgres',
                'user' => 'postgres.zrwtxvybdxphylsvjopi',
                'password' => 'Dj123456789.',
                'sslmode' => 'require'
            );
            
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;sslmode=%s",
                $DB_CONFIG['host'],
                $DB_CONFIG['port'],
                $DB_CONFIG['dbname'],
                $DB_CONFIG['sslmode']
            );
            
            $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Update the language preference in the database
            // Assuming you have admin_id in session and a admins table
            if (isset($_SESSION['admin_id'])) {
                $adminId = $_SESSION['admin_id'];
                
                $stmt = $pdo->prepare("UPDATE admins SET langue_preferee = :lang WHERE id = :id");
                $result = $stmt->execute([
                    'lang' => $language,
                    'id' => $adminId
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Language preference updated successfully'
                ]);

            } else {
                // Just update session if user is not logged in
                $_SESSION['language'] = $language;
                echo json_encode(['success' => true, 'message' => 'Language updated in session']);
            }
            
        } catch (PDOException $e) {
            error_log('Language update error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Database error occurred'
            ]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid language selection']);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>