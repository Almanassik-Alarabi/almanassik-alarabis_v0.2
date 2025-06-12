<?php 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');

if (ob_get_level()) {
    ob_clean();
}
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../includes/config.php';
require_once '../../includes/admin_functions.php';

$offer_id = isset($_GET['offer_id']) ? intval($_GET['offer_id']) : 0;
$images = [];

if ($offer_id > 0) {
    $stmt = $pdo->prepare("SELECT id, image FROM offer_images WHERE offer_id = ?");
    $stmt->execute([$offer_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $images[] = [
            'id' => $row['id'],
            'image_url' => '/uploads/offer_images/' . $row['image']
        ];
    }
}

echo json_encode($images);
exit;
