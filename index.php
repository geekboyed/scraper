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

// Load level 1 (parent) categories for dynamic UI
$level1_categories = get_level1_categories($conn);
$categories_grouped = get_categories_grouped($conn);

// Map level 1 category names to URL parameter slugs and icons
$category_slug_map = [
    'Deals' => 'deals',
    'Business' => 'business',
    'Technology' => 'tech',
    'Sports' => 'sports',
    'General' => 'general'
];
$category_icon_map = [
    'Deals' => '🏷️',
    'Business' => '💼',
    'Technology' => '🖥️',
    'Sports' => '⚽',
    'General' => '🌐'
];

// Get filter parameters
$category_filter = isset($_GET['category']) ? (is_array($_GET['category']) ? array_map('intval', $_GET['category']) : [(int)$_GET['category']]) : [];
$source_filter = isset($_GET['source']) ? (is_array($_GET['source']) ? array_map('intval', $_GET['source']) : [(int)$_GET['source']]) : [];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '1day'; // Default to '1day'
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$page_size = isset($_GET['size']) ? (int)$_GET['size'] : 50;

// Apply default view if no explicit filter is set in URL
$has_explicit_filter = !empty($category_filter) || !empty($source_filter) ||
                       isset($_GET['deals']) || isset($_GET['tech']) || isset($_GET['business']) || isset($_GET['sports']) || isset($_GET['general']);

if (!$has_explicit_filter && $default_view !== 'all') {
    // Auto-redirect to apply default view - supports dynamic parent category names
    $valid_views = ['deals', 'tech', 'business', 'sports', 'general'];
    if (in_array($default_view, $valid_views)) {
        $redirect_url = 'index.php?' . urlencode($default_view) . '=1';
        if ($date_filter !== '1day') {
            $redirect_url .= '&date=' . urlencode($date_filter);
        }
        header('Location: ' . $redirect_url);
        exit;
    }
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

// Parent category filter flags (used in template for hidden form fields)
$deals_filter = isset($_GET['deals']) && $_GET['deals'] == '1';
$tech_filter = isset($_GET['tech']) && $_GET['tech'] == '1';
$business_filter = isset($_GET['business']) && $_GET['business'] == '1';
$sports_filter = isset($_GET['sports']) && $_GET['sports'] == '1';
$general_filter = isset($_GET['general']) && $_GET['general'] == '1';

// Map URL parameter names to parent category names
$parent_filter_map = [
    'deals' => 'Deals',
    'tech' => 'Technology',
    'business' => 'Business',
    'sports' => 'Sports',
    'general' => 'General'
];

// Check which parent filter is active
$active_parent_filter = null;
foreach ($parent_filter_map as $param => $parent_name) {
    if (isset($_GET[$param]) && $_GET[$param] == '1') {
        $active_parent_filter = $parent_name;
        break;
    }
}

// DEALS MODE: If deals filter is active, query deals table instead of articles
if ($deals_filter) {
    // Build deals query with proper aggregation to prevent duplicates
    $query = "SELECT MAX(d.id) as id, d.product_name as title, d.deal_url as url,
              MAX(d.posted_at) as published_date,
              MAX(d.description) as summary, MAX(d.scraped_at) as scraped_at,
              MAX(d.price) as price, MAX(d.price_text) as price_text,
              MAX(d.original_price) as original_price,
              MAX(d.discount_percentage) as discount_percentage,
              MAX(d.store_name) as store_name, MAX(d.image_url) as image_url,
              MAX(d.votes_up) as votes_up, MAX(d.votes_down) as votes_down,
              MAX(d.comments_count) as comments_count, MAX(d.deal_type) as deal_type,
              MAX(d.category) as category, MAX(d.category) as categories,
              MAX(d.coupon_code) as coupon_code, MAX(d.expires_at) as expires_at,
              MAX(s.name) as source_name, MAX(s.id) as source_id
              FROM deals d
              LEFT JOIN sources s ON d.source_id = s.id";

    $where = [];
    $params = [];

    // Only show active deals that haven't expired
    $where[] = "d.is_active = 'Y'";
    $where[] = "(d.expires_at IS NULL OR d.expires_at > NOW())";

    // Only show deals with actual prices (filter out cashback-only deals)
    $where[] = "(d.price IS NOT NULL OR d.price_text IS NOT NULL)";

    // Search filter
    if ($search) {
        $where[] = "(d.product_name LIKE ? OR d.description LIKE ? OR d.store_name LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // Date filter
    if ($date_filter && $date_filter !== 'all') {
        switch ($date_filter) {
            case '1day':
                $where[] = "d.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
                break;
            case '1week':
                $where[] = "d.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                break;
            case '1month':
                $where[] = "d.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                break;
        }
    }

    // Source filter
    if (!empty($source_filter)) {
        $placeholders = implode(',', array_fill(0, count($source_filter), '?'));
        $where[] = "d.source_id IN ($placeholders)";
        foreach ($source_filter as $src_id) {
            $params[] = $src_id;
        }
    }

    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }

    // Group by product_name and deal_url to eliminate duplicates
    $query .= " GROUP BY d.product_name, d.deal_url";

    // Get total count for pagination
    $count_query = "SELECT COUNT(DISTINCT d.id) as total FROM deals d
                    LEFT JOIN sources s ON d.source_id = s.id";
    if (!empty($where)) {
        $count_query .= " WHERE " . implode(" AND ", $where);
    }

    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute(!empty($params) ? $params : []);
    $count_result = $count_stmt->fetch();
    $total_articles = $count_result['total'];
    $total_pages = ceil($total_articles / $page_size);

    // Add pagination to main query
    $offset = ($page - 1) * $page_size;
    $query .= " ORDER BY MAX(d.scraped_at) DESC, MAX(d.posted_at) DESC LIMIT ? OFFSET ?";
    $params[] = $page_size;
    $params[] = $offset;

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $result = $stmt;
    $articles_on_page = $result->fetchAll();
    $result_count = count($articles_on_page);

    // Get sources with deal counts
    $sources_query = "SELECT s.id, s.name, s.mainCategory,
                                    (SELECT COUNT(*) FROM deals d WHERE d.source_id = s.id AND d.is_active = 'Y') as article_count
                                    FROM sources s
                                    WHERE s.isActive = 'Y'
                                    ORDER BY s.name";
    $sources_result = $conn->query($sources_query);

    // Get all sources
    $all_sources_result = $conn->query("SELECT id, name, mainCategory, isActive, articles_count FROM sources ORDER BY name ASC");
    $all_sources = [];
    while ($source = $all_sources_result->fetch()) {
        $all_sources[] = $source;
    }

    $unsummarized_count = 0;
    $last_scrape_formatted = date('M j, Y g:i A');

} else {
    // ARTICLES MODE - continue with normal articles query

// Track whether the parent filter came from a URL param (e.g. ?sports=1) or was inferred from category_filter
$parent_filter_from_url = ($active_parent_filter !== null);

// If no parent filter but category_filter is set, determine parent from first category (for UI highlighting only)
if (!$active_parent_filter && !empty($category_filter)) {
    $first_cat_id = $category_filter[0];
    $parent_query = $conn->prepare("SELECT p.name FROM categories c JOIN categories p ON c.parentID = p.id WHERE c.id = ? AND c.level = 2 LIMIT 1");
    $parent_query->execute([$first_cat_id]);
    if ($parent_row = $parent_query->fetch()) {
        $active_parent_filter = $parent_row['name'];
    }
}

if ($parent_filter_from_url && $active_parent_filter) {
    // Parent filter from URL (e.g. ?sports=1) - show all children of that parent
    $parent_id = get_parent_category_id($conn, $active_parent_filter);
    if ($parent_id) {
        $child_ids = get_child_category_ids($conn, $parent_id);
        if (!empty($child_ids)) {
            $placeholders = implode(',', array_fill(0, count($child_ids), '?'));
            $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN ($placeholders))";
            foreach ($child_ids as $cid) {
                $params[] = $cid;
                    }
        }
    }
} elseif (!empty($category_filter)) {
    // Specific category selection (e.g. ?category[]=42) - filter by exact categories
    $placeholders = implode(',', array_fill(0, count($category_filter), '?'));
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN ($placeholders))";
    foreach ($category_filter as $cat_id) {
        $params[] = $cat_id;
    }
}

// Filter sources by user visibility: non-admins see base sources + their own linked sources
if ($current_user && $current_user['isAdmin'] != 'Y') {
    $where[] = "(s.isBase = 'Y' OR (s.isBase = 'N' AND s.id IN (SELECT source_id FROM users_sources WHERE user_id = ?)))";
    $params[] = (int)$current_user['id'];
}

// ALWAYS apply user preference filters first (if they exist)
// Apply preferred sources filter
if (!empty($preferred_sources)) {
    $placeholders = implode(',', array_fill(0, count($preferred_sources), '?'));
    $where[] = "a.source_id IN ($placeholders)";
    foreach ($preferred_sources as $src_id) {
        $params[] = $src_id;
    }
}

// Apply preferred categories filter
if (!empty($preferred_categories)) {
    $placeholders = implode(',', array_fill(0, count($preferred_categories), '?'));
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN ($placeholders))";
    foreach ($preferred_categories as $cat_id) {
        $params[] = $cat_id;
    }
}

// Then apply quick filter overrides on top
if (!empty($source_filter)) {
    // Handle multiple source selection
    $placeholders = implode(',', array_fill(0, count($source_filter), '?'));
    $where[] = "a.source_id IN ($placeholders)";
    foreach ($source_filter as $src_id) {
        $params[] = $src_id;
    }
}

if ($search) {
    $where[] = "(a.title LIKE ? OR a.summary LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
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
$count_stmt->execute(!empty($params) ? $params : []);
$count_result = $count_stmt->fetch();
$total_articles = $count_result['total'];
$total_pages = ceil($total_articles / $page_size);

// Add pagination to main query
$offset = ($page - 1) * $page_size;
$query .= " ORDER BY a.scraped_at DESC, a.published_date DESC LIMIT ? OFFSET ?";
$params[] = $page_size;
$params[] = $offset;

$stmt = $conn->prepare($query);
$stmt->execute($params);
$result = $stmt;
$articles_on_page = $result->fetchAll();
$result_count = count($articles_on_page);

// Get all sources for filter with counts based on current filters
// Use subquery to count matching articles while keeping all sources
$sources_query = "SELECT s.id, s.name,
    (SELECT COUNT(DISTINCT a2.id)
     FROM articles a2
     WHERE a2.source_id = s.id";

$sources_conditions = [];
$sources_params = [];

// Apply same filters as main query (except source filter)
if ($parent_filter_from_url && $active_parent_filter) {
    $parent_id = get_parent_category_id($conn, $active_parent_filter);
    if ($parent_id) {
        $child_ids = get_child_category_ids($conn, $parent_id);
        if (!empty($child_ids)) {
            $placeholders = implode(',', array_fill(0, count($child_ids), '?'));
            $sources_conditions[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a2.id AND category_id IN ($placeholders))";
            foreach ($child_ids as $cid) {
                $sources_params[] = $cid;
            }
        }
    }
} elseif (!empty($category_filter)) {
    $placeholders = implode(',', array_fill(0, count($category_filter), '?'));
    $sources_conditions[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a2.id AND category_id IN ($placeholders))";
    foreach ($category_filter as $cat_id) {
        $sources_params[] = $cat_id;
    }
}

if ($search) {
    $sources_conditions[] = "(a2.title LIKE ? OR a2.summary LIKE ?)";
    $search_param = "%{$search}%";
    $sources_params[] = $search_param;
    $sources_params[] = $search_param;
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
    WHERE s.isActive = 'Y'";

// Filter sources by user visibility: base sources + user's linked sources
if ($current_user && $current_user['isAdmin'] != 'Y') {
    $sources_query .= " AND (s.isBase = 'Y' OR (s.isBase = 'N' AND s.id IN (SELECT source_id FROM users_sources WHERE user_id = ?)))";
    $sources_params[] = (int)$current_user['id'];
}

$sources_query .= " ORDER BY s.name";

if (!empty($sources_params)) {
    $sources_stmt = $conn->prepare($sources_query);
    $sources_stmt->execute($sources_params);
    $sources_result = $sources_stmt;
} else {
    $sources_result = $conn->query($sources_query);
}

// Get all sources with main category for sources modal
if ($current_user && $current_user['isAdmin'] != 'Y') {
    $all_sources_stmt = $conn->prepare("SELECT id, name, mainCategory, isActive, articles_count FROM sources WHERE isBase = 'Y' OR (isBase = 'N' AND id IN (SELECT source_id FROM users_sources WHERE user_id = ?)) ORDER BY name ASC");
    $all_sources_stmt->execute([$current_user['id']]);
    $all_sources_result = $all_sources_stmt;
} else {
    $all_sources_result = $conn->query("SELECT id, name, mainCategory, isActive, articles_count FROM sources ORDER BY name ASC");
}
$all_sources = [];
while ($source = $all_sources_result->fetch()) {
    $all_sources[] = $source;
}

// Get count of unsummarized articles (excluding failed)
$unsummarized_query = "SELECT COUNT(*) as count FROM articles
                       WHERE (summary IS NULL OR summary = '')
                       AND (isSummaryFailed IS NULL OR isSummaryFailed != 'Y')";
$unsummarized_result = $conn->query($unsummarized_query)->fetch();
$unsummarized_count = $unsummarized_result['count'];

// Get last scrape time
$last_scrape_query = "SELECT MAX(scraped_at) as last_scrape FROM articles";
$last_scrape_result = $conn->query($last_scrape_query)->fetch();
$last_scrape_time = $last_scrape_result['last_scrape'] ?? 'Never';
if ($last_scrape_time !== 'Never') {
    $last_scrape_formatted = date('M j, Y g:i A', strtotime($last_scrape_time));
} else {
    $last_scrape_formatted = 'Never';
}

} // End of articles mode else block

// Get all categories for filter with counts based on current filters
// Use subquery to count matching articles or deals while keeping all categories
if ($deals_filter) {
    // Count deals when viewing deals page
    $categories_query = "SELECT c.id, c.name, c.parentID, p.name AS parent_name,
        (SELECT COUNT(DISTINCT d2.id)
         FROM deals d2
         JOIN deal_categories dc2 ON d2.id = dc2.deal_id
         WHERE dc2.category_id = c.id AND d2.is_active = 'Y'";
} else {
    // Count articles when viewing articles page
    $categories_query = "SELECT c.id, c.name, c.parentID, p.name AS parent_name,
        (SELECT COUNT(DISTINCT a2.id)
         FROM articles a2
         JOIN article_categories ac2 ON a2.id = ac2.article_id
         WHERE ac2.category_id = c.id";
}

$cat_conditions = [];
$cat_params = [];

// Apply same filters as main query (except category filter)
if (!empty($source_filter)) {
    $placeholders = implode(',', array_fill(0, count($source_filter), '?'));
    if ($deals_filter) {
        $cat_conditions[] = "d2.source_id IN ($placeholders)";
    } else {
        $cat_conditions[] = "a2.source_id IN ($placeholders)";
    }
    foreach ($source_filter as $src_id) {
        $cat_params[] = $src_id;
    }
}

if ($search) {
    if ($deals_filter) {
        $cat_conditions[] = "(d2.product_name LIKE ? OR d2.description LIKE ?)";
    } else {
        $cat_conditions[] = "(a2.title LIKE ? OR a2.summary LIKE ?)";
    }
    $search_param = "%{$search}%";
    $cat_params[] = $search_param;
    $cat_params[] = $search_param;
}

if (!$deals_filter) {
    // Always show only summarized articles (doesn't apply to deals)
    $cat_conditions[] = "a2.summary IS NOT NULL AND a2.summary != ''";
} else {
    // Only show deals with actual prices (filter out cashback-only deals)
    $cat_conditions[] = "(d2.price IS NOT NULL OR d2.price_text IS NOT NULL)";
}

if ($date_filter && $date_filter !== 'all') {
    $table_prefix = $deals_filter ? 'd2' : 'a2';
    switch ($date_filter) {
        case '1day':
            $cat_conditions[] = "{$table_prefix}.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
            break;
        case '1week':
            $cat_conditions[] = "{$table_prefix}.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
            break;
        case '1month':
            $cat_conditions[] = "{$table_prefix}.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            break;
    }
}

if (!empty($cat_conditions)) {
    $categories_query .= " AND " . implode(" AND ", $cat_conditions);
}

$categories_query .= ") as article_count
    FROM categories c
    LEFT JOIN categories p ON c.parentID = p.id
    WHERE c.level = 2
    ORDER BY p.name, c.name";

if (!empty($cat_params)) {
    $cat_stmt = $conn->prepare($categories_query);
    $cat_stmt->execute($cat_params);
    $categories_result = $cat_stmt;
    // Store results for later use in filter modal
    $categories_result_array = $cat_stmt->fetchAll();
} else {
    $categories_result = $conn->query($categories_query);
    // Store results for later use in filter modal
    $categories_result_array = $categories_result->fetchAll();
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

        .category-dropdown a:last-child {
            border-bottom: none !important;
        }

        .category-dropdown a:hover {
            background: #f1f5f9 !important;
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
            }

            .header-buttons-row .btn,
            .header-buttons-row .tooltip-wrapper {
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

                // Populate categories checkboxes (grouped by parent)
                const categoriesDiv = document.getElementById('categoriesCheckboxes');
                categoriesDiv.innerHTML = '';

                // Group categories by parentID
                const grouped = {};
                const parentNames = {};
                if (data.parent_categories) {
                    data.parent_categories.forEach(p => {
                        parentNames[p.id] = p.name;
                        grouped[p.id] = [];
                    });
                }
                data.categories.forEach(category => {
                    const pid = category.parentID || 'none';
                    if (!grouped[pid]) grouped[pid] = [];
                    grouped[pid].push(category);
                });

                // Create level 1 category checkboxes
                const level1ButtonsDiv = document.createElement('div');
                level1ButtonsDiv.style.cssText = 'display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;';

                if (data.parent_categories) {
                    data.parent_categories.forEach(parent => {
                        const label = document.createElement('label');
                        label.style.cssText = 'display: inline-flex; align-items: center; padding: 10px 20px; background: #e0e0e0; color: #334155; border: 2px solid #cbd5e1; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s;';
                        label.dataset.parentId = parent.id;

                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.className = 'pref-level1-filter';
                        checkbox.dataset.parentId = parent.id;
                        checkbox.style.cssText = 'margin-right: 10px; cursor: pointer; width: 18px; height: 18px;';
                        checkbox.onchange = function() {
                            // Update label styling
                            if (this.checked) {
                                label.style.background = '#2563eb';
                                label.style.color = 'white';
                                label.style.borderColor = '#2563eb';
                            } else {
                                label.style.background = '#e0e0e0';
                                label.style.color = '#334155';
                                label.style.borderColor = '#cbd5e1';
                            }

                            // Show/hide level 2 groups based on checked level 1 filters
                            const checkedParents = Array.from(document.querySelectorAll('.pref-level1-filter:checked'))
                                .map(cb => cb.dataset.parentId);

                            document.querySelectorAll('.pref-level2-group').forEach(g => {
                                if (checkedParents.length === 0) {
                                    // No filters selected - show all
                                    g.style.display = 'block';
                                } else {
                                    // Show only selected parents' children
                                    g.style.display = checkedParents.includes(g.dataset.parentId) ? 'block' : 'none';
                                }
                            });
                        };

                        const span = document.createElement('span');
                        span.textContent = parent.name;

                        label.appendChild(checkbox);
                        label.appendChild(span);
                        level1ButtonsDiv.appendChild(label);
                    });
                }
                categoriesDiv.appendChild(level1ButtonsDiv);

                // Render grouped categories (level 2)
                const parentOrder = data.parent_categories ? data.parent_categories.map(p => p.id) : Object.keys(grouped);
                parentOrder.forEach(pid => {
                    const children = grouped[pid] || [];
                    if (children.length === 0) return;

                    const groupDiv = document.createElement('div');
                    groupDiv.className = 'pref-level2-group';
                    groupDiv.dataset.parentId = pid;
                    groupDiv.style.cssText = 'margin-bottom: 15px;';

                    if (parentNames[pid]) {
                        const header = document.createElement('div');
                        header.style.cssText = 'font-weight: 700; font-size: 14px; color: #2563eb; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid #e0e0e0;';
                        header.textContent = parentNames[pid];
                        groupDiv.appendChild(header);
                    }

                    const childrenGrid = document.createElement('div');
                    childrenGrid.style.cssText = 'display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 5px;';

                    children.forEach(category => {
                        const isChecked = !data.preferences || !data.preferences.categories ||
                                         data.preferences.categories.length === 0 ||
                                         data.preferences.categories.includes(parseInt(category.id));
                        const label = document.createElement('label');
                        label.style.cssText = 'display: flex; align-items: center; cursor: pointer; padding: 5px 8px; border-radius: 4px; transition: background 0.2s;';
                        label.onmouseover = function() { this.style.background = '#f1f5f9'; };
                        label.onmouseout = function() { this.style.background = 'transparent'; };
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.className = 'pref-category';
                        checkbox.value = category.id;
                        checkbox.checked = isChecked;
                        checkbox.style.cssText = 'margin-right: 8px; cursor: pointer;';
                        const span = document.createElement('span');
                        span.style.fontSize = '14px';
                        span.textContent = category.name;
                        label.appendChild(checkbox);
                        label.appendChild(span);
                        childrenGrid.appendChild(label);
                    });

                    groupDiv.appendChild(childrenGrid);
                    categoriesDiv.appendChild(groupDiv);
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

                // Save preferences
                const response = await fetch('api_save_preferences.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        sources: sources,
                        categories: categories
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

        // Category dropdown toggle
        function toggleCategoryDropdown(categorySlug) {
            const dropdown = document.getElementById('dropdown-' + categorySlug);
            const isVisible = dropdown.style.display === 'block';

            // Close all other dropdowns first
            document.querySelectorAll('.category-dropdown').forEach(dd => {
                dd.style.display = 'none';
            });

            // Toggle this dropdown
            dropdown.style.display = isVisible ? 'none' : 'block';
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const isDropdownButton = event.target.closest('.category-arrow-btn');
            const isDropdownContent = event.target.closest('.category-dropdown');

            if (!isDropdownButton && !isDropdownContent) {
                document.querySelectorAll('.category-dropdown').forEach(dd => {
                    dd.style.display = 'none';
                });
            }
        });

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
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; flex-wrap: wrap; width: 100%; flex-basis: 100%;">
                <div>
                    <h1>📰 News Dashboard</h1>
                    <p class="subtitle">Latest business news articles, automatically scraped and categorized</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-shrink: 0;">
                    <a href="sources.php" class="btn" style="height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px;">Sources</a>
                    <button onclick="showPreferencesModal()" class="btn" style="height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px; gap: 5px;">
                        ⚙️
                    </button>
                    <a href="logout.php" class="btn btn-secondary" style="height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">Logout</a>
                </div>
            </div>
            <div class="header-actions" style="display: flex; flex-direction: column; gap: 10px; align-items: stretch;">
                <!-- Main Action Buttons Row -->
                <?php if ($current_user && $current_user['isAdmin'] == 'Y'): ?>
                <div class="header-buttons-row" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
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
                </div>
                <?php endif; ?>
                <!-- Management Buttons Row (Admin Only) -->
                <?php if ($current_user && $current_user['isAdmin'] == 'Y'): ?>
                <div class="header-buttons-row" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a href="admin_users.php" class="btn" style="text-align: center; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px;">
                        👥 Manage Users
                    </a>
                    <a href="admin_invites.php" class="btn" style="text-align: center; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px;">
                        🎫 Manage Codes
                    </a>
                    <button onclick="showCategoryManagementModal()" class="btn" style="text-align: center; height: 44px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; padding: 12px 20px;">
                        🏷️ Manage Categories
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <!-- Search & Filters -->
        <div class="controls" style="flex-direction: column; align-items: stretch;">
            <!-- Quick Filter Buttons -->
            <!-- Line 1: Category Buttons and Search -->
            <?php
            // Pre-fetch all level 2 categories grouped by parent
            $children_by_parent = [];
            $all_children_result = $conn->query("SELECT id, name, parentID FROM categories WHERE level = 2 ORDER BY parentID, name");
            while ($child_row = $all_children_result->fetch()) {
                $parent_id = (int)$child_row['parentID'];
                if (!isset($children_by_parent[$parent_id])) {
                    $children_by_parent[$parent_id] = [];
                }
                $children_by_parent[$parent_id][] = [
                    'id' => (int)$child_row['id'],
                    'name' => $child_row['name']
                ];
            }
            ?>
            <div class="quick-filters-row" style="margin-bottom: 8px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <a href="?date=1day"
                   class="btn"
                   style="<?php echo (!$active_parent_filter && empty($category_filter)) ? 'background: #2563eb; color: white;' : 'background: #f8f9fa; color: #334155; border: 2px solid #2563eb;'; ?> padding: 12px 20px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">
                    📰 All Articles
                </a>
                <?php foreach ($level1_categories as $l1_cat):
                    $slug = isset($category_slug_map[$l1_cat['name']]) ? $category_slug_map[$l1_cat['name']] : strtolower($l1_cat['name']);
                    $icon = isset($category_icon_map[$l1_cat['name']]) ? $category_icon_map[$l1_cat['name']] : '📂';
                    $is_active = ($active_parent_filter === $l1_cat['name']);

                    // Get level 2 categories for this parent from pre-fetched data
                    $child_details = isset($children_by_parent[$l1_cat['id']]) ? $children_by_parent[$l1_cat['id']] : [];
                ?>
                <div style="position: relative; display: inline-flex; align-items: stretch;">
                    <a href="?<?php echo htmlspecialchars($slug); ?>=1&date=1day"
                       class="btn category-main-btn"
                       style="<?php echo $is_active ? 'background: #2563eb; color: white;' : 'background: #f8f9fa; color: #334155; border: 2px solid #2563eb;'; ?> padding: 12px 16px 12px 20px; text-decoration: none; border-radius: 6px 0 0 6px; font-weight: 600; display: inline-flex; align-items: center; border-right: none; vertical-align: middle;">
                        <?php echo $icon; ?> <?php echo htmlspecialchars($l1_cat['name']); ?>
                    </a>
                    <button onclick="toggleCategoryDropdown('<?php echo htmlspecialchars($slug); ?>')"
                            class="btn category-arrow-btn"
                            style="<?php echo $is_active ? 'background: #2563eb; color: white;' : 'background: #f8f9fa; color: #334155; border: 2px solid #2563eb;'; ?> padding: 12px 8px; border-radius: 0 6px 6px 0; font-weight: 600; cursor: pointer; border-left: none; display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; min-width: 32px;">
                        ▼
                    </button>
                    <?php if (!empty($child_details)): ?>
                    <div id="dropdown-<?php echo htmlspecialchars($slug); ?>" class="category-dropdown" style="display: none; position: absolute; top: 100%; left: 0; margin-top: 5px; background: white; border: 2px solid #2563eb; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); min-width: 200px; z-index: 1000;">
                        <?php foreach ($child_details as $child): ?>
                        <a href="?category[]=<?php echo $child['id']; ?>&date=1day"
                           style="display: block; padding: 10px 15px; color: #334155; text-decoration: none; border-bottom: 1px solid #e0e0e0; transition: background 0.2s;"
                           onmouseover="this.style.background='#f1f5f9'"
                           onmouseout="this.style.background='white'">
                            <?php echo htmlspecialchars($child['name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

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
                    <?php if ($active_parent_filter):
                        $active_slug = isset($category_slug_map[$active_parent_filter]) ? $category_slug_map[$active_parent_filter] : strtolower($active_parent_filter);
                    ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($active_slug); ?>" value="1">
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

                        <!-- Selected Categories Display -->
                        <?php if (!empty($category_filter)): ?>
                            <?php
                            // Get only level 2 categories from the filter
                            $cat_ids_str = implode(',', array_map('intval', $category_filter));
                            $level2_cats = [];
                            if (!empty($cat_ids_str)) {
                                $cat_result = $conn->query("SELECT name FROM categories WHERE id IN ($cat_ids_str) AND level = 2 ORDER BY name");
                                while ($cat = $cat_result->fetch()) {
                                    $level2_cats[] = htmlspecialchars($cat['name']);
                                }
                            }
                            if (!empty($level2_cats)):
                            ?>
                            <div style="padding: 10px 16px; background: #f1f5f9; border: 2px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #334155; max-width: 600px;">
                                <strong>🏷️ Categories:</strong> <?php echo implode(', ', $level2_cats); ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>

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
                                    while ($src = $src_result->fetch()) {
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
                                    while ($cat = $cat_result->fetch()) {
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
                                $cat_display_stmt = $conn->prepare("SELECT id, name, parentID, level FROM categories WHERE id IN ($placeholders)");
                                if ($cat_display_stmt) {
                                    $cat_display_stmt->execute($category_filter);
                                    while ($cat = $cat_display_stmt->fetch()):
                                        // Build URL to remove this specific category filter
                                        $remaining_cats = array_diff($category_filter, [$cat['id']]);
                                        $remove_url = '?';
                                        $url_params = [];

                                        // If removing a level 2 category and no other categories remain, redirect to parent level 1
                                        if (empty($remaining_cats) && $cat['level'] == 2 && $cat['parentID']) {
                                            // Get parent category name and slug
                                            $parent_stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
                                            $parent_stmt->execute([$cat['parentID']]);
                                            if ($parent_row = $parent_stmt->fetch()) {
                                                $parent_slug = isset($category_slug_map[$parent_row['name']]) ? $category_slug_map[$parent_row['name']] : strtolower($parent_row['name']);
                                                $url_params[] = $parent_slug . '=1';
                                            }
                                        } else {
                                            // Keep other category filters
                                            foreach ($remaining_cats as $remaining_cat) {
                                                $url_params[] = 'category[]=' . urlencode($remaining_cat);
                                            }
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
                                    $src_display_stmt->execute($source_filter);
                                    while ($src = $src_display_stmt->fetch()):
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
                            // Re-execute query for modal display if using prepared statement
                            if (isset($sources_stmt)) {
                                $sources_stmt->execute($sources_params);
                                $modal_sources_result = $sources_stmt;
                            } else {
                                $modal_sources_result = $conn->query($sources_query);
                            }
                            while ($src = $modal_sources_result->fetch()):
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

                        <!-- Categories Checkboxes (Hierarchical) -->
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
                            // Build article/deal count lookup from stored results
                            $cat_counts = [];
                            // Get categories array if not already set
                            if (!isset($categories_result_array)) {
                                if (isset($cat_stmt)) {
                                    // Re-execute prepared statement
                                    $cat_stmt->execute($cat_params);
                                    $categories_result_array = $cat_stmt->fetchAll();
                                } else {
                                    // Re-run query
                                    $temp_result = $conn->query($categories_query);
                                    $categories_result_array = $temp_result->fetchAll();
                                }
                            }
                            foreach ($categories_result_array as $cat) {
                                $cat_counts[(int)$cat['id']] = $cat['article_count'];
                            }

                            // DEBUG
                            if ($deals_filter && isset($_GET['debug'])) {
                                echo "<!-- DEBUG: Array count=" . count($categories_result_array) . " -->\n";
                                foreach ($categories_result_array as $cat) {
                                    if (in_array($cat['id'], [48,49,50,52])) {
                                        echo "<!-- DEBUG: cat[{$cat['id']}]={$cat['article_count']} -->\n";
                                    }
                                }
                                echo "<!-- DEBUG: cat_counts[48]=" . (isset($cat_counts[48]) ? $cat_counts[48] : 'not set') . " -->\n";
                            }

                            foreach ($level1_categories as $parent):
                                $parent_id = $parent['id'];
                                $parent_name = $parent['name'];
                                $children = isset($categories_grouped[$parent_name]) ? $categories_grouped[$parent_name] : [];
                                if (empty($children)) continue;

                                $icon = isset($category_icon_map[$parent['name']]) ? $category_icon_map[$parent['name']] : '📂';
                            ?>
                                <div style="margin-bottom: 12px;">
                                    <div style="font-weight: 700; font-size: 13px; color: #334155; margin-bottom: 6px; border-bottom: 1px solid #e0e0e0; padding-bottom: 4px;">
                                        <?php echo $icon; ?> <?php echo htmlspecialchars($parent['name']); ?>
                                    </div>
                                    <?php foreach ($children as $child):
                                        // Skip if user has preferences and this category is not in them
                                        if (!empty($preferred_categories) && !in_array($child['id'], $preferred_categories)) {
                                            continue;
                                        }
                                        $count = isset($cat_counts[$child['id']]) ? $cat_counts[$child['id']] : 0;
                                    ?>
                                    <label style="display: block; margin-bottom: 6px; cursor: pointer; white-space: nowrap; padding-left: 12px;">
                                        <input type="checkbox" class="category-filter" value="<?php echo $child['id']; ?>"
                                               <?php echo (empty($category_filter) || in_array($child['id'], $category_filter)) ? 'checked' : ''; ?>
                                               style="margin-right: 8px;">
                                        <?php echo htmlspecialchars($child['name']); ?> <span style="color: #999;">(<?php echo $count; ?>)</span>
                                        <a href="javascript:void(0)" onclick="selectOnlyCategory(<?php echo $child['id']; ?>); event.stopPropagation();"
                                           style="margin-left: 5px; font-size: 11px; color: #2563eb; text-decoration: none; font-weight: 600;">only</a>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
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
        if ($deals_filter) $query_params['deals'] = '1';
        if ($tech_filter) $query_params['tech'] = '1';
        if ($business_filter) $query_params['business'] = '1';
        if ($sports_filter) $query_params['sports'] = '1';
        if ($general_filter) $query_params['general'] = '1';

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
                Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $result_count, $total_articles); ?>
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
            <?php if ($result_count > 0): ?>
                <?php foreach ($articles_on_page as $row): ?>
                    <div class="article-card">
                        <?php if ($deals_filter): ?>
                            <!-- Deal card (with or without image) -->
                            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                                <?php if (!empty($row['image_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($row['url']); ?>" target="_blank" style="flex-shrink: 0;">
                                        <img src="<?php echo htmlspecialchars($row['image_url']); ?>"
                                             alt="<?php echo htmlspecialchars($row['title']); ?>"
                                             style="width: 150px; height: 150px; object-fit: cover; border-radius: 8px;">
                                    </a>
                                <?php else: ?>
                                    <!-- Big $ placeholder for deals without image -->
                                    <a href="<?php echo htmlspecialchars($row['url']); ?>" target="_blank" style="flex-shrink: 0;">
                                        <div style="width: 150px; height: 150px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 80px; color: white; font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                                            $
                                        </div>
                                    </a>
                                <?php endif; ?>
                                <div style="flex-grow: 1;">
                                    <div class="article-header">
                                        <div class="article-title">
                                            <h2>
                                                <a href="<?php echo htmlspecialchars($row['url']); ?>" target="_blank" style="text-decoration: none; color: inherit;">
                                                    <?php echo htmlspecialchars($row['title']); ?>
                                                </a>
                                                <?php if ($row['source_name']): ?>
                                                    <span class="source-badge"><?php echo htmlspecialchars($row['source_name']); ?></span>
                                                <?php endif; ?>
                                            </h2>
                                            <?php if (!empty($row['price']) || !empty($row['price_text']) || !empty($row['discount_percentage'])): ?>
                                                <div style="font-size: 24px; font-weight: bold; color: #10b981; margin-top: 10px;">
                                                    <?php if (!empty($row['price']) || !empty($row['price_text'])): ?>
                                                        💰 <?php echo !empty($row['price']) ? '$' . number_format($row['price'], 2) : htmlspecialchars($row['price_text']); ?>
                                                        <?php if (!empty($row['discount_percentage'])): ?>
                                                            <span style="color: #ef4444; font-size: 18px; margin-left: 10px;">
                                                                (<?php echo $row['discount_percentage']; ?>% off)
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php elseif (!empty($row['discount_percentage'])): ?>
                                                        💰 <span style="color: #10b981;"><?php echo $row['discount_percentage']; ?>% Cash Back</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['store_name'])): ?>
                                                <div style="color: #6b7280; margin-top: 5px;">
                                                    🏪 <?php echo htmlspecialchars($row['store_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['votes_up'])): ?>
                                                <div style="color: #3b82f6; margin-top: 5px;">
                                                    👍 <?php echo $row['votes_up']; ?> votes
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['categories']) && $deals_filter): ?>
                                                <!-- Deal category badge -->
                                                <div style="margin-top: 8px;">
                                                    <?php
                                                        $badge_color = match($row['categories']) {
                                                            'New' => '#10b981',
                                                            'Promoted' => '#f59e0b',
                                                            'Popular' => '#3b82f6',
                                                            'Personalized' => '#8b5cf6',
                                                            default => '#6b7280'
                                                        };
                                                    ?>
                                                    <span style="background: <?php echo $badge_color; ?>; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                                        <?php echo htmlspecialchars($row['categories']); ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="article-date">
                                            <?php
                                                $dt = new DateTime($row['scraped_at']);
                                                $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
                                                echo $dt->format('M d, Y g:i A') . ' PST';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (!empty($row['image_url'])): ?>
                            <!-- Legacy: Article with image_url (shouldn't happen, but handle it) -->
                            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                                <img src="<?php echo htmlspecialchars($row['image_url']); ?>"
                                     alt="<?php echo htmlspecialchars($row['title']); ?>"
                                     style="width: 150px; height: 150px; object-fit: cover; border-radius: 8px;">
                                <div style="flex-grow: 1;">
                                    <div class="article-header">
                                        <div class="article-title">
                                            <h2><?php echo htmlspecialchars($row['title']); ?></h2>
                                        </div>
                                        <div class="article-date">
                                            <?php
                                                $dt = new DateTime($row['scraped_at']);
                                                $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
                                                echo $dt->format('M d, Y g:i A') . ' PST';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Regular article card without image -->
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
                        <?php endif; ?>

                        <?php if (empty($row['image_url']) && $row['summary'] && trim($row['summary']) != '' && strlen(trim($row['summary'])) > 20): ?>
                            <div class="article-footer">
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <button class="btn-read-summary" onclick="toggleSummary(<?php echo $row['id']; ?>)">
                                        📖 Read Summary
                                    </button>
                                    <button class="btn-read-summary" onclick="readAloud(<?php echo $row['id']; ?>)">
                                        🔊 Read Aloud
                                    </button>
                                    <?php if (isset($row['fullArticle']) && $row['fullArticle'] && trim($row['fullArticle']) != ''): ?>
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
                        <?php elseif (empty($row['image_url'])): ?>
                            <!-- Only show "summary pending" for articles, not deals -->
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
                <?php endforeach; ?>
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
                Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $result_count, $total_articles); ?>
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
                            <?php $first_cat = true; foreach ($level1_categories as $l1_cat): ?>
                            <label style="cursor: pointer; padding: 8px 16px; background: #e0e0e0; border: 2px solid #ccc; border-radius: 5px; font-weight: 600; transition: all 0.3s;">
                                <input type="radio" name="newSourceCategory" value="<?php echo htmlspecialchars($l1_cat['name']); ?>"<?php echo $first_cat ? ' checked' : ''; ?> style="display: none;">
                                <?php echo htmlspecialchars($l1_cat['name']); ?>
                            </label>
                            <?php $first_cat = false; endforeach; ?>
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

    <!-- Category Management Modal -->
    <div id="categoryManagementModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h2>🏷️ Category Management</h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button onclick="showAddCategoryForm()" class="btn btn-success" style="padding: 8px 16px; font-size: 14px;">+ Add Category</button>
                    <span class="close" onclick="closeCategoryManagementModal()">&times;</span>
                </div>
            </div>
            <div class="modal-body" style="padding: 30px;">
                <div id="categoryError" style="display: none; padding: 12px; margin-bottom: 20px; border-radius: 5px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;"></div>
                <div id="categorySuccess" style="display: none; padding: 12px; margin-bottom: 20px; border-radius: 5px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb;"></div>

                <!-- Recategorization Progress (Hidden by default) -->
                <div id="recatProgressContainer" style="display: none; margin-bottom: 20px; padding: 16px; border-radius: 8px; background: #e8f4fd; border: 2px solid #b8daff;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <div id="recatSpinner" class="spinner-border spinner-border-sm" style="width: 20px; height: 20px; border: 3px solid #007bff; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite; display: inline-block;"></div>
                        <strong id="recatTitle" style="color: #004085;">Recategorizing...</strong>
                    </div>
                    <div id="recatLog" style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 13px; color: #333; background: #fff; padding: 10px; border-radius: 4px; border: 1px solid #dee2e6; white-space: pre-wrap;"></div>
                </div>
                <style>
                    @keyframes spin { to { transform: rotate(360deg); } }
                </style>

                <!-- Add/Edit Category Form (Hidden by default) -->
                <div id="categoryFormContainer" style="display: none; margin-bottom: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 2px solid #dee2e6;">
                    <h3 id="categoryFormTitle">Add New Category</h3>
                    <form id="categoryForm" onsubmit="submitCategory(event)">
                        <input type="hidden" id="categoryId">

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Category Name <span style="color: #dc3545;">*</span></label>
                            <input type="text" id="categoryName" required maxlength="100" placeholder="e.g., Real Estate"
                                   style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 14px;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Description</label>
                            <textarea id="categoryDescription" maxlength="500" placeholder="Brief description of this category"
                                      style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 14px; min-height: 80px; resize: vertical;"></textarea>
                        </div>

                        <input type="hidden" id="categoryLevel" value="2">

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">Parent Category <span style="color: #dc3545;">*</span></label>
                            <div id="categoryParentButtons" style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <!-- Parent category radio buttons will be populated here -->
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #dee2e6;">
                            <button type="button" onclick="cancelCategoryForm()" class="btn btn-secondary" style="padding: 10px 24px;">Cancel</button>
                            <button type="submit" id="categorySubmitBtn" class="btn btn-success" style="padding: 10px 24px;">Save Category</button>
                        </div>
                    </form>
                </div>

                <!-- Categories List -->
                <div id="categoriesListContainer">
                    <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0;">All Categories</h3>
                        <span id="categoryCount" style="color: #666; font-size: 14px;"></span>
                    </div>
                    <div id="categoriesList" style="max-height: 600px; overflow-y: auto;">
                        <!-- Categories will be loaded here -->
                    </div>
                </div>

                <!-- Close Button at Bottom -->
                <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e0e0e0; text-align: center;">
                    <button onclick="closeCategoryManagementModal()" class="btn btn-secondary" style="padding: 12px 30px; font-size: 16px;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Category Management Functions
    let categoriesData = [];

    async function showCategoryManagementModal() {
        document.getElementById('categoryManagementModal').style.display = 'flex';
        await loadCategories();
    }

    function closeCategoryManagementModal() {
        document.getElementById('categoryManagementModal').style.display = 'none';
        cancelCategoryForm();
    }

    async function loadCategories() {
        try {
            const response = await fetch('api_category_get.php');
            const data = await response.json();

            if (data.success) {
                categoriesData = data.categories;
                renderCategories(data.categories);

                // Populate parent category radio buttons
                const parentButtonsContainer = document.getElementById('categoryParentButtons');
                const level1Cats = data.categories.filter(cat => cat.level == 1);

                parentButtonsContainer.innerHTML = '';
                level1Cats.forEach((cat, index) => {
                    const isGeneral = cat.name === 'General';
                    const label = document.createElement('label');
                    label.className = 'category-parent-btn';
                    label.style.cssText = 'cursor: pointer; padding: 10px 20px; background: #e0e0e0; border: 2px solid #ccc; border-radius: 5px; font-weight: 600; transition: all 0.3s; display: inline-block;';

                    const radio = document.createElement('input');
                    radio.type = 'radio';
                    radio.name = 'categoryParent';
                    radio.value = cat.id;
                    radio.required = true;
                    radio.style.display = 'none';
                    if (isGeneral) radio.checked = true; // Default to General

                    radio.addEventListener('change', updateParentCategoryStyles);

                    label.appendChild(radio);
                    label.appendChild(document.createTextNode(cat.name));
                    parentButtonsContainer.appendChild(label);
                });

                updateParentCategoryStyles();
            } else {
                showCategoryError('Failed to load categories: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            showCategoryError('Error loading categories: ' + error.message);
        }
    }

    function updateParentCategoryStyles() {
        document.querySelectorAll('.category-parent-btn').forEach(label => {
            const radio = label.querySelector('input[type="radio"]');
            if (radio.checked) {
                label.style.background = '#667eea';
                label.style.borderColor = '#667eea';
                label.style.color = '#fff';
            } else {
                label.style.background = '#e0e0e0';
                label.style.borderColor = '#ccc';
                label.style.color = '#333';
            }
        });
    }

    function renderCategories(categories) {
        const container = document.getElementById('categoriesList');
        const level1Cats = categories.filter(c => c.level == 1);

        let html = '';
        let totalCount = 0;

        level1Cats.forEach(parent => {
            const children = categories.filter(c => c.parentID == parent.id);
            totalCount++;

            html += `
                <div style="margin-bottom: 25px; border: 2px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                    <div style="background: #f8f9fa; padding: 15px; border-bottom: 2px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; cursor: pointer; flex-wrap: wrap; gap: 10px;" onclick="toggleCategoryGroup(${parent.id})">
                        <div style="flex: 1; min-width: 150px;">
                            <span id="arrow-${parent.id}" style="display: inline-block; transition: transform 0.3s; margin-right: 8px;">▶</span>
                            <strong style="font-size: 16px; color: #333;">📁 ${parent.name}</strong>
                            <span style="margin-left: 10px; color: #666; font-size: 13px;">(Level 1 - ${children.length} subcategories)</span>
                            ${parent.description ? `<div style="margin-top: 5px; margin-left: 28px; color: #666; font-size: 13px;">${parent.description}</div>` : ''}
                        </div>
                        <div style="display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap;" onclick="event.stopPropagation();">
                            <button id="recatBtn-${parent.id}" onclick="recategorize(${parent.id}, '${parent.name.replace(/'/g, "\\'")}')" class="btn btn-sm" style="padding: 8px 16px; font-size: 14px; font-weight: 600; background: #ff6b00; color: #fff; border: 2px solid #ff6b00; white-space: nowrap; box-shadow: 0 2px 4px rgba(0,0,0,0.2);" title="Re-run AI categorization on recent articles in this category">🔄 Recategorize</button>
                            <button onclick="showAddSubcategoryForm(${parent.id}, '${parent.name.replace(/'/g, "\\'")}')" class="btn btn-sm btn-success" style="padding: 6px 12px; font-size: 13px; white-space: nowrap;">+ Category</button>
                            <button onclick="editCategory(${parent.id})" class="btn btn-sm" style="padding: 6px 12px; font-size: 13px; white-space: nowrap;">✏️ Edit</button>
                            <button onclick="deleteCategory(${parent.id})" class="btn btn-sm btn-danger" style="padding: 6px 12px; font-size: 13px; white-space: nowrap;">🗑️ Delete</button>
                        </div>
                    </div>
                    <div id="children-${parent.id}" style="padding: 10px; display: none;">
            `;

            if (children.length > 0) {
                children.forEach(child => {
                    totalCount++;
                    html += `
                        <div style="padding: 12px 15px; margin: 5px 0; background: #fff; border: 1px solid #e0e0e0; border-radius: 5px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #333;">└─ ${child.name}</strong>
                                <span style="margin-left: 10px; color: #999; font-size: 12px;">(Level 2)</span>
                                ${child.description ? `<div style="margin-top: 5px; color: #666; font-size: 12px;">${child.description}</div>` : ''}
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <button onclick="editCategory(${child.id})" class="btn btn-sm" style="padding: 5px 10px; font-size: 12px;">✏️ Edit</button>
                                <button onclick="deleteCategory(${child.id})" class="btn btn-sm btn-danger" style="padding: 5px 10px; font-size: 12px;">🗑️ Delete</button>
                            </div>
                        </div>
                    `;
                });
            } else {
                html += '<div style="padding: 15px; text-align: center; color: #999; font-style: italic;">No subcategories</div>';
            }

            html += `
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
        document.getElementById('categoryCount').textContent = `${totalCount} total categories`;
    }

    function toggleCategoryGroup(parentId) {
        const childrenDiv = document.getElementById(`children-${parentId}`);
        const arrow = document.getElementById(`arrow-${parentId}`);

        if (childrenDiv.style.display === 'none') {
            childrenDiv.style.display = 'block';
            arrow.style.transform = 'rotate(90deg)';
        } else {
            childrenDiv.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
        }
    }

    function showAddCategoryForm() {
        document.getElementById('categoryFormTitle').textContent = 'Add New Category';
        document.getElementById('categoryId').value = '';
        document.getElementById('categoryName').value = '';
        document.getElementById('categoryDescription').value = '';
        document.getElementById('categoryLevel').value = '2';
        document.getElementById('categorySubmitBtn').textContent = 'Save Category';
        document.getElementById('categoryFormContainer').style.display = 'block';
        document.getElementById('categoriesListContainer').style.display = 'none';

        // Default to General
        const generalRadio = Array.from(document.querySelectorAll('input[name="categoryParent"]'))
            .find(r => {
                const cat = categoriesData.find(c => c.id == r.value);
                return cat && cat.name === 'General';
            });
        if (generalRadio) generalRadio.checked = true;
        updateParentCategoryStyles();
    }

    function showAddSubcategoryForm(parentId, parentName) {
        document.getElementById('categoryFormTitle').textContent = `Add Subcategory to ${parentName}`;
        document.getElementById('categoryId').value = '';
        document.getElementById('categoryName').value = '';
        document.getElementById('categoryDescription').value = '';
        document.getElementById('categoryLevel').value = '2';
        document.getElementById('categorySubmitBtn').textContent = 'Save Category';
        document.getElementById('categoryFormContainer').style.display = 'block';
        document.getElementById('categoriesListContainer').style.display = 'none';

        // Set the specific parent
        const parentRadio = document.querySelector(`input[name="categoryParent"][value="${parentId}"]`);
        if (parentRadio) parentRadio.checked = true;
        updateParentCategoryStyles();

        // Focus on the name input for quick entry
        setTimeout(() => document.getElementById('categoryName').focus(), 100);
    }

    function editCategory(id) {
        const category = categoriesData.find(c => c.id == id);
        if (!category) return;

        // If editing a level 1 category, just show an alert for now
        if (category.level == 1) {
            alert('Editing parent categories is restricted. Please contact your administrator.');
            return;
        }

        document.getElementById('categoryFormTitle').textContent = 'Edit Category';
        document.getElementById('categoryId').value = category.id;
        document.getElementById('categoryName').value = category.name;
        document.getElementById('categoryDescription').value = category.description || '';
        document.getElementById('categoryLevel').value = category.level;
        document.getElementById('categorySubmitBtn').textContent = 'Update Category';
        document.getElementById('categoryFormContainer').style.display = 'block';
        document.getElementById('categoriesListContainer').style.display = 'none';

        // Set the parent radio button
        if (category.parentID) {
            const parentRadio = document.querySelector(`input[name="categoryParent"][value="${category.parentID}"]`);
            if (parentRadio) parentRadio.checked = true;
        }
        updateParentCategoryStyles();
    }

    function cancelCategoryForm() {
        document.getElementById('categoryFormContainer').style.display = 'none';
        document.getElementById('categoriesListContainer').style.display = 'block';
        document.getElementById('categoryForm').reset();
    }

    async function submitCategory(event) {
        event.preventDefault();

        const id = document.getElementById('categoryId').value;
        const name = document.getElementById('categoryName').value.trim();
        const description = document.getElementById('categoryDescription').value.trim();
        const level = document.getElementById('categoryLevel').value;
        const parentRadio = document.querySelector('input[name="categoryParent"]:checked');
        const parentID = parentRadio ? parentRadio.value : null;

        const formData = new FormData();
        formData.append('action', id ? 'update' : 'create');
        if (id) formData.append('id', id);
        formData.append('name', name);
        formData.append('description', description);
        formData.append('level', level);
        if (level == '2' && parentID) formData.append('parentID', parentID);

        try {
            const response = await fetch('api_category_manage.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                showCategorySuccess(data.message || (id ? 'Category updated successfully!' : 'Category added successfully!'));
                cancelCategoryForm();
                await loadCategories();
            } else {
                showCategoryError(data.error || 'Failed to save category');
            }
        } catch (error) {
            showCategoryError('Error saving category: ' + error.message);
        }
    }

    async function deleteCategory(id) {
        const category = categoriesData.find(c => c.id == id);
        if (!category) return;

        // Check if it has children
        const children = categoriesData.filter(c => c.parentID == id);
        if (children.length > 0) {
            if (!confirm(`"${category.name}" has ${children.length} subcategories. Deleting it will also delete all subcategories. Continue?`)) {
                return;
            }
        } else {
            if (!confirm(`Are you sure you want to delete "${category.name}"?`)) {
                return;
            }
        }

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        try {
            const response = await fetch('api_category_manage.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                showCategorySuccess(data.message || 'Category deleted successfully!');
                await loadCategories();
            } else {
                showCategoryError(data.error || 'Failed to delete category');
            }
        } catch (error) {
            showCategoryError('Error deleting category: ' + error.message);
        }
    }

    function showCategoryError(message) {
        const errorDiv = document.getElementById('categoryError');
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
        document.getElementById('categorySuccess').style.display = 'none';
        setTimeout(() => { errorDiv.style.display = 'none'; }, 5000);
    }

    function showCategorySuccess(message) {
        const successDiv = document.getElementById('categorySuccess');
        successDiv.textContent = message;
        successDiv.style.display = 'block';
        document.getElementById('categoryError').style.display = 'none';
        setTimeout(() => { successDiv.style.display = 'none'; }, 3000);
    }

    // Recategorization
    let recatInProgress = false;

    function recategorize(categoryId, categoryName) {
        if (recatInProgress) {
            showCategoryError('A recategorization is already in progress. Please wait for it to finish.');
            return;
        }

        if (!confirm(`Recategorize all recent articles under "${categoryName}"?\n\nThis will re-run AI categorization on articles from the last 24 hours in this category tree.`)) {
            return;
        }

        recatInProgress = true;

        // Disable all recategorize buttons
        document.querySelectorAll('[id^="recatBtn-"]').forEach(btn => {
            btn.disabled = true;
            btn.style.opacity = '0.6';
            btn.style.cursor = 'not-allowed';
        });

        // Show progress container
        const progressContainer = document.getElementById('recatProgressContainer');
        const recatLog = document.getElementById('recatLog');
        const recatTitle = document.getElementById('recatTitle');
        const recatSpinner = document.getElementById('recatSpinner');

        progressContainer.style.display = 'block';
        progressContainer.style.background = '#e8f4fd';
        progressContainer.style.borderColor = '#b8daff';
        recatTitle.textContent = `Recategorizing "${categoryName}"...`;
        recatTitle.style.color = '#004085';
        recatSpinner.style.display = 'inline-block';
        recatLog.textContent = '';

        const eventSource = new EventSource(`api_recategorize.php?category_id=${categoryId}`);

        eventSource.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);

                switch (data.type) {
                    case 'start':
                        recatLog.textContent += data.message + '\n';
                        break;

                    case 'progress':
                        recatLog.textContent += data.message + '\n';
                        // Auto-scroll to bottom
                        recatLog.scrollTop = recatLog.scrollHeight;
                        break;

                    case 'complete':
                        recatLog.textContent += '\n' + data.message + '\n';
                        recatLog.scrollTop = recatLog.scrollHeight;
                        recatSpinner.style.display = 'none';
                        recatTitle.textContent = 'Recategorization Complete';
                        progressContainer.style.background = '#d4edda';
                        progressContainer.style.borderColor = '#c3e6cb';
                        recatTitle.style.color = '#155724';
                        finishRecategorization();
                        eventSource.close();
                        break;

                    case 'error':
                        recatLog.textContent += '\nERROR: ' + data.message + '\n';
                        recatLog.scrollTop = recatLog.scrollHeight;
                        recatSpinner.style.display = 'none';
                        recatTitle.textContent = 'Recategorization Failed';
                        progressContainer.style.background = '#f8d7da';
                        progressContainer.style.borderColor = '#f5c6cb';
                        recatTitle.style.color = '#721c24';
                        finishRecategorization();
                        eventSource.close();
                        break;
                }
            } catch (e) {
                recatLog.textContent += event.data + '\n';
                recatLog.scrollTop = recatLog.scrollHeight;
            }
        };

        eventSource.onerror = function() {
            // EventSource will fire onerror when the stream ends normally
            // Only treat as error if still in progress
            if (recatInProgress) {
                recatSpinner.style.display = 'none';
                recatTitle.textContent = 'Connection Lost';
                progressContainer.style.background = '#f8d7da';
                progressContainer.style.borderColor = '#f5c6cb';
                recatTitle.style.color = '#721c24';
                recatLog.textContent += '\nConnection to server lost.\n';
                finishRecategorization();
            }
            eventSource.close();
        };
    }

    function finishRecategorization() {
        recatInProgress = false;

        // Re-enable all recategorize buttons
        document.querySelectorAll('[id^="recatBtn-"]').forEach(btn => {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        });

        // Auto-hide progress after 30 seconds
        setTimeout(() => {
            const container = document.getElementById('recatProgressContainer');
            if (container) container.style.display = 'none';
        }, 30000);
    }
    </script>
</body>
</html>
<?php
// PDO connection is automatically closed when script ends
?>
