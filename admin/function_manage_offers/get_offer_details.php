<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../includes/config.php';
require_once '../../includes/admin_functions.php';

$offer_id = isset($_GET['offer_id']) ? intval($_GET['offer_id']) : 0;
if ($offer_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid offer ID']);
    exit;
}

// جلب بيانات العرض
$stmt = $pdo->prepare("SELECT * FROM offres WHERE id = ?");
$stmt->execute([$offer_id]);
$offer = $stmt->fetch(PDO::FETCH_ASSOC);

if ($offer) {
    // جلب الصور المرتبطة بالعرض
    $stmt_images = $pdo->prepare("SELECT id, image, created_at FROM offer_images WHERE offer_id = ?");
    $stmt_images->execute([$offer_id]);
    $images = $stmt_images->fetchAll(PDO::FETCH_ASSOC);

    $offer['images'] = $images;

    echo json_encode($offer);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Offer not found']);
}
?>
