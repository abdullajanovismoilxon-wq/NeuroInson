<?php
// api/admin-stats.php
require_once __DIR__ . "/db.php";

$telegram_id = isset($_GET['telegram_id']) ? (int)$_GET['telegram_id'] : 0;

// Load admins
$config_file = __DIR__ . "/config.json";
$admins = [799317334]; // Default admin
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    if (isset($config['ADMIN_IDS'])) {
        $admins = $config['ADMIN_IDS'];
    }
}

if (!in_array($telegram_id, $admins)) {
    $stmt = $db->prepare("SELECT google_email FROM users WHERE telegram_id=?");
    $stmt->execute([$telegram_id]);
    $admin_email = $stmt->fetchColumn();
    if ($admin_email !== 'abdullajanovismoilxon@gmail.com') {
        http_response_code(403);
        echo json_encode(["error" => "Ruxsat etilmagan!"]);
        exit;
    }
}

// Period filter (qualified with table aliases to prevent ambiguous column errors)
$period = isset($_GET['period']) ? $_GET['period'] : 'all';
$period_clause = '';
$expense_period_clause = '';
switch ($period) {
    case 'today':
        $period_clause = "AND date(t.created_at) = date('now')";
        $expense_period_clause = "AND date(created_at) = date('now')";
        break;
    case 'week':
        $period_clause = "AND t.created_at >= datetime('now', '-7 days')";
        $expense_period_clause = "AND created_at >= datetime('now', '-7 days')";
        break;
    case 'month':
        $period_clause = "AND t.created_at >= datetime('now', '-30 days')";
        $expense_period_clause = "AND created_at >= datetime('now', '-30 days')";
        break;
    case 'year':
        $period_clause = "AND t.created_at >= datetime('now', '-365 days')";
        $expense_period_clause = "AND created_at >= datetime('now', '-365 days')";
        break;
    default:
        $period_clause = '';
        $expense_period_clause = '';
}

try {
    // 1. General Stats
    $total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_balance = $db->query("SELECT SUM(balance) FROM users")->fetchColumn() ?: 0;
    
    // New users for the period (using users u table alias)
    $new_users_period_clause = str_replace('t.created_at', 'u.created_at', $period_clause);
    $new_users_sql = "SELECT COUNT(*) FROM users u WHERE 1=1 " . $new_users_period_clause;
    $new_users = $db->query($new_users_sql)->fetchColumn() ?: 0;
    
    // Total Topups for the period
    $period_topups_sql = "SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.type='topup' " . $period_clause;
    $period_topups = $db->query($period_topups_sql)->fetchColumn() ?: 0;
    
    // Total all-time topups
    $total_topups = $db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='topup'")->fetchColumn() ?: 0;
    
    // Total spent by users (negative amounts in transactions)
    $total_spent = $db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE amount < 0")->fetchColumn() ?: 0;
    $total_spent = abs($total_spent);
    
    // Period spent by users (type='spent')
    $period_spent_sql = "SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.type='spent' " . $period_clause;
    $period_spent = abs($db->query($period_spent_sql)->fetchColumn() ?: 0);
    
    // Platform expenses for the period
    $period_expenses_sql = "SELECT COALESCE(SUM(amount),0) FROM platform_expenses WHERE 1=1 " . $expense_period_clause;
    $period_expenses = $db->query($period_expenses_sql)->fetchColumn() ?: 0;
    
    // Net profit for the period = revenue (period_topups) - platform expenses (period_expenses)
    $net_profit = $period_topups - $period_expenses;

    // MRR (this month topups)
    $mrr = $db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='topup' AND created_at >= datetime('now', '-30 days')")->fetchColumn() ?: 0;

    // 2. Recent Topups (period aware)
    $recent_sql = "SELECT t.id, t.telegram_id, u.username, t.amount, t.description, t.created_at 
                   FROM transactions t 
                   LEFT JOIN users u ON t.telegram_id = u.telegram_id
                   WHERE t.type='topup' " . $period_clause . "
                   ORDER BY t.created_at DESC LIMIT 15";
    $recent_topups = $db->query($recent_sql)->fetchAll(PDO::FETCH_ASSOC);

    // 3. User List (search support)
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    if ($search !== '') {
        $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id LIKE ? OR username LIKE ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute(["%$search%", "%$search%"]);
    } else {
        $stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 50");
    }
    $users_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Platform Expenses List
    $expenses_list = $db->query("SELECT * FROM platform_expenses ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "stats" => [
            "total_users"     => (int)$total_users,
            "new_users"       => (int)$new_users,
            "total_balance"   => (int)$total_balance,
            "total_topups"    => (int)$total_topups,
            "total_spent"     => (int)$total_spent,
            "period_topups"   => (int)$period_topups,
            "period_spent"    => (int)$period_spent,
            "period_expenses" => (int)$period_expenses,
            "net_profit"      => (int)$net_profit,
            "mrr"             => (int)$mrr
        ],
        "recent_topups" => $recent_topups,
        "users" => $users_list,
        "expenses" => $expenses_list
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
