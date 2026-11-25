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

if ($admin_data['role'] !== 'super_admin' && $admin_data['role'] !== 'admin') {
    header('Location: unified_admin_dashboard.php');
    exit();
}

$is_super_admin = ($admin_data['role'] === 'super_admin');

// Get audit statistics
$total_logs = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log")->fetch_assoc()['count'] ?? 0;
$today_logs = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'] ?? 0;
$failed_logins = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE action = 'failed_login' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['count'] ?? 0;
$suspicious_activities = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE action IN ('failed_login', 'unauthorized_access') AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['count'] ?? 0;

// Get recent audit logs
$audit_logs_query = "
    SELECT 
        aal.*,
        i.username,
        i.full_name,
        i.role
    FROM admin_activity_log aal
    LEFT JOIN infos i ON aal.user_id = i.id
    ORDER BY aal.created_at DESC
    LIMIT 50
";
$audit_logs = $conn->query($audit_logs_query)->fetch_all(MYSQLI_ASSOC);

// Get activity by action type
$activity_breakdown = $conn->query("
    SELECT action, COUNT(*) as count 
    FROM admin_activity_log 
    WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND action NOT IN ('failed_login', 'login_failed', 'account_locked')
    GROUP BY action 
    ORDER BY count DESC 
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Get activity by user
$user_activity = $conn->query("
    SELECT 
        i.username,
        i.full_name,
        i.role,
        COUNT(aal.id) as activity_count,
        MAX(aal.created_at) as last_activity
    FROM infos i
    LEFT JOIN admin_activity_log aal ON i.id = aal.user_id
    WHERE i.role IN ('admin', 'super_admin')
    GROUP BY i.id
    ORDER BY activity_count DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Get daily activity for chart (last 14 days)
$daily_activity = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $count = $conn->query("SELECT COUNT(*) as count FROM admin_activity_log WHERE DATE(created_at) = '$date'")->fetch_assoc()['count'] ?? 0;
    $daily_activity[] = ['date' => $date, 'count' => $count];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Policy & Monitoring - <?php echo $is_super_admin ? 'Super Admin' : 'Admin'; ?></title>
    <link rel="icon" href="../PICTURES/bookstore.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/professional_styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        body {
            background: #F8FAFC;
            font-family: 'Inter', sans-serif;
        }
        
        .policy-header {
            background: linear-gradient(135deg, #4F46E5 0%, #6366F1 100%);
            padding: 40px 0;
            margin-bottom: 30px;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            color: white;
        }
        
        .policy-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .policy-subtitle {
            font-size: 14px;
            opacity: 0.9;
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
        
        .policy-card {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
            margin-bottom: 24px;
        }
        
        .policy-card h5 {
            font-size: 18px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .policy-section {
            margin-bottom: 24px;
        }
        
        .policy-section:last-child {
            margin-bottom: 0;
        }
        
        .policy-section-title {
            font-size: 15px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .policy-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .policy-list li {
            padding: 12px 16px;
            background: #F8FAFC;
            border-radius: 8px;
            margin-bottom: 8px;
            border-left: 3px solid #4F46E5;
            font-size: 14px;
            color: #475569;
        }
        
        .policy-list li:last-child {
            margin-bottom: 0;
        }
        
        .policy-list li i {
            color: #4F46E5;
            margin-right: 8px;
        }
        
        .audit-log-item {
            padding: 16px;
            border-bottom: 1px solid #F1F5F9;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .audit-log-item:last-child {
            border-bottom: none;
        }
        
        .audit-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
        }
        
        .audit-content {
            flex: 1;
        }
        
        .audit-title {
            font-weight: 600;
            color: #1E293B;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .audit-meta {
            font-size: 12px;
            color: #94A3B8;
        }
        
        .audit-time {
            font-size: 12px;
            color: #64748B;
            white-space: nowrap;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 20px;
        }
        
        .activity-breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #F1F5F9;
        }
        
        .activity-breakdown-item:last-child {
            border-bottom: none;
        }
        
        .activity-label {
            font-size: 13px;
            color: #475569;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .activity-count {
            font-size: 16px;
            font-weight: 700;
            color: #4F46E5;
        }
        
        .user-activity-item {
            padding: 14px;
            background: #F8FAFC;
            border-radius: 8px;
            margin-bottom: 12px;
            border: 1px solid #E2E8F0;
        }
        
        .user-activity-item:last-child {
            margin-bottom: 0;
        }
        
        .user-name {
            font-weight: 600;
            color: #1E293B;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .user-meta {
            font-size: 12px;
            color: #64748B;
            display: flex;
            gap: 12px;
        }
        
        .badge-role {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            background: transparent !important;
            border: 1px solid;
        }
        
        .badge-super-admin {
            color: #4F46E5;
            border-color: #4F46E5;
        }
        
        .badge-admin {
            color: #2563EB;
            border-color: #2563EB;
        }
    </style>
</head>
<body>
    <?php include 'includes/professional_sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <!-- Policy Header -->
        <div class="policy-header">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="policy-title">
                            <i class="fas fa-shield-alt me-2"></i>
                            Logging, Monitoring & Audit Policy
                        </h1>
                        <p class="policy-subtitle mb-0">
                            Comprehensive system activity tracking and compliance monitoring
                        </p>
                    </div>
                    <a href="admin_dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
        
        <div class="container-fluid">
            <?php if (!$is_super_admin): ?>
            <div class="alert alert-info mb-4" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Limited Access:</strong> You have view-only access to audit logs. Export and alert configuration features are restricted to Super Administrators.
            </div>
            <?php endif; ?>
            
            <!-- Statistics Overview -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #EEF2FF; color: #4F46E5;">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-value" style="color: #4F46E5;"><?php echo number_format($total_logs); ?></div>
                        <div class="stat-label">Total Audit Logs</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #DCFCE7; color: #10B981;">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="stat-value" style="color: #10B981;"><?php echo number_format($today_logs); ?></div>
                        <div class="stat-label">Today's Activities</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #FEE2E2; color: #EF4444;">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-value" style="color: #EF4444;"><?php echo number_format($failed_logins); ?></div>
                        <div class="stat-label">Failed Logins (7 days)</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #FEF3C7; color: #F59E0B;">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-value" style="color: #F59E0B;"><?php echo number_format($suspicious_activities); ?></div>
                        <div class="stat-label">Suspicious Activities</div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Left Column -->
                <div class="col-lg-8">
                    <!-- Policy Overview -->
                    <div class="policy-card">
                        <h5><i class="fas fa-file-contract" style="color: #4F46E5;"></i> Audit Policy Overview</h5>
                        <p style="font-size: 14px; color: #64748B; margin-bottom: 24px;">
                            This policy specifies how system activities are recorded and reviewed to ensure security, compliance, and operational integrity.
                        </p>
                        
                        <div class="policy-section">
                            <div class="policy-section-title">
                                <i class="fas fa-check-circle" style="color: #10B981;"></i>
                                Role of Integration
                            </div>
                            <ul class="policy-list">
                                <li><i class="fas fa-link"></i> Enables traceability of cross-system transactions</li>
                                <li><i class="fas fa-eye"></i> Detects unauthorized or abnormal behavior</li>
                                <li><i class="fas fa-search"></i> Supports incident investigation and compliance audits</li>
                                <li><i class="fas fa-chart-line"></i> Provides real-time monitoring and alerting</li>
                            </ul>
                        </div>
                        
                        <div class="policy-section">
                            <div class="policy-section-title">
                                <i class="fas fa-clipboard-check" style="color: #3B82F6;"></i>
                                What is Logged
                            </div>
                            <ul class="policy-list">
                                <li><i class="fas fa-sign-in-alt"></i> User authentication (login/logout attempts)</li>
                                <li><i class="fas fa-user-edit"></i> Account modifications and privilege changes</li>
                                <li><i class="fas fa-database"></i> Data access and modifications</li>
                                <li><i class="fas fa-cog"></i> System configuration changes</li>
                                <li><i class="fas fa-ban"></i> Failed access attempts and security violations</li>
                                <li><i class="fas fa-trash"></i> Data deletion and critical operations</li>
                            </ul>
                        </div>
                        
                        <div class="policy-section">
                            <div class="policy-section-title">
                                <i class="fas fa-shield-alt" style="color: #EF4444;"></i>
                                Security Measures
                            </div>
                            <ul class="policy-list">
                                <li><i class="fas fa-lock"></i> Logs are tamper-proof and immutable</li>
                                <li><i class="fas fa-clock"></i> Automatic log retention for 90 days</li>
                                <li><i class="fas fa-bell"></i> Real-time alerts for suspicious activities</li>
                                <li><i class="fas fa-user-lock"></i> Access restricted to Super Administrators only</li>
                                <li><i class="fas fa-archive"></i> Regular backup and archival of audit logs</li>
                            </ul>
                        </div>
                        
                        <div class="policy-section">
                            <div class="policy-section-title">
                                <i class="fas fa-balance-scale" style="color: #8B5CF6;"></i>
                                Compliance & Standards
                            </div>
                            <ul class="policy-list">
                                <li><i class="fas fa-certificate"></i> Compliant with industry security standards</li>
                                <li><i class="fas fa-file-alt"></i> Regular audit reports generated monthly</li>
                                <li><i class="fas fa-users"></i> Supports internal and external audits</li>
                                <li><i class="fas fa-gavel"></i> Meets regulatory requirements for data protection</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Activity Chart -->
                    <div class="policy-card">
                        <h5><i class="fas fa-chart-area" style="color: #10B981;"></i> Activity Trend (Last 14 Days)</h5>
                        <div class="chart-container">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Recent Audit Logs -->
                    <div class="policy-card">
                        <h5><i class="fas fa-history" style="color: #F59E0B;"></i> Recent Audit Logs</h5>
                        <div class="audit-log-list" style="max-height: 500px; overflow-y: auto;">
                            <?php if (empty($audit_logs)): ?>
                            <p class="text-muted text-center py-4">No audit logs found</p>
                            <?php else: ?>
                            <?php foreach ($audit_logs as $log): ?>
                            <div class="audit-log-item">
                                <div class="audit-icon" style="background: <?php 
                                    echo match($log['action']) {
                                        'login' => '#DCFCE7; color: #10B981',
                                        'logout' => '#FEE2E2; color: #EF4444',
                                        'failed_login' => '#FEE2E2; color: #DC2626',
                                        'profile_update' => '#DBEAFE; color: #3B82F6',
                                        'password_change' => '#FEF3C7; color: #F59E0B',
                                        default => '#F1F5F9; color: #64748B'
                                    };
                                ?>;">
                                    <i class="fas fa-<?php 
                                        echo match($log['action']) {
                                            'login' => 'sign-in-alt',
                                            'logout' => 'sign-out-alt',
                                            'failed_login' => 'exclamation-triangle',
                                            'profile_update' => 'user-edit',
                                            'password_change' => 'key',
                                            default => 'circle'
                                        };
                                    ?>"></i>
                                </div>
                                <div class="audit-content">
                                    <div class="audit-title"><?php echo htmlspecialchars($log['description']); ?></div>
                                    <div class="audit-meta">
                                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?>
                                        <span class="mx-2">•</span>
                                        <i class="fas fa-map-marker-alt me-1"></i><?php echo $log['ip_address'] === '::1' ? '127.0.0.1' : htmlspecialchars($log['ip_address']); ?>
                                    </div>
                                </div>
                                <div class="audit-time">
                                    <?php echo date('M d, h:i A', strtotime($log['created_at'])); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="col-lg-4">
                    <!-- Activity Breakdown -->
                    <div class="policy-card">
                        <h5><i class="fas fa-chart-pie" style="color: #8B5CF6;"></i> Activity Breakdown</h5>
                        <p style="font-size: 13px; color: #64748B; margin-bottom: 16px;">Last 30 days</p>
                        <?php if (empty($activity_breakdown)): ?>
                        <p class="text-muted text-center py-3">No activity data</p>
                        <?php else: ?>
                        <?php foreach ($activity_breakdown as $activity): ?>
                        <div class="activity-breakdown-item">
                            <div class="activity-label"><?php echo str_replace('_', ' ', $activity['action']); ?></div>
                            <div class="activity-count"><?php echo number_format($activity['count']); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- User Activity -->
                    <div class="policy-card">
                        <h5><i class="fas fa-users" style="color: #3B82F6;"></i> User Activity</h5>
                        <p style="font-size: 13px; color: #64748B; margin-bottom: 16px;">Most active administrators</p>
                        <?php if (empty($user_activity)): ?>
                        <p class="text-muted text-center py-3">No user activity</p>
                        <?php else: ?>
                        <?php foreach ($user_activity as $user): ?>
                        <div class="user-activity-item">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <span class="badge-role <?php echo $user['role'] === 'super_admin' ? 'badge-super-admin' : 'badge-admin'; ?>">
                                    <?php echo $user['role'] === 'super_admin' ? 'Super Admin' : 'Admin'; ?>
                                </span>
                            </div>
                            <div class="user-meta">
                                <span><i class="fas fa-chart-line me-1"></i><?php echo number_format($user['activity_count']); ?> activities</span>
                                <?php if ($user['last_activity']): ?>
                                <span><i class="fas fa-clock me-1"></i><?php echo date('M d, h:i A', strtotime($user['last_activity'])); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="policy-card">
                        <h5><i class="fas fa-bolt" style="color: #F59E0B;"></i> Quick Actions</h5>
                        <div class="d-grid gap-2">
                            <a href="failed_login_monitor.php" class="btn btn-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>Failed Login Monitor (Live)
                            </a>
                            <a href="admin_logs.php" class="btn btn-outline-primary">
                                <i class="fas fa-list me-2"></i>View All Logs
                            </a>
                            <?php if ($is_super_admin): ?>
                            <a href="admin_reports.php" class="btn btn-outline-success">
                                <i class="fas fa-file-export me-2"></i>Export Report
                            </a>
                            <button class="btn btn-outline-secondary" onclick="alert('Alert configuration coming soon')">
                                <i class="fas fa-bell me-2"></i>Configure Alerts
                            </button>
                            <?php else: ?>
                            <button class="btn btn-outline-secondary" disabled title="Super Admin only">
                                <i class="fas fa-lock me-2"></i>Export Report (Super Admin Only)
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Activity Chart
        const activityData = <?php echo json_encode($daily_activity); ?>;
        const ctx = document.getElementById('activityChart');
        
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: activityData.map(d => {
                        const date = new Date(d.date);
                        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Activities',
                        data: activityData.map(d => d.count),
                        borderColor: '#4F46E5',
                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#4F46E5',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#4F46E5',
                            borderWidth: 1,
                            displayColors: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color: '#64748B',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: '#F1F5F9',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: '#64748B',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
