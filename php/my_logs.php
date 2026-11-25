<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$accountStmt = $conn->prepare('SELECT idnumber, full_name, role FROM infos WHERE id = ? LIMIT 1');
$accountStmt->bind_param('i', $user_id);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();

if (!$account || $account['role'] !== 'customer') {
    header('Location: Sign_in.php');
    exit();
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$month_filter = $_GET['month'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $month_filter)) {
    $month_filter = '';
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'user_session_activity'");
$hasLogsTable = $tableCheck && $tableCheck->num_rows > 0;
$total_logs = 0;
$logs = [];

if ($hasLogsTable) {
    $conditions = ['user_id = ?'];
    $types = 'i';
    $params = [$user_id];

    if ($date_from !== '') {
        $conditions[] = 'DATE(login_time) >= ?';
        $types .= 's';
        $params[] = $date_from;
    }
    if ($date_to !== '') {
        $conditions[] = 'DATE(login_time) <= ?';
        $types .= 's';
        $params[] = $date_to;
    }
    if ($month_filter !== '') {
        $conditions[] = "DATE_FORMAT(login_time, '%Y-%m') = ?";
        $types .= 's';
        $params[] = $month_filter;
    }

    $where = implode(' AND ', $conditions);
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM user_session_activity WHERE $where");
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total_logs = (int) $countStmt->get_result()->fetch_assoc()['total'];

    $logStmt = $conn->prepare("SELECT login_time, logout_time, status, duration_seconds, ip_address FROM user_session_activity WHERE $where ORDER BY login_time DESC LIMIT ? OFFSET ?");
    $logTypes = $types . 'ii';
    $logParams = array_merge($params, [$per_page, $offset]);
    $logStmt->bind_param($logTypes, ...$logParams);
    $logStmt->execute();
    $logs = $logStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$total_pages = max(1, (int) ceil($total_logs / $per_page));
$current_page = 'my_logs.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Login Logs - CSUCC FIND</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'includes/customer_top_header.php'; ?>
<?php include 'includes/customer_sidebar.php'; ?>

<main class="main-content" id="mainContent">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h3 class="mb-1">My Login Logs</h3>
            <p class="text-muted mb-0">Only your account activity is shown here.</p>
        </div>
        <button class="btn btn-outline-secondary" type="button" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-6"><strong><?= htmlspecialchars($account['full_name']) ?></strong><br><small class="text-muted">ID Number: <?= htmlspecialchars($account['idnumber']) ?></small></div>
                <div class="col-md-6 text-md-end"><span class="badge text-bg-primary px-3 py-2"><?= number_format($total_logs) ?> login session<?= $total_logs === 1 ? '' : 's' ?></span></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3 mb-4">
                <div class="col-sm-3"><label class="form-label">From date</label><input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
                <div class="col-sm-3"><label class="form-label">To date</label><input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
                <div class="col-sm-3"><label class="form-label">Month</label><input class="form-control" type="month" name="month" value="<?= htmlspecialchars($month_filter, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-sm-3 d-flex align-items-end gap-2"><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-outline-secondary" href="my_logs.php">Clear</a></div>
            </form>

            <?php if (!$hasLogsTable): ?>
                <div class="alert alert-info mb-0">No login records are available yet.</div>
            <?php elseif (empty($logs)): ?>
                <div class="alert alert-info mb-0">No login records match your selected dates.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Login time</th><th>Logout time</th><th>Duration</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($log['login_time']))) ?></td>
                                <td><?= $log['logout_time'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($log['logout_time']))) : 'Active session' ?></td>
                                <td><?= $log['duration_seconds'] !== null ? htmlspecialchars(gmdate('H:i:s', (int) $log['duration_seconds'])) : '—' ?></td>
                                <td><span class="badge text-bg-<?= $log['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($log['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                    <nav><ul class="pagination justify-content-end mb-0">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(['page' => $i, 'date_from' => $date_from, 'date_to' => $date_to, 'month' => $month_filter]) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                    </ul></nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
