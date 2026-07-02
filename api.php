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
        $sql = "SELECT * FROM listings";
        if ($category && $category !== 'all') {
            $sql .= " WHERE category = ? ORDER BY id DESC";
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
        $stmt = $pdo->prepare("INSERT INTO listings (name, category, price, description, location, image, image_icon, seller_name, seller_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $category, $price, $description, $location, $image, $image_icon, $seller_name, $seller_email]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
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

    // ---------- CART ----------
    case 'cart':
        // unchanged – keep your existing cart logic
        // (We'll keep it as you have it; it's already good)
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
            if ($quantity <= 0) {
                $stmt = $pdo->prepare("DELETE FROM carts WHERE user_id = ? AND listing_id = ?");
                $stmt->execute([$user_id, $listing_id]);
                echo json_encode(['success' => true]);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM carts WHERE user_id = ? AND listing_id = ?");
            $stmt->execute([$user_id, $listing_id]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("UPDATE carts SET quantity = ? WHERE user_id = ? AND listing_id = ?");
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
        // unchanged – keep your existing
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
        // unchanged – keep your existing
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

    // ---------- REPORTS ----------
    case 'report_item':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $listing_id = $_POST['listing_id'];
        $reason = $_POST['reason'];
        $reported_by = $_SESSION['user_id'];
        $stmt = $pdo->prepare("INSERT INTO reported_items (listing_id, reported_by, reason) VALUES (?, ?, ?)");
        $stmt->execute([$listing_id, $reported_by, $reason]);
        echo json_encode(['success' => true]);
        break;

    case 'report_user':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $reported_user_id = $_POST['reported_user_id'];
        $reason = $_POST['reason'];
        $reported_by = $_SESSION['user_id'];
        $stmt = $pdo->prepare("INSERT INTO reported_users (reported_user_id, reported_by, reason) VALUES (?, ?, ?)");
        $stmt->execute([$reported_user_id, $reported_by, $reason]);
        echo json_encode(['success' => true]);
        break;

    // ---------- CHAT ----------
    case 'send_message':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $receiver_id = $_POST['receiver_id'];
        $message = $_POST['message'];
        $sender_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("INSERT INTO chats (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$sender_id, $receiver_id, $message]);
        echo json_encode(['success' => true]);
        break;

    case 'get_messages':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $other_user = $_GET['other_user'];
        $my_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT * FROM chats WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY sent_at ASC");
        $stmt->execute([$my_id, $other_user, $other_user, $my_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'messages' => $messages]);
        break;

    // ---------- ADMIN ----------
    case 'admin_users':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        $stmt = $pdo->query("SELECT id, name, email, created_at FROM users ORDER BY id DESC");
        echo json_encode(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'admin_listings':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        $stmt = $pdo->query("SELECT * FROM listings ORDER BY id DESC");
        echo json_encode(['success' => true, 'listings' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'admin_orders':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        $stmt = $pdo->query("SELECT o.*, u.name as buyer_name, u.email as buyer_email FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC");
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$order) {
            $stmt = $pdo->prepare("SELECT oi.*, l.name FROM order_items oi JOIN listings l ON oi.listing_id = l.id WHERE oi.order_id = ?");
            $stmt->execute([$order['id']]);
            $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['success' => true, 'orders' => $orders]);
        break;

    case 'admin_reports':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        $items = $pdo->query("SELECT r.*, l.name as item_name, u.name as reporter_name FROM reported_items r JOIN listings l ON r.listing_id = l.id JOIN users u ON r.reported_by = u.id ORDER BY r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $users = $pdo->query("SELECT r.*, u.name as reporter_name, ru.name as reported_name FROM reported_users r JOIN users u ON r.reported_by = u.id JOIN users ru ON r.reported_user_id = ru.id ORDER BY r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'reported_items' => $items, 'reported_users' => $users]);
        break;

    case 'admin_stats':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        $users_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $listings_count = $pdo->query("SELECT COUNT(*) FROM listings")->fetchColumn();
        $orders_count = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $total_revenue = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM orders")->fetchColumn();
        $total_fee = $pdo->query("SELECT COALESCE(SUM(fee), 0) FROM orders")->fetchColumn();
        // Sold items count (sum of quantities from order_items)
        $sold_items = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM order_items")->fetchColumn();
        echo json_encode(['success' => true, 'stats' => [
            'users' => $users_count,
            'listings' => $listings_count,
            'orders' => $orders_count,
            'revenue' => $total_revenue,
            'fee_income' => $total_fee,
            'sold_items' => $sold_items
        ]]);
        break;

    case 'admin_chats':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        // Join users table to get emails instead of IDs
        $stmt = $pdo->query("
            SELECT c.*, 
                   s.name as sender_name, s.email as sender_email,
                   r.name as receiver_name, r.email as receiver_email
            FROM chats c
            JOIN users s ON c.sender_id = s.id
            JOIN users r ON c.receiver_id = r.id
            ORDER BY c.sent_at DESC
        ");
        $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'chats' => $chats]);
        break;

    case 'admin_delete_user':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        $user_id = $_POST['user_id'];
        // Also delete associated listings, carts, wishlists, orders? For simplicity, we can delete user and cascade.
        // But we'll manually delete related records.
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("DELETE FROM carts WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM wishlists WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM orders WHERE user_id = ?");
            $stmt->execute([$user_id]);
            // Delete listings from this user
            $stmt = $pdo->prepare("DELETE FROM listings WHERE seller_email = (SELECT email FROM users WHERE id = ?)");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'admin_delete_listing':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        $listing_id = $_POST['listing_id'];
        $stmt = $pdo->prepare("DELETE FROM listings WHERE id = ?");
        $stmt->execute([$listing_id]);
        echo json_encode(['success' => true]);
        break;

    case 'admin_update_order':
    if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        break;
    }
    $order_id = $_POST['order_id'];
    $status = $_POST['status'];
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$status, $order_id]);
    echo json_encode(['success' => true]);
    break;

case 'admin_delete_order':
    if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        break;
    }
    $order_id = $_POST['order_id'];
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    echo json_encode(['success' => true]);
    break;

case 'admin_delete_report_item':
    if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        break;
    }
    $report_id = $_POST['report_id'];
    $stmt = $pdo->prepare("DELETE FROM reported_items WHERE id = ?");
    $stmt->execute([$report_id]);
    echo json_encode(['success' => true]);
    break;

case 'admin_delete_report_user':
    if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        break;
    }
    $report_id = $_POST['report_id'];
    $stmt = $pdo->prepare("DELETE FROM reported_users WHERE id = ?");
    $stmt->execute([$report_id]);
    echo json_encode(['success' => true]);
    break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
