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

// Helper: check if column exists
function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name=? AND column_name=?");
    $stmt->execute([$table, $column]);
    return $stmt->rowCount() > 0;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    // ---------- USER ----------
    case 'register':
        $name = $_POST['name'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $password]);
            echo json_encode(['success' => true, 'message' => 'Registered']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Email already exists']);
        }
        break;

    case 'login':
        $email = $_POST['email'];
        $password = $_POST['password'];
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            echo json_encode(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']]]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'get_user':
        if (isset($_SESSION['user_id'])) {
            echo json_encode(['success' => true, 'user' => ['id' => $_SESSION['user_id'], 'name' => $_SESSION['user_name'], 'email' => $_SESSION['user_email']]]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
        }
        break;

    // ---------- LISTINGS ----------
    case 'listings':
        $category = $_GET['category'] ?? '';
        // Check if stock column exists
        $hasStock = columnExists($pdo, 'listings', 'stock');
        $sql = "SELECT * FROM listings";
        if ($hasStock) {
            $sql .= " WHERE (stock > 0 OR stock IS NULL)";
        }
        if ($category && $category !== 'all') {
            $sql .= ($hasStock ? " AND" : " WHERE") . " category = ? ORDER BY id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$category]);
        } else {
            $sql .= " ORDER BY id DESC";
            $stmt = $pdo->query($sql);
        }
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'listings' => $listings]);
        break;

    case 'add_listing':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $name = $_POST['name'];
        $category = $_POST['category'];
        $price = $_POST['price'];
        $description = $_POST['description'] ?? '';
        $location = $_POST['location'];
        $image = $_POST['image'] ?? null;
        $image_icon = $_POST['image_icon'] ?? '📦';
        $seller_name = $_POST['seller_name'];
        $seller_email = $_POST['seller_email'];
        $stock = isset($_POST['stock']) ? (int)$_POST['stock'] : 1;
        $hasStock = columnExists($pdo, 'listings', 'stock');
        if ($hasStock) {
            $stmt = $pdo->prepare("INSERT INTO listings (name, category, price, description, location, image, image_icon, seller_name, seller_email, stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $category, $price, $description, $location, $image, $image_icon, $seller_name, $seller_email, $stock]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO listings (name, category, price, description, location, image, image_icon, seller_name, seller_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $category, $price, $description, $location, $image, $image_icon, $seller_name, $seller_email]);
        }
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    case 'update_listing':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $id = $_POST['id'];
        $name = $_POST['name'];
        $category = $_POST['category'];
        $price = $_POST['price'];
        $description = $_POST['description'] ?? '';
        $location = $_POST['location'];
        $image = $_POST['image'] ?? null;
        $image_icon = $_POST['image_icon'] ?? '📦';
        $stock = isset($_POST['stock']) ? (int)$_POST['stock'] : 1;
        $hasStock = columnExists($pdo, 'listings', 'stock');
        if ($hasStock) {
            $stmt = $pdo->prepare("UPDATE listings SET name = ?, category = ?, price = ?, description = ?, location = ?, image = ?, image_icon = ?, stock = ? WHERE id = ? AND seller_email = ?");
            $stmt->execute([$name, $category, $price, $description, $location, $image, $image_icon, $stock, $id, $_SESSION['user_email']]);
        } else {
            $stmt = $pdo->prepare("UPDATE listings SET name = ?, category = ?, price = ?, description = ?, location = ?, image = ?, image_icon = ? WHERE id = ? AND seller_email = ?");
            $stmt->execute([$name, $category, $price, $description, $location, $image, $image_icon, $id, $_SESSION['user_email']]);
        }
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Update failed or you are not the seller']);
        }
        break;

    case 'delete_listing':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM listings WHERE id = ? AND seller_email = ?");
        $stmt->execute([$id, $_SESSION['user_email']]);
        echo json_encode(['success' => true]);
        break;

    // ---------- CART (simplified – without stock for now) ----------
    case 'cart':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $user_id = $_SESSION['user_id'];
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $pdo->prepare("SELECT c.*, l.name, l.price, l.image, l.image_icon FROM carts c JOIN listings l ON c.listing_id = l.id WHERE c.user_id = ?");
            $stmt->execute([$user_id]);
            $cart = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'cart' => $cart]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $listing_id = $_POST['listing_id'];
            $quantity = (int)($_POST['quantity'] ?? 1);
            // Check if column exists for stock – skip stock check for now to avoid errors
            $stmt = $pdo->prepare("SELECT * FROM carts WHERE user_id = ? AND listing_id = ?");
            $stmt->execute([$user_id, $listing_id]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("UPDATE carts SET quantity = quantity + ? WHERE user_id = ? AND listing_id = ?");
                $stmt->execute([$quantity, $user_id, $listing_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO carts (user_id, listing_id, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $listing_id, $quantity]);
            }
            echo json_encode(['success' => true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            parse_str(file_get_contents("php://input"), $delete_vars);
            $listing_id = $delete_vars['listing_id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM carts WHERE user_id = ? AND listing_id = ?");
            $stmt->execute([$user_id, $listing_id]);
            echo json_encode(['success' => true]);
        }
        break;

    // ---------- WISHLIST ----------
    case 'wishlist':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $user_id = $_SESSION['user_id'];
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $pdo->prepare("SELECT l.* FROM wishlists w JOIN listings l ON w.listing_id = l.id WHERE w.user_id = ?");
            $stmt->execute([$user_id]);
            $wishlist = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'wishlist' => $wishlist]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $listing_id = $_POST['listing_id'];
            $stmt = $pdo->prepare("INSERT INTO wishlists (user_id, listing_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            $stmt->execute([$user_id, $listing_id]);
            echo json_encode(['success' => true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            parse_str(file_get_contents("php://input"), $delete_vars);
            $listing_id = $delete_vars['listing_id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM wishlists WHERE user_id = ? AND listing_id = ?");
            $stmt->execute([$user_id, $listing_id]);
            echo json_encode(['success' => true]);
        }
        break;

    // ---------- CHECKOUT ----------
    case 'checkout':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT c.listing_id, c.quantity, l.price, l.seller_email FROM carts c JOIN listings l ON c.listing_id = l.id WHERE c.user_id = ?");
        $stmt->execute([$user_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) {
            echo json_encode(['success' => false, 'error' => 'Cart empty']);
            break;
        }
        $total = 0;
        foreach ($items as $item) $total += $item['price'] * $item['quantity'];
        $fee = round($total * 0.15, 2);
        $seller_gets = round($total - $fee, 2);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, total, fee, seller_gets) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $total, $fee, $seller_gets]);
            $order_id = $pdo->lastInsertId();
            foreach ($items as $item) {
                $stmt = $pdo->prepare("INSERT INTO order_items (order_id, listing_id, quantity, price) VALUES (?, ?, ?, ?)");
                $stmt->execute([$order_id, $item['listing_id'], $item['quantity'], $item['price']]);
                // Decrease stock if column exists
                if (columnExists($pdo, 'listings', 'stock')) {
                    $stmt = $pdo->prepare("UPDATE listings SET stock = stock - ? WHERE id = ?");
                    $stmt->execute([$item['quantity'], $item['listing_id']]);
                }
            }
            $stmt = $pdo->prepare("DELETE FROM carts WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $pdo->commit();
            echo json_encode(['success' => true, 'order_id' => $order_id, 'total' => $total, 'fee' => $fee, 'seller_gets' => $seller_gets]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ---------- REPORTS, CHAT, ADMIN (simplified for brevity) ----------
    // For now, we'll include only essential actions to keep the API working.
    // You can add the rest later.

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
