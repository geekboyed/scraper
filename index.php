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

// Get filter parameters
$category_filter = isset($_GET['category']) ? (is_array($_GET['category']) ? array_map('intval', $_GET['category']) : [(int)$_GET['category']]) : [];
$source_filter = isset($_GET['source']) ? (is_array($_GET['source']) ? array_map('intval', $_GET['source']) : [(int)$_GET['source']]) : [];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '1day'; // Default to '1day'
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$page_size = isset($_GET['size']) ? (int)$_GET['size'] : 50;

// Build query
$query = "SELECT a.id, a.title, a.url, a.published_date, a.summary, a.scraped_at, a.fullArticle, a.hasPaywall,
          s.name as source_name, s.id as source_id,
          GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') as categories
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

if ($tech_filter) {
    // Technology categories: 2=Technology, 7=Automotive, 8=Media, 16=Crypto, 17=AI&ML, 18=Cybersecurity, 19=Cloud, 20=Hardware, 21=Software, 22=Robotics
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN (2, 7, 8, 16, 17, 18, 19, 20, 21, 22))";
} elseif ($business_filter) {
    // Business categories: 1=Finance, 3=Retail, 9=Economy, 10=Markets, 11=Leadership, 12=Startups, 13=Global Business, 14=Legal, 15=Labor
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN (1, 3, 9, 10, 11, 12, 13, 14, 15))";
} elseif (!empty($category_filter)) {
    // Handle multiple category selection
    $placeholders = implode(',', array_fill(0, count($category_filter), '?'));
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id IN ($placeholders))";
    foreach ($category_filter as $cat_id) {
        $params[] = $cat_id;
        $types .= 'i';
    }
}

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
    WHERE s.enabled = 1
    ORDER BY s.name";

if (!empty($sources_params)) {
    $sources_stmt = $conn->prepare($sources_query);
    $sources_stmt->bind_param($sources_types, ...$sources_params);
    $sources_stmt->execute();
    $sources_result = $sources_stmt->get_result();
} else {
    $sources_result = $conn->query($sources_query);
}

// Get count of unsummarized articles (excluding failed)
$unsummarized_query = "SELECT COUNT(*) as count FROM articles
                       WHERE (summary IS NULL OR summary = '')
                       AND (isSummaryFailed IS NULL OR isSummaryFailed != 'Y')";
$unsummarized_count = $conn->query($unsummarized_query)->fetch_assoc()['count'];
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
            margin: 0 auto;
        }

        header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            border-color: #667eea;
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
            border-color: #667eea;
        }

        .btn {
            padding: 12px 25px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-success {
            background: #28a745;
        }

        .btn-success:hover {
            background: #218838;
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
            color: #667eea;
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
            border-left: 4px solid #667eea;
            font-size: 1.15em;
            line-height: 1.8;
        }

        .btn-read-summary {
            background: #28a745;
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.85em;
            font-weight: 600;
            transition: background 0.3s;
            white-space: nowrap;
        }

        .btn-read-summary:hover {
            background: #218838;
        }

        .btn-read-summary.active {
            background: #6c757d;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            white-space: nowrap;
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
            background: #667eea;
            color: white;
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .pagination .current {
            background: #667eea;
            color: white;
            border-color: #667eea;
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
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
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
            border-left: 4px solid #667eea;
        }

        .article-meta h3 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .article-meta a {
            color: #667eea;
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
            border-left: 4px solid #dc3545;
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
            border-left: 4px solid #dc3545;
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
            color: #dc3545;
            font-size: 1.1em;
        }

        .error-timestamp {
            font-size: 0.85em;
            color: #666;
        }

        .error-file {
            font-size: 0.85em;
            color: #007bff;
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
            color: #28a745;
        }

        .no-errors h3 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 1.8em;
            }

            .controls {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .article-header {
                flex-direction: column;
            }

            .article-footer {
                flex-direction: column;
                align-items: flex-start;
            }

            .article-categories {
                justify-content: flex-start;
                width: 100%;
            }

            .modal-content {
                width: 95%;
                margin: 20px auto;
            }

            .error-summary {
                flex-direction: column;
            }
        }

        /* Back to Top Button */
        #backToTop {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #667eea;
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
            background: #5568d3;
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
                btn.style.background = '#17a2b8';
                return;
            }

            // Stop any other speech
            if (currentSpeech) {
                window.speechSynthesis.cancel();
                // Reset previous button
                const prevBtn = document.querySelector('[data-article-id="' + currentArticleId + '"]');
                if (prevBtn) {
                    prevBtn.textContent = '🔊 Read Aloud';
                    prevBtn.style.background = '#17a2b8';
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
            btn.style.background = '#dc3545';
            btn.setAttribute('data-article-id', articleId);

            // Event handlers
            utterance.onend = function() {
                btn.textContent = '🔊 Read Aloud';
                btn.style.background = '#17a2b8';
                currentSpeech = null;
                currentArticleId = null;
            };

            utterance.onerror = function(event) {
                btn.textContent = '🔊 Read Aloud';
                btn.style.background = '#17a2b8';
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
            const allButtons = document.querySelectorAll('.btn-toggle-summary');

            allSummaries.forEach(summary => {
                if (summary.classList.contains('summary-visible')) {
                    summary.classList.remove('summary-visible');
                    summary.classList.add('summary-hidden');
                }
            });

            allButtons.forEach(btn => {
                if (btn.classList.contains('active')) {
                    btn.textContent = '📖 Read Summary';
                    btn.classList.remove('active');
                }
            });
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

                        // Reload page after 2 seconds
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
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

            // Disable button and show spinner
            btn.disabled = true;
            icon.className = 'spinner-icon';
            icon.textContent = '⚙️';
            text.textContent = 'Summarizing...';

            // Call API
            fetch('api_summarize.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        icon.textContent = '✓';
                        text.textContent = 'Complete!';

                        // Reload page after 2 seconds
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
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
                html += '<div style="margin-bottom: 20px;"><strong>Error Types:</strong> ';
                const types = [];
                for (const [type, count] of Object.entries(data.error_counts)) {
                    types.push(type + ' (' + count + ')');
                }
                html += types.join(', ');
                html += '</div>';
            }

            // Individual errors
            html += '<h3 style="margin-bottom: 15px;">Error Details:</h3>';
            data.errors.forEach(error => {
                html += '<div class="error-item severity-' + error.severity + '">';
                html += '<div class="error-header">';
                html += '<span class="error-type">' + escapeHtml(error.type) + '</span>';
                html += '<span class="error-timestamp">' + escapeHtml(error.timestamp) + '</span>';
                html += '</div>';
                html += '<div class="error-file">📁 ' + escapeHtml(error.file) + ' &nbsp;|&nbsp; 🌐 ' + escapeHtml(error.source || 'Unknown') + '</div>';
                html += '<div class="error-description" style="color: #dc3545; font-weight: 500; margin: 8px 0; font-size: 0.95em;">💡 ' + escapeHtml(error.description || '') + '</div>';
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

        // Close modals when clicking outside
        window.onclick = function(event) {
            const errorsModal = document.getElementById('errorsModal');
            const fulltextModal = document.getElementById('fulltextModal');
            const filtersModal = document.getElementById('filtersModal');

            if (event.target == errorsModal) {
                errorsModal.style.display = 'none';
            }
            if (event.target == fulltextModal) {
                fulltextModal.style.display = 'none';
            }
            if (event.target === filtersModal) {
                closeFiltersModal();
            }
        }

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

            // Navigate to clean index
            window.location.href = 'index.php';
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
            <div style="display: flex; gap: 10px;">
                <button id="scrapeBtn" class="btn btn-success" onclick="startScrape()">
                    <span id="scrapeIcon">🔄</span> <span id="scrapeText">Scrape</span>
                </button>
                <div class="tooltip-wrapper">
                    <button id="summarizeBtn" class="btn btn-success" onclick="startSummarize()">
                        <span id="summarizeIcon">📝</span> <span id="summarizeText">Summarize</span>
                    </button>
                    <span class="tooltip-text">
                        <?php echo $unsummarized_count; ?> article<?php echo $unsummarized_count != 1 ? 's' : ''; ?> need summarization
                    </span>
                </div>
                <button id="errorsBtn" class="btn" onclick="checkErrors()" style="background: #dc3545;">
                    <span id="errorsIcon">⚠️</span> <span id="errorsText">Errors</span>
                </button>
                <a href="sources.php" class="btn">Sources</a>
            </div>
        </header>

        <!-- Search & Filters -->
        <div class="controls" style="flex-direction: column; align-items: stretch;">
            <!-- Quick Filter Buttons -->
            <div style="margin-bottom: 5px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <a href="?date=1day"
                   class="btn"
                   style="<?php echo (!isset($_GET['tech']) && !isset($_GET['business']) && empty($category_filter)) ? 'background: #667eea; color: white;' : 'background: #f8f9fa; color: #333; border: 2px solid #667eea;'; ?> padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: 600; display: inline-block;">
                    📰 All Articles
                </a>
                <a href="?tech=1&date=1day"
                   class="btn"
                   style="<?php echo isset($_GET['tech']) ? 'background: #667eea; color: white;' : 'background: #f8f9fa; color: #333; border: 2px solid #667eea;'; ?> padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: 600; display: inline-block;">
                    🖥️ Technology
                </a>
                <a href="?business=1&date=1day"
                   class="btn"
                   style="<?php echo isset($_GET['business']) ? 'background: #667eea; color: white;' : 'background: #f8f9fa; color: #333; border: 2px solid #667eea;'; ?> padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: 600; display: inline-block;">
                    💼 Business
                </a>

                <!-- Filter & Search (aligned right) -->
                <div style="margin-left: auto; display: flex; gap: 8px; align-items: center;">
                    <!-- Filter Button with Tooltip -->
                    <div style="position: relative; display: inline-block;">
                        <button onclick="openFiltersModal()"
                                style="padding: 12px 20px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 14px; white-space: nowrap;">
                            🔍 Filters<?php if (!empty($source_filter) || !empty($category_filter)) echo ' (' . (count($source_filter) + count($category_filter)) . ')'; ?>
                        </button>
                        <?php if (!empty($source_filter) || !empty($category_filter)): ?>
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
                        <?php endif; ?>
                    </div>

                    <!-- Search Form -->
                    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin: 0;">
                        <input type="text"
                               name="search"
                               placeholder="Search articles..."
                               value="<?php echo htmlspecialchars($search); ?>"
                               style="padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 14px; width: 250px; outline: none;">
                        <button type="submit"
                                style="padding: 12px 20px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 14px; white-space: nowrap;">
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
                    </form>
                </div>
            </div>
        </div>

        <!-- Filters Modal (Outside of controls container) -->
            <div id="filtersModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; overflow-y: auto;">
                <div onclick="event.stopPropagation()" style="background: white; margin: 30px auto; max-width: 1200px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); position: relative;">
                    <!-- Modal Header -->
                    <div style="padding: 20px 25px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px 10px 0 0; display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="color: white; margin: 0;">🔍 Filter Articles</h2>
                        <button onclick="closeFiltersModal()" style="background: transparent; border: none; color: white; font-size: 32px; cursor: pointer; line-height: 1; padding: 0; width: 32px; height: 32px;">&times;</button>
                    </div>

                    <!-- Modal Body -->
                    <div style="padding: 25px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px;">

                        <!-- Sources Checkboxes -->
                        <div>
                            <h3 style="margin-bottom: 10px; font-size: 14px; font-weight: 600; color: #667eea;">SOURCES</h3>
                            <div style="margin-bottom: 10px; display: flex; gap: 5px;">
                                <button onclick="selectAllSources(); return false;" style="padding: 5px 10px; background: #667eea; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                    Select All
                                </button>
                                <button onclick="clearAllSources(); return false;" style="padding: 5px 10px; background: #6c757d; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                    Clear All
                                </button>
                            </div>
                            <?php
                            $sources_result->data_seek(0);
                            while ($src = $sources_result->fetch_assoc()):
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
                            <h3 style="margin-bottom: 10px; font-size: 14px; font-weight: 600; color: #667eea;">CATEGORIES</h3>
                            <div style="margin-bottom: 10px; display: flex; gap: 5px;">
                                <button onclick="selectAllCategories(); return false;" style="padding: 5px 10px; background: #667eea; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                    Select All
                                </button>
                                <button onclick="clearAllCategories(); return false;" style="padding: 5px 10px; background: #6c757d; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; font-weight: 600;">
                                    Clear All
                                </button>
                            </div>
                            <div>
                            <?php
                            $categories_result->data_seek(0);
                            while ($cat = $categories_result->fetch_assoc()):
                            ?>
                                <label style="display: block; margin-bottom: 8px; cursor: pointer; white-space: nowrap;">
                                    <input type="checkbox" class="category-filter" value="<?php echo $cat['id']; ?>"
                                           <?php echo (empty($category_filter) || in_array($cat['id'], $category_filter)) ? 'checked' : ''; ?>
                                           style="margin-right: 8px;">
                                    <?php echo htmlspecialchars($cat['name']); ?> <span style="color: #999;">(<?php echo $cat['article_count']; ?>)</span>
                                    <a href="javascript:void(0)" onclick="selectOnlyCategory(<?php echo $cat['id']; ?>); event.stopPropagation();"
                                       style="margin-left: 5px; font-size: 11px; color: #667eea; text-decoration: none; font-weight: 600;">only</a>
                                </label>
                            <?php endwhile; ?>
                            </div>
                        </div>

                        <!-- Time Frame Radio Buttons -->
                        <div>
                            <h3 style="margin-bottom: 10px; font-size: 14px; font-weight: 600; color: #667eea;">TIME FRAME</h3>
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
                        </div>

                        <!-- Search Bar -->
                        <div>
                            <h3 style="margin-bottom: 10px; font-size: 14px; font-weight: 600; color: #667eea;">SEARCH</h3>
                            <form method="GET" style="margin: 0;">
                                <div style="position: relative; margin-bottom: 10px;">
                                    <input type="text"
                                           id="searchInput"
                                           name="search"
                                           placeholder="🔍 Search articles..."
                                           value="<?php echo htmlspecialchars($search); ?>"
                                           oninput="toggleClearButton()"
                                           style="width: 100%; padding: 10px 35px 10px 10px; font-size: 14px; border: 2px solid #e0e0e0; border-radius: 5px;">
                                    <button type="button" id="clearBtn" onclick="clearSearchInput()"
                                            style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
                                                   background: #f0f0f0; border: none; cursor: pointer; font-size: 20px;
                                                   color: #666; padding: 2px 6px; line-height: 1; border-radius: 3px;
                                                   font-weight: bold; display: <?php echo $search ? 'block' : 'none'; ?>;">×</button>
                                </div>
                                <button type="submit" class="btn" style="width: 100%; padding: 10px; font-size: 14px; background: #667eea; color: white;">
                                    Search
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
                            </form>
                        </div>

                    </div>
                    </div>

                    <!-- Modal Footer -->
                    <div style="padding: 20px 25px; background: #f8f9fa; border-radius: 0 0 10px 10px; display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid #e0e0e0;">
                        <button onclick="clearFilters()" style="padding: 12px 25px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 14px;">
                            Clear All Filters
                        </button>
                        <button onclick="applyFilters()" style="padding: 12px 25px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 14px;">
                            Apply Filters
                        </button>
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
            <div style="font-size: 14px; color: #666;">
                Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $result->num_rows, $total_articles); ?>
                of <?php echo $total_articles; ?> article<?php echo $total_articles != 1 ? 's' : ''; ?>
                (Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?>)
            </div>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
            <div style="display: flex; align-items: center; gap: 10px;">
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

                <!-- Minimize All Button -->
                <button onclick="minimizeAll()" class="btn" style="background: #6c757d; color: white; padding: 8px 16px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; margin-left: 15px;">
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
                                    <button class="btn-read-summary" onclick="readAloud(<?php echo $row['id']; ?>)" style="background: #17a2b8;">
                                        🔊 Read Aloud
                                    </button>
                                    <?php if ($row['fullArticle'] && trim($row['fullArticle']) != ''): ?>
                                    <button class="btn-read-summary" onclick="showFullText(<?php echo $row['id']; ?>)" style="background: #6f42c1;">
                                        📄 Full Text
                                    </button>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars($row['url']); ?>"
                                       target="_article"
                                       class="btn-read-summary"
                                       style="text-decoration: none; display: inline-flex; align-items: center; background: #007bff;">
                                        🔗 Read Article<?php if (isset($row['hasPaywall']) && $row['hasPaywall'] === 'Y'): ?> <span style="color: #ffc107; font-weight: bold;">(paywall)</span><?php endif; ?>
                                    </a>
                                </div>
                                <?php if ($row['categories']): ?>
                                    <div class="article-categories">
                                        <?php
                                            $cats = explode(', ', $row['categories']);
                                            foreach ($cats as $cat):
                                        ?>
                                            <span class="category-tag"><?php echo htmlspecialchars($cat); ?></span>
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
                                   style="text-decoration: none; display: inline-flex; align-items: center; background: #007bff;">
                                    🔗 Read Article<?php if (isset($row['hasPaywall']) && $row['hasPaywall'] === 'Y'): ?> <span style="color: #ffc107; font-weight: bold;">(paywall)</span><?php endif; ?>
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
            <div style="font-size: 14px; color: #666;">
                Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $result->num_rows, $total_articles); ?>
                of <?php echo $total_articles; ?> article<?php echo $total_articles != 1 ? 's' : ''; ?>
                (Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?>)
            </div>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
            <div style="display: flex; align-items: center; gap: 10px;">
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

                <!-- Minimize All Button -->
                <button onclick="minimizeAll()" class="btn" style="background: #6c757d; color: white; padding: 8px 16px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; margin-left: 15px;">
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

    <!-- Full Text Modal -->
    <div id="fulltextModal" class="modal fulltext-modal">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);">
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
</body>
</html>
<?php
$conn->close();
?>
