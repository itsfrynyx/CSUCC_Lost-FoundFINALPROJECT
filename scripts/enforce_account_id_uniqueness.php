<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../DB/conn.php';

try {
    $existing = $conn->query("SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'infos' AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = 'idnumber' AND MAX(SUB_PART) IS NULL");
    if ($existing->num_rows === 0) {
        $conn->query('ALTER TABLE infos ADD UNIQUE INDEX uq_infos_idnumber (idnumber)');
    }
    echo "Account ID uniqueness is enforced.\n";
} catch (mysqli_sql_exception $error) {
    if ($error->getCode() === 1062) {
        fwrite(STDERR, "Resolve duplicate ID numbers before running this migration again.\n");
    } else {
        fwrite(STDERR, "Unable to add the unique account ID index.\n");
    }
    exit(1);
}
