<?php
/**
 * Sources Management - CRUD for scraping sources
 * Filtered by user: regular users see base + their linked sources, admins see all.
 */

require_once 'config.php';
require_once 'auth_check.php';

// Redirect to login if not authenticated
if (!$current_user) {
    header('Location: login.php');
    exit;
}

$is_admin = ($current_user['isAdmin'] == 'Y');
$user_id = (int)$current_user['id'];

/**
 * Check if a non-admin user owns a given source (linked via users_sources).
 */
function userOwnsSource($conn, $user_id, $source_id) {
    $stmt = $conn->prepare("SELECT 1 FROM users_sources WHERE user_id = ? AND source_id = ?");
    $stmt->execute([$user_id, $source_id]);
    $owns = ($stmt->fetch() !== false);
    return $owns;
}

// Handle POST requests (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name']);
        $url = trim($_POST['url']);
        $isActive = isset($_POST['isActive']) ? 'Y' : 'N';
        $mainCategory = trim($_POST['mainCategory'] ?? 'Business');

        // Check if URL already exists (may be inactive/soft-deleted)
        $check = $conn->prepare("SELECT id, isActive, isBase FROM sources WHERE url = ?");
        $check->execute([$url]);
        $existing = $check->fetch();

        // Admin creates base sources, regular users create non-base
        $isBase = $is_admin ? 'Y' : 'N';

        try {
            if ($existing) {
                if ($existing['isActive'] === 'N') {
                    // Reactivate the inactive source and update its details
                    $stmt = $conn->prepare("UPDATE sources SET name = ?, isActive = 'Y', mainCategory = ?, isBase = ? WHERE id = ?");
                    if ($stmt->execute([$name, $mainCategory, $isBase, $existing['id']])) {
                        // Link to user if non-admin
                        if (!$is_admin) {
                            $link = $conn->prepare("INSERT IGNORE INTO users_sources (user_id, source_id) VALUES (?, ?)");
                            $link->execute([$user_id, $existing['id']]);
                        }
                        $message = "Source reactivated successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error: Failed to reactivate source";
                        $message_type = "error";
                    }
                } else {
                    // Source exists and is already active
                    $message = "Error: This source is already active";
                    $message_type = "error";
                }
            } else {
                // Source doesn't exist, insert new
                $stmt = $conn->prepare("INSERT INTO sources (name, url, isActive, mainCategory, isBase) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$name, $url, $isActive, $mainCategory, $isBase])) {
                    $new_source_id = $conn->lastInsertId();
                    // Link to user if non-admin
                    if (!$is_admin) {
                        $link = $conn->prepare("INSERT IGNORE INTO users_sources (user_id, source_id) VALUES (?, ?)");
                        $link->execute([$user_id, $new_source_id]);
                    }
                    $message = "Source added successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error: Failed to add source";
                    $message_type = "error";
                }
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $message = "✗ Error: This source already exists (duplicate URL or name)";
                $message_type = "error";
            } else {
                $message = "✗ Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }

    elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $url = trim($_POST['url']);
        $isActive = isset($_POST['isActive']) ? 'Y' : 'N';
        $mainCategory = trim($_POST['mainCategory'] ?? 'Business');

        // Non-admin users can only update sources they own
        if (!$is_admin && !userOwnsSource($conn, $user_id, $id)) {
            $message = "Error: You do not have permission to edit this source";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE sources SET name = ?, url = ?, isActive = ?, mainCategory = ? WHERE id = ?");

            try {
                if ($stmt->execute([$name, $url, $isActive, $mainCategory, $id])) {
                    $message = "Source updated successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error: Failed to update source";
                    $message_type = "error";
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $message = "Error: This URL or name is already used by another source";
                    $message_type = "error";
                } else {
                    $message = "Error: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }
    }

    elseif ($action === 'toggle') {
        $id = (int)$_POST['id'];

        // Non-admin users can only toggle sources they own
        if (!$is_admin && !userOwnsSource($conn, $user_id, $id)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            $conn = null;
            exit;
        }

        // Get current status
        $stmt = $conn->prepare("SELECT isActive FROM sources WHERE id = ?");
        $stmt->execute([$id]);
        $source = $stmt->fetch();

        // Toggle status
        $new_status = ($source['isActive'] === 'Y') ? 'N' : 'Y';
        $stmt = $conn->prepare("UPDATE sources SET isActive = ? WHERE id = ?");

        if ($stmt->execute([$new_status, $id])) {
            echo json_encode(['success' => true, 'isActive' => $new_status]);
            $conn = null;
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to toggle status']);
            $conn = null;
            exit;
        }
    }

    elseif ($action === 'delete') {
        $id = (int)$_POST['id'];

        // Non-admin users can only deactivate sources they own
        if (!$is_admin && !userOwnsSource($conn, $user_id, $id)) {
            $message = "Error: You do not have permission to deactivate this source";
            $message_type = "error";
        } else {
            // Soft delete: set isActive='N' instead of removing the row
            $stmt = $conn->prepare("UPDATE sources SET isActive = 'N' WHERE id = ?");

            if ($stmt->execute([$id])) {
                $message = "Source deactivated successfully!";
                $message_type = "success";
            } else {
                $message = "Error: Failed to deactivate source";
                $message_type = "error";
            }
        }
    }
}

// Get sources filtered by user visibility with combined article+deal counts
if ($is_admin) {
    // Admin sees all sources
    $sources_result = $conn->query("
        SELECT s.*,
               (SELECT COUNT(*) FROM articles WHERE source_id = s.id) +
               (SELECT COUNT(*) FROM deals WHERE source_id = s.id AND is_active = 'Y') as articles_count
        FROM sources s
        ORDER BY name ASC
    ");
} else {
    // Regular users see base sources + their own linked sources
    $sources_stmt = $conn->prepare("
        SELECT s.*,
               (SELECT COUNT(*) FROM articles WHERE source_id = s.id) +
               (SELECT COUNT(*) FROM deals WHERE source_id = s.id AND is_active = 'Y') as articles_count
        FROM sources s
        WHERE s.isBase = 'Y' OR (s.isBase = 'N' AND s.id IN (SELECT source_id FROM users_sources WHERE user_id = ?))
        ORDER BY s.name ASC
    ");
    $sources_stmt->execute([$user_id]);
    $sources_result = $sources_stmt;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sources - BIScrape</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        header {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h1 {
            color: #333;
            font-size: 2em;
        }

        .btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }

        .btn:hover { background: #5568d3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #5a6268; }

        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .sources-table {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .status-enabled {
            background: #d4edda;
            color: #155724;
        }

        .status-disabled {
            background: #f8d7da;
            color: #721c24;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }

        .form-group input[type="text"],
        .form-group input[type="url"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group input[type="checkbox"] {
            margin-right: 8px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .radio-button-group {
            display: flex;
            gap: 10px;
        }

        .radio-button-group input[type="radio"] {
            display: none;
        }

        .radio-button-group label {
            padding: 10px 20px;
            background: #e0e0e0;
            color: #333;
            border: 2px solid #ccc;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-block;
            text-align: center;
        }

        .radio-button-group label:hover {
            background: #d0d0d0;
        }

        .radio-button-group input[type="radio"]:checked + label {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .category-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .category-business {
            background: #cfe2ff;
            color: #084298;
        }

        .category-technology {
            background: #d1e7dd;
            color: #0f5132;
        }

        .category-sports {
            background: #fff3cd;
            color: #997404;
        }

        .category-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .category-filter-btn {
            padding: 8px 16px;
            background: #f8f9fa;
            color: #334155;
            border: 2px solid #2563eb;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
        }

        .category-filter-btn:hover {
            background: #e2e8f0;
        }

        .category-filter-btn.active {
            background: #2563eb;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔗 Manage Sources</h1>
            <div>
                <a href="index.php" class="btn btn-secondary">← Back to Articles</a>
                <button class="btn btn-success" onclick="openModal('add')">+ Add Source</button>
            </div>
        </header>

        <?php if (isset($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="sources-table">
            <h2 style="margin-bottom: 15px;">Scraping Sources</h2>
            <div class="category-filters">
                <button class="category-filter-btn active" onclick="filterByCategory('All')">All</button>
                <?php
                $filter_cats = get_level1_categories($conn);
                foreach ($filter_cats as $fcat):
                ?>
                <button class="category-filter-btn" onclick="filterByCategory('<?php echo htmlspecialchars($fcat['name']); ?>')"><?php echo htmlspecialchars($fcat['name']); ?></button>
                <?php endforeach; ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>URL</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Last Scraped</th>
                        <th>Articles</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($source = $sources_result->fetch()):
                        $categoryClass = 'category-' . strtolower($source['mainCategory'] ?? 'business');
                        $isSourceActive = ($source['isActive'] == 'Y');
                        $isBaseSource = ($source['isBase'] == 'Y');
                        $canModify = $is_admin || (!$isBaseSource && userOwnsSource($conn, $user_id, $source['id']));
                    ?>
                        <tr data-category="<?php echo htmlspecialchars($source['mainCategory'] ?? 'Business'); ?>">
                            <td><strong><?php echo htmlspecialchars($source['name']); ?></strong></td>
                            <td style="font-size: 0.9em;">
                                <a href="<?php echo htmlspecialchars($source['url']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($source['url']); ?>
                                </a>
                            </td>
                            <td>
                                <span class="category-badge <?php echo $categoryClass; ?>">
                                    <?php echo htmlspecialchars($source['mainCategory'] ?? 'Business'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($canModify): ?>
                                <button class="btn <?php echo $isSourceActive ? 'btn-success' : 'btn-secondary'; ?>"
                                        id="toggle-<?php echo $source['id']; ?>"
                                        onclick="toggleSource(<?php echo $source['id']; ?>, '<?php echo $source['isActive']; ?>')"
                                        style="white-space: nowrap;">
                                    <?php echo $isSourceActive ? 'Active' : 'Inactive'; ?>
                                </button>
                                <?php else: ?>
                                <span class="status-badge <?php echo $isSourceActive ? 'status-enabled' : 'status-disabled'; ?>">
                                    <?php echo $isSourceActive ? 'Active' : 'Inactive'; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $source['last_scraped'] ?? 'Never'; ?></td>
                            <td><?php echo $source['articles_count']; ?></td>
                            <td>
                                <?php if ($canModify): ?>
                                <button class="btn" onclick='editSource(<?php echo json_encode($source); ?>)'>
                                    Edit
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Deactivate this source? It can be reactivated later.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $source['id']; ?>">
                                    <button type="submit" class="btn btn-danger">Deactivate</button>
                                </form>
                                <?php else: ?>
                                <span style="color: #999; font-size: 0.9em;">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="sourceModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Add New Source</h2>
            <form method="POST" id="sourceForm">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="sourceId">

                <div class="form-group">
                    <label>Source Name</label>
                    <input type="text" name="name" id="sourceName" required>
                </div>

                <div class="form-group">
                    <label>URL</label>
                    <input type="url" name="url" id="sourceUrl" required>
                </div>

                <div class="form-group">
                    <label>Main Category</label>
                    <div class="radio-button-group">
                        <?php
                        // Get all level 1 (parent) categories dynamically
                        $level1_cats = get_level1_categories($conn);
                        $first = true;
                        foreach ($level1_cats as $cat):
                            $cat_id = 'cat' . str_replace(' ', '', $cat['name']);
                        ?>
                        <input type="radio" name="mainCategory" id="<?php echo $cat_id; ?>" value="<?php echo htmlspecialchars($cat['name']); ?>"<?php echo $first ? ' checked' : ''; ?>>
                        <label for="<?php echo $cat_id; ?>"><?php echo htmlspecialchars($cat['name']); ?></label>
                        <?php
                            $first = false;
                        endforeach;
                        ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="isActive" id="sourceEnabled" checked>
                        Active
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(mode) {
            document.getElementById('sourceModal').classList.add('active');
            if (mode === 'add') {
                document.getElementById('modalTitle').textContent = 'Add New Source';
                document.getElementById('formAction').value = 'create';
                document.getElementById('sourceForm').reset();
            }
        }

        function closeModal() {
            document.getElementById('sourceModal').classList.remove('active');
        }

        function editSource(source) {
            document.getElementById('modalTitle').textContent = 'Edit Source';
            document.getElementById('formAction').value = 'update';
            document.getElementById('sourceId').value = source.id;
            document.getElementById('sourceName').value = source.name;
            document.getElementById('sourceUrl').value = source.url;
            document.getElementById('sourceEnabled').checked = source.isActive === 'Y';

            // Set the correct main category radio button dynamically
            const mainCategory = source.mainCategory || 'Business';
            const catId = 'cat' + mainCategory.replace(/\s+/g, ''); // Remove spaces from category name
            const radioBtn = document.getElementById(catId);
            if (radioBtn) {
                radioBtn.checked = true;
            }

            openModal('edit');
        }

        function toggleSource(id, currentStatus) {
            const btn = document.getElementById('toggle-' + id);
            const originalText = btn.textContent;

            // Disable button and show loading
            btn.disabled = true;
            btn.textContent = '⏳ Toggling...';

            // Send AJAX request
            fetch('sources.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=toggle&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update button appearance
                    if (data.isActive === 'Y') {
                        btn.className = 'btn btn-success';
                        btn.textContent = '✓ Active';
                    } else {
                        btn.className = 'btn btn-secondary';
                        btn.textContent = 'Inactive';
                    }
                    btn.setAttribute('onclick', `toggleSource(${id}, '${data.isActive}')`);
                } else {
                    alert('Error toggling source: ' + (data.error || 'Unknown error'));
                    btn.textContent = originalText;
                }
                btn.disabled = false;
            })
            .catch(error => {
                alert('Error: ' + error);
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }

        function filterByCategory(category) {
            // Update active button
            document.querySelectorAll('.category-filter-btn').forEach(function(btn) {
                btn.classList.remove('active');
                if (btn.textContent.trim() === category) {
                    btn.classList.add('active');
                }
            });

            // Filter table rows
            var rows = document.querySelectorAll('tbody tr[data-category]');
            rows.forEach(function(row) {
                if (category === 'All' || row.getAttribute('data-category') === category) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Close modal on outside click
        document.getElementById('sourceModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>
<?php $conn = null; ?>
