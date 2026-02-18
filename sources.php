<?php
/**
 * Sources Management - CRUD for scraping sources
 */

require_once 'config.php';

// Handle POST requests (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name']);
        $url = trim($_POST['url']);
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $mainCategory = trim($_POST['mainCategory'] ?? 'Business');

        $stmt = $conn->prepare("INSERT INTO sources (name, url, enabled, mainCategory) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $name, $url, $enabled, $mainCategory);

        try {
            if ($stmt->execute()) {
                $message = "✓ Source added successfully!";
                $message_type = "success";
            } else {
                $message = "✗ Error: " . $conn->error;
                $message_type = "error";
            }
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $message = "✗ Error: This source already exists (duplicate URL or name)";
                $message_type = "error";
            } else {
                $message = "✗ Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
        $stmt->close();
    }

    elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $url = trim($_POST['url']);
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $mainCategory = trim($_POST['mainCategory'] ?? 'Business');

        $stmt = $conn->prepare("UPDATE sources SET name = ?, url = ?, enabled = ?, mainCategory = ? WHERE id = ?");
        $stmt->bind_param("ssisi", $name, $url, $enabled, $mainCategory, $id);

        try {
            if ($stmt->execute()) {
                $message = "✓ Source updated successfully!";
                $message_type = "success";
            } else {
                $message = "✗ Error: " . $conn->error;
                $message_type = "error";
            }
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $message = "✗ Error: This URL or name is already used by another source";
                $message_type = "error";
            } else {
                $message = "✗ Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
        $stmt->close();
    }

    elseif ($action === 'toggle') {
        $id = (int)$_POST['id'];

        // Get current status
        $stmt = $conn->prepare("SELECT enabled FROM sources WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $source = $result->fetch_assoc();
        $stmt->close();

        // Toggle status
        $new_status = $source['enabled'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE sources SET enabled = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'enabled' => $new_status]);
            $stmt->close();
            $conn->close();
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            $stmt->close();
            $conn->close();
            exit;
        }
    }

    elseif ($action === 'delete') {
        $id = (int)$_POST['id'];

        $stmt = $conn->prepare("DELETE FROM sources WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $message = "✓ Source deleted successfully!";
            $message_type = "success";
        } else {
            $message = "✗ Error: " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
    }
}

// Get all sources
$sources_result = $conn->query("SELECT * FROM sources ORDER BY created_at DESC");
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
            <h2 style="margin-bottom: 20px;">Scraping Sources</h2>
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
                    <?php while ($source = $sources_result->fetch_assoc()):
                        $categoryClass = 'category-' . strtolower($source['mainCategory'] ?? 'business');
                    ?>
                        <tr>
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
                                <button class="btn <?php echo $source['enabled'] ? 'btn-success' : 'btn-secondary'; ?>"
                                        id="toggle-<?php echo $source['id']; ?>"
                                        onclick="toggleSource(<?php echo $source['id']; ?>, <?php echo $source['enabled']; ?>)"
                                        style="white-space: nowrap;">
                                    <?php echo $source['enabled'] ? '✓ Enabled' : 'Disabled'; ?>
                                </button>
                            </td>
                            <td><?php echo $source['last_scraped'] ?? 'Never'; ?></td>
                            <td><?php echo $source['articles_count']; ?></td>
                            <td>
                                <button class="btn" onclick='editSource(<?php echo json_encode($source); ?>)'>
                                    Edit
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this source?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $source['id']; ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
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
                        <input type="radio" name="mainCategory" id="catBusiness" value="Business" checked>
                        <label for="catBusiness">Business</label>

                        <input type="radio" name="mainCategory" id="catTechnology" value="Technology">
                        <label for="catTechnology">Technology</label>

                        <input type="radio" name="mainCategory" id="catSports" value="Sports">
                        <label for="catSports">Sports</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="enabled" id="sourceEnabled" checked>
                        Enabled
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
            document.getElementById('sourceEnabled').checked = source.enabled == 1;

            // Set the correct main category radio button
            const mainCategory = source.mainCategory || 'Business';
            if (mainCategory === 'Business') {
                document.getElementById('catBusiness').checked = true;
            } else if (mainCategory === 'Technology') {
                document.getElementById('catTechnology').checked = true;
            } else if (mainCategory === 'Sports') {
                document.getElementById('catSports').checked = true;
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
                    if (data.enabled) {
                        btn.className = 'btn btn-success';
                        btn.textContent = '✓ Enabled';
                    } else {
                        btn.className = 'btn btn-secondary';
                        btn.textContent = 'Disabled';
                    }
                    btn.setAttribute('onclick', `toggleSource(${id}, ${data.enabled})`);
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

        // Close modal on outside click
        document.getElementById('sourceModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
