<?php
require 'DB/conn.php';
$res = $conn->query("SELECT action, session_id FROM admin_activity_log ORDER BY id DESC LIMIT 10");
while($row = $res->fetch_assoc()) {
    echo "Action: {$row['action']} | SID: {$row['session_id']}\n";
}
?>
