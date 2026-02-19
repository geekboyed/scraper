<?php
/**
 * Business Insider Articles Dashboard
 * Displays scraped articles with categories and summaries
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once 'config.php';
require_once 'auth_check.php';

// Redirect to login if not authenticated
if (!$current_user) {
    header('Location: login.php');
    exit;
}

// Load user preferences
$user_preferences = null;
$preferred_sources = [];
$preferred_categories = [];
$default_view = 'all';

if ($current_user && $current_user['preferenceJSON']) {
    $user_preferences = json_decode($current_user['preferenceJSON'], true);
    if ($user_preferences) {
        $preferred_sources = isset($user_preferences['sources']) ? $user_preferences['sources'] : [];
        $preferred_categories = isset($user_preferences['categories']) ? $user_preferences['categories'] : [];
        $default_view = isset($user_preferences['defaultView']) ? $user_preferences['defaultView'] : 'all';
    }
}

// Get filter parameters
$category_filter = isset($_GET['category']) ? (is_array($_GET['category']) ? array_map('intval', $_GET['category']) : [(int)$_GET['category']]) : [];
$source_filter = isset($_GET['source']) ? (is_array($_GET['source']) ? array_map('intval', $_GET['source']) : [(int)$_GET['source']]) : [];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '1day'; // Default to '1day'
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$page_size = isset($_GET['size']) ? (int)$_GET['size'] : 50;

// Apply default view if no explicit filter is set in URL
$has_explicit_filter = !empty($category_filter) || !empty($source_filter) ||
                       isset($_GET['tech']) || isset($_GET['business']) || isset($_GET['sports']);

if (!$has_explicit_filter && $default_view !== 'all') {
    // Auto-redirect to apply default view
    $redirect_url = 'index.php?';
    switch ($default_view) {
        case 'tech':
            $redirect_url .= 'tech=1';
            break;
        case 'business':
            $redirect_url .= 'business=1';
            break;
        case 'sports':
            $redirect_url .= 'sports=1';
            break;
    }
    if ($date_filter !== '1day') {
        $redirect_url .= '&date=' . urlencode($date_filter);
    }
    header('Location: ' . $redirect_url);
    exit;
}

// Build query
$query = "SELECT a.id, a.title, a.url, a.published_date, a.summary, a.scraped_at, a.fullArticle, a.hasPaywall,
          s.name as source_name, s.id as source_id,
          GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') as categories,
          GROUP_CONCAT(c.id ORDER BY c.name SEPARATOR ',') as category_ids
          FROM articles a
          LEFT JOIN sources s ON a.source_id = s.id
          LEFT JOIN article_categories ac ON a.id = ac.article_id
          LEFT JOIN categories c ON ac.category_id = c.id";

$where = [];
$params = [];
$types = '';

// Tech filter - show only technology categories
$tech_filter = isset($_GET['tech']) && $_GET['tech'] == '1';
// Business filter - show only business categories
$business_filter = isset($_GET['business']) && $_GET['business'] == '1';
// Sports filter - show only sports categories
$sports_filter = isset($_GET['sports']) && $_GET['sports'] == '1';

if ($tech_filter) {
    // Technology categories: 2=Technology, 7=Automotive, 8=Media, 16=Crypto, 17=AI&ML, 18=Cybersecurity, 19=Cloud, 20=Hardware, 21=Software, 22=Robotics
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN (2, 7, 8, 16, 17, 18, 19, 20, 21, 22))";
} elseif ($business_filter) {
    // Business categories: 1=Finance, 3=Retail, 9=Economy, 10=Markets, 11=Leadership, 12=Startups, 13=Global Business, 14=Legal, 15=Labor
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN (1, 3, 9, 10, 11, 12, 13, 14, 15))";
} elseif ($sports_filter) {
    // Sports categories: 23=Sports
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN (23))";
} elseif (!empty($category_filter)) {
    // Handle multiple category selection
    $placeholders = implode(',', array_fill(0, count($category_filter), '?'));
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN ($placeholders))";
    foreach ($category_filter as $cat_id) {
        $params[] = $cat_id;
        $types .= 'i';
    }
}

// Filter sources by user visibility: non-admins see base sources + their own linked sources
if ($current_user && $current_user['isAdmin'] != 'Y') {
    $where[] = "(s.isBase = 'Y' OR (s.isBase = 'N' AND s.id IN (SELECT source_id FROM users_sources WHERE user_id = ?)))";
    $params[] = (int)$current_user['id'];
    $types .= 'i';
}

// ALWAYS apply user preference filters first (if they exist)
// Apply preferred sources filter
if (!empty($preferred_sources)) {
    $placeholders = implode(',', array_fill(0, count($preferred_sources), '?'));
    $where[] = "a.source_id IN ($placeholders)";
    foreach ($preferred_sources as $src_id) {
        $params[] = $src_id;
        $types .= 'i';
    }
}

// Apply preferred categories filter
if (!empty($preferred_categories)) {
    $placeholders = implode(',', array_fill(0, count($preferred_categories), '?'));
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN ($placeholders))";
    foreach ($preferred_categories as $cat_id) {
        $params[] = $cat_id;
        $types .= 'i';
    }
}

// Then apply quick filter overrides on top
if (!empty($source_filter)) {
    // Handle multiple source selection
    $placeholders = implode(',', array_fill(0, count($source_filter), '?'));
    $where[] = "a.source_id IN ($placeholders)";
    foreach ($source_filter as $src_id) {
        $params[] = $src_id;
        $types .= 'i';
    }
}

if ($search) {
    $where[] = "(a.title LIKE ? OR a.summary LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

// Always show only summarized articles
$where[] = "a.summary IS NOT NULL AND a.summary != ''";

if ($date_filter && $date_filter !== 'all') {
    switch ($date_filter) {
        case '1day':
            $where[] = "a.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
            break;
        case '1week':
            $where[] = "a.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
            break;
        case '1month':
            $where[] = "a.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            break;
    }
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

$query .= " GROUP BY a.id";

// Get total count for pagination
$count_query = "SELECT COUNT(DISTINCT a.id) as total FROM articles a
                LEFT JOIN sources s ON a.source_id = s.id
                LEFT JOIN article_categories ac ON a.id = ac.article_id
                LEFT JOIN categories c ON ac.category_id = c.id";
if (!empty($where)) {
    $count_query .= " WHERE " . implode(" AND ", $where);
}

$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_articles = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_articles / $page_size);
$count_stmt->close();

// Add pagination to main query
$offset = ($page - 1) * $page_size;
$query .= " ORDER BY a.scraped_at DESC, a.published_date DESC LIMIT ? OFFSET ?";
$params[] = $page_size;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get all categories for filter with counts based on current filters
// Use subquery to count matching articles while keeping all categories
$categories_query = "SELECT c.id, c.name,
    (SELECT COUNT(DISTINCT a2.id)
     FROM articles a2
     JOIN article_categories ac2 ON a2.id = ac2.article_id
     WHERE ac2.category_id = c.id";

$cat_conditions = [];
$cat_params = [];
$cat_types = '';

// Apply same filters as main query (except category filter)
if (!empty($source_filter)) {
    $placeholders = implode(',', array_fill(0, count($source_filter), '?'));
    $cat_conditions[] = "a2.source_id IN ($placeholders)";
    foreach ($source_filter as $src_id) {
        $cat_params[] = $src_id;
        $cat_types .= 'i';
    }
}

if ($search) {
    $cat_conditions[] = "(a2.title LIKE ? OR a2.summary LIKE ?)";
    $search_param = "%{$search}%";
    $cat_params[] = $search_param;
    $cat_params[] = $search_param;
    $cat_types .= 'ss';
}

// Always show only summarized articles
$cat_conditions[] = "a2.summary IS NOT NULL AND a2.summary != ''";

if ($date_filter && $date_filter !== 'all') {
    switch ($date_filter) {
        case '1day':
            $cat_conditions[] = "a2.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
            break;
        case '1week':
            $cat_conditions[] = "a2.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
            break;
        case '1month':
            $cat_conditions[] = "a2.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            break;
    }
}

if (!empty($cat_conditions)) {
    $categories_query .= " AND " . implode(" AND ", $cat_conditions);
}

$categories_query .= ") as article_count
    FROM categories c
    ORDER BY c.name";

if (!empty($cat_params)) {
    $cat_stmt = $conn->prepare($categories_query);
    $cat_stmt->bind_param($cat_types, ...$cat_params);
    $cat_stmt->execute();
    $categories_result = $cat_stmt->get_result();
} else {
    $categories_result = $conn->query($categories_query);
}

// Get all sources for filter with counts based on current filters
// Use subquery to count matching articles while keeping all sources
$sources_query = "SELECT s.id, s.name,
    (SELECT COUNT(DISTINCT a2.id)
     FROM articles a2
     WHERE a2.source_id = s.id";

$sources_conditions = [];
$sources_params = [];
$sources_types = '';

// Apply same filters as main query (except source filter)
if (!empty($category_filter)) {
    $placeholders = implode(',', array_fill(0, count($category_filter), '?'));
    $sources_conditions[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a2.id AND category_id IN ($placeholders))";
    foreach ($category_filter as $cat_id) {
        $sources_params[] = $cat_id;
        $sources_types .= 'i';
    }
}

if ($search) {
    $sources_conditions[] = "(a2.title LIKE ? OR a2.summary LIKE ?)";
    $search_param = "%{$search}%";
    $sources_params[] = $search_param;
    $sources_params[] = $search_param;
    $sources_types .= 'ss';
}

// Always show only summarized articles
$sources_conditions[] = "a2.summary IS NOT NULL AND a2.summary != ''";

if ($date_filter && $date_filter !== 'all') {
    switch ($date_filter) {
        case '1day':
            $sources_conditions[] = "a2.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
            break;
        case '1week':
            $sources_conditions[] = "a2.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
            break;
        case '1month':
            $sources_conditions[] = "a2.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            break;
    }
}

if (!empty($sources_conditions)) {
    $sources_query .= " AND " . implode(" AND ", $sources_conditions);
}

$sources_query .= ") as article_count
    FROM sources s
    WHERE s.enabled = 1";

// Filter sources by user visibility: base sources + user's linked sources
if ($current_user && $current_user['isAdmin'] != 'Y') {
    $sources_query .= " AND (s.isBase = 'Y' OR (s.isBase = 'N' AND s.id IN (SELECT source_id FROM users_sources WHERE user_id = ?)))";
    $sources_params[] = (int)$current_user['id'];
    $sources_types .= 'i';
}

$sources_query .= " ORDER BY s.name";

if (!empty($sources_params)) {
    $sources_stmt = $conn->prepare($sources_query);
    $sources_stmt->bind_param($sources_types, ...$sources_params);
    $sources_stmt->execute();
    $sources_result = $sources_stmt->get_result();
} else {
    $sources_result = $conn->query($sources_query);
}

// Get all sources with main category for sources modal
if ($current_user && $current_user['isAdmin'] != 'Y') {
    $all_sources_stmt = $conn->prepare("SELECT id, name, mainCategory, enabled, articles_count FROM sources WHERE isBase = 'Y' OR (isBase = 'N' AND id IN (SELECT source_id FROM users_sources WHERE user_id = ?)) ORDER BY name ASC");
    $all_sources_stmt->bind_param("i", $current_user['id']);
    $all_sources_stmt->execute();
    $all_sources_result = $all_sources_stmt->get_result();
} else {
    $all_sources_result = $conn->query("SELECT id, name, mainCategory, enabled, articles_count FROM sources ORDER BY name ASC");
}
$all_sources = [];
while ($source = $all_sources_result->fetch_assoc()) {
    $all_sources[] = $source;
}

// Get count of unsummarized articles (excluding failed)
$unsummarized_query = "SELECT COUNT(*) as count FROM articles
                       WHERE (summary IS NULL OR summary = '')
                       AND (isSummaryFailed IS NULL OR isSummaryFailed != 'Y')";
$unsummarized_count = $conn->query($unsummarized_query)->fetch_assoc()['count'];

// Get last scrape time
$last_scrape_query = "SELECT MAX(scraped_at) as last_scrape FROM articles";
$last_scrape_result = $conn->query($last_scrape_query)->fetch_assoc();
$last_scrape_time = $last_scrape_result['last_scrape'] ?? 'Never';
if ($last_scrape_time !== 'Never') {
    $last_scrape_formatted = date('M j, Y g:i A', strtotime($last_scrape_time));
} else {
    $last_scrape_formatted = 'Never';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="600">
    <title>News Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #1e5128 0%, #2d6a4f 50%, #52b788 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }

        header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
        }

        header > * {
            min-width: 0;
        }

        h1 {
            color: #333;
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #666;
            font-size: 1.1em;
        }

        .source-badge {
            display: inline-block;
            background: #e9ecef;
            color: #495057;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 0.7em;
            font-weight: 600;
            margin-left: 10px;
        }

        .controls {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #2563eb;
        }

        .category-filter select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: border-color 0.3s;
        }

        .category-filter select:focus {
            outline: none;
            border-color: #2563eb;
        }

        .btn {
            padding: 12px 25px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.01em;
            transition: background 0.2s, box-shadow 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background: #1d4ed8;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: #64748b;
        }

        .btn-secondary:hover {
            background: #475569;
            box-shadow: 0 2px 8px rgba(100, 116, 139, 0.3);
        }

        .btn-success {
            background: #2563eb;
        }

        .btn-success:hover {
            background: #1d4ed8;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        .btn-danger {
            background: #dc2626;
        }

        .btn-danger:hover {
            background: #b91c1c;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .spinner-icon {
            display: inline-block;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .tooltip-wrapper {
            position: relative;
            display: inline-block;
        }

        .tooltip-wrapper .tooltip-text {
            visibility: hidden;
            background-color: #333;
            color: #fff;
            text-align: center;
            padding: 8px 12px;
            border-radius: 6px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 13px;
        }

        .tooltip-wrapper .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #333 transparent transparent transparent;
        }

        .tooltip-wrapper:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        .articles-grid {
            display: grid;
            gap: 20px;
        }

        .article-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .article-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }

        .article-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
            gap: 15px;
        }

        .article-title {
            flex: 1;
        }

        .article-title h2 {
            color: #333;
            font-size: 1.3em;
            margin-bottom: 5px;
            line-height: 1.3;
        }

        .article-title a {
            color: #333;
            text-decoration: none;
            transition: color 0.3s;
        }

        .article-title a:hover {
            color: #2563eb;
        }

        .article-date {
            background: #f0f0f0;
            padding: 6px 12px;
            border-radius: 5px;
            font-size: 0.85em;
            color: #666;
            white-space: nowrap;
        }

        .article-summary {
            color: #555;
            line-height: 1.5;
            margin-bottom: 0;
            margin-top: 10px;
            font-size: 0.95em;
        }

        .summary-hidden {
            display: none;
        }

        .summary-visible {
            display: block;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0 0 0;
            border-left: 4px solid #2563eb;
            font-size: 1.15em;
            line-height: 1.8;
        }

        .btn-read-summary {
            background: #2563eb;
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85em;
            font-weight: 600;
            letter-spacing: 0.01em;
            transition: background 0.2s, box-shadow 0.2s;
            white-space: nowrap;
        }

        .btn-read-summary:hover {
            background: #1d4ed8;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.3);
        }

        .btn-read-summary.active {
            background: #64748b;
        }

        .article-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-top: 0;
        }

        .article-categories {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .category-tag {
            background: #2563eb;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            white-space: nowrap;
            display: inline-block;
            transition: all 0.2s ease;
        }

        .category-tag:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
            color: white;
            text-decoration: none;
        }

        .no-results {
            background: white;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
            color: #666;
        }

        .stats {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            color: #666;
        }

        .pagination {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-top: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .pagination a, .pagination span {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            font-size: 16px;
            font-weight: 500;
            min-width: 40px;
            text-align: center;
            display: inline-block;
        }

        .pagination a:hover {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .pagination .current {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
            font-weight: 700;
            font-size: 18px;
        }

        .pagination .disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow: auto;
        }

        .modal-content {
            background-color: white;
            margin: 50px auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 1000px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.3);
            max-height: 85vh;
            display: flex;
            flex-direction: column;
        }

        .fulltext-modal .modal-content {
            max-width: 900px;
        }

        .modal-header {
            padding: 20px 30px;
            background: #1e293b;
            color: white;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5em;
        }

        .close {
            color: white;
            font-size: 35px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            opacity: 0.8;
        }

        .modal-body {
            padding: 30px;
            overflow-y: auto;
            flex: 1;
        }

        .fulltext-content {
            font-size: 1.1em;
            line-height: 1.8;
            color: #333;
            font-family: Georgia, 'Times New Roman', serif;
        }

        .fulltext-content p {
            margin-bottom: 1em;
        }

        .article-meta {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2563eb;
        }

        .article-meta h3 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .article-meta a {
            color: #2563eb;
            text-decoration: none;
        }

        .article-meta a:hover {
            text-decoration: underline;
        }

        .error-summary {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .error-stat {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 8px;
            flex: 1;
            min-width: 150px;
            border-left: 4px solid #dc2626;
        }

        .error-stat-label {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 5px;
        }

        .error-stat-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #333;
        }

        .error-item {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            transition: box-shadow 0.3s;
        }

        .error-item:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .error-item.severity-high {
            border-left: 4px solid #dc2626;
        }

        .error-item.severity-medium {
            border-left: 4px solid #ffc107;
        }

        .error-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .error-type {
            font-weight: bold;
            color: #dc2626;
            font-size: 1.1em;
        }

        .error-timestamp {
            font-size: 0.9em;
            color: #555;
            font-weight: 600;
            background: #f0f0f0;
            padding: 3px 8px;
            border-radius: 3px;
        }

        .error-file {
            font-size: 0.85em;
            color: #2563eb;
            margin-bottom: 8px;
        }

        .error-message {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.9em;
            color: #333;
            word-break: break-all;
        }

        .no-errors {
            text-align: center;
            padding: 40px;
            color: #2563eb;
        }

        .no-errors h3 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            h1 {
                font-size: 1.5em;
            }

            .subtitle {
                font-size: 0.95em;
            }

            header {
                flex-direction: column;
                padding: 20px;
                gap: 15px;
            }

            .controls {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .article-card {
                padding: 15px;
            }

            .article-header {
                flex-direction: column;
            }

            .article-title h2 {
                font-size: 1.1em;
            }

            .article-footer {
                flex-direction: column;
                align-items: flex-start;
            }

            .article-categories {
                justify-content: flex-start;
                width: 100%;
            }

            .btn-read-summary {
                padding: 10px 16px;
                font-size: 0.9em;
            }

            .modal-content {
                width: 98%;
                margin: 10px auto;
                max-height: 95vh;
            }

            .modal-header {
                padding: 15px 20px;
            }

            .modal-header h2 {
                font-size: 1.2em;
            }

            .modal-body {
                padding: 15px;
            }

            .error-summary {
                flex-direction: column;
            }

            .header-actions {
                align-items: stretch !important;
                width: 100%;
            }

            .header-buttons-row {
                flex-wrap: wrap !important;
                justify-content: center !important;
            }

            .header-buttons-row .btn,
            .header-buttons-row .tooltip-wrapper {
                flex: 1 1 auto;
                min-width: 0;
            }

            .quick-filters-row {
                justify-content: center;
            }

            .search-form {
                width: 100%;
                margin-left: 0 !important;
            }

            .search-form input[type="text"] {
                width: 100% !important;
                min-width: 0 !important;
            }

            .filter-controls-row {
                justify-content: center;
            }

            .pagination {
                padding: 15px;
                gap: 8px;
                flex-direction: column;
                align-items: center;
            }

            .pagination-stats {
                text-align: center;
                width: 100%;
            }

            .pagination-controls {
                justify-content: center;
            }

            .pagination a, .pagination span {
                padding: 8px 10px;
                font-size: 14px;
                min-width: 36px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 6px;
            }

            h1 {
                font-size: 1.3em;
            }

            .subtitle {
                font-size: 0.85em;
            }

            header {
                padding: 15px;
            }

            .header-buttons-row {
                gap: 6px !important;
            }

            .header-buttons-row .btn {
                padding: 10px 12px !important;
                font-size: 13px !important;
                min-width: 0 !important;
                height: auto !important;
            }

            .quick-filters-row .btn {
                padding: 10px 14px !important;
                font-size: 13px;
            }

            .article-card {
                padding: 12px;
            }

            .article-title h2 {
                font-size: 1em;
            }

            .source-badge {
                display: block;
                margin-left: 0;
                margin-top: 5px;
            }

            .article-date {
                font-size: 0.8em;
            }

            .summary-visible {
                font-size: 1em;
                line-height: 1.6;
                padding: 12px;
            }

            .category-tag {
                font-size: 0.7em;
                padding: 4px 8px;
            }

            .modal-content {
                width: 100%;
                margin: 0;
                border-radius: 0;
                max-height: 100vh;
                min-height: 100vh;
            }

            .modal-header {
                border-radius: 0;
                flex-wrap: wrap;
                gap: 8px;
            }

            .modal-header h2 {
                font-size: 1.1em;
            }

            .fulltext-content {
                font-size: 1em;
                line-height: 1.6;
            }

            .pagination {
                padding: 10px;
            }

            .pagination a, .pagination span {
                padding: 6px 8px;
                font-size: 13px;
                min-width: 32px;
            }

            .pagination-controls {
                gap: 5px !important;
            }
        }

        /* Back to Top Button */
        #backToTop {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            font-size: 20px;
            cursor: pointer;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            transition: all 0.3s ease;
            opacity: 0;
            visibility: hidden;
        }

        #backToTop.show {
            opacity: 1;
            visibility: visible;
        }

        #backToTop:hover {
            background: #1d4ed8;
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.4);
        }

        #backToTop:active {
            transform: translateY(-1px);
        }

        /* Filter tooltip on hover */
        div:hover > .filter-tooltip {
            opacity: 1 !important;
            visibility: visible !important;
        }
    </style>
    <script>
        // Text-to-Speech functionality
        let currentSpeech = null;
        let currentArticleId = null;
        let preferredVoice = null;
        let voicesLoaded = false;

        // Load and cache preferred voice
        function loadPreferredVoice() {
            const voices = window.speechSynthesis.getVoices();
            if (voices.length === 0) return null;

            // Try British English female voices
            let voice = voices.find(v =>
                v.lang.startsWith('en-GB') &&
                (v.name.toLowerCase().includes('female') ||
                 v.name.includes('Samantha') ||
                 v.name.includes('Kate') ||
                 v.name.includes('Serena') ||
                 v.name.includes('Susan'))
            );

            // Try any British English voice
            if (!voice) {
                voice = voices.find(v => v.lang.startsWith('en-GB'));
            }

            // Try any female-sounding English voice
            if (!voice) {
                voice = voices.find(v =>
                    v.lang.startsWith('en') &&
                    (v.name.toLowerCase().includes('female') ||
                     v.name.includes('Samantha') ||
                     v.name.includes('Kate') ||
                     v.name.includes('Victoria') ||
                     v.name.includes('Zira'))
                );
            }

            if (voice) {
                console.log('Selected voice:', voice.name, voice.lang);
            }
            return voice;
        }

        // Initialize voices
        if (window.speechSynthesis) {
            // Try to load voices immediately
            preferredVoice = loadPreferredVoice();

            // Also listen for voiceschanged event
            window.speechSynthesis.onvoiceschanged = function() {
                if (!voicesLoaded) {
                    voicesLoaded = true;
                    preferredVoice = loadPreferredVoice();
                }
            };
        }

        function readAloud(articleId) {
            const btn = event.target;
            const summaryDiv = document.getElementById('summary-' + articleId);
            const summaryText = summaryDiv.textContent.trim();

            // If already reading this article, stop it
            if (currentSpeech && currentArticleId === articleId) {
                window.speechSynthesis.cancel();
                currentSpeech = null;
                currentArticleId = null;
                btn.textContent = '🔊 Read Aloud';
                btn.style.background = '#2563eb';
                return;
            }

            // Stop any other speech
            if (currentSpeech) {
                window.speechSynthesis.cancel();
                // Reset previous button
                const prevBtn = document.querySelector('[data-article-id="' + currentArticleId + '"]');
                if (prevBtn) {
                    prevBtn.textContent = '🔊 Read Aloud';
                    prevBtn.style.background = '#2563eb';
                }
            }

            // Check if browser supports speech synthesis
            if (!('speechSynthesis' in window)) {
                alert('Sorry, your browser does not support text-to-speech!');
                return;
            }

            // Make sure summary is visible
            if (summaryDiv.classList.contains('summary-hidden')) {
                summaryDiv.classList.remove('summary-hidden');
                summaryDiv.classList.add('summary-visible');
                const readBtn = document.querySelector('[onclick="toggleSummary(' + articleId + ')"]');
                if (readBtn) {
                    readBtn.textContent = '📕 Hide Summary';
                    readBtn.classList.add('active');
                }
            }

            // Create speech
            const utterance = new SpeechSynthesisUtterance(summaryText);
            utterance.rate = 1.0;  // Normal speed
            utterance.pitch = 1.0; // Normal pitch
            utterance.volume = 1.0; // Full volume

            // Use cached preferred voice, or try to load it now
            if (!preferredVoice) {
                preferredVoice = loadPreferredVoice();
            }

            if (preferredVoice) {
                utterance.voice = preferredVoice;
                utterance.lang = preferredVoice.lang; // Also set lang explicitly
                console.log('Speaking with:', preferredVoice.name, preferredVoice.lang);
            } else {
                // Fallback: set lang to British English even if no specific voice found
                utterance.lang = 'en-GB';
                console.log('No preferred voice found, using lang: en-GB');
            }

            // Update button state
            btn.textContent = '⏸️ Stop';
            btn.style.background = '#dc2626';
            btn.setAttribute('data-article-id', articleId);

            // Event handlers
            utterance.onend = function() {
                btn.textContent = '🔊 Read Aloud';
                btn.style.background = '#2563eb';
                currentSpeech = null;
                currentArticleId = null;
            };

            utterance.onerror = function(event) {
                btn.textContent = '🔊 Read Aloud';
                btn.style.background = '#2563eb';
                currentSpeech = null;
                currentArticleId = null;

                // Don't show error for user-initiated cancellations
                if (event.error && event.error !== 'canceled' && event.error !== 'interrupted') {
                    console.error('Speech synthesis error:', event.error);
                    alert('Error reading text. Please try again.');
                }
            };

            // Speak
            currentSpeech = utterance;
            currentArticleId = articleId;
            window.speechSynthesis.speak(utterance);
        }

        function toggleSummary(articleId) {
            const summaryDiv = document.getElementById('summary-' + articleId);
            const btn = event.target;

            if (summaryDiv.classList.contains('summary-hidden')) {
                summaryDiv.classList.remove('summary-hidden');
                summaryDiv.classList.add('summary-visible');
                btn.textContent = '📕 Hide Summary';
                btn.classList.add('active');
            } else {
                summaryDiv.classList.remove('summary-visible');
                summaryDiv.classList.add('summary-hidden');
                btn.textContent = '📖 Read Summary';
                btn.classList.remove('active');
            }
        }

        function minimizeAll() {
            // Find all summary divs that are visible
            const allSummaries = document.querySelectorAll('.article-summary');
            const allButtons = document.querySelectorAll('.btn-read-summary');

            allSummaries.forEach(summary => {
                if (summary.classList.contains('summary-visible')) {
                    summary.classList.remove('summary-visible');
                    summary.classList.add('summary-hidden');
                }
            });

            allButtons.forEach(btn => {
                if (btn.textContent.includes('📕 Hide Summary')) {
                    btn.textContent = '📖 Read Summary';
                    btn.classList.remove('active');
                }
            });
        }

        function expandAll() {
            // Find all summary divs and expand them
            const allSummaries = document.querySelectorAll('.article-summary');
            const allButtons = document.querySelectorAll('.btn-read-summary');

            allSummaries.forEach(summary => {
                if (summary.classList.contains('summary-hidden')) {
                    summary.classList.remove('summary-hidden');
                    summary.classList.add('summary-visible');
                }
            });

            allButtons.forEach(btn => {
                if (btn.textContent.includes('📖 Read Summary')) {
                    btn.textContent = '📕 Hide Summary';
                    btn.classList.add('active');
                }
            });

            // Scroll to top of articles
            document.querySelector('.articles-grid').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Show/hide back to top button based on scroll position
        window.addEventListener('scroll', function() {
            const backToTopBtn = document.getElementById('backToTop');
            if (window.pageYOffset > 300) {
                backToTopBtn.classList.add('show');
            } else {
                backToTopBtn.classList.remove('show');
            }
        });

        function startScrape() {
            const btn = document.getElementById('scrapeBtn');
            const icon = document.getElementById('scrapeIcon');
            const text = document.getElementById('scrapeText');

            // Disable button and show spinner
            btn.disabled = true;
            icon.className = 'spinner-icon';
            icon.textContent = '⚙️';
            text.textContent = 'Scraping...';

            // Call API
            fetch('api_scrape.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        icon.textContent = '✓';
                        text.textContent = 'Complete!';

                        // Show popup with results
                        showScrapeResults(data);

                        // Reload page after 3 seconds
                        setTimeout(() => {
                            window.location.reload();
                        }, 3000);
                    } else {
                        icon.textContent = '✗';
                        text.textContent = 'Error';
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    icon.textContent = '✗';
                    text.textContent = 'Error';
                    btn.disabled = false;
                    console.error('Scrape error:', error);
                });
        }

        function startSummarize() {
            const btn = document.getElementById('summarizeBtn');
            const icon = document.getElementById('summarizeIcon');
            const text = document.getElementById('summarizeText');

            // Disable button and show animated spinner
            btn.disabled = true;
            icon.className = 'spinner-icon';
            icon.textContent = '⚙️';
            text.textContent = 'Summarizing...';

            // Store initial count
            const initialCount = <?php echo $unsummarized_count; ?>;

            // Call API to start background process
            fetch('api_summarize.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Keep spinner going and poll for completion
                        let pollCount = 0;
                        const maxPolls = 60; // 60 * 2 seconds = 2 minutes max

                        const pollInterval = setInterval(() => {
                            pollCount++;

                            // Check if max time exceeded
                            if (pollCount >= maxPolls) {
                                clearInterval(pollInterval);
                                icon.textContent = '✓';
                                text.textContent = 'Complete!';
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1000);
                                return;
                            }

                            // Poll database for unsummarized count
                            fetch('api_get_unsummarized_count.php')
                                .then(r => r.json())
                                .then(countData => {
                                    const currentCount = countData.count || 0;

                                    // Update button text with progress
                                    if (initialCount > 0) {
                                        const processed = initialCount - currentCount;
                                        text.textContent = `Processing (${processed}/${initialCount})`;
                                    }

                                    // Check if done (count is 0 or significantly reduced)
                                    if (currentCount === 0 || (initialCount > 0 && currentCount < initialCount * 0.2)) {
                                        clearInterval(pollInterval);
                                        icon.textContent = '✓';
                                        text.textContent = `Complete! (${initialCount - currentCount})`;
                                        setTimeout(() => {
                                            window.location.reload();
                                        }, 1500);
                                    }
                                })
                                .catch(err => {
                                    console.error('Poll error:', err);
                                });
                        }, 2000); // Poll every 2 seconds
                    } else {
                        icon.textContent = '✗';
                        text.textContent = 'Error';
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    icon.textContent = '✗';
                    text.textContent = 'Error';
                    btn.disabled = false;
                    console.error('Summarize error:', error);
                });
        }

        function toggleClearButton() {
            const input = document.getElementById('searchInput');
            const clearBtn = document.getElementById('clearBtn');
            clearBtn.style.display = input.value.length > 0 ? 'block' : 'none';
        }

        function clearSearchInput() {
            const input = document.getElementById('searchInput');
            input.value = '';
            toggleClearButton();

            // If there was a search parameter in URL, redirect without it
            const url = new URL(window.location.href);
            if (url.searchParams.has('search')) {
                url.searchParams.delete('search');
                window.location.href = url.toString();
            }
        }

        function checkErrors() {
            const modal = document.getElementById('errorsModal');
            const modalBody = document.getElementById('errorsList');
            const btn = document.getElementById('errorsBtn');
            const icon = document.getElementById('errorsIcon');
            const text = document.getElementById('errorsText');

            // Show modal and loading state
            modal.style.display = 'block';
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="spinner-icon" style="font-size: 3em;">⚙️</div><p>Checking logs for errors...</p></div>';

            // Disable button
            btn.disabled = true;
            icon.className = 'spinner-icon';

            // Fetch errors
            fetch('api_check_errors.php')
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    icon.className = '';

                    if (data.success) {
                        displayErrors(data);

                        // Update button badge
                        if (data.total_errors > 0) {
                            text.textContent = `Errors (${data.total_errors})`;
                        } else {
                            text.textContent = 'Errors';
                        }
                    } else {
                        modalBody.innerHTML = '<div class="no-errors"><h3>❌ Error</h3><p>Failed to load error log</p></div>';
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    icon.className = '';
                    modalBody.innerHTML = '<div class="no-errors"><h3>❌ Error</h3><p>Failed to fetch error data: ' + error.message + '</p></div>';
                    console.error('Error checking logs:', error);
                });
        }

        function displayErrors(data) {
            const modalBody = document.getElementById('errorsList');

            if (data.total_errors === 0) {
                modalBody.innerHTML = '<div class="no-errors"><h3>✅ No Errors Found</h3><p>All systems running smoothly!</p><p style="margin-top: 20px; color: #666;">Checked ' + data.checked_files + ' log files</p></div>';
                return;
            }

            let html = '<div class="error-summary">';
            html += '<div class="error-stat"><div class="error-stat-label">Total Errors</div><div class="error-stat-value">' + data.total_errors + '</div></div>';
            html += '<div class="error-stat"><div class="error-stat-label">Log Files Checked</div><div class="error-stat-value">' + data.checked_files + '</div></div>';
            html += '<div class="error-stat"><div class="error-stat-label">Last Checked</div><div class="error-stat-value" style="font-size: 1.2em;">' + data.timestamp + '</div></div>';
            html += '</div>';

            // Error counts by type
            if (data.error_counts) {
                html += '<div style="margin-bottom: 15px;"><strong>Error Types:</strong> ';
                const types = [];
                for (const [type, count] of Object.entries(data.error_counts)) {
                    types.push(type + ' (' + count + ')');
                }
                html += types.join(', ');
                html += '</div>';
            }

            // Error counts by source
            if (data.source_counts) {
                html += '<div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">';
                html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
                html += '<strong style="font-size: 1.1em;">📊 Errors by Source:</strong>';
                html += '<button id="showAllErrors" onclick="filterErrorsBySource(null)" style="display: none; padding: 6px 12px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9em;">Show All</button>';
                html += '</div>';
                html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">';
                for (const [source, count] of Object.entries(data.source_counts)) {
                    const percentage = Math.round((count / data.total_errors) * 100);
                    html += '<div class="source-filter-card" data-source="' + escapeHtml(source) + '" onclick="filterErrorsBySource(\'' + escapeHtml(source).replace(/'/g, "\\'") + '\')" style="background: white; padding: 10px; border-radius: 5px; border-left: 4px solid #dc2626; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.transform=\'translateY(-2px)\'; this.style.boxShadow=\'0 4px 8px rgba(0,0,0,0.1)\';" onmouseout="this.style.transform=\'translateY(0)\'; this.style.boxShadow=\'none\';">';
                    html += '<div style="font-weight: 600; color: #333;">' + escapeHtml(source) + '</div>';
                    html += '<div style="font-size: 1.3em; color: #dc2626; font-weight: 700;">' + count + '</div>';
                    html += '<div style="font-size: 0.85em; color: #666;">' + percentage + '% of total</div>';
                    html += '</div>';
                }
                html += '</div></div>';
            }

            // Individual errors
            html += '<h3 style="margin-bottom: 15px;" id="errorDetailsHeader">Error Details:</h3>';
            data.errors.forEach(error => {
                html += '<div class="error-item severity-' + error.severity + '" data-error-source="' + escapeHtml(error.source || 'Unknown') + '">';
                html += '<div class="error-header">';
                html += '<span class="error-type">' + escapeHtml(error.type) + '</span>';
                if (error.retry_count !== undefined) {
                    const retryBadge = error.retry_count > 0
                        ? '<span style="background: #fbbf24; color: #000; padding: 2px 8px; border-radius: 10px; font-size: 0.85em; margin-left: 8px;">🔄 Retry #' + error.retry_count + '</span>'
                        : '<span style="background: #64748b; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.85em; margin-left: 8px;">First attempt</span>';
                    html += retryBadge;
                }
                html += '<span class="error-timestamp">' + escapeHtml(error.timestamp) + '</span>';
                html += '</div>';
                html += '<div class="error-file">📁 ' + escapeHtml(error.file) + ' &nbsp;|&nbsp; 🌐 ' + escapeHtml(error.source || 'Unknown') + '</div>';
                html += '<div class="error-description" style="color: #dc2626; font-weight: 500; margin: 8px 0; font-size: 0.95em;">💡 ' + escapeHtml(error.description || '') + '</div>';
                html += '<div class="error-message">' + escapeHtml(error.line) + '</div>';
                html += '</div>';
            });

            modalBody.innerHTML = html;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function filterErrorsBySource(sourceName) {
            const errorItems = document.querySelectorAll('.error-item');
            const showAllBtn = document.getElementById('showAllErrors');
            const sourceCards = document.querySelectorAll('.source-filter-card');
            const header = document.getElementById('errorDetailsHeader');

            if (sourceName === null) {
                // Show all errors
                errorItems.forEach(item => {
                    item.style.display = 'block';
                });
                showAllBtn.style.display = 'none';
                header.textContent = 'Error Details:';

                // Reset card highlighting
                sourceCards.forEach(card => {
                    card.style.borderLeft = '4px solid #dc2626';
                    card.style.background = 'white';
                });
            } else {
                // Filter by source
                let visibleCount = 0;
                errorItems.forEach(item => {
                    const itemSource = item.getAttribute('data-error-source');
                    if (itemSource === sourceName) {
                        item.style.display = 'block';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });
                showAllBtn.style.display = 'inline-block';
                header.textContent = 'Error Details: ' + sourceName + ' (' + visibleCount + ')';

                // Highlight selected card
                sourceCards.forEach(card => {
                    if (card.getAttribute('data-source') === sourceName) {
                        card.style.borderLeft = '4px solid #2563eb';
                        card.style.background = '#eff6ff';
                    } else {
                        card.style.borderLeft = '4px solid #dc2626';
                        card.style.background = 'white';
                    }
                });
            }
        }

        function showScrapeResults(data) {
            const modal = document.getElementById('scrapeResultsModal');
            const modalBody = document.getElementById('scrapeResultsBody');

            let html = '<div style="text-align: center; padding: 20px;">';
            html += '<div style="font-size: 4em; margin-bottom: 20px;">✅</div>';
            html += '<h2 style="color: #2563eb; margin-bottom: 20px;">Scraping Complete!</h2>';

            // Stats grid
            html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 30px 0;">';

            // New articles
            html += '<div style="background: #dbeafe; padding: 20px; border-radius: 10px; border: 2px solid #2563eb;">';
            html += '<div style="font-size: 2.5em; font-weight: 700; color: #1e40af;">' + (data.new_articles || 0) + '</div>';
            html += '<div style="color: #1e40af; font-weight: 600; margin-top: 5px;">New Articles</div>';
            html += '</div>';

            // Total found (if available)
            if (data.total_found !== undefined) {
                html += '<div style="background: #e2e8f0; padding: 20px; border-radius: 10px; border: 2px solid #64748b;">';
                html += '<div style="font-size: 2.5em; font-weight: 700; color: #334155;">' + data.total_found + '</div>';
                html += '<div style="color: #334155; font-weight: 600; margin-top: 5px;">Total Found</div>';
                html += '</div>';
            }

            // Sources scraped (if available)
            if (data.sources_scraped !== undefined) {
                html += '<div style="background: #e2e8f0; padding: 20px; border-radius: 10px; border: 2px solid #64748b;">';
                html += '<div style="font-size: 2.5em; font-weight: 700; color: #334155;">' + data.sources_scraped + '</div>';
                html += '<div style="color: #334155; font-weight: 600; margin-top: 5px;">Sources</div>';
                html += '</div>';
            }

            html += '</div>';

            if (data.message) {
                html += '<p style="color: #666; margin-top: 15px;">' + escapeHtml(data.message) + '</p>';
            }

            html += '<p style="color: #999; margin-top: 20px; font-size: 0.9em;">Page will refresh automatically...</p>';
            html += '</div>';

            modalBody.innerHTML = html;
            modal.style.display = 'block';
        }

        function closeScrapeResultsModal() {
            document.getElementById('scrapeResultsModal').style.display = 'none';
        }

        function showSourcesModal() {
            const modal = document.getElementById('sourcesModal');
            modal.style.display = 'block';
        }

        function closeSourcesModal() {
            document.getElementById('sourcesModal').style.display = 'none';
        }

        function closeErrorModal() {
            document.getElementById('errorsModal').style.display = 'none';
        }

        function showFullText(articleId) {
            const fullTextDiv = document.getElementById('fulltext-' + articleId);
            const modal = document.getElementById('fulltextModal');
            const modalBody = document.getElementById('fulltextBody');

            if (!fullTextDiv) {
                alert('Full text not available for this article');
                return;
            }

            const title = fullTextDiv.getAttribute('data-title');
            const url = fullTextDiv.getAttribute('data-url');
            const source = fullTextDiv.getAttribute('data-source');
            const fullText = fullTextDiv.textContent;

            // Build modal content
            let html = '<div class="article-meta">';
            html += '<h3>' + escapeHtml(title) + '</h3>';
            html += '<p><strong>Source:</strong> ' + escapeHtml(source) + '</p>';
            html += '<p><strong>URL:</strong> <a href="' + escapeHtml(url) + '" target="_blank">' + escapeHtml(url) + '</a></p>';
            html += '</div>';
            html += '<div class="fulltext-content">';

            // Split into paragraphs and format, preserving line breaks
            const paragraphs = fullText.split('\n\n');
            paragraphs.forEach(para => {
                if (para.trim()) {
                    // Replace single newlines with <br> tags to preserve line breaks
                    const formattedPara = escapeHtml(para.trim()).replace(/\n/g, '<br>');
                    html += '<p>' + formattedPara + '</p>';
                }
            });

            html += '</div>';

            modalBody.innerHTML = html;
            modal.style.display = 'block';
        }

        function closeFulltextModal() {
            document.getElementById('fulltextModal').style.display = 'none';
        }

        // User Preferences Functions
        async function showPreferencesModal() {
            try {
                const response = await fetch('api_get_preferences.php');
                const data = await response.json();

                if (!data.success) {
                    alert('Error loading preferences: ' + data.error);
                    return;
                }

                // Populate sources checkboxes
                const sourcesDiv = document.getElementById('sourcesCheckboxes');
                sourcesDiv.innerHTML = '';
                data.sources.forEach(source => {
                    const isChecked = !data.preferences || !data.preferences.sources ||
                                     data.preferences.sources.length === 0 ||
                                     data.preferences.sources.includes(parseInt(source.id));
                    const label = document.createElement('label');
                    label.style.cssText = 'display: flex; align-items: center; cursor: pointer; padding: 5px;';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'pref-source';
                    checkbox.value = source.id;
                    checkbox.checked = isChecked;
                    checkbox.style.cssText = 'margin-right: 8px; cursor: pointer;';
                    const span = document.createElement('span');
                    span.textContent = source.name;
                    label.appendChild(checkbox);
                    label.appendChild(span);
                    sourcesDiv.appendChild(label);
                });

                // Populate categories checkboxes
                const categoriesDiv = document.getElementById('categoriesCheckboxes');
                categoriesDiv.innerHTML = '';
                data.categories.forEach(category => {
                    const isChecked = !data.preferences || !data.preferences.categories ||
                                     data.preferences.categories.length === 0 ||
                                     data.preferences.categories.includes(parseInt(category.id));
                    const label = document.createElement('label');
                    label.style.cssText = 'display: flex; align-items: center; cursor: pointer; padding: 5px;';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'pref-category';
                    checkbox.value = category.id;
                    checkbox.checked = isChecked;
                    checkbox.style.cssText = 'margin-right: 8px; cursor: pointer;';
                    const span = document.createElement('span');
                    span.textContent = category.name;
                    label.appendChild(checkbox);
                    label.appendChild(span);
                    categoriesDiv.appendChild(label);
                });

                // Set default view radio button
                const defaultView = (data.preferences && data.preferences.defaultView) ? data.preferences.defaultView : 'all';
                const defaultViewRadios = document.querySelectorAll('.pref-default-view');
                defaultViewRadios.forEach(radio => {
                    radio.checked = (radio.value === defaultView);
                });

                // Show modal
                document.getElementById('preferencesModal').style.display = 'block';
            } catch (error) {
                alert('Error loading preferences: ' + error.message);
            }
        }

        function closePreferencesModal() {
            document.getElementById('preferencesModal').style.display = 'none';
        }

        async function savePreferences() {
            try {
                // Collect checked sources
                const sources = Array.from(document.querySelectorAll('.pref-source:checked'))
                    .map(cb => parseInt(cb.value));

                // Collect checked categories
                const categories = Array.from(document.querySelectorAll('.pref-category:checked'))
                    .map(cb => parseInt(cb.value));

                // Get selected default view
                const defaultViewRadio = document.querySelector('.pref-default-view:checked');
                const defaultView = defaultViewRadio ? defaultViewRadio.value : 'all';

                // Save preferences
                const response = await fetch('api_save_preferences.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        sources: sources,
                        categories: categories,
                        defaultView: defaultView
                    })
                });

                const data = await response.json();

                if (data.success) {
                    alert('Preferences saved successfully!');
                    closePreferencesModal();
                    // Reload page to apply new preferences
                    window.location.reload();
                } else {
                    alert('Error saving preferences: ' + data.error);
                }
            } catch (error) {
                alert('Error saving preferences: ' + error.message);
            }
        }

        function toggleAllSources(checked) {
            document.querySelectorAll('.pref-source').forEach(cb => {
                cb.checked = checked;
            });
        }

        function toggleAllCategories(checked) {
            document.querySelectorAll('.pref-category').forEach(cb => {
                cb.checked = checked;
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const errorsModal = document.getElementById('errorsModal');
            const fulltextModal = document.getElementById('fulltextModal');
            const filtersModal = document.getElementById('filtersModal');
            const preferencesModal = document.getElementById('preferencesModal');

            if (event.target == errorsModal) {
                errorsModal.style.display = 'none';
            }
            if (event.target == fulltextModal) {
                fulltextModal.style.display = 'none';
            }
            if (event.target === filtersModal) {
                closeFiltersModal();
            }
            if (event.target === preferencesModal) {
                closePreferencesModal();
            }
            const addSourceModal = document.getElementById('addSourceModal');
            if (event.target === addSourceModal) {
                closeAddSourceModal();
            }
        }

        // Auto-expand summaries when coming from filter buttons
        window.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('expand') === '1') {
                // Small delay to ensure page is fully loaded
                setTimeout(function() {
                    expandAll();
                }, 300);
            }
        });

        // Filter Modal Functions
        function openFiltersModal() {
            document.getElementById('filtersModal').style.display = 'block';
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
            document.getElementById('filterToggleIcon').textContent = '▲';
        }

        function closeFiltersModal() {
            document.getElementById('filtersModal').style.display = 'none';
            document.body.style.overflow = 'auto'; // Restore scrolling
            document.getElementById('filterToggleIcon').textContent = '▼';
        }

        function applyFilters() {
            const params = new URLSearchParams();

            // Collect selected sources
            const totalSources = document.querySelectorAll('.source-filter').length;
            const checkedSources = document.querySelectorAll('.source-filter:checked');
            const sources = Array.from(checkedSources).map(cb => cb.value);

            // Apply source filter only if NOT all are selected
            if (sources.length > 0 && sources.length < totalSources) {
                sources.forEach(source => params.append('source[]', source));
            }

            // Collect selected categories
            const totalCategories = document.querySelectorAll('.category-filter').length;
            const checkedCategories = document.querySelectorAll('.category-filter:checked');
            const categories = Array.from(checkedCategories).map(cb => cb.value);

            // Apply category filter only if NOT all are selected
            if (categories.length > 0 && categories.length < totalCategories) {
                categories.forEach(category => params.append('category[]', category));
            }

            // Get selected date filter
            const dateFilter = document.querySelector('input[name="date-filter"]:checked');
            if (dateFilter && dateFilter.value !== '1day') {
                params.set('date', dateFilter.value);
            }

            // Build and navigate to URL
            const url = 'index.php' + (params.toString() ? '?' + params.toString() : '');
            window.location.href = url;
        }

        function selectAllFilters() {
            // Check all checkboxes
            document.querySelectorAll('.source-filter, .category-filter').forEach(cb => {
                cb.checked = true;
            });
        }

        function clearFilters() {
            // Uncheck all checkboxes
            document.querySelectorAll('.source-filter, .category-filter').forEach(cb => {
                cb.checked = false;
            });

            // Reset date filter to default (1day)
            const defaultDate = document.querySelector('input[name="date-filter"][value="1day"]');
            if (defaultDate) {
                defaultDate.checked = true;
            }

            // Stay on page - don't navigate
            // User can click "Apply Filters" to apply the cleared state
        }

        function clearSearchAndRefresh() {
            // Build URL with all current parameters except search
            const params = new URLSearchParams(window.location.search);
            params.delete('search');

            const url = 'index.php' + (params.toString() ? '?' + params.toString() : '');
            window.location.href = url;
        }

        // Select/Clear All functions
        function selectAllSources() {
            document.querySelectorAll('.source-filter').forEach(cb => {
                cb.checked = true;
            });
        }

        function clearAllSources() {
            document.querySelectorAll('.source-filter').forEach(cb => {
                cb.checked = false;
            });
        }

        function selectAllCategories() {
            document.querySelectorAll('.category-filter').forEach(cb => {
                cb.checked = true;
            });
        }

        function clearAllCategories() {
            document.querySelectorAll('.category-filter').forEach(cb => {
                cb.checked = false;
            });
        }

        // Helper: Click a category to select only that one
        function selectOnlyCategory(categoryId) {
            document.querySelectorAll('.category-filter').forEach(cb => {
                cb.checked = (cb.value == categoryId);
            });
        }

        // Helper: Click a source to select only that one
        function selectOnlySource(sourceId) {
            document.querySelectorAll('.source-filter').forEach(cb => {
                cb.checked = (cb.value == sourceId);
            });
        }

        // ========== Add Source Functions ==========

        function showAddSourceModal() {
            const btn = document.getElementById('addSourceBtn');
            const isAdmin = btn.dataset.isAdmin;
            const sourceLimit = btn.dataset.sourceLimit;
            const remaining = parseInt(btn.dataset.sourceCount) || 0;

            // Check if user has reached their limit (non-admins only)
            if (sourceLimit !== 'unlimited' && remaining <= 0) {
                alert('You have reached your limit (0 remaining). Please contact an admin to increase your limit.');
                return;
            }

            // Update source count display
            const countDisplay = document.getElementById('sourceCountDisplay');
            if (sourceLimit === 'unlimited') {
                countDisplay.textContent = 'unlimited sources';
            } else {
                countDisplay.textContent = 'You have ' + remaining + ' source' + (remaining !== 1 ? 's' : '') + ' remaining';
            }

            // Reset form and messages
            document.getElementById('addSourceForm').reset();
            document.getElementById('addSourceError').style.display = 'none';
            document.getElementById('addSourceSuccess').style.display = 'none';
            document.getElementById('addSourceSubmitBtn').disabled = false;
            document.getElementById('addSourceSubmitBtn').textContent = 'Add Source';
            clearAddSourceFieldErrors();
            updateCategoryRadioStyles();

            // Show modal
            document.getElementById('addSourceModal').style.display = 'block';
            document.body.style.overflow = 'hidden';

            // Focus on the name input
            setTimeout(function() {
                document.getElementById('newSourceName').focus();
            }, 100);
        }

        function closeAddSourceModal() {
            document.getElementById('addSourceModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function clearAddSourceFieldErrors() {
            document.getElementById('nameError').style.display = 'none';
            document.getElementById('urlError').style.display = 'none';
            document.getElementById('newSourceName').style.borderColor = '#e0e0e0';
            document.getElementById('newSourceUrl').style.borderColor = '#e0e0e0';
        }

        function validateAddSourceForm() {
            clearAddSourceFieldErrors();
            let isValid = true;

            const name = document.getElementById('newSourceName').value.trim();
            const url = document.getElementById('newSourceUrl').value.trim();

            if (!name || name.length < 2) {
                document.getElementById('nameError').textContent = 'Source name must be at least 2 characters.';
                document.getElementById('nameError').style.display = 'block';
                document.getElementById('newSourceName').style.borderColor = '#dc3545';
                isValid = false;
            }

            if (!url) {
                document.getElementById('urlError').textContent = 'URL is required.';
                document.getElementById('urlError').style.display = 'block';
                document.getElementById('newSourceUrl').style.borderColor = '#dc3545';
                isValid = false;
            } else {
                try {
                    const parsedUrl = new URL(url);
                    if (!['http:', 'https:'].includes(parsedUrl.protocol)) {
                        throw new Error('Invalid protocol');
                    }
                } catch (e) {
                    document.getElementById('urlError').textContent = 'Please enter a valid HTTP or HTTPS URL.';
                    document.getElementById('urlError').style.display = 'block';
                    document.getElementById('newSourceUrl').style.borderColor = '#dc3545';
                    isValid = false;
                }
            }

            return isValid;
        }

        async function submitAddSource(event) {
            event.preventDefault();

            if (!validateAddSourceForm()) {
                return;
            }

            const submitBtn = document.getElementById('addSourceSubmitBtn');
            const errorDiv = document.getElementById('addSourceError');
            const successDiv = document.getElementById('addSourceSuccess');

            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding...';
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';

            const name = document.getElementById('newSourceName').value.trim();
            const url = document.getElementById('newSourceUrl').value.trim();
            const category = document.querySelector('input[name="newSourceCategory"]:checked').value;

            try {
                const response = await fetch('api_source_add.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        name: name,
                        url: url,
                        mainCategory: category
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Show success message
                    successDiv.textContent = 'Source "' + name + '" added successfully!';
                    successDiv.style.display = 'block';

                    // Update remaining source count on the button
                    const btn = document.getElementById('addSourceBtn');
                    const sourceLimit = btn.dataset.sourceLimit;
                    if (sourceLimit !== 'unlimited') {
                        const newRemaining = Math.max((parseInt(btn.dataset.sourceCount) || 0) - 1, 0);
                        btn.dataset.sourceCount = newRemaining;

                        // Update count display
                        const countDisplay = document.getElementById('sourceCountDisplay');
                        countDisplay.textContent = 'You have ' + newRemaining + ' source' + (newRemaining !== 1 ? 's' : '') + ' remaining';

                        // Disable button if limit reached
                        if (newRemaining <= 0) {
                            btn.disabled = true;
                            btn.style.opacity = '0.5';
                            btn.style.cursor = 'not-allowed';
                            btn.title = 'No more sources available';
                        }
                    }

                    // Reset form after short delay and close
                    setTimeout(function() {
                        closeAddSourceModal();
                        window.location.reload();
                    }, 1500);
                } else {
                    errorDiv.textContent = data.error || 'Failed to add source. Please try again.';
                    errorDiv.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Add Source';
                }
            } catch (error) {
                errorDiv.textContent = 'Network error: ' + error.message;
                errorDiv.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Source';
            }
        }

        // Style radio buttons for category selection in Add Source modal
        function updateCategoryRadioStyles() {
            document.querySelectorAll('#addSourceForm input[name="newSourceCategory"]').forEach(function(radio) {
                const label = radio.parentElement;
                if (radio.checked) {
                    label.style.background = '#667eea';
                    label.style.color = 'white';
                    label.style.borderColor = '#667eea';
                } else {
                    label.style.background = '#e0e0e0';
                    label.style.color = '#333';
                    label.style.borderColor = '#ccc';
                }
            });
        }

        // Add event listeners for category radio button styling once DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#addSourceForm input[name="newSourceCategory"]').forEach(function(radio) {
                radio.addEventListener('change', updateCategoryRadioStyles);
            });
        });

        // Real-time validation on input
        document.addEventListener('DOMContentLoaded', function() {
            const nameInput = document.getElementById('newSourceName');
            const urlInput = document.getElementById('newSourceUrl');

            if (nameInput) {
                nameInput.addEventListener('input', function() {
                    if (this.value.trim().length >= 2) {
                        document.getElementById('nameError').style.display = 'none';
                        this.style.borderColor = '#e0e0e0';
                    }
                });
            }

            if (urlInput) {
                urlInput.addEventListener('input', function() {
                    if (this.value.trim()) {
                        try {
                            const parsedUrl = new URL(this.value.trim());
                            if (['http:', 'https:'].includes(parsedUrl.protocol)) {
                                document.getElementById('urlError').style.display = 'none';
                                this.style.borderColor = '#e0e0e0';
                            }
                        } catch (e) {
                            // Still invalid, keep error if shown
                        }
                    }
                });
            }
        });
    </script>
</head>
<body>
    <!-- Floating Back to Top Button -->
    <button id="backToTop" onclick="scrollToTop()" title="Back to Top">
        ▲
    </button>

    <div class="container">
        <header>
            <div>
                <h1>📰 News Dashboard</h1>
                <p class="subtitle">Latest business news articles, automatically scraped and categorized</p>
            </div>
            <div class="header-actions" style="display: flex; flex-direction: column; gap: 10px; align-items: flex-end;">
                <!-- Main Action Buttons Row -->
                <div class="header-buttons-row" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
                    <?php if ($current_user && $current_user['isAdmin'] == 'Y'): ?>
                    <div class="tooltip-wrapper">
                        <button id="scrapeBtn" class="btn btn-success" onclick="startScrape()" style="min-width: 120px; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">
                            <span id="scrapeIcon">🔄</span> <span id="scrapeText">Scrape</span>
                        </button>
                        <span class="tooltip-text">
                            Last scrape: <?php echo $last_scrape_formatted; ?>
                        </span>
                    </div>
                    <div class="tooltip-wrapper">
                        <button id="summarizeBtn" class="btn btn-success" onclick="startSummarize()" style="min-width: 120px; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">
                            <span id="summarizeIcon">📝</span> <span id="summarizeText">Summarize (<?php echo $unsummarized_count; ?>)</span>
                        </button>
                        <span class="tooltip-text">
                            <?php echo $unsummarized_count; ?> article<?php echo $unsummarized_count != 1 ? 's' : ''; ?> need summarization
                        </span>
                    </div>
                    <button id="errorsBtn" class="btn btn-danger" onclick="checkErrors()" style="min-width: 120px; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">
                        <span id="errorsIcon">⚠️</span> <span id="errorsText">Errors</span>
                    </button>
                    <a href="sources.php" class="btn" style="min-width: 120px; text-align: center; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">Sources</a>
                    <?php endif; ?>
                    <?php if ($current_user):
                        $is_admin = ($current_user['isAdmin'] == 'Y');
                        $remaining = isset($current_user['sourceCount']) ? (int)$current_user['sourceCount'] : 0;
                        $limit_reached = !$is_admin && $remaining <= 0;
                    ?>
                    <button id="addSourceBtn" onclick="showAddSourceModal()" class="btn btn-success" style="height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px; gap: 5px;<?php echo $limit_reached ? ' opacity: 0.5; cursor: not-allowed;' : ''; ?>"
                            data-is-admin="<?php echo $current_user['isAdmin']; ?>"
                            data-source-limit="<?php echo $is_admin ? 'unlimited' : '5'; ?>"
                            data-source-count="<?php echo $remaining; ?>"
                            <?php echo $limit_reached ? 'disabled title="No more sources available"' : ''; ?>>
                        + Add Source
                    </button>
                    <?php endif; ?>
                    <button onclick="showPreferencesModal()" class="btn" style="height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px; gap: 5px;">
                        ⚙️ Preferences
                    </button>
                    <a href="logout.php" class="btn btn-secondary" style="height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">Logout</a>
                </div>
                <!-- Management Buttons Row (Admin Only) -->
                <?php if ($current_user && $current_user['isAdmin'] == 'Y'): ?>
                <div class="header-buttons-row" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
                    <a href="admin_users.php" class="btn" style="text-align: center; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px;">
                        👥 Manage Users
                    </a>
                    <a href="admin_invites.php" class="btn" style="text-align: center; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px;">
                        🎫 Manage Codes
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <!-- Search & Filters -->
        <div class="controls" style="flex-direction: column; align-items: stretch;">
            <!-- Quick Filter Buttons -->
            <!-- Line 1: Category Buttons and Search -->
            <div class="quick-filters-row" style="margin-bottom: 8px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <a href="?date=1day"
                   class="btn"
                   style="<?php echo (!isset($_GET['tech']) && !isset($_GET['business']) && !isset($_GET['sports']) && empty($category_filter)) ? 'background: #2563eb; color: white;' : 'background: #f8f9fa; color: #334155; border: 2px solid #2563eb;'; ?> padding: 12px 20px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">
                    📰 All Articles
                </a>
                <a href="?tech=1&date=1day"
                   class="btn"
                   style="<?php echo isset($_GET['tech']) ? 'background: #2563eb; color: white;' : 'background: #f8f9fa; color: #334155; border: 2px solid #2563eb;'; ?> padding: 12px 20px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">
                    🖥️ Technology
                </a>
                <a href="?business=1&date=1day"
                   class="btn"
                   style="<?php echo isset($_GET['business']) ? 'background: #2563eb; color: white;' : 'background: #f8f9fa; color: #334155; border: 2px solid #2563eb;'; ?> padding: 12px 20px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">
                    💼 Business
                </a>
                <a href="?sports=1&date=1day"
                   class="btn"
                   style="<?php echo isset($_GET['sports']) ? 'background: #2563eb; color: white;' : 'background: #f8f9fa; color: #334155; border: 2px solid #2563eb;'; ?> padding: 12px 20px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">
                    ⚽ Sports
                </a>

                <!-- Search Form (aligned right on same line) -->
                <form class="search-form" method="GET" style="display: flex; gap: 8px; align-items: center; margin: 0; margin-left: auto;">
                    <div style="position: relative; display: inline-block;">
                        <input type="text"
                               id="searchInput"
                               name="search"
                               placeholder="Search articles..."
                               value="<?php echo htmlspecialchars($search); ?>"
                               style="padding: 12px 15px; padding-right: <?php echo !empty($search) ? '35px' : '15px'; ?>; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 14px; width: 250px; outline: none;">
                        <?php if (!empty($search)): ?>
                        <button type="button"
                                onclick="clearSearchAndRefresh();"
                                style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: #dc2626; color: white; border: none; border-radius: 3px; width: 22px; height: 22px; cursor: pointer; font-size: 14px; line-height: 1; padding: 0; display: flex; align-items: center; justify-content: center;"
                                onmouseover="this.style.background='#b91c1c';"
                                onmouseout="this.style.background='#dc2626';">
                            ✕
                        </button>
                        <?php endif; ?>
                    </div>
                    <button type="submit"
                            style="padding: 12px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; white-space: nowrap;">
                        🔍 Search
                    </button>
                    <?php foreach ($category_filter as $cat_id): ?>
                        <input type="hidden" name="category[]" value="<?php echo $cat_id; ?>">
                    <?php endforeach; ?>
                    <?php foreach ($source_filter as $src_id): ?>
                        <input type="hidden" name="source[]" value="<?php echo $src_id; ?>">
                    <?php endforeach; ?>
                    <?php if ($date_filter && $date_filter !== 'all'): ?>
                        <input type="hidden" name="date" value="<?php echo $date_filter; ?>">
                    <?php endif; ?>
                    <?php if ($tech_filter): ?>
                        <input type="hidden" name="tech" value="1">
                    <?php endif; ?>
                    <?php if ($business_filter): ?>
                        <input type="hidden" name="business" value="1">
                    <?php endif; ?>
                    <?php if ($sports_filter): ?>
                        <input type="hidden" name="sports" value="1">
                    <?php endif; ?>
                </form>
            </div>

            <!-- Line 2: Filter Button and Active Filters -->
            <div style="margin-bottom: 5px;">
                <div class="filter-controls-row" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <!-- Filter Button with Tooltip -->
                        <div style="position: relative; display: inline-block;">
                            <button onclick="openFiltersModal()"
                                    style="padding: 12px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; white-space: nowrap;">
                                🔍 Filters<?php if (!empty($source_filter) || !empty($category_filter)) echo ' (' . (count($source_filter) + count($category_filter)) . ')'; ?>
                            </button>
                        </div>

                        <!-- Current Sources Button -->
                        <button onclick="showSourcesModal()"
                                style="padding: 12px 20px; background: #64748b; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; white-space: nowrap;">
                            📰 Current Sources
                        </button>

                        <?php if (!empty($source_filter) || !empty($category_filter)): ?>
                        <div style="position: relative; display: inline-block;">
                            <div class="filter-tooltip" style="position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); margin-bottom: 8px; background: #333; color: white; padding: 10px 15px; border-radius: 5px; font-size: 12px; white-space: nowrap; opacity: 0; visibility: hidden; transition: opacity 0.2s, visibility 0.2s; z-index: 1000; pointer-events: none;">
                                <?php
                                $tooltip_parts = [];

                                // Show source filters
                                if (!empty($source_filter)) {
                                    $src_ids = implode(',', $source_filter);
                                    $src_result = $conn->query("SELECT name FROM sources WHERE id IN ($src_ids)");
                                    $sources = [];
                                    while ($src = $src_result->fetch_assoc()) {
                                        $sources[] = htmlspecialchars($src['name']);
                                    }
                                    if (!empty($sources)) {
                                        $tooltip_parts[] = '<strong>Sources:</strong> ' . implode(', ', $sources);
                                    }
                                }

                                // Show category filters
                                if (!empty($category_filter)) {
                                    $cat_ids = implode(',', $category_filter);
                                    $cat_result = $conn->query("SELECT name FROM categories WHERE id IN ($cat_ids)");
                                    $categories = [];
                                    while ($cat = $cat_result->fetch_assoc()) {
                                        $categories[] = htmlspecialchars($cat['name']);
                                    }
                                    if (!empty($categories)) {
                                        $tooltip_parts[] = '<strong>Categories:</strong> ' . implode(', ', $categories);
                                    }
                                }

                                echo implode('<br>', $tooltip_parts);
                                ?>
                                <div style="position: absolute; top: 100%; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 6px solid #333;"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Clear Filter Button -->
                        <?php if (!empty($source_filter) || !empty($category_filter) || !empty($search)): ?>
                        <a href="index.php<?php echo (!empty($date_filter) && $date_filter !== 'all') ? '?date=' . urlencode($date_filter) : ''; ?>"
                           style="padding: 12px 20px; background: #dc2626; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; white-space: nowrap; text-decoration: none; display: inline-block;"
                           onmouseover="this.style.background='#b91c1c';"
                           onmouseout="this.style.background='#dc2626';">
                            ✕ Clear Filters
                        </a>
                        <?php endif; ?>

                        <!-- Active Category Filters Display -->
                        <?php if (!empty($category_filter)): ?>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <?php
                            if (count($category_filter) > 0) {
                                $placeholders = implode(',', array_fill(0, count($category_filter), '?'));
                                $cat_display_stmt = $conn->prepare("SELECT id, name FROM categories WHERE id IN ($placeholders)");
                                if ($cat_display_stmt) {
                                    $cat_display_stmt->bind_param(str_repeat('i', count($category_filter)), ...$category_filter);
                                    $cat_display_stmt->execute();
                                    $cat_display_result = $cat_display_stmt->get_result();
                                    while ($cat = $cat_display_result->fetch_assoc()):
                                        // Build URL to remove this specific category filter
                                        $remaining_cats = array_diff($category_filter, [$cat['id']]);
                                        $remove_url = '?';
                                        $url_params = [];

                                        // Keep other category filters
                                        foreach ($remaining_cats as $remaining_cat) {
                                            $url_params[] = 'category[]=' . urlencode($remaining_cat);
                                        }

                                        // Keep source filters
                                        foreach ($source_filter as $src_id) {
                                            $url_params[] = 'source[]=' . urlencode($src_id);
                                        }

                                        // Keep search
                                        if (!empty($search)) {
                                            $url_params[] = 'search=' . urlencode($search);
                                        }

                                        // Keep date filter
                                        if (!empty($date_filter)) {
                                            $url_params[] = 'date=' . urlencode($date_filter);
                                        }

                                        $remove_url .= implode('&', $url_params);
                                        if (empty($url_params)) {
                                            $remove_url = 'index.php'; // Default to index if no params
                                        }
                            ?>
                                        <a href="<?php echo $remove_url; ?>"
                                           style="display: inline-flex; align-items: center; gap: 8px; background: #2563eb; color: white; padding: 8px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.2s ease;"
                                           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(37, 99, 235, 0.4)';"
                                           onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                            <span><?php echo htmlspecialchars($cat['name']); ?></span>
                                            <span style="font-size: 18px; line-height: 1; font-weight: bold; opacity: 0.9;">×</span>
                                        </a>
                            <?php
                                    endwhile;
                                    $cat_display_stmt->close();
                                }
                            }
                            ?>
                            </div>
                        <?php endif; ?>

                        <!-- Active Source Filters Display -->
                        <?php if (!empty($source_filter)): ?>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <?php
                            if (count($source_filter) > 0) {
                                $placeholders = implode(',', array_fill(0, count($source_filter), '?'));
                                $src_display_stmt = $conn->prepare("SELECT id, name FROM sources WHERE id IN ($placeholders)");
                                if ($src_display_stmt) {
                                    $src_display_stmt->bind_param(str_repeat('i', count($source_filter)), ...$source_filter);
                                    $src_display_stmt->execute();
                                    $src_display_result = $src_display_stmt->get_result();
                                    while ($src = $src_display_result->fetch_assoc()):
                                        // Build URL to remove this specific source filter
                                        $remaining_srcs = array_diff($source_filter, [$src['id']]);
                                        $remove_url = '?';
                                        $url_params = [];

                                        // Keep category filters
                                        foreach ($category_filter as $cat_id) {
                                            $url_params[] = 'category[]=' . urlencode($cat_id);
                                        }

                                        // Keep other source filters
                                        foreach ($remaining_srcs as $remaining_src) {
                                            $url_params[] = 'source[]=' . urlencode($remaining_src);
                                        }

                                        // Keep search
                                        if (!empty($search)) {
                                            $url_params[] = 'search=' . urlencode($search);
                                        }

                                        // Keep date filter
                                        if (!empty($date_filter)) {
                                            $url_params[] = 'date=' . urlencode($date_filter);
                                        }

                                        $remove_url .= implode('&', $url_params);
                                        if (empty($url_params)) {
                                            $remove_url = 'index.php'; // Default to index if no params
                                        }
                            ?>
                                        <a href="<?php echo $remove_url; ?>"
                                           style="display: inline-flex; align-items: center; gap: 8px; background: #64748b; color: white; padding: 8px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.2s ease;"
                                           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(100, 116, 139, 0.4)';"
                                           onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                            <span><?php echo htmlspecialchars($src['name']); ?></span>
                                            <span style="font-size: 18px; line-height: 1; font-weight: bold; opacity: 0.9;">×</span>
                                        </a>
                            <?php
                                    endwhile;
                                    $src_display_stmt->close();
                                }
                            }
                            ?>
                            </div>
                        <?php endif; ?>
                </div>
            </div>
            </div>
        </div>

        <!-- Filters Modal (Outside of controls container) -->
            <div id="filtersModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; overflow-y: auto;">
                <div onclick="event.stopPropagation()" style="background: white; margin: 30px auto; max-width: 1200px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); position: relative;">
                    <!-- Modal Header -->
                    <div style="padding: 20px 25px; background: #1e293b; border-radius: 10px 10px 0 0; display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="color: white; margin: 0;">🔍 Filter Articles</h2>
                        <button onclick="closeFiltersModal()" style="background: transparent; border: none; color: white; font-size: 32px; cursor: pointer; line-height: 1; padding: 0; width: 32px; height: 32px;">&times;</button>
                    </div>

                    <!-- Action Buttons -->
                    <div style="padding: 15px 25px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="selectAllFilters()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">
                            Select All
                        </button>
                        <button onclick="clearFilters()" style="padding: 10px 20px; background: #64748b; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">
                            Unselect All
                        </button>
                        <button onclick="closeFiltersModal()" style="padding: 10px 20px; background: #64748b; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">
                            Close
                        </button>
                    </div>

                    <!-- Modal Body -->
                    <div style="padding: 25px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px;">

                        <!-- Sources Checkboxes -->
                        <div>
                            <h3 style="margin-bottom: 10px; font-size: 14px; font-weight: 600; color: #2563eb;">SOURCES</h3>
                            <div style="margin-bottom: 10px; display: flex; gap: 5px;">
                                <button onclick="selectAllSources(); return false;" style="padding: 5px 10px; background: #2563eb; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                    Select All
                                </button>
                                <button onclick="clearAllSources(); return false;" style="padding: 5px 10px; background: #64748b; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                    Clear All
                                </button>
                            </div>
                            <?php
                            $sources_result->data_seek(0);
                            while ($src = $sources_result->fetch_assoc()):
                                // Skip if user has preferences and this source is not in them
                                if (!empty($preferred_sources) && !in_array($src['id'], $preferred_sources)) {
                                    continue;
                                }
                            ?>
                                <label style="display: block; margin-bottom: 8px; cursor: pointer;">
                                    <input type="checkbox" class="source-filter" value="<?php echo $src['id']; ?>"
                                           <?php echo (empty($source_filter) || in_array($src['id'], $source_filter)) ? 'checked' : ''; ?>
                                           style="margin-right: 8px;">
                                    <?php echo htmlspecialchars($src['name']); ?> <span style="color: #999;">(<?php echo $src['article_count']; ?>)</span>
                                </label>
                            <?php endwhile; ?>
                        </div>

                        <!-- Categories Checkboxes -->
                        <div>
                            <h3 style="margin-bottom: 10px; font-size: 14px; font-weight: 600; color: #2563eb;">CATEGORIES</h3>
                            <div style="margin-bottom: 10px; display: flex; gap: 5px;">
                                <button onclick="selectAllCategories(); return false;" style="padding: 5px 10px; background: #2563eb; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                    Select All
                                </button>
                                <button onclick="clearAllCategories(); return false;" style="padding: 5px 10px; background: #64748b; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                    Clear All
                                </button>
                            </div>
                            <div>
                            <?php
                            $categories_result->data_seek(0);
                            while ($cat = $categories_result->fetch_assoc()):
                                // Skip if user has preferences and this category is not in them
                                if (!empty($preferred_categories) && !in_array($cat['id'], $preferred_categories)) {
                                    continue;
                                }
                            ?>
                                <label style="display: block; margin-bottom: 8px; cursor: pointer; white-space: nowrap;">
                                    <input type="checkbox" class="category-filter" value="<?php echo $cat['id']; ?>"
                                           <?php echo (empty($category_filter) || in_array($cat['id'], $category_filter)) ? 'checked' : ''; ?>
                                           style="margin-right: 8px;">
                                    <?php echo htmlspecialchars($cat['name']); ?> <span style="color: #999;">(<?php echo $cat['article_count']; ?>)</span>
                                    <a href="javascript:void(0)" onclick="selectOnlyCategory(<?php echo $cat['id']; ?>); event.stopPropagation();"
                                       style="margin-left: 5px; font-size: 11px; color: #2563eb; text-decoration: none; font-weight: 600;">only</a>
                                </label>
                            <?php endwhile; ?>
                            </div>
                        </div>

                        <!-- Time Frame Radio Buttons -->
                        <div>
                            <h3 style="margin-bottom: 10px; font-size: 14px; font-weight: 600; color: #2563eb;">TIME FRAME</h3>
                            <label style="display: block; margin-bottom: 8px; cursor: pointer;">
                                <input type="radio" name="date-filter" value="1day"
                                       <?php echo (!$date_filter || $date_filter == '1day') ? 'checked' : ''; ?>
                                       style="margin-right: 8px;">
                                Last 24 Hours
                            </label>
                            <label style="display: block; margin-bottom: 8px; cursor: pointer;">
                                <input type="radio" name="date-filter" value="1week"
                                       <?php echo ($date_filter == '1week') ? 'checked' : ''; ?>
                                       style="margin-right: 8px;">
                                Last Week
                            </label>
                            <label style="display: block; margin-bottom: 8px; cursor: pointer;">
                                <input type="radio" name="date-filter" value="1month"
                                       <?php echo ($date_filter == '1month') ? 'checked' : ''; ?>
                                       style="margin-right: 8px;">
                                Last Month
                            </label>
                            <label style="display: block; margin-bottom: 8px; cursor: pointer;">
                                <input type="radio" name="date-filter" value="all"
                                       <?php echo ($date_filter == 'all') ? 'checked' : ''; ?>
                                       style="margin-right: 8px;">
                                All Time
                            </label>

                            <!-- Apply Filters Button -->
                            <button onclick="applyFilters()"
                                    onmouseover="this.style.background='#1d4ed8'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(37, 99, 235, 0.4)';"
                                    onmouseout="this.style.background='#2563eb'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px rgba(37, 99, 235, 0.3)';"
                                    style="width: 100%; margin-top: 20px; padding: 15px 25px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 16px; box-shadow: 0 4px 6px rgba(37, 99, 235, 0.3); transition: all 0.2s ease;">
                                Apply Filters
                            </button>
                        </div>

                    </div>
                    </div>

                </div>
            </div>

        <?php
        // Pagination helper function and params
        $query_params = [];
        if (!empty($category_filter)) $query_params['category'] = $category_filter;
        if (!empty($source_filter)) $query_params['source'] = $source_filter;
        if ($search) $query_params['search'] = $search;
        if ($date_filter && $date_filter !== 'all') $query_params['date'] = $date_filter;
        if ($page_size != 50) $query_params['size'] = $page_size;

        if (!function_exists('buildPageUrl')) {
            function buildPageUrl($page_num, $params) {
                $params['page'] = $page_num;
                return 'index.php?' . http_build_query($params);
            }
        }
        ?>

        <!-- Combined Stats & Pagination -->
        <div class="pagination" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; border-bottom: 1px solid #e0e0e0; padding-bottom: 10px; margin-bottom: 10px;">
            <!-- Stats -->
            <div class="pagination-stats" style="font-size: 14px; color: #666;">
                Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $result->num_rows, $total_articles); ?>
                of <?php echo $total_articles; ?> article<?php echo $total_articles != 1 ? 's' : ''; ?>
                (Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?>)
            </div>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-controls" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <!-- First Page -->
                <?php if ($page > 1): ?>
                    <a href="<?php echo buildPageUrl(1, $query_params); ?>">«</a>
                <?php else: ?>
                    <span class="disabled">«</span>
                <?php endif; ?>

                <!-- Previous Page -->
                <?php if ($page > 1): ?>
                    <a href="<?php echo buildPageUrl($page - 1, $query_params); ?>">‹</a>
                <?php else: ?>
                    <span class="disabled">‹</span>
                <?php endif; ?>

                <!-- Page Numbers -->
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);

                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo buildPageUrl($i, $query_params); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <!-- Next Page -->
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo buildPageUrl($page + 1, $query_params); ?>">›</a>
                <?php else: ?>
                    <span class="disabled">›</span>
                <?php endif; ?>

                <!-- Last Page -->
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo buildPageUrl($total_pages, $query_params); ?>">»</a>
                <?php else: ?>
                    <span class="disabled">»</span>
                <?php endif; ?>

                <!-- Page Size Selector -->
                <span style="margin-left: 20px; font-size: 14px; color: #666;">Size:</span>
                <form method="GET" style="margin: 0; display: inline;">
                    <select name="size" onchange="this.form.submit()" style="padding: 8px 12px; border-radius: 5px; border: 2px solid #e0e0e0; font-size: 14px;">
                        <option value="25" <?php echo $page_size == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $page_size == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $page_size == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo $page_size == 200 ? 'selected' : ''; ?>>200</option>
                    </select>
                    <?php foreach ($category_filter as $cat_id): ?>
                        <input type="hidden" name="category[]" value="<?php echo $cat_id; ?>">
                    <?php endforeach; ?>
                    <?php foreach ($source_filter as $src_id): ?>
                        <input type="hidden" name="source[]" value="<?php echo $src_id; ?>">
                    <?php endforeach; ?>
                    <?php if ($search): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                    <?php if ($date_filter && $date_filter !== 'all'): ?>
                        <input type="hidden" name="date" value="<?php echo $date_filter; ?>">
                    <?php endif; ?>
                </form>

                <!-- Expand/Minimize All Buttons -->
                <button onclick="expandAll()" class="btn" style="color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; margin-left: 15px;">
                    ▼ Expand All
                </button>
                <button onclick="minimizeAll()" class="btn btn-secondary" style="color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                    ▲ Minimize All
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="articles-grid">
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div class="article-card">
                        <div class="article-header">
                            <div class="article-title">
                                <h2>
                                    <?php echo htmlspecialchars($row['title']); ?>
                                    <?php if ($row['source_name']): ?>
                                        <span class="source-badge"><?php echo htmlspecialchars($row['source_name']); ?></span>
                                    <?php endif; ?>
                                </h2>
                            </div>
                            <div class="article-date">
                                <?php
                                    // Always show scraped time with hours/minutes in PST
                                    $dt = new DateTime($row['scraped_at']);
                                    $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
                                    echo $dt->format('M d, Y g:i A') . ' PST';
                                ?>
                            </div>
                        </div>

                        <?php if ($row['summary'] && trim($row['summary']) != '' && strlen(trim($row['summary'])) > 20): ?>
                            <div class="article-footer">
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <button class="btn-read-summary" onclick="toggleSummary(<?php echo $row['id']; ?>)">
                                        📖 Read Summary
                                    </button>
                                    <button class="btn-read-summary" onclick="readAloud(<?php echo $row['id']; ?>)">
                                        🔊 Read Aloud
                                    </button>
                                    <?php if ($row['fullArticle'] && trim($row['fullArticle']) != ''): ?>
                                    <button class="btn-read-summary" onclick="showFullText(<?php echo $row['id']; ?>)">
                                        📄 Full Text
                                    </button>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars($row['url']); ?>"
                                       target="_article"
                                       class="btn-read-summary"
                                       style="text-decoration: none; display: inline-flex; align-items: center;">
                                        🔗 Read Article<?php if (isset($row['hasPaywall']) && $row['hasPaywall'] === 'Y'): ?> <span style="color: #fbbf24; font-weight: bold;">(paywall)</span><?php endif; ?>
                                    </a>
                                </div>
                                <?php if ($row['categories']): ?>
                                    <div class="article-categories">
                                        <?php
                                            $cats = explode(', ', $row['categories']);
                                            $cat_ids = explode(',', $row['category_ids']);
                                            foreach ($cats as $index => $cat):
                                                $cat_id = $cat_ids[$index];
                                                // Build URL with just category filter (preserve date filter)
                                                $filter_url = '?category[]=' . urlencode($cat_id);
                                                if (!empty($date_filter)) {
                                                    $filter_url .= '&date=' . urlencode($date_filter);
                                                }
                                        ?>
                                            <a href="<?php echo $filter_url; ?>" class="category-tag" style="text-decoration: none; cursor: pointer;"><?php echo htmlspecialchars($cat); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div id="summary-<?php echo $row['id']; ?>" class="article-summary summary-hidden">
                                <?php echo nl2br(htmlspecialchars($row['summary'])); ?>
                            </div>
                            <?php if ($row['fullArticle'] && trim($row['fullArticle']) != ''): ?>
                            <div id="fulltext-<?php echo $row['id']; ?>" style="display: none;"
                                 data-title="<?php echo htmlspecialchars($row['title']); ?>"
                                 data-url="<?php echo htmlspecialchars($row['url']); ?>"
                                 data-source="<?php echo htmlspecialchars($row['source_name']); ?>">
                                <?php echo htmlspecialchars($row['fullArticle']); ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="article-footer">
                                <a href="<?php echo htmlspecialchars($row['url']); ?>"
                                   target="_article"
                                   class="btn-read-summary"
                                   style="text-decoration: none; display: inline-flex; align-items: center;">
                                    🔗 Read Article<?php if (isset($row['hasPaywall']) && $row['hasPaywall'] === 'Y'): ?> <span style="color: #fbbf24; font-weight: bold;">(paywall)</span><?php endif; ?>
                                </a>
                            </div>
                            <div class="article-summary" style="color: #999; font-style: italic;">
                                ⏳ Summary pending...
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-results">
                    <h3>No articles found</h3>
                    <p>Try running the scraper to fetch new articles</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Combined Stats & Pagination (Bottom) -->
        <div class="pagination" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;">
            <!-- Stats -->
            <div class="pagination-stats" style="font-size: 14px; color: #666;">
                Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $result->num_rows, $total_articles); ?>
                of <?php echo $total_articles; ?> article<?php echo $total_articles != 1 ? 's' : ''; ?>
                (Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?>)
            </div>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-controls" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <!-- First Page -->
                <?php if ($page > 1): ?>
                    <a href="<?php echo buildPageUrl(1, $query_params); ?>">«</a>
                <?php else: ?>
                    <span class="disabled">«</span>
                <?php endif; ?>

                <!-- Previous Page -->
                <?php if ($page > 1): ?>
                    <a href="<?php echo buildPageUrl($page - 1, $query_params); ?>">‹</a>
                <?php else: ?>
                    <span class="disabled">‹</span>
                <?php endif; ?>

                <!-- Page Numbers -->
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);

                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo buildPageUrl($i, $query_params); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <!-- Next Page -->
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo buildPageUrl($page + 1, $query_params); ?>">›</a>
                <?php else: ?>
                    <span class="disabled">›</span>
                <?php endif; ?>

                <!-- Last Page -->
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo buildPageUrl($total_pages, $query_params); ?>">»</a>
                <?php else: ?>
                    <span class="disabled">»</span>
                <?php endif; ?>

                <!-- Page Size Selector -->
                <span style="margin-left: 20px; font-size: 14px; color: #666;">Size:</span>
                <form method="GET" style="margin: 0; display: inline;">
                    <select name="size" onchange="this.form.submit()" style="padding: 8px 12px; border-radius: 5px; border: 2px solid #e0e0e0; font-size: 14px;">
                        <option value="25" <?php echo $page_size == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $page_size == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $page_size == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo $page_size == 200 ? 'selected' : ''; ?>>200</option>
                    </select>
                    <?php foreach ($category_filter as $cat_id): ?>
                        <input type="hidden" name="category[]" value="<?php echo $cat_id; ?>">
                    <?php endforeach; ?>
                    <?php foreach ($source_filter as $src_id): ?>
                        <input type="hidden" name="source[]" value="<?php echo $src_id; ?>">
                    <?php endforeach; ?>
                    <?php if ($search): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                    <?php if ($date_filter && $date_filter !== 'all'): ?>
                        <input type="hidden" name="date" value="<?php echo $date_filter; ?>">
                    <?php endif; ?>
                </form>

                <!-- Expand/Minimize All Buttons -->
                <button onclick="expandAll()" class="btn" style="color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; margin-left: 15px;">
                    ▼ Expand All
                </button>
                <button onclick="minimizeAll()" class="btn btn-secondary" style="color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                    ▲ Minimize All
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Error Modal -->
    <div id="errorsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>⚠️ Error Log Analysis</h2>
                <span class="close" onclick="closeErrorModal()">&times;</span>
            </div>
            <div class="modal-body" id="errorsList">
                <div style="text-align: center; padding: 40px;">
                    <p>Click "Errors" button to check logs</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Scrape Results Modal -->
    <div id="scrapeResultsModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2>🔄 Scrape Results</h2>
                <span class="close" onclick="closeScrapeResultsModal()">&times;</span>
            </div>
            <div class="modal-body" id="scrapeResultsBody">
                <!-- Results will be inserted here by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Full Text Modal -->
    <div id="fulltextModal" class="modal fulltext-modal">
        <div class="modal-content">
            <div class="modal-header" style="background: #1e293b;">
                <h2>📄 Full Article Text</h2>
                <span class="close" onclick="closeFulltextModal()">&times;</span>
            </div>
            <div class="modal-body" id="fulltextBody">
                <div style="text-align: center; padding: 40px;">
                    <p>Click "Full Text" button to read article</p>
                </div>
            </div>
        </div>
    </div>

    <!-- User Preferences Modal -->
    <div id="preferencesModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2>⚙️ User Preferences</h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button onclick="savePreferences()" class="btn btn-success">Save Preferences</button>
                    <button onclick="closePreferencesModal()" class="btn btn-secondary">Close</button>
                    <span class="close" onclick="closePreferencesModal()" style="margin-left: 5px;">&times;</span>
                </div>
            </div>
            <div class="modal-body" id="preferencesBody" style="padding: 30px;">
                <div style="margin-bottom: 30px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0; color: #333;">Preferred Sources</h3>
                        <div>
                            <button onclick="toggleAllSources(true)" class="btn" style="padding: 8px 16px; font-size: 13px;">Select All</button>
                            <button onclick="toggleAllSources(false)" class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px; margin-left: 10px;">Deselect All</button>
                        </div>
                    </div>
                    <div id="sourcesCheckboxes" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <!-- Sources will be loaded here by JavaScript -->
                    </div>
                </div>

                <div style="margin-bottom: 30px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0; color: #333;">Preferred Categories</h3>
                        <div>
                            <button onclick="toggleAllCategories(true)" class="btn" style="padding: 8px 16px; font-size: 13px;">Select All</button>
                            <button onclick="toggleAllCategories(false)" class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px; margin-left: 10px;">Deselect All</button>
                        </div>
                    </div>
                    <div id="categoriesCheckboxes" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <!-- Categories will be loaded here by JavaScript -->
                    </div>
                </div>

                <div style="margin-bottom: 30px;">
                    <h3 style="margin: 0 0 15px 0; color: #333;">Default View</h3>
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <label style="display: block; margin-bottom: 10px; cursor: pointer;">
                            <input type="radio" name="defaultView" value="all" class="pref-default-view" style="margin-right: 8px; cursor: pointer;">
                            <span>All Articles (no filter applied)</span>
                        </label>
                        <label style="display: block; margin-bottom: 10px; cursor: pointer;">
                            <input type="radio" name="defaultView" value="tech" class="pref-default-view" style="margin-right: 8px; cursor: pointer;">
                            <span>Technology</span>
                        </label>
                        <label style="display: block; margin-bottom: 10px; cursor: pointer;">
                            <input type="radio" name="defaultView" value="business" class="pref-default-view" style="margin-right: 8px; cursor: pointer;">
                            <span>Business</span>
                        </label>
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" name="defaultView" value="sports" class="pref-default-view" style="margin-right: 8px; cursor: pointer;">
                            <span>Sports</span>
                        </label>
                    </div>
                </div>

                <div style="border-top: 1px solid #e0e0e0; padding-top: 20px; margin-top: 20px;">
                    <p style="color: #666; font-size: 14px; margin-bottom: 0;">
                        <strong>Note:</strong> Selected sources and categories will always be applied as base filters. The default view determines what you see when first loading the page.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Source Modal -->
    <div id="addSourceModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h2>+ Add New Source</h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span id="sourceCountDisplay" style="font-size: 14px; color: #666;"></span>
                    <span class="close" onclick="closeAddSourceModal()">&times;</span>
                </div>
            </div>
            <div class="modal-body" style="padding: 30px;">
                <div id="addSourceError" style="display: none; padding: 12px; margin-bottom: 20px; border-radius: 5px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;"></div>
                <div id="addSourceSuccess" style="display: none; padding: 12px; margin-bottom: 20px; border-radius: 5px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb;"></div>

                <form id="addSourceForm" onsubmit="submitAddSource(event)">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Source Name <span style="color: #dc3545;">*</span></label>
                        <input type="text" id="newSourceName" required maxlength="100" placeholder="e.g., TechCrunch"
                               style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 14px;">
                        <small id="nameError" style="color: #dc3545; display: none; margin-top: 4px;"></small>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">URL <span style="color: #dc3545;">*</span></label>
                        <input type="url" id="newSourceUrl" required maxlength="500" placeholder="https://example.com/feed"
                               style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 14px;">
                        <small id="urlError" style="color: #dc3545; display: none; margin-top: 4px;"></small>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Category <span style="color: #dc3545;">*</span></label>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <label style="cursor: pointer; padding: 8px 16px; background: #e0e0e0; border: 2px solid #ccc; border-radius: 5px; font-weight: 600; transition: all 0.3s;">
                                <input type="radio" name="newSourceCategory" value="Business" checked style="display: none;">
                                Business
                            </label>
                            <label style="cursor: pointer; padding: 8px 16px; background: #e0e0e0; border: 2px solid #ccc; border-radius: 5px; font-weight: 600; transition: all 0.3s;">
                                <input type="radio" name="newSourceCategory" value="Technology" style="display: none;">
                                Technology
                            </label>
                            <label style="cursor: pointer; padding: 8px 16px; background: #e0e0e0; border: 2px solid #ccc; border-radius: 5px; font-weight: 600; transition: all 0.3s;">
                                <input type="radio" name="newSourceCategory" value="Sports" style="display: none;">
                                Sports
                            </label>
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                        <button type="button" onclick="closeAddSourceModal()" class="btn btn-secondary" style="padding: 10px 24px;">Cancel</button>
                        <button type="submit" id="addSourceSubmitBtn" class="btn btn-success" style="padding: 10px 24px;">Add Source</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
?>
