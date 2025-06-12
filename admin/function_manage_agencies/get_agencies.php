<?php
// Database connection parameters
$host = 'localhost';
$db   = 'your_database_name';
$user = 'your_database_user';
$pass = 'your_database_password';
$port = '5432';

// Connect to PostgreSQL
$conn = pg_connect("host=$host port=$port dbname=$db user=$user password=$pass");
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Fetch agencies data
$query = "SELECT * FROM agences";
$result = pg_query($conn, $query);

if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed']);
    exit;
}

$agencies = [];
while ($row = pg_fetch_assoc($result)) {
    $agencies[] = $row;
}

// Output as JSON
header('Content-Type: application/json');
echo json_encode($agencies);

// Close connection
pg_close($conn);
?>
