<?php
require 'DB/conn.php';
$query = "
    SELECT al.session_id, usa.login_time, usa.logout_time, usa.status
    FROM admin_activity_log al 
    LEFT JOIN user_session_activity usa ON al.session_id = usa.session_id
    GROUP BY al.session_id
    ORDER BY al.id DESC LIMIT 10
";
$res = $conn->query($query);
while($row = $res->fetch_assoc()) {
    echo "SID: " . substr($row['session_id'], 0, 8) . " | Login: {$row['login_time']} | Logout: " . ($row['logout_time'] ?? 'NULL') . " | Status: {$row['status']}\n";
}
?>
