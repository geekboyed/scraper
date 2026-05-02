<?php
/**
 * Scraping Analytics - Hourly article scraping and summarization chart
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once 'config.php';
require_once 'auth_check.php';

if (!$current_user) {
    header('Location: login.php');
    exit;
}

// Query hourly data for last 24 hours
$stmt = $conn->query("
    SELECT
        DATE_FORMAT(scraped_at, '%Y-%m-%d %H:00') as hour_bucket,
        COUNT(*) as scraped_count,
        SUM(CASE WHEN summary IS NOT NULL AND summary != '' THEN 1 ELSE 0 END) as summarized_count
    FROM articles
    WHERE scraped_at >= NOW() - INTERVAL 24 HOUR
    GROUP BY hour_bucket
    ORDER BY hour_bucket
");
$hourly_data = $stmt->fetchAll();

// Prepare chart data
$labels = [];
$scraped_values = [];
$summarized_values = [];
$total_scraped = 0;
$total_summarized = 0;

foreach ($hourly_data as $row) {
    $dt = new DateTime($row['hour_bucket']);
    $labels[] = $dt->format('ga'); // e.g. "2pm", "3pm"
    $scraped_values[] = (int)$row['scraped_count'];
    $summarized_values[] = (int)$row['summarized_count'];
    $total_scraped += (int)$row['scraped_count'];
    $total_summarized += (int)$row['summarized_count'];
}

$pct_summarized = $total_scraped > 0 ? round(($total_summarized / $total_scraped) * 100, 1) : 0;

$labels_json = json_encode($labels);
$scraped_json = json_encode($scraped_values);
$summarized_json = json_encode($summarized_values);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scraping Analytics - BIScrape</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .page-wrapper {
            max-width: 1200px;
            margin: 0 auto;
        }

        .container {
            background: #eaeae5;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 20px;
        }

        .page-title {
            color: #333;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .chart-card {
            background: #ededea;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }

        .chart-card h2 {
            color: #333;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .chart-wrapper {
            position: relative;
            width: 100%;
            height: 400px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 8px;
        }

        .stat-card {
            background: #ededea;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 24px;
            border: 1px solid #e2e8f0;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }

        .stat-value {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .stat-value.blue { color: #2563eb; }
        .stat-value.green { color: #10b981; }
        .stat-value.gray { color: #64748b; }

        .stat-label {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        @media (max-width: 640px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            .chart-wrapper {
                height: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
    <nav class="site-nav">
        <div class="nav-left">
            <a href="index.php">Articles</a>
            <a href="sources.php">Sources</a>
            <a href="data.php" class="active">Data</a>
            <?php if ($current_user && $current_user['isAdmin'] == 'Y'): ?>
            <div class="nav-divider"></div>
            <a href="admin_users.php">Users</a>
            <a href="admin_invites.php">Invites</a>
            <?php endif; ?>
        </div>
        <div class="nav-right">
            <?php if ($current_user): ?>
            <span class="nav-user-info">
                <?php echo htmlspecialchars($current_user['username'] ?? $current_user['email']); ?>
                <?php if ($current_user['isAdmin'] == 'Y'): ?>
                    <span class="nav-admin-badge">Admin</span>
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </nav>
    <div class="container">
        <h1 class="page-title">Scraping Analytics</h1>

        <div class="chart-card">
            <h2>Articles Scraped &amp; Summarized (Last 24 Hours)</h2>
            <div class="chart-wrapper">
                <canvas id="hourlyChart"></canvas>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value blue"><?= number_format($total_scraped) ?></div>
                <div class="stat-label">Total Scraped</div>
            </div>
            <div class="stat-card">
                <div class="stat-value green"><?= number_format($total_summarized) ?></div>
                <div class="stat-label">Total Summarized</div>
            </div>
            <div class="stat-card">
                <div class="stat-value gray"><?= $pct_summarized ?>%</div>
                <div class="stat-label">Summarized Rate</div>
            </div>
        </div>
    </div>
    </div>

    <script>
        const ctx = document.getElementById('hourlyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= $labels_json ?>,
                datasets: [
                    {
                        label: 'Scraped',
                        data: <?= $scraped_json ?>,
                        backgroundColor: '#2563eb',
                        borderRadius: 6,
                        borderSkipped: false
                    },
                    {
                        label: 'Summarized',
                        data: <?= $summarized_json ?>,
                        backgroundColor: '#10b981',
                        borderRadius: 6,
                        borderSkipped: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'rectRounded',
                            padding: 20,
                            font: {
                                family: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                                size: 13,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        titleFont: { size: 13 },
                        bodyFont: { size: 13 },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            title: function(items) {
                                return items[0].label.toUpperCase();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                                size: 12
                            },
                            color: '#666'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.06)'
                        },
                        ticks: {
                            font: {
                                family: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                                size: 12
                            },
                            color: '#666',
                            precision: 0
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
