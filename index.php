<?php
/**
 * Business Insider Articles Dashboard
 * Displays scraped articles with categories and summaries
 */

require_once 'config.php';

// Get filter parameters
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$source_filter = isset($_GET['source']) ? (int)$_GET['source'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$summary_filter = isset($_GET['summary']) ? $_GET['summary'] : 'all'; // Default to 'all'
$date_filter = isset($_GET['date']) ? $_GET['date'] : '1day'; // Default to '1day'
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$page_size = isset($_GET['size']) ? (int)$_GET['size'] : 50;

// Build query
$query = "SELECT a.id, a.title, a.url, a.published_date, a.summary, a.scraped_at, a.fullText, a.hasPaywall,
          s.name as source_name, s.id as source_id,
          GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') as categories
          FROM articles a
          LEFT JOIN sources s ON a.source_id = s.id
          LEFT JOIN article_categories ac ON a.id = ac.article_id
          LEFT JOIN categories c ON ac.category_id = c.id";

$where = [];
$params = [];
$types = '';

if ($category_filter) {
    $where[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a.id AND category_id = ?)";
    $params[] = $category_filter;
    $types .= 'i';
}

if ($source_filter) {
    $where[] = "a.source_id = ?";
    $params[] = $source_filter;
    $types .= 'i';
}

if ($search) {
    $where[] = "(a.title LIKE ? OR a.summary LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

if ($summary_filter === 'with') {
    $where[] = "a.summary IS NOT NULL AND a.summary != ''";
}

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
} elseif ($summary_filter === 'without') {
    $where[] = "(a.summary IS NULL OR a.summary = '')";
}
// 'all' or any other value shows all articles (no filter)

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
if ($source_filter) {
    $cat_conditions[] = "a2.source_id = ?";
    $cat_params[] = $source_filter;
    $cat_types .= 'i';
}

if ($search) {
    $cat_conditions[] = "(a2.title LIKE ? OR a2.summary LIKE ?)";
    $search_param = "%{$search}%";
    $cat_params[] = $search_param;
    $cat_params[] = $search_param;
    $cat_types .= 'ss';
}

if ($summary_filter === 'with') {
    $cat_conditions[] = "a2.summary IS NOT NULL AND a2.summary != ''";
} elseif ($summary_filter === 'without') {
    $cat_conditions[] = "(a2.summary IS NULL OR a2.summary = '')";
}

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
if ($category_filter) {
    $sources_conditions[] = "EXISTS (SELECT 1 FROM article_categories WHERE article_id = a2.id AND category_id = ?)";
    $sources_params[] = $category_filter;
    $sources_types .= 'i';
}

if ($search) {
    $sources_conditions[] = "(a2.title LIKE ? OR a2.summary LIKE ?)";
    $search_param = "%{$search}%";
    $sources_params[] = $search_param;
    $sources_params[] = $search_param;
    $sources_types .= 'ss';
}

if ($summary_filter === 'with') {
    $sources_conditions[] = "a2.summary IS NOT NULL AND a2.summary != ''";
} elseif ($summary_filter === 'without') {
    $sources_conditions[] = "(a2.summary IS NULL OR a2.summary = '')";
}

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

// Get count of unsummarized articles
$unsummarized_query = "SELECT COUNT(*) as count FROM articles WHERE summary IS NULL OR summary = ''";
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

        .toggle-buttons {
            display: flex;
            gap: 5px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
        }

        .toggle-btn {
            padding: 12px 20px;
            background: white;
            color: #333;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .toggle-btn:hover {
            background: #f0f0f0;
        }

        .toggle-btn.active {
            background: #667eea;
            color: white;
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
    </style>
    <script>
        // Text-to-Speech functionality
        let currentSpeech = null;
        let currentArticleId = null;

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

            utterance.onerror = function() {
                btn.textContent = '🔊 Read Aloud';
                btn.style.background = '#17a2b8';
                currentSpeech = null;
                currentArticleId = null;
                alert('Error reading text. Please try again.');
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

            // Split into paragraphs and format
            const paragraphs = fullText.split('\n\n');
            paragraphs.forEach(para => {
                if (para.trim()) {
                    html += '<p>' + escapeHtml(para.trim()) + '</p>';
                }
            });

            html += '</div>';

            modalBody.innerHTML = html;
            modal.style.display = 'block';
        }

        function closeFulltextModal() {
            document.getElementById('fulltextModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const errorsModal = document.getElementById('errorsModal');
            const fulltextModal = document.getElementById('fulltextModal');

            if (event.target == errorsModal) {
                errorsModal.style.display = 'none';
            }
            if (event.target == fulltextModal) {
                fulltextModal.style.display = 'none';
            }
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
            <!-- Search Bar Row -->
            <div style="width: 100%; margin-bottom: 15px;">
                <form method="GET" style="margin: 0; display: flex; gap: 10px; align-items: center;">
                    <div style="position: relative; width: 50%;">
                        <input type="text"
                               id="searchInput"
                               name="search"
                               placeholder="🔍 Search articles..."
                               value="<?php echo htmlspecialchars($search); ?>"
                               oninput="toggleClearButton()"
                               style="width: 100%; padding: 18px 45px 18px 15px; font-size: 18px; border: 2px solid #e0e0e0; border-radius: 5px;">
                        <button type="button" id="clearBtn" onclick="clearSearchInput()"
                                style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
                                       background: #f0f0f0; border: none; cursor: pointer; font-size: 24px;
                                       color: #666; padding: 2px 8px; line-height: 1; border-radius: 3px;
                                       font-weight: bold; display: <?php echo $search ? 'block' : 'none'; ?>;">×</button>
                    </div>
                    <button type="submit" class="btn" style="padding: 18px 30px; font-size: 16px;">Search</button>
                    <?php if ($category_filter): ?>
                        <input type="hidden" name="category" value="<?php echo $category_filter; ?>">
                    <?php endif; ?>
                    <?php if ($source_filter): ?>
                        <input type="hidden" name="source" value="<?php echo $source_filter; ?>">
                    <?php endif; ?>
                    <?php if ($summary_filter): ?>
                        <input type="hidden" name="summary" value="<?php echo $summary_filter; ?>">
                    <?php endif; ?>
                    <?php if ($date_filter && $date_filter !== 'all'): ?>
                        <input type="hidden" name="date" value="<?php echo $date_filter; ?>">
                    <?php endif; ?>
                </form>
            </div>

            <!-- Filters Row -->
            <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
            <div class="category-filter">
                <form method="GET" style="margin: 0;" id="sourceForm">
                    <select name="source" onchange="document.getElementById('sourceForm').submit()">
                        <option value="">All Sources</option>
                        <?php while ($src = $sources_result->fetch_assoc()): ?>
                            <option value="<?php echo $src['id']; ?>"
                                    <?php echo $source_filter == $src['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($src['name']); ?> (<?php echo $src['article_count']; ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <input type="hidden" name="summary" value="<?php echo $summary_filter; ?>">
                    <?php if ($date_filter && $date_filter !== 'all'): ?>
                        <input type="hidden" name="date" value="<?php echo $date_filter; ?>">
                    <?php endif; ?>
                    <?php if ($category_filter): ?>
                        <input type="hidden" name="category" value="<?php echo $category_filter; ?>">
                    <?php endif; ?>
                    <?php if ($search): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                </form>
            </div>

            <div class="category-filter">
                <form method="GET" style="margin: 0;" id="categoryForm">
                    <select name="category" onchange="document.getElementById('categoryForm').submit()">
                        <option value="">All Categories</option>
                        <?php
                        $categories_result->data_seek(0); // Reset pointer
                        while ($cat = $categories_result->fetch_assoc()):
                        ?>
                            <option value="<?php echo $cat['id']; ?>"
                                    <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?> (<?php echo $cat['article_count']; ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <input type="hidden" name="summary" value="<?php echo $summary_filter; ?>">
                    <?php if ($date_filter && $date_filter !== 'all'): ?>
                        <input type="hidden" name="date" value="<?php echo $date_filter; ?>">
                    <?php endif; ?>
                    <?php if ($source_filter): ?>
                        <input type="hidden" name="source" value="<?php echo $source_filter; ?>">
                    <?php endif; ?>
                    <?php if ($search): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                </form>
            </div>

            <div class="category-filter">
                <form method="GET" style="margin: 0;" id="dateForm">
                    <select name="date" onchange="document.getElementById('dateForm').submit()">
                        <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="1day" <?php echo $date_filter === '1day' ? 'selected' : ''; ?>>Last 24 Hours</option>
                        <option value="1week" <?php echo $date_filter === '1week' ? 'selected' : ''; ?>>Last Week</option>
                        <option value="1month" <?php echo $date_filter === '1month' ? 'selected' : ''; ?>>Last Month</option>
                    </select>
                    <input type="hidden" name="summary" value="<?php echo $summary_filter; ?>">
                    <?php if ($category_filter): ?>
                        <input type="hidden" name="category" value="<?php echo $category_filter; ?>">
                    <?php endif; ?>
                    <?php if ($source_filter): ?>
                        <input type="hidden" name="source" value="<?php echo $source_filter; ?>">
                    <?php endif; ?>
                    <?php if ($search): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                </form>
            </div>

            <div class="toggle-buttons">
                <?php
                $query_params = [];
                if ($category_filter) $query_params['category'] = $category_filter;
                if ($source_filter) $query_params['source'] = $source_filter;
                if ($search) $query_params['search'] = $search;
                if ($date_filter && $date_filter !== 'all') $query_params['date'] = $date_filter;

                function buildFilterUrl($params, $summary_val) {
                    $params['summary'] = $summary_val;
                    return 'index.php?' . http_build_query($params);
                }
                ?>
                <a href="<?php echo buildFilterUrl($query_params, 'all'); ?>"
                   class="toggle-btn <?php echo $summary_filter === 'all' ? 'active' : ''; ?>">
                    All Articles
                </a>
                <a href="<?php echo buildFilterUrl($query_params, 'with'); ?>"
                   class="toggle-btn <?php echo $summary_filter === 'with' ? 'active' : ''; ?>">
                    With Summary
                </a>
            </div>

            <?php if ($category_filter || $source_filter || $search || $summary_filter !== 'all' || $date_filter !== 'all'): ?>
                <a href="index.php" class="btn btn-secondary">Clear Filters</a>
            <?php endif; ?>
            </div>
        </div>

        <?php
        // Pagination helper function and params
        $query_params = [];
        if ($category_filter) $query_params['category'] = $category_filter;
        if ($source_filter) $query_params['source'] = $source_filter;
        if ($search) $query_params['search'] = $search;
        if ($summary_filter) $query_params['summary'] = $summary_filter;
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
        <div class="pagination" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; border-bottom: 1px solid #e0e0e0; padding-bottom: 15px; margin-bottom: 25px;">
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
                    <?php if ($category_filter): ?>
                        <input type="hidden" name="category" value="<?php echo $category_filter; ?>">
                    <?php endif; ?>
                    <?php if ($source_filter): ?>
                        <input type="hidden" name="source" value="<?php echo $source_filter; ?>">
                    <?php endif; ?>
                    <?php if ($search): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                    <?php if ($summary_filter): ?>
                        <input type="hidden" name="summary" value="<?php echo $summary_filter; ?>">
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
                                    <a href="<?php echo htmlspecialchars($row['url']); ?>"
                                       target="_blank"
                                       rel="noopener noreferrer">
                                        <?php echo htmlspecialchars($row['title']); ?>
                                    </a>
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
                                    <?php if ($row['fullText'] && trim($row['fullText']) != ''): ?>
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
                            <?php if ($row['fullText'] && trim($row['fullText']) != ''): ?>
                            <div id="fulltext-<?php echo $row['id']; ?>" style="display: none;"
                                 data-title="<?php echo htmlspecialchars($row['title']); ?>"
                                 data-url="<?php echo htmlspecialchars($row['url']); ?>"
                                 data-source="<?php echo htmlspecialchars($row['source_name']); ?>">
                                <?php echo htmlspecialchars($row['fullText']); ?>
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
                    <?php if ($category_filter): ?>
                        <input type="hidden" name="category" value="<?php echo $category_filter; ?>">
                    <?php endif; ?>
                    <?php if ($source_filter): ?>
                        <input type="hidden" name="source" value="<?php echo $source_filter; ?>">
                    <?php endif; ?>
                    <?php if ($search): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                    <?php if ($summary_filter): ?>
                        <input type="hidden" name="summary" value="<?php echo $summary_filter; ?>">
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
