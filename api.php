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
        // Show items with stock > 0 OR stock IS NULL (for old items)
        $sql = "SELECT * FROM listings WHERE (stock > 0 OR stock IS NULL)";
        if ($category && $category !== 'all') {
            $sql .= " AND category = ? ORDER BY id DESC";
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
        $stock = (int)($_POST['stock'] ?? 1);
        $image = $_POST['image'] ?? null;
        $image_icon = $_POST['image_icon'] ?? '📦';
        $seller_name = $_POST['seller_name'];
        $seller_email = $_POST['seller_email'];
        $stmt = $pdo->prepare("INSERT INTO listings (name, category, price, description, location, stock, image, image_icon, seller_name, seller_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $category, $price, $description, $location, $stock, $image, $image_icon, $seller_name, $seller_email]);
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
        $stock = (int)($_POST['stock'] ?? 1);
        $image = $_POST['image'] ?? null;
        $stmt = $pdo->prepare("UPDATE listings SET name=?, category=?, price=?, description=?, location=?, stock=?, image=? WHERE id=? AND seller_email=?");
        $stmt->execute([$name, $category, $price, $description, $location, $stock, $image, $id, $_SESSION['user_email']]);
        echo json_encode(['success' => true]);
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
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $user_id = $_SESSION['user_id'];
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $pdo->prepare("SELECT c.*, l.name, l.price, l.image, l.image_icon, l.stock FROM carts c JOIN listings l ON c.listing_id = l.id WHERE c.user_id = ?");
            $stmt->execute([$user_id]);
            $cart = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'cart' => $cart]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $listing_id = $_POST['listing_id'];
            $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
            // Check stock
            $stmt = $pdo->prepare("SELECT stock FROM listings WHERE id = ?");
            $stmt->execute([$listing_id]);
            $listing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$listing) {
                echo json_encode(['success' => false, 'error' => 'Listing not found']);
                break;
            }
            if ($listing['stock'] < $quantity) {
                echo json_encode(['success' => false, 'error' => 'Insufficient stock']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM carts WHERE user_id = ? AND listing_id = ?");
            $stmt->execute([$user_id, $listing_id]);
            if ($stmt->rowCount() > 0) {
                if ($quantity > 0) {
                    $stmt = $pdo->prepare("UPDATE carts SET quantity = ? WHERE user_id = ? AND listing_id = ?");
                    $stmt->execute([$quantity, $user_id, $listing_id]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM carts WHERE user_id = ? AND listing_id = ?");
                    $stmt->execute([$user_id, $listing_id]);
                }
            } else {
                if ($quantity > 0) {
                    $stmt = $pdo->prepare("INSERT INTO carts (user_id, listing_id, quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$user_id, $listing_id, $quantity]);
                }
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
        foreach ($items as $item) {
            $stmt = $pdo->prepare("SELECT stock FROM listings WHERE id = ?");
            $stmt->execute([$item['listing_id']]);
            $listing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$listing || $listing['stock'] < $item['quantity']) {
                echo json_encode(['success' => false, 'error' => 'Insufficient stock for item ID ' . $item['listing_id']]);
                break 2;
            }
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
                $stmt = $pdo->prepare("UPDATE listings SET stock = stock - ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['listing_id']]);
                $stmt = $pdo->prepare("DELETE FROM listings WHERE id = ? AND stock <= 0");
                $stmt->execute([$item['listing_id']]);
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
        $receiver_email = $_POST['receiver_email'];
        $message = $_POST['message'];
        $sender_email = $_SESSION['user_email'];
        $stmt = $pdo->prepare("INSERT INTO chats (sender_email, receiver_email, message) VALUES (?, ?, ?)");
        $stmt->execute([$sender_email, $receiver_email, $message]);
        echo json_encode(['success' => true]);
        break;

    case 'get_messages':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $other_email = $_GET['other_email'];
        $my_email = $_SESSION['user_email'];
        $stmt = $pdo->prepare("SELECT * FROM chats WHERE (sender_email = ? AND receiver_email = ?) OR (sender_email = ? AND receiver_email = ?) ORDER BY sent_at ASC");
        $stmt->execute([$my_email, $other_email, $other_email, $my_email]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'messages' => $messages]);
        break;

    case 'list_chats':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            break;
        }
        $my_email = $_SESSION['user_email'];
        $stmt = $pdo->prepare("SELECT DISTINCT 
            CASE WHEN sender_email = ? THEN receiver_email ELSE sender_email END AS other_email,
            MAX(sent_at) as last_time
            FROM chats 
            WHERE sender_email = ? OR receiver_email = ?
            GROUP BY other_email
            ORDER BY last_time DESC");
        $stmt->execute([$my_email, $my_email, $my_email]);
        $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($chats as &$chat) {
            $stmt = $pdo->prepare("SELECT message, sent_at FROM chats WHERE (sender_email = ? AND receiver_email = ?) OR (sender_email = ? AND receiver_email = ?) ORDER BY sent_at DESC LIMIT 1");
            $stmt->execute([$my_email, $chat['other_email'], $chat['other_email'], $my_email]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            $chat['last_message'] = $last['message'] ?? '';
            $chat['last_time'] = $last['sent_at'] ?? $chat['last_time'];
        }
        echo json_encode(['success' => true, 'chats' => $chats]);
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
        echo json_encode(['success' => true, 'stats' => [
            'users' => $users_count,
            'listings' => $listings_count,
            'orders' => $orders_count,
            'revenue' => $total_revenue,
            'fee_income' => $total_fee
        ]]);
        break;

    case 'admin_chats':
        if (!isset($_SESSION['user_id']) || $_SESSION['user_email'] !== 'admin@thrifti.com') {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            break;
        }
        $stmt = $pdo->query("SELECT * FROM chats ORDER BY sent_at DESC");
        $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'chats' => $chats]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
