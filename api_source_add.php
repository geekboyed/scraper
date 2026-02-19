<?php
/**
 * API Endpoint - Add a new source
 * Uses users_sources relationship table to track ownership.
 * Admin-added sources have no users_sources entry (visible to all).
 * Regular user sources get an entry in users_sources.
 * Same URL won't be duplicated in the sources table.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';

/**
 * Normalize URL to prevent near-duplicates
 * - Removes trailing slashes
 * - Removes URL fragments (#anchors)
 * - Trims whitespace
 */
function normalizeUrl($url) {
    $url = trim($url);

    // Remove fragment (#anchor)
    if (($pos = strpos($url, '#')) !== false) {
        $url = substr($url, 0, $pos);
    }

    // Remove trailing slash (but keep it for root URLs like https://example.com/)
    $parsed = parse_url($url);
    if (isset($parsed['path']) && $parsed['path'] !== '/') {
        $url = rtrim($url, '/');
    }

    return $url;
}

// Require authentication
if (!$current_user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Accept both JSON body and form-encoded POST data
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$name = trim($input['name'] ?? '');
$url = normalizeUrl($input['url'] ?? '');
$mainCategory = trim($input['mainCategory'] ?? '');
$scrape_frequency = trim($input['scrape_frequency'] ?? 'hourly');

// Validate inputs
if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Source name is required']);
    exit;
}

if (strlen($name) > 200) {
    echo json_encode(['success' => false, 'error' => 'Source name must be 200 characters or less']);
    exit;
}

if (empty($url)) {
    echo json_encode(['success' => false, 'error' => 'Source URL is required']);
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid URL']);
    exit;
}

if (strlen($url) > 500) {
    echo json_encode(['success' => false, 'error' => 'URL must be 500 characters or less']);
    exit;
}

if (strlen($mainCategory) > 32) {
    echo json_encode(['success' => false, 'error' => 'Category name must be 32 characters or less']);
    exit;
}

// Validate scrape_frequency
$valid_frequencies = ['hourly', 'daily', 'weekly'];
if (!in_array($scrape_frequency, $valid_frequencies)) {
    $scrape_frequency = 'hourly';
}

$isAdmin = $current_user['isAdmin'] == 'Y';
$userId = (int)$current_user['id'];

// Enforce source limit for non-admin users (sourceCount = remaining sources allowed)
if (!$isAdmin) {
    $sourcesRemaining = (int)($current_user['sourceCount'] ?? 0);

    if ($sourcesRemaining <= 0) {
        echo json_encode([
            'success' => false,
            'error' => "You have reached your source limit (0 remaining). Please contact an admin to add more sources.",
            'sourcesRemaining' => 0
        ]);
        exit;
    }
}

// Check if URL already exists in sources table
$stmt = $conn->prepare("SELECT id, name, isBase, isActive FROM sources WHERE url = ?");
$stmt->bind_param("s", $url);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

try {
    if ($existing) {
        // Source URL already exists
        $sourceId = (int)$existing['id'];

        // If the source is inactive, reactivate it
        if ($existing['isActive'] === 'N') {
            $reactivate = $conn->prepare("UPDATE sources SET isActive = 'Y', name = ? WHERE id = ?");
            $reactivate->bind_param("si", $name, $sourceId);
            $reactivate->execute();
            $reactivate->close();

            // For non-admin users, ensure the user-source relationship exists
            if (!$isAdmin) {
                $checkRel = $conn->prepare("SELECT id FROM users_sources WHERE user_id = ? AND source_id = ?");
                $checkRel->bind_param("ii", $userId, $sourceId);
                $checkRel->execute();
                $hasRel = $checkRel->get_result()->fetch_assoc();
                $checkRel->close();

                if (!$hasRel) {
                    $rel = $conn->prepare("INSERT INTO users_sources (user_id, source_id) VALUES (?, ?)");
                    $rel->bind_param("ii", $userId, $sourceId);
                    $rel->execute();
                    $rel->close();

                    $update = $conn->prepare("UPDATE users SET sourceCount = GREATEST(sourceCount - 1, 0) WHERE id = ?");
                    $update->bind_param("i", $userId);
                    $update->execute();
                    $update->close();
                }
            }

            $fetch = $conn->prepare("SELECT id, name, url, mainCategory, scrape_frequency, isActive, created_at FROM sources WHERE id = ?");
            $fetch->bind_param("i", $sourceId);
            $fetch->execute();
            $source = $fetch->get_result()->fetch_assoc();
            $fetch->close();

            echo json_encode(['success' => true, 'source' => $source, 'reactivated' => true]);
            $conn->close();
            exit;
        }

        if ($isAdmin) {
            if ($existing['isBase'] == 'Y') {
                // Already a base source, nothing to do
                echo json_encode(['success' => false, 'error' => 'This is already a base source: ' . $existing['name']]);
                $conn->close();
                exit;
            }

            // Promote user source to base source: set isBase='Y', remove users_sources entries
            $promote = $conn->prepare("UPDATE sources SET isBase = 'Y' WHERE id = ?");
            $promote->bind_param("i", $sourceId);
            $promote->execute();
            $promote->close();

            // Remove all users_sources entries (source is now public to everyone)
            // Refund sourceCount to users who had this source
            $refund = $conn->prepare("SELECT user_id FROM users_sources WHERE source_id = ?");
            $refund->bind_param("i", $sourceId);
            $refund->execute();
            $usersToRefund = $refund->get_result();
            while ($row = $usersToRefund->fetch_assoc()) {
                $inc = $conn->prepare("UPDATE users SET sourceCount = sourceCount + 1 WHERE id = ?");
                $inc->bind_param("i", $row['user_id']);
                $inc->execute();
                $inc->close();
            }
            $refund->close();

            $del = $conn->prepare("DELETE FROM users_sources WHERE source_id = ?");
            $del->bind_param("i", $sourceId);
            $del->execute();
            $del->close();

            // Fetch the promoted source
            $fetch = $conn->prepare("SELECT id, name, url, mainCategory, scrape_frequency, isActive, created_at FROM sources WHERE id = ?");
            $fetch->bind_param("i", $sourceId);
            $fetch->execute();
            $source = $fetch->get_result()->fetch_assoc();
            $fetch->close();

            echo json_encode(['success' => true, 'source' => $source, 'promoted' => true]);
            $conn->close();
            exit;
        }

        // Base sources are already visible to all users -- don't waste a source slot
        if ($existing['isBase'] == 'Y') {
            echo json_encode(['success' => false, 'error' => 'This source is already available to all users as a base source']);
            $conn->close();
            exit;
        }

        // Check if user already has this source
        $stmt = $conn->prepare("SELECT id FROM users_sources WHERE user_id = ? AND source_id = ?");
        $stmt->bind_param("ii", $userId, $sourceId);
        $stmt->execute();
        $hasSource = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($hasSource) {
            echo json_encode(['success' => false, 'error' => 'You already have this source: ' . $existing['name']]);
            $conn->close();
            exit;
        }

        // Add relationship for this user
        $stmt = $conn->prepare("INSERT INTO users_sources (user_id, source_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $userId, $sourceId);
        $stmt->execute();
        $stmt->close();

        // Decrement sourceCount for non-admin users
        $update = $conn->prepare("UPDATE users SET sourceCount = GREATEST(sourceCount - 1, 0) WHERE id = ?");
        $update->bind_param("i", $userId);
        $update->execute();
        $update->close();

        // Fetch the source
        $fetch = $conn->prepare("SELECT id, name, url, mainCategory, scrape_frequency, isActive, created_at FROM sources WHERE id = ?");
        $fetch->bind_param("i", $sourceId);
        $fetch->execute();
        $source = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        echo json_encode(['success' => true, 'source' => $source, 'shared' => true]);
    } else {
        // Source URL doesn't exist -- create new source
        // Admin sources: isBase='Y' (public), User sources: isBase='N' (private)
        $isBase = $isAdmin ? 'Y' : 'N';
        $stmt = $conn->prepare("INSERT INTO sources (name, url, mainCategory, scrape_frequency, isBase, isActive) VALUES (?, ?, ?, ?, ?, 'Y')");
        $stmt->bind_param("sssss", $name, $url, $mainCategory, $scrape_frequency, $isBase);

        if ($stmt->execute()) {
            $newSourceId = $conn->insert_id;
            $stmt->close();

            // For non-admin users, create relationship and decrement sourceCount
            if (!$isAdmin) {
                $rel = $conn->prepare("INSERT INTO users_sources (user_id, source_id) VALUES (?, ?)");
                $rel->bind_param("ii", $userId, $newSourceId);
                $rel->execute();
                $rel->close();

                $update = $conn->prepare("UPDATE users SET sourceCount = GREATEST(sourceCount - 1, 0) WHERE id = ?");
                $update->bind_param("i", $userId);
                $update->execute();
                $update->close();
            }

            // Fetch the created source
            $fetch = $conn->prepare("SELECT id, name, url, mainCategory, scrape_frequency, isActive, created_at FROM sources WHERE id = ?");
            $fetch->bind_param("i", $newSourceId);
            $fetch->execute();
            $source = $fetch->get_result()->fetch_assoc();
            $fetch->close();

            echo json_encode(['success' => true, 'source' => $source]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to add source']);
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log("api_source_add.php error: " . $e->getMessage());
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo json_encode(['success' => false, 'error' => 'A source with this URL already exists']);
    } else {
        echo json_encode(['success' => false, 'error' => 'An error occurred while adding the source']);
    }
}

$conn->close();
