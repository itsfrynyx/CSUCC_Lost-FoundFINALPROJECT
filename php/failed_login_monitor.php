<?php
session_start();
require_once '../DB/conn.php';

// Check if logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: Sign_in.php');
    exit();
}

$admin_id = $_SESSION['super_admin_id'];
$stmt = $conn->prepare("SELECT * FROM infos WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();

if ($admin_data['role'] !== 'super_admin') {
    header('Location: unified_admin_dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Failed Login Monitor - Real-time</title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    
    <style>
        body {
            background: #F8FAFC;
            font-family: 'Inter', sans-serif;
        }
        
        .monitor-header {
            background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%);
            padding: 40px 0;
            margin-bottom: 30px;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            color: white;
        }
        
        .monitor-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .live-dot {
            width: 8px;
            height: 8px;
            background: #10B981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
            height: 100%;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748B;
            font-weight: 500;
        }
        
        .monitor-card {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
            margin-bottom: 24px;
        }
        
        .monitor-card h5 {
            font-size: 18px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .failed-login-item {
            padding: 16px;
            border: 1px solid #FEE2E2;
            background: #FEF2F2;
            border-radius: 10px;
            margin-bottom: 12px;
            animation: slideIn 0.3s ease;
        }
        
        .failed-login-item.new {
            animation: highlight 1s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes highlight {
            0%, 100% { background: #FEF2F2; }
            50% { background: #FEE2E2; }
        }
        
        .failed-login-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-weight: 600;
            color: #DC2626;
            font-size: 15px;
            margin-bottom: 4px;
        }
        
        .user-role {
            font-size: 12px;
            padding: 3px 10px;
            border-radius: 12px;
            display: inline-block;
            background: transparent !important;
            border: 1px solid;
            font-weight: 600;
        }
        
        .user-role.badge-super-admin {
            color: #4F46E5;
            border-color: #4F46E5;
        }
        
        .user-role.badge-admin {
            color: #2563EB;
            border-color: #2563EB;
        }
        
        .user-role.badge-customer {
            color: #8B4513;
            border-color: #8B4513;
        }
        
        .attempt-time {
            font-size: 12px;
            color: #64748B;
            white-space: nowrap;
        }
        
        .attempt-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #FEE2E2;
        }
        
        .detail-item {
            font-size: 12px;
            color: #64748B;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .detail-item i {
            color: #DC2626;
        }
        
        .refresh-btn {
            background: white;
            border: 1px solid #E2E8F0;
            color: #64748B;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .refresh-btn:hover {
            background: #F8FAFC;
            border-color: #CBD5E1;
        }
        
        .refresh-btn.refreshing {
            pointer-events: none;
            opacity: 0.6;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94A3B8;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #CBD5E1;
        }
        
        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }
        
        .filter-tab {
            padding: 8px 16px;
            border: 1px solid #E2E8F0;
            background: white;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #64748B;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .filter-tab.active {
            background: #DC2626;
            color: white;
            border-color: #DC2626;
        }
        
        .filter-tab:hover:not(.active) {
            background: #F8FAFC;
        }
    </style>
</head>
<body>
    <?php include 'includes/professional_sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <!-- Monitor Header -->
        <div class="monitor-header">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="monitor-title">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Failed Login Monitor
                        </h1>
                        <div class="live-indicator">
                            <span class="live-dot"></span>
                            LIVE MONITORING
                        </div>
                    </div>
                    <a href="audit_policy.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Audit Policy
                    </a>
                </div>
            </div>
        </div>
        
        <div class="container-fluid">
            <!-- Statistics Overview -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #FEE2E2; color: #DC2626;">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-value" style="color: #DC2626;" id="totalFailed">0</div>
                        <div class="stat-label">Total Failed Attempts</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #FEF3C7; color: #F59E0B;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value" style="color: #F59E0B;" id="uniqueUsers">0</div>
                        <div class="stat-label">Unique Users</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #E0E7FF; color: #4F46E5;">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <div class="stat-value" style="color: #4F46E5;" id="uniqueIPs">0</div>
                        <div class="stat-label">Unique IP Addresses</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #DCFCE7; color: #10B981;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value" style="color: #10B981;" id="lastHour">0</div>
                        <div class="stat-label">Last Hour</div>
                    </div>
                </div>
            </div>
            
            <!-- Failed Login List -->
            <div class="monitor-card">
                <h5>
                    <span>
                        <i class="fas fa-list" style="color: #DC2626;"></i>
                        Recent Failed Login Attempts
                    </span>
                    <button class="refresh-btn" onclick="refreshData()">
                        <i class="fas fa-sync-alt me-2"></i>
                        <span id="refreshText">Refresh</span>
                    </button>
                </h5>
                
                <div class="filter-tabs">
                    <button class="filter-tab active" data-hours="24" onclick="setTimeFilter(24)">Last 24 Hours</button>
                    <button class="filter-tab" data-hours="12" onclick="setTimeFilter(12)">Last 12 Hours</button>
                    <button class="filter-tab" data-hours="6" onclick="setTimeFilter(6)">Last 6 Hours</button>
                    <button class="filter-tab" data-hours="1" onclick="setTimeFilter(1)">Last Hour</button>
                </div>
                
                <div id="failedLoginList">
                    <div class="empty-state">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading failed login attempts...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentTimeFilter = 24;
        let lastData = [];
        let refreshInterval;
        
        // Auto-refresh every 5 seconds
        function startAutoRefresh() {
            refreshInterval = setInterval(() => {
                refreshData(true);
            }, 5000);
        }
        
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }
        
        function setTimeFilter(hours) {
            currentTimeFilter = hours;
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            refreshData();
        }
        
        async function refreshData(isAuto = false) {
            const refreshBtn = document.querySelector('.refresh-btn');
            const refreshText = document.getElementById('refreshText');
            
            if (!isAuto) {
                refreshBtn.classList.add('refreshing');
                refreshText.textContent = 'Refreshing...';
            }
            
            try {
                const response = await fetch(`get_failed_logins.php?hours=${currentTimeFilter}`);
                const data = await response.json();
                
                if (data.success) {
                    updateStatistics(data.statistics);
                    updateFailedLoginList(data.failed_logins, isAuto);
                    lastData = data.failed_logins;
                }
            } catch (error) {
                console.error('Error fetching data:', error);
            } finally {
                if (!isAuto) {
                    refreshBtn.classList.remove('refreshing');
                    refreshText.textContent = 'Refresh';
                }
            }
        }
        
        function updateStatistics(stats) {
            document.getElementById('totalFailed').textContent = stats.total_failed || 0;
            document.getElementById('uniqueUsers').textContent = stats.unique_users || 0;
            document.getElementById('uniqueIPs').textContent = stats.unique_ips || 0;
            document.getElementById('lastHour').textContent = stats.last_hour || 0;
        }
        
        function updateFailedLoginList(logins, isAuto = false) {
            const listContainer = document.getElementById('failedLoginList');
            
            if (logins.length === 0) {
                listContainer.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No failed login attempts in the selected time period</p>
                    </div>
                `;
                return;
            }
            
            // Check for new items
            const newIds = logins.map(l => l.id);
            const oldIds = lastData.map(l => l.id);
            const hasNewItems = isAuto && newIds.some(id => !oldIds.includes(id));
            
            let html = '';
            logins.forEach((login, index) => {
                const isNew = isAuto && !oldIds.includes(login.id);
                const isLocked = login.action === 'account_locked';
                const bgColor = isLocked ? '#FEF2F2' : '#FEF2F2';
                const borderColor = isLocked ? '#DC2626' : '#FEE2E2';
                
                html += `
                    <div class="failed-login-item ${isNew ? 'new' : ''}" style="background: ${bgColor}; border-color: ${borderColor};">
                        <div class="failed-login-header">
                            <div class="user-info">
                                <div class="user-name">
                                    <i class="fas fa-${isLocked ? 'lock' : 'user-circle'} me-2"></i>
                                    ${login.full_name}
                                    ${isLocked ? '<span style="color: #DC2626; font-weight: 700; margin-left: 8px;">[LOCKED]</span>' : ''}
                                </div>
                                <span class="user-role badge-${login.role === 'super_admin' ? 'super-admin' : (login.role === 'admin' ? 'admin' : 'customer')}">${login.role.toUpperCase().replace('_', ' ')}</span>
                            </div>
                            <div class="attempt-time">
                                <i class="fas fa-clock me-1"></i>
                                ${login.time_ago}
                            </div>
                        </div>
                        <div class="attempt-details">
                            <div class="detail-item">
                                <i class="fas fa-user"></i>
                                <span>Username: ${login.username}</span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-envelope"></i>
                                <span>${login.email}</span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span>IP: ${login.ip_address}</span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-calendar"></i>
                                <span>${new Date(login.created_at).toLocaleString()}</span>
                            </div>
                        </div>
                        ${isLocked ? '<div style="margin-top: 12px; padding: 8px 12px; background: #FEE2E2; border-radius: 6px; font-size: 12px; color: #991B1B;"><i class="fas fa-exclamation-triangle me-2"></i><strong>Account Locked:</strong> Multiple failed login attempts detected</div>' : ''}
                    </div>
                `;
            });
            
            listContainer.innerHTML = html;
            
            // Show notification for new failed logins
            if (hasNewItems && isAuto) {
                const hasLockedAccount = logins.some(l => !oldIds.includes(l.id) && l.action === 'account_locked');
                if (hasLockedAccount) {
                    showNotification('⚠️ ALERT: Account has been locked due to multiple failed attempts!');
                } else {
                    showNotification('New failed login attempt detected!');
                }
            }
        }
        
        function showNotification(message) {
            // You can implement a toast notification here
            console.log('Notification:', message);
            
            // Play a subtle sound or show browser notification if permitted
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('Failed Login Alert', {
                    body: message,
                    icon: '../PICTURES/bookstore.png'
                });
            }
        }
        
        // Request notification permission
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            refreshData();
            startAutoRefresh();
        });
        
        // Stop auto-refresh when page is hidden
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });
    </script>
</body>
</html>
