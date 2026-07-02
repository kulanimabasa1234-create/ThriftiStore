<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'thrifti_db';
$user = getenv('DB_USER') ?: 'thrifti_user';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname;port=5432", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]));
}

session_start();
$action = $_GET['action'] ?? '';

// Helper function to safely handle SQL errors
function safeQuery($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        // Log error (optional) and return a JSON error
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]));
    }
}

switch ($action) {
    // ---------- USER ----------
    case 'register':
        // ... (keep existing code, but use safeQuery)
        // For brevity, I'll show the essential parts for add_listing and update_listing.
        // Replace the whole file with the version I'll give below.
        break;
}
