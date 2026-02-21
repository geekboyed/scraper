<?php
/**
 * Admin User Management - CRUD operations for users
 * Requires admin authentication (isAdmin = 'Y')
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';

// Require admin access
if (!$current_user || $current_user['isAdmin'] != 'Y') {
    header('Location: login.php');
    exit;
}

// Fetch all users
$users_result = $conn->query("SELECT id, username, email, isAdmin, isActive, created_at, last_login FROM users ORDER BY created_at DESC");
$users = [];
$admin_count = 0;
$active_count = 0;
while ($row = $users_result->fetch()) {
    $users[] = $row;
    if ($row['isAdmin'] == 'Y') $admin_count++;
    if ($row['isActive'] == 'Y') $active_count++;
}
$total_count = count($users);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - BIScrape</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
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
            flex-wrap: wrap;
            gap: 15px;
        }

        h1 {
            color: #333;
            font-size: 2em;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
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
            white-space: nowrap;
        }

        .btn:hover { background: #5568d3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-warning:hover { background: #e0a800; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        .stats-bar {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            gap: 30px;
            align-items: center;
            flex-wrap: wrap;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-number {
            font-size: 1.5em;
            font-weight: 700;
            color: #667eea;
        }

        .stat-label {
            color: #666;
            font-size: 0.9em;
        }

        .search-bar {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .search-bar input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.3s;
        }

        .search-bar input:focus {
            border-color: #667eea;
        }

        .users-table {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow-x: auto;
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
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }

        th:hover {
            background: #e9ecef;
        }

        th .sort-arrow {
            margin-left: 5px;
            color: #adb5bd;
        }

        th .sort-arrow.active {
            color: #667eea;
        }

        tr:hover {
            background: #f8f9fa;
        }

        tr.inactive-user {
            opacity: 0.6;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
            display: inline-block;
        }

        .badge-admin {
            background: #cfe2ff;
            color: #084298;
        }

        .badge-user {
            background: #e2e3e5;
            color: #41464b;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .action-btns {
            display: flex;
            gap: 6px;
            flex-wrap: nowrap;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }

        .modal-overlay.active {
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
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content h2 {
            margin-bottom: 20px;
            color: #333;
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
        .form-group input[type="email"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            border-color: #667eea;
        }

        .form-group .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: normal;
            cursor: pointer;
        }

        .form-group .checkbox-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 2000;
            transform: translateX(120%);
            transition: transform 0.3s ease;
            max-width: 400px;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast-success { background: #28a745; }
        .toast-error { background: #dc3545; }

        .confirm-dialog {
            text-align: center;
        }

        .confirm-dialog p {
            margin-bottom: 20px;
            font-size: 1.1em;
            color: #333;
        }

        .confirm-dialog .username-highlight {
            font-weight: 700;
            color: #dc3545;
        }

        .self-indicator {
            font-size: 0.8em;
            color: #667eea;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            header {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-bar {
                flex-direction: column;
                gap: 10px;
            }

            .action-btns {
                flex-direction: column;
                gap: 4px;
            }

            h1 { font-size: 1.5em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Manage Users</h1>
            <div class="header-actions">
                <a href="index.php" class="btn btn-secondary">Back to Articles</a>
                <button class="btn btn-success" onclick="openCreateModal()">+ Add User</button>
            </div>
        </header>

        <div class="stats-bar">
            <div class="stat-item">
                <span class="stat-number" id="totalCount"><?php echo $total_count; ?></span>
                <span class="stat-label">Total Users</span>
            </div>
            <div class="stat-item">
                <span class="stat-number" id="adminCount"><?php echo $admin_count; ?></span>
                <span class="stat-label">Admins</span>
            </div>
            <div class="stat-item">
                <span class="stat-number" id="activeCount"><?php echo $active_count; ?></span>
                <span class="stat-label">Active</span>
            </div>
        </div>

        <?php /*
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search by username or email..." oninput="filterUsers()">
        </div>
        */ ?>

        <div class="users-table">
            <table>
                <thead>
                    <tr>
                        <th onclick="sortTable('id')">ID <span class="sort-arrow" id="sort-id">&#9650;</span></th>
                        <th onclick="sortTable('username')">Username <span class="sort-arrow" id="sort-username"></span></th>
                        <th onclick="sortTable('email')">Email <span class="sort-arrow" id="sort-email"></span></th>
                        <th onclick="sortTable('isAdmin')">Role <span class="sort-arrow" id="sort-isAdmin"></span></th>
                        <th onclick="sortTable('isactive')">Status <span class="sort-arrow" id="sort-isactive"></span></th>
                        <th onclick="sortTable('created_at')">Created <span class="sort-arrow" id="sort-created_at"></span></th>
                        <th onclick="sortTable('last_login')">Last Login <span class="sort-arrow" id="sort-last_login"></span></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <?php foreach ($users as $user): ?>
                    <tr id="user-row-<?php echo $user['id']; ?>" class="<?php echo $user['isActive'] == 'Y' ? '' : 'inactive-user'; ?>"
                        data-id="<?php echo $user['id']; ?>"
                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                        data-email="<?php echo htmlspecialchars($user['email']); ?>"
                        data-isadmin="<?php echo $user['isAdmin']; ?>"
                        data-isactive="<?php echo $user['isActive']; ?>"
                        data-created_at="<?php echo $user['created_at']; ?>"
                        data-last_login="<?php echo $user['last_login'] ?? ''; ?>">
                        <td><?php echo $user['id']; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                            <?php if ($user['id'] == $current_user['id']): ?>
                                <span class="self-indicator">(you)</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td>
                            <button class="badge <?php echo $user['isAdmin'] == 'Y' ? 'badge-admin' : 'badge-user'; ?>"
                                    id="admin-badge-<?php echo $user['id']; ?>"
                                    onclick="toggleAdmin(<?php echo $user['id']; ?>)"
                                    style="cursor: pointer; border: none;"
                                    title="Click to toggle admin status"
                                    <?php echo $user['id'] == $current_user['id'] ? 'disabled style="cursor: not-allowed; border: none; opacity: 0.7;"' : ''; ?>>
                                <?php echo $user['isAdmin'] == 'Y' ? 'Admin' : 'User'; ?>
                            </button>
                        </td>
                        <td>
                            <button class="badge <?php echo $user['isActive'] == 'Y' ? 'badge-active' : 'badge-inactive'; ?>"
                                    id="active-badge-<?php echo $user['id']; ?>"
                                    onclick="toggleActive(<?php echo $user['id']; ?>)"
                                    style="cursor: pointer; border: none;"
                                    title="Click to toggle active status"
                                    <?php echo $user['id'] == $current_user['id'] ? 'disabled style="cursor: not-allowed; border: none; opacity: 0.7;"' : ''; ?>>
                                <?php echo $user['isActive'] == 'Y' ? 'Active' : 'Inactive'; ?>
                            </button>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                        <td><?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?></td>
                        <td>
                            <div class="action-btns">
                                <button class="btn btn-sm" onclick="openEditModal(<?php echo $user['id']; ?>)">Edit</button>
                                <?php if ($user['id'] != $current_user['id']): ?>
                                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['username'])); ?>')">Delete</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create/Edit User Modal -->
    <div id="userModal" class="modal-overlay">
        <div class="modal-content">
            <h2 id="modalTitle">Add New User</h2>
            <form id="userForm" onsubmit="return saveUser(event)">
                <input type="hidden" id="editUserId" value="">

                <div class="form-group">
                    <label for="formUsername">Username</label>
                    <input type="text" id="formUsername" required minlength="3" maxlength="50">
                </div>

                <div class="form-group">
                    <label for="formEmail">Email</label>
                    <input type="email" id="formEmail" required>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="formIsAdmin">
                        Grant admin privileges
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="formIsActive" checked>
                        Account active
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" id="saveBtn">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay">
        <div class="modal-content confirm-dialog">
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete user <span class="username-highlight" id="deleteUsername"></span>?</p>
            <p style="font-size: 0.9em; color: #666;">This action cannot be undone.</p>
            <input type="hidden" id="deleteUserId" value="">
            <div class="form-actions" style="justify-content: center;">
                <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn btn-danger" onclick="deleteUser()">Delete User</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <script>
        const currentUserId = <?php echo (int)$current_user['id']; ?>;
        let sortColumn = 'id';
        let sortDirection = 'desc';

        // Toast notifications
        function showToast(message, type) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast toast-' + type + ' show';
            setTimeout(() => { toast.classList.remove('show'); }, 3000);
        }

        // Search/filter
        function filterUsers() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('#usersTableBody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const username = row.getAttribute('data-username').toLowerCase();
                const email = row.getAttribute('data-email').toLowerCase();
                if (username.includes(search) || email.includes(search)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Sorting
        function sortTable(column) {
            if (sortColumn === column) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortColumn = column;
                sortDirection = 'asc';
            }

            // Update sort arrows
            document.querySelectorAll('.sort-arrow').forEach(el => {
                el.textContent = '';
                el.classList.remove('active');
            });
            const arrow = document.getElementById('sort-' + column);
            if (arrow) {
                arrow.textContent = sortDirection === 'asc' ? '\u25B2' : '\u25BC';
                arrow.classList.add('active');
            }

            const tbody = document.getElementById('usersTableBody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort((a, b) => {
                let aVal = a.getAttribute('data-' + column) || '';
                let bVal = b.getAttribute('data-' + column) || '';

                if (column === 'id') {
                    aVal = parseInt(aVal) || 0;
                    bVal = parseInt(bVal) || 0;
                } else if (column === 'created_at' || column === 'last_login') {
                    aVal = aVal ? new Date(aVal).getTime() : 0;
                    bVal = bVal ? new Date(bVal).getTime() : 0;
                } else {
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }

                if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1;
                if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1;
                return 0;
            });

            rows.forEach(row => tbody.appendChild(row));
        }

        // Create modal
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('editUserId').value = '';
            document.getElementById('formUsername').value = '';
            document.getElementById('formEmail').value = '';
            document.getElementById('formIsAdmin').checked = false;
            document.getElementById('formIsActive').checked = true;
            document.getElementById('userModal').classList.add('active');
            document.getElementById('formUsername').focus();
        }

        // Edit modal
        function openEditModal(userId) {
            const row = document.getElementById('user-row-' + userId);
            if (!row) return;

            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('editUserId').value = userId;
            document.getElementById('formUsername').value = row.getAttribute('data-username');
            document.getElementById('formEmail').value = row.getAttribute('data-email');
            document.getElementById('formIsAdmin').checked = row.getAttribute('data-isadmin') === 'Y';
            document.getElementById('formIsActive').checked = row.getAttribute('data-isactive') === 'Y';

            // Disable checkboxes for self
            document.getElementById('formIsAdmin').disabled = (userId === currentUserId);
            document.getElementById('formIsActive').disabled = (userId === currentUserId);

            document.getElementById('userModal').classList.add('active');
            document.getElementById('formUsername').focus();
        }

        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
            document.getElementById('formIsAdmin').disabled = false;
            document.getElementById('formIsActive').disabled = false;
        }

        // Save user (create or update)
        function saveUser(event) {
            event.preventDefault();
            const userId = document.getElementById('editUserId').value;
            const username = document.getElementById('formUsername').value.trim();
            const email = document.getElementById('formEmail').value.trim();
            const isAdmin = document.getElementById('formIsAdmin').checked ? 'Y' : 'N';
            const isActive = document.getElementById('formIsActive').checked ? 'Y' : 'N';

            const isEdit = userId !== '';
            const url = isEdit ? 'api_user_update.php' : 'api_user_create.php';

            const formData = new FormData();
            formData.append('username', username);
            formData.append('email', email);
            formData.append('isAdmin', isAdmin);
            formData.append('isActive', isActive);
            if (isEdit) {
                formData.append('id', userId);
                formData.append('action', 'update');
            }

            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            fetch(url, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(isEdit ? 'User updated successfully' : 'User created successfully', 'success');
                        closeModal();
                        setTimeout(() => location.reload(), 500);
                    } else {
                        showToast(data.error || 'Operation failed', 'error');
                    }
                })
                .catch(err => {
                    showToast('Network error: ' + err.message, 'error');
                })
                .finally(() => {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save';
                });

            return false;
        }

        // Toggle admin status
        function toggleAdmin(userId) {
            if (userId === currentUserId) return;

            const badge = document.getElementById('admin-badge-' + userId);
            badge.disabled = true;

            const formData = new FormData();
            formData.append('id', userId);
            formData.append('action', 'toggle_admin');

            fetch('api_user_update.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const row = document.getElementById('user-row-' + userId);
                        row.setAttribute('data-isadmin', data.isAdmin);

                        if (data.isAdmin === 'Y') {
                            badge.className = 'badge badge-admin';
                            badge.textContent = 'Admin';
                        } else {
                            badge.className = 'badge badge-user';
                            badge.textContent = 'User';
                        }
                        updateStats();
                        showToast('Admin status updated', 'success');
                    } else {
                        showToast(data.error || 'Failed to update', 'error');
                    }
                })
                .catch(err => showToast('Network error', 'error'))
                .finally(() => { badge.disabled = false; });
        }

        // Toggle active status
        function toggleActive(userId) {
            if (userId === currentUserId) return;

            const badge = document.getElementById('active-badge-' + userId);
            badge.disabled = true;

            const formData = new FormData();
            formData.append('id', userId);
            formData.append('action', 'toggle_active');

            fetch('api_user_update.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const row = document.getElementById('user-row-' + userId);
                        row.setAttribute('data-isactive', data.isActive);

                        if (data.isActive === 'Y') {
                            badge.className = 'badge badge-active';
                            badge.textContent = 'Active';
                            row.classList.remove('inactive-user');
                        } else {
                            badge.className = 'badge badge-inactive';
                            badge.textContent = 'Inactive';
                            row.classList.add('inactive-user');
                        }
                        updateStats();
                        showToast('Active status updated', 'success');
                    } else {
                        showToast(data.error || 'Failed to update', 'error');
                    }
                })
                .catch(err => showToast('Network error', 'error'))
                .finally(() => { badge.disabled = false; });
        }

        // Delete confirmation
        function confirmDelete(userId, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUsername').textContent = username;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        function deleteUser() {
            const userId = document.getElementById('deleteUserId').value;

            const formData = new FormData();
            formData.append('id', userId);

            fetch('api_user_delete.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('User deleted successfully', 'success');
                        closeDeleteModal();
                        const row = document.getElementById('user-row-' + userId);
                        if (row) row.remove();
                        updateStats();
                    } else {
                        showToast(data.error || 'Failed to delete user', 'error');
                    }
                })
                .catch(err => showToast('Network error', 'error'));
        }

        // Update stat counters from current DOM state
        function updateStats() {
            const rows = document.querySelectorAll('#usersTableBody tr');
            let total = 0, admins = 0, active = 0;
            rows.forEach(row => {
                total++;
                if (row.getAttribute('data-isadmin') === 'Y') admins++;
                if (row.getAttribute('data-isactive') === 'Y') active++;
            });
            document.getElementById('totalCount').textContent = total;
            document.getElementById('adminCount').textContent = admins;
            document.getElementById('activeCount').textContent = active;
        }

        // Close modals on overlay click
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });
    </script>

    <!-- Close/Back Button -->
    <div style="max-width: 1400px; margin: 0 auto; padding: 20px; text-align: center; border-top: 2px solid #e0e0e0;">
        <a href="index.php" class="btn btn-secondary" style="padding: 12px 30px; font-size: 16px;">
            ← Back to Dashboard
        </a>
    </div>
</body>
</html>
<?php $conn = null; ?>
