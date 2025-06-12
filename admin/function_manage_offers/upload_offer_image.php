<?php
// رفع صور الغرفة وربطها بالعرض (offre_id)

session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}
require_once '../../includes/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offer_id']) && isset($_FILES['chomber_image'])) {
    $offer_id = (int)$_POST['offer_id'];
    $uploadDir = __DIR__ . '/../../uploads/rooms/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $files = $_FILES['chomber_image'];
    $uploaded = 0;
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) continue;
            $filename = uniqid('room_', true) . '.' . $ext;
            $target = $uploadDir . $filename;
            if (move_uploaded_file($files['tmp_name'][$i], $target)) {
                // حفظ الرابط في قاعدة البيانات
                $image_url = '/uploads/rooms/' . $filename;
                $stmt = $pdo->prepare("INSERT INTO chomber_images (offre_id, image_url) VALUES (?, ?)");
                $stmt->execute([$offer_id, $image_url]);
                $uploaded++;
            }
        }
    }

    // جلب بيانات العرض
    $stmt = $pdo->prepare("SELECT * FROM offres WHERE id = ?");
    $stmt->execute([$offer_id]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    // جلب صور الغرف المرتبطة بالعرض
    $stmt = $pdo->prepare("SELECT id, image_url FROM chomber_images WHERE offre_id = ?");
    $stmt->execute([$offer_id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // إرجاع البيانات كـ JSON
    header('Content-Type: application/json');
    echo json_encode([
        'offer' => $offer,
        'images' => $images
    ]);
    exit;
} else {
    http_response_code(400);
    echo 'Invalid request';
}