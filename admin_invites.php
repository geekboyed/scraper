<?php
/**
 * Admin Panel: Manage Invite Codes
 * View all invite codes, generate new ones
 * Restricted to admin users only
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once 'config.php';
require_once 'auth_check.php';

// Must be logged in
if (!isset($current_user)) {
    header('Location: login.php');
    exit;
}

// Must be admin
if (!$current_user || $current_user['isAdmin'] != 'Y') {
    header('Location: index.php');
    exit;
}

// Fetch all invite codes with creator and user info
$query = "SELECT ic.id, ic.code, ic.is_used, ic.created_at, ic.used_at, ic.expires_at,
                 ic.current_uses, ic.max_uses, ic.isActive,
                 creator.username AS created_by_username,
                 GROUP_CONCAT(
                     DISTINCT CONCAT(used_users.username, ' (', DATE_FORMAT(used_users.created_at, '%b %e, %Y'), ')')
                     ORDER BY used_users.created_at
                     SEPARATOR '\n'
                 ) AS used_by_info
          FROM invite_codes ic
          LEFT JOIN users creator ON ic.created_by = creator.id
          LEFT JOIN users used_users ON used_users.invite_code_id = ic.id
          GROUP BY ic.id, ic.code, ic.is_used, ic.created_at, ic.used_at, ic.expires_at,
                   ic.current_uses, ic.max_uses, ic.isActive, creator.username
          ORDER BY ic.created_at DESC";
$result = $conn->query($query);

// Get counts
$count_query = "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN is_used = 1 THEN 1 ELSE 0 END) as used,
    SUM(CASE WHEN is_used = 0 AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN is_used = 0 AND expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 ELSE 0 END) as expired
    FROM invite_codes";
$counts = $conn->query($count_query)->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Invite Codes - Admin</title>
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
            font-size: 2em;
            margin-bottom: 5px;
        }

        .subtitle {
            color: #666;
            font-size: 1.1em;
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

        .btn-success {
            background: #28a745;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-card .stat-value {
            font-size: 2em;
            font-weight: 700;
            color: #333;
        }

        .stat-card .stat-label {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }

        .stat-card.available .stat-value { color: #28a745; }
        .stat-card.used .stat-value { color: #667eea; }
        .stat-card.expired .stat-value { color: #dc3545; }

        .panel {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 25px;
            margin-bottom: 20px;
        }

        .panel h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3em;
        }


        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th, table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table th.sortable {
            cursor: pointer;
            user-select: none;
        }

        table th.sortable:hover {
            background: #e9ecef;
        }

        table th.sortable::after {
            content: ' ⇅';
            opacity: 0.3;
        }

        table th.sortable.asc::after {
            content: ' ↑';
            opacity: 1;
        }

        table th.sortable.desc::after {
            content: ' ↓';
            opacity: 1;
        }

        table tr:hover {
            background: #f8f9fa;
        }

        table tr.used-row {
            background: #e9ecef;
            opacity: 0.7;
        }

        table tr.used-row:hover {
            background: #dee2e6;
        }

        .code-cell {
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            word-break: break-all;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .badge-available {
            background: #d4edda;
            color: #155724;
        }

        .badge-used {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-expired {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }


        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 90%;
            animation: slideIn 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            margin: 0;
            color: #333;
            font-size: 1.5em;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: #999;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            line-height: 1;
        }

        .close-modal:hover {
            color: #333;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group input[type="number"] {
            width: 120px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .btn-new-code {
            padding: 6px 12px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            margin: 0;
        }

        .btn-new-code:hover {
            background: #218838;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .nav-links {
            display: flex;
            gap: 10px;
        }

        @media (max-width: 768px) {
            header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .nav-links {
                justify-content: center;
            }

            .generate-section {
                flex-direction: column;
            }

            .new-code-display {
                flex-direction: column;
            }

            table {
                font-size: 0.85em;
            }

            table th, table td {
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>Invite Codes</h1>
                <p class="subtitle">Generate and manage user invite codes</p>
            </div>
            <div class="nav-links">
                <a href="index.php" class="btn btn-secondary">Dashboard</a>
                <a href="admin_users.php" class="btn btn-secondary">Users</a>
            </div>
        </header>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $counts['total']; ?></div>
                <div class="stat-label">Total Codes</div>
            </div>
            <div class="stat-card available">
                <div class="stat-value"><?php echo $counts['available']; ?></div>
                <div class="stat-label">Available</div>
            </div>
            <div class="stat-card used">
                <div class="stat-value"><?php echo $counts['used']; ?></div>
                <div class="stat-label">Used</div>
            </div>
            <div class="stat-card expired">
                <div class="stat-value"><?php echo $counts['expired']; ?></div>
                <div class="stat-label">Expired</div>
            </div>
        </div>

        <!-- Invite Codes Table -->
        <div class="panel">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h2 style="margin: 0;">All Invite Codes</h2>
                <button class="btn-new-code" onclick="openNewCodeModal()">
                    + New Invite Code
                </button>
            </div>
            <?php if ($result && $result->num_rows > 0): ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th class="sortable" onclick="sortTable(0)">Code</th>
                            <th>Uses</th>
                            <th>Created By</th>
                            <th class="sortable" onclick="sortTable(3)">Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <?php
                            $is_expired = !$row['is_used'] && $row['expires_at'] && strtotime($row['expires_at']) <= time();
                            $show_status_badge = false;
                            $status_class = '';
                            $status_text = '';

                            if ($row['is_used']) {
                                $status_class = 'badge-used';
                                $status_text = 'Used';
                                $show_status_badge = true;
                            } elseif ($is_expired) {
                                $status_class = 'badge-expired';
                                $status_text = 'Expired';
                                $show_status_badge = true;
                            }
                        ?>
                        <tr id="code-row-<?php echo $row['id']; ?>" class="<?php echo $row['is_used'] ? 'used-row' : ''; ?>">
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span class="code-cell"><?php echo htmlspecialchars($row['code']); ?></span>
                                    <button class="badge <?php echo $row['isActive'] ? 'badge-active' : 'badge-inactive'; ?>"
                                            id="active-badge-<?php echo $row['id']; ?>"
                                            onclick="toggleCodeActive(<?php echo $row['id']; ?>)"
                                            style="cursor: pointer; border: none;"
                                            title="Click to toggle active status">
                                        <?php echo $row['isActive'] ? 'Active' : 'Inactive'; ?>
                                    </button>
                                </div>
                            </td>
                            <td style="white-space: nowrap;">
                                <span id="uses-display-<?php echo $row['id']; ?>"
                                      <?php if ($row['used_by_info']): ?>
                                      title="<?php echo htmlspecialchars($row['used_by_info']); ?>"
                                      style="cursor: help; border-bottom: 1px dotted #666;"
                                      <?php endif; ?>>
                                    <?php echo $row['current_uses']; ?>
                                </span> /
                                <input type="number"
                                       id="max-uses-<?php echo $row['id']; ?>"
                                       value="<?php echo $row['max_uses']; ?>"
                                       min="1"
                                       max="999"
                                       style="width: 50px; padding: 2px 4px; border: 1px solid #ddd; border-radius: 3px; font-size: 13px;"
                                       onchange="updateMaxUses(<?php echo $row['id']; ?>, this.value)">
                            </td>
                            <td><?php echo htmlspecialchars($row['created_by_username'] ?? 'System'); ?></td>
                            <td><?php echo date('M j, Y', strtotime($row['created_at'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <p>No invite codes yet. Generate one to get started.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Invite Code Modal -->
    <div id="newCodeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>New Invite Code</h3>
                <button class="close-modal" onclick="closeNewCodeModal()">&times;</button>
            </div>
            <div class="form-group">
                <label for="modalCodeText">Invite Code:</label>
                <input type="text" id="modalCodeText" placeholder="Edit or generate new code" style="font-family: 'Courier New', monospace;">
            </div>
            <div class="form-group">
                <label for="modalMaxUses">Max Uses:</label>
                <input type="number" id="modalMaxUses" value="1" min="1" max="999">
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="generateRandomCode()">
                    Generate Random
                </button>
                <button class="btn btn-success" onclick="submitCode()">
                    Create Code
                </button>
            </div>
        </div>
    </div>

    <script>
    function openNewCodeModal() {
        document.getElementById('newCodeModal').classList.add('show');
        // Auto-generate a code when modal opens
        generateRandomCode();
        // Reset max uses to 1
        document.getElementById('modalMaxUses').value = 1;
    }

    function closeNewCodeModal() {
        document.getElementById('newCodeModal').classList.remove('show');
        // Clear the form
        document.getElementById('modalCodeText').value = '';
        document.getElementById('modalMaxUses').value = 1;
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('newCodeModal');
        if (event.target === modal) {
            closeNewCodeModal();
        }
    }

    function sortTable(columnIndex) {
        const table = document.querySelector('table tbody');
        const rows = Array.from(table.querySelectorAll('tr'));
        const header = document.querySelectorAll('th.sortable')[columnIndex === 0 ? 0 : 1];

        // Determine sort direction
        const isAsc = header.classList.contains('asc');

        // Remove all sort classes
        document.querySelectorAll('th.sortable').forEach(th => {
            th.classList.remove('asc', 'desc');
        });

        // Add appropriate class to current header
        if (isAsc) {
            header.classList.add('desc');
        } else {
            header.classList.add('asc');
        }

        // Sort rows
        rows.sort((a, b) => {
            let aValue, bValue;

            // For code column (0), get the text from the first div only
            if (columnIndex === 0) {
                aValue = a.cells[columnIndex].querySelector('.code-cell').textContent.trim();
                bValue = b.cells[columnIndex].querySelector('.code-cell').textContent.trim();
            } else {
                aValue = a.cells[columnIndex].textContent.trim();
                bValue = b.cells[columnIndex].textContent.trim();
            }

            // Handle empty values (like '-' for unused codes)
            if (aValue === '-') aValue = '';
            if (bValue === '-') bValue = '';

            // For date columns, convert to comparable format
            if (columnIndex === 3) {
                aValue = aValue ? new Date(aValue).getTime() : 0;
                bValue = bValue ? new Date(bValue).getTime() : 0;
            }

            if (aValue < bValue) return isAsc ? 1 : -1;
            if (aValue > bValue) return isAsc ? -1 : 1;
            return 0;
        });

        // Re-append rows in sorted order
        rows.forEach(row => table.appendChild(row));
    }

    function generateRandomCode() {
        // Generate a random invite code (format: XXXX-XXXX-XXXX)
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        let code = '';
        for (let i = 0; i < 12; i++) {
            if (i > 0 && i % 4 === 0) code += '-';
            code += chars.charAt(Math.floor(Math.random() * chars.length));
        }

        // Display the code in the modal input field
        document.getElementById('modalCodeText').value = code;
    }

    function submitCode() {
        const code = document.getElementById('modalCodeText').value.trim();
        const maxUses = document.getElementById('modalMaxUses').value;

        if (!code) {
            alert('Please enter an invite code');
            return;
        }

        // Send code and max_uses to API
        const formData = new FormData();
        formData.append('code', code);
        formData.append('max_uses', maxUses);

        fetch('api_generate_invite.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeNewCodeModal();
                // Reload page to update the table
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to create code'));
            }
        })
        .catch(error => {
            alert('Error creating invite code: ' + error.message);
        });
    }

    function toggleCodeActive(id) {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('action', 'toggle_active');

        fetch('api_invite_update.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const badge = document.getElementById('active-badge-' + id);
                if (data.isActive) {
                    badge.className = 'badge badge-active';
                    badge.textContent = 'Active';
                } else {
                    badge.className = 'badge badge-inactive';
                    badge.textContent = 'Inactive';
                }
            } else {
                alert('Error: ' + (data.error || 'Failed to toggle active status'));
            }
        })
        .catch(error => {
            alert('Error toggling active status: ' + error.message);
        });
    }

    function updateMaxUses(id, value) {
        const maxUses = parseInt(value);
        if (maxUses < 1 || maxUses > 999) {
            alert('Max uses must be between 1 and 999');
            document.getElementById('max-uses-' + id).value = document.getElementById('max-uses-' + id).defaultValue;
            return;
        }

        const formData = new FormData();
        formData.append('id', id);
        formData.append('action', 'update_max_uses');
        formData.append('max_uses', maxUses);

        fetch('api_invite_update.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update the default value so user can see it saved
                document.getElementById('max-uses-' + id).defaultValue = maxUses;
                // Reload to update is_used status if needed
                setTimeout(() => location.reload(), 500);
            } else {
                alert('Error: ' + (data.error || 'Failed to update max uses'));
                document.getElementById('max-uses-' + id).value = document.getElementById('max-uses-' + id).defaultValue;
            }
        })
        .catch(error => {
            alert('Error updating max uses: ' + error.message);
            document.getElementById('max-uses-' + id).value = document.getElementById('max-uses-' + id).defaultValue;
        });
    }

    </script>

    <!-- Close/Back Button -->
    <div style="max-width: 1400px; margin: 0 auto; padding: 20px; text-align: center; border-top: 2px solid #e0e0e0;">
        <a href="index.php" class="btn btn-secondary" style="padding: 12px 30px; font-size: 16px;">
            ← Back to Dashboard
        </a>
    </div>
</body>
</html>
<?php $conn->close(); ?>
