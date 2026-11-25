<?php
/**
 * AI-assisted matching for lost-and-found reports.
 *
 * This service compares newly reported lost items against found items and stores
 * candidate matches in the ai_matches table with a scored, reviewable result.
 */

function ai_normalize_text($value)
{
    if ($value === null) {
        return '';
    }

    $value = strtolower((string)$value);
    $value = preg_replace('/[^a-z0-9\s]/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);

    return trim((string)$value);
}

function ai_tokenize($value)
{
    $normalized = ai_normalize_text($value);
    if ($normalized === '') {
        return [];
    }

    return array_values(array_filter(explode(' ', $normalized), static function ($token) {
        return $token !== '';
    }));
}

function ai_overlap_ratio($left, $right)
{
    $leftTokens = ai_tokenize($left);
    $rightTokens = ai_tokenize($right);

    if (empty($leftTokens) || empty($rightTokens)) {
        return 0.0;
    }

    $leftSet = array_flip($leftTokens);
    $rightSet = array_flip($rightTokens);
    $intersection = count(array_intersect_key($leftSet, $rightSet));
    $union = count(array_unique(array_merge($leftTokens, $rightTokens)));

    if ($union === 0) {
        return 0.0;
    }

    return $intersection / $union;
}

function ai_calculate_match_score(array $lostItem, array $foundItem): array
{
    $score = 0;
    $factors = [];

    $categoryMatch = ai_normalize_text($lostItem['category'] ?? '') === ai_normalize_text($foundItem['category'] ?? '');
    if ($categoryMatch) {
        $score += 25;
        $factors['category'] = 25;
    } else {
        $categoryRatio = ai_overlap_ratio($lostItem['category'] ?? '', $foundItem['category'] ?? '');
        if ($categoryRatio >= 0.5) {
            $score += 12;
            $factors['category'] = 12;
        }
    }

    $nameRatio = ai_overlap_ratio($lostItem['item_name'] ?? '', $foundItem['item_name'] ?? '');
    $namePoints = (int) round($nameRatio * 30);
    $score += $namePoints;
    $factors['item_name'] = $namePoints;

    $colorLost = ai_normalize_text($lostItem['color'] ?? '');
    $colorFound = ai_normalize_text($foundItem['color'] ?? '');
    if ($colorLost !== '' && $colorFound !== '' && $colorLost === $colorFound) {
        $score += 15;
        $factors['color'] = 15;
    } elseif ($colorLost !== '' && $colorFound !== '' && ai_overlap_ratio($colorLost, $colorFound) >= 0.5) {
        $score += 8;
        $factors['color'] = 8;
    }

    $brandLost = ai_normalize_text($lostItem['brand'] ?? '');
    $brandFound = ai_normalize_text($foundItem['brand'] ?? '');
    if ($brandLost !== '' && $brandFound !== '' && $brandLost === $brandFound) {
        $score += 10;
        $factors['brand'] = 10;
    }

    $modelLost = ai_normalize_text($lostItem['model'] ?? '');
    $modelFound = ai_normalize_text($foundItem['model'] ?? '');
    if ($modelLost !== '' && $modelFound !== '' && $modelLost === $modelFound) {
        $score += 10;
        $factors['model'] = 10;
    }

    $locationLost = $lostItem['location_lost'] ?? $lostItem['location_found'] ?? '';
    $locationFound = $foundItem['location_found'] ?? $foundItem['location_lost'] ?? '';
    $locationRatio = ai_overlap_ratio($locationLost, $locationFound);
    if ($locationRatio >= 0.6) {
        $score += 15;
        $factors['location'] = 15;
    } elseif ($locationRatio >= 0.3) {
        $score += 8;
        $factors['location'] = 8;
    }

    $lostDate = $lostItem['date_lost'] ?? $lostItem['date_found'] ?? null;
    $foundDate = $foundItem['date_found'] ?? $foundItem['date_lost'] ?? null;
    if ($lostDate && $foundDate) {
        $lostTs = strtotime($lostDate);
        $foundTs = strtotime($foundDate);
        if ($lostTs !== false && $foundTs !== false) {
            $dayDiff = abs((int)(($foundTs - $lostTs) / 86400));
            if ($dayDiff <= 7) {
                $score += 10;
                $factors['date_window'] = 10;
            } elseif ($dayDiff <= 30) {
                $score += 5;
                $factors['date_window'] = 5;
            }
        }
    }

    $descriptionOverlap = ai_overlap_ratio(
        $lostItem['description'] ?? '',
        $foundItem['description'] ?? ''
    );
    if ($descriptionOverlap >= 0.25) {
        $score += 10;
        $factors['description'] = 10;
    }

    $distinguishingLost = $lostItem['distinguishing_features'] ?? '';
    $distinguishingFound = $foundItem['distinguishing_features'] ?? '';
    $distinguishingOverlap = ai_overlap_ratio($distinguishingLost, $distinguishingFound);
    if ($distinguishingOverlap >= 0.3) {
        $score += 10;
        $factors['features'] = 10;
    }

    return [
        'score' => min(100, max(0, (int) round($score))),
        'factors' => $factors,
    ];
}

function ai_upsert_match(mysqli $conn, int $lostItemId, int $foundItemId, int $score, array $factors): bool
{
    $json = json_encode($factors, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{}';
    }

    $sql = "
        INSERT INTO ai_matches (lost_item_id, found_item_id, match_score, matching_factors, review_status, reviewed_by, created_at)
        VALUES (?, ?, ?, ?, 'pending', NULL, NOW())
        ON DUPLICATE KEY UPDATE
            match_score = VALUES(match_score),
            matching_factors = VALUES(matching_factors),
            review_status = 'pending',
            reviewed_by = NULL,
            created_at = NOW()
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return false;
    }

    $stmt->bind_param('iisd', $lostItemId, $foundItemId, $score, $json);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function ai_generate_matches_for_lost_item(mysqli $conn, int $lostItemId): int
{
    $lostStmt = $conn->prepare('SELECT * FROM lost_items WHERE id = ? LIMIT 1');
    if ($lostStmt === false) {
        return 0;
    }

    $lostStmt->bind_param('i', $lostItemId);
    $lostStmt->execute();
    $lostResult = $lostStmt->get_result();
    $lostItem = $lostResult->fetch_assoc();
    $lostStmt->close();

    if (!$lostItem) {
        return 0;
    }

    $foundSql = "
        SELECT *
        FROM found_items
        WHERE status IN ('pending', 'verified', 'claimed', 'returned')
        ORDER BY created_at DESC
    ";

    $foundResult = $conn->query($foundSql);
    if ($foundResult === false) {
        return 0;
    }

    $generated = 0;
    while ($foundItem = $foundResult->fetch_assoc()) {
        $result = ai_calculate_match_score($lostItem, $foundItem);
        if ($result['score'] >= 55) {
            if (ai_upsert_match($conn, (int)$lostItem['id'], (int)$foundItem['id'], $result['score'], $result['factors'])) {
                $generated++;
            }
        }
    }

    return $generated;
}

function ai_generate_matches_for_found_item(mysqli $conn, int $foundItemId): int
{
    $foundStmt = $conn->prepare('SELECT * FROM found_items WHERE id = ? LIMIT 1');
    if ($foundStmt === false) {
        return 0;
    }

    $foundStmt->bind_param('i', $foundItemId);
    $foundStmt->execute();
    $foundResult = $foundStmt->get_result();
    $foundItem = $foundResult->fetch_assoc();
    $foundStmt->close();

    if (!$foundItem) {
        return 0;
    }

    $lostSql = "
        SELECT *
        FROM lost_items
        WHERE status IN ('pending', 'verified', 'possible_match', 'recovered')
        ORDER BY created_at DESC
    ";

    $lostResult = $conn->query($lostSql);
    if ($lostResult === false) {
        return 0;
    }

    $generated = 0;
    while ($lostItem = $lostResult->fetch_assoc()) {
        $result = ai_calculate_match_score($lostItem, $foundItem);
        if ($result['score'] >= 55) {
            if (ai_upsert_match($conn, (int)$lostItem['id'], (int)$foundItem['id'], $result['score'], $result['factors'])) {
                $generated++;
            }
        }
    }

    return $generated;
}

function ai_generate_all_matches(mysqli $conn): int
{
    $lostResult = $conn->query("SELECT id FROM lost_items WHERE status IN ('pending', 'verified', 'possible_match', 'recovered') ORDER BY created_at DESC");
    if ($lostResult === false) {
        return 0;
    }

    $generated = 0;
    while ($row = $lostResult->fetch_assoc()) {
        $generated += ai_generate_matches_for_lost_item($conn, (int)$row['id']);
    }

    $foundResult = $conn->query("SELECT id FROM found_items WHERE status IN ('pending', 'verified', 'claimed', 'returned') ORDER BY created_at DESC");
    if ($foundResult !== false) {
        while ($row = $foundResult->fetch_assoc()) {
            $generated += ai_generate_matches_for_found_item($conn, (int)$row['id']);
        }
    }

    return $generated;
}
