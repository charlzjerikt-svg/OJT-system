<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';

$user = require_role('admin');
$navUser = $user;

global $pdo;

$search = trim($_GET['search'] ?? '');
$allowedAccountStatuses = ['', 'pending', 'active', 'inactive', 'rejected'];
$accountStatusParam = $_GET['account_status'] ?? '';
$accountStatus = in_array($accountStatusParam, $allowedAccountStatuses, true) ? $accountStatusParam : '';
$allowedOjtStatuses = ['', 'not_started', 'ongoing', 'completed'];
$ojtStatusParam = $_GET['ojt_status'] ?? '';
$ojtStatus = in_array($ojtStatusParam, $allowedOjtStatuses, true) ? $ojtStatusParam : '';
$course = trim($_GET['course'] ?? '');
$company = trim($_GET['company'] ?? '');

$allowedSorts = ['name', 'status', 'ojt_status', 'created'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts, true) ? $_GET['sort'] : 'name';
$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$result = get_students_list([
    'search' => $search,
    'account_status' => $accountStatus,
    'ojt_status' => $ojtStatus,
    'course' => $course,
    'company' => $company,
    'sort' => $sort,
    'dir' => $dir,
    'page' => $page,
    'per_page' => $perPage,
]);

$totalPages = max(1, (int) ceil($result['total'] / $perPage));

function students_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return APP_URL . '/admin/students.php' . ($params ? '?' . http_build_query($params) : '');
}

function sort_link(string $col, string $label): string {
    global $sort, $dir;
    $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="' . e(students_url(['sort' => $col, 'dir' => $nextDir])) . '">' . e($label) . $arrow . '</a>';
}

$pageTitle = 'Student Management';
$extraScripts = [APP_URL . '/assets/js/admin.js'];
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>Student Management</h1>
  <p class="subtitle">Approve registrations and manage student accounts.</p>

  <form method="get" action="" class="filter-form">
    <div class="filter-field">
      <label for="f_search">Search</label>
      <input type="text" id="f_search" name="search" placeholder="Name, email, or Student ID" value="<?= e($search) ?>">
    </div>
    <div class="filter-field">
      <label for="f_account">Account Status</label>
      <select id="f_account" name="account_status">
        <option value="">All</option>
        <?php foreach (['pending' => 'Pending', 'active' => 'Active', 'inactive' => 'Inactive', 'rejected' => 'Rejected'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $accountStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field">
      <label for="f_ojt">OJT Status</label>
      <select id="f_ojt" name="ojt_status">
        <option value="">All</option>
        <?php foreach (['not_started' => 'Not Started', 'ongoing' => 'In Progress', 'completed' => 'Completed'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $ojtStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field">
      <label for="f_course">Course</label>
      <input type="text" id="f_course" name="course" value="<?= e($course) ?>">
    </div>
    <div class="filter-field">
      <label for="f_company">Company</label>
      <input type="text" id="f_company" name="company" value="<?= e($company) ?>">
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn-secondary">Apply</button>
      <a class="btn-secondary" href="<?= APP_URL ?>/admin/students.php">Clear</a>
    </div>
  </form>

  <?php if (!$result['rows']): ?>
    <p class="field-hint">No students found for this filter.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Student ID</th>
          <th><?= sort_link('name', 'Name') ?></th>
          <th>Course</th>
          <th>Company</th>
          <th>Required</th>
          <th>Completed</th>
          <th>Remaining</th>
          <th>Progress</th>
          <th><?= sort_link('ojt_status', 'OJT Status') ?></th>
          <th><?= sort_link('status', 'Account') ?></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result['rows'] as $row): $s = $row['summary']; ?>
        <tr>
          <td><a href="<?= APP_URL ?>/admin/student_profile.php?id=<?= (int) $row['id'] ?>"><?= e($row['student_id'] ?? '—') ?></a></td>
          <td><?= e($row['full_name']) ?></td>
          <td><?= e($row['course'] ?? '—') ?></td>
          <td><?= e($row['company'] ?? '—') ?></td>
          <td><?= number_format($s['required_minutes'] / 60, 2) ?> hrs</td>
          <td><?= number_format($s['completed_minutes'] / 60, 2) ?> hrs</td>
          <td><?= number_format($s['remaining_minutes'] / 60, 2) ?> hrs</td>
          <td><?= number_format($s['percent'], 2) ?>%</td>
          <td><span class="status-pill status-<?= e($row['ojt_status_derived']['key']) ?>"><?= e(strtoupper($row['ojt_status_derived']['label'])) ?></span></td>
          <td><span class="status-pill status-<?= e($row['account_status']) ?>"><?= e(strtoupper($row['account_status'])) ?></span></td>
          <td class="admin-row-actions">
            <?php if ($row['account_status'] === 'pending'): ?>
              <form method="post" action="<?= APP_URL ?>/admin/student_action.php" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn-secondary btn-tiny">Approve</button>
              </form>
              <button type="button" class="btn-secondary btn-tiny" data-reject-student="<?= (int) $row['id'] ?>" data-reject-name="<?= e($row['full_name']) ?>">Reject</button>
            <?php elseif ($row['account_status'] === 'active'): ?>
              <button type="button" class="btn-secondary btn-tiny" data-deactivate-student="<?= (int) $row['id'] ?>" data-deactivate-name="<?= e($row['full_name']) ?>">Deactivate</button>
            <?php else: ?>
              <form method="post" action="<?= APP_URL ?>/admin/student_action.php" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="action" value="activate">
                <button type="submit" class="btn-secondary btn-tiny">Activate</button>
              </form>
            <?php endif; ?>
            <a class="btn-secondary btn-tiny" href="<?= APP_URL ?>/admin/student_profile.php?id=<?= (int) $row['id'] ?>">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a href="<?= e(students_url(['page' => $page - 1])) ?>">&laquo; Previous</a><?php else: ?><span class="disabled">&laquo; Previous</span><?php endif; ?>
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <?php if ($p === $page): ?><span class="active"><?= $p ?></span><?php else: ?><a href="<?= e(students_url(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="<?= e(students_url(['page' => $page + 1])) ?>">Next &raquo;</a><?php else: ?><span class="disabled">Next &raquo;</span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Reject dialog -->
<div class="modal-backdrop" id="rejectModal" style="display:none;">
  <div class="modal-box">
    <h3>Reject Registration?</h3>
    <p id="rejectModalText" class="field-hint"></p>
    <form method="post" action="<?= APP_URL ?>/admin/student_action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="student_id" id="rejectStudentId" value="">
      <div class="form-group">
        <label for="reject_reason">Reason</label>
        <textarea id="reject_reason" name="reason" rows="3" required></textarea>
      </div>
      <div class="filter-actions">
        <button type="button" class="btn-secondary" data-modal-cancel>Cancel</button>
        <button type="submit" class="btn" style="background:var(--color-danger);width:auto;padding:11px 20px;">Reject</button>
      </div>
    </form>
  </div>
</div>

<!-- Deactivate dialog -->
<div class="modal-backdrop" id="deactivateModal" style="display:none;">
  <div class="modal-box">
    <h3>Deactivate Student?</h3>
    <p id="deactivateModalText" class="field-hint"></p>
    <form method="post" action="<?= APP_URL ?>/admin/student_action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="deactivate">
      <input type="hidden" name="student_id" id="deactivateStudentId" value="">
      <div class="form-group">
        <label for="deactivate_reason">Reason (optional)</label>
        <textarea id="deactivate_reason" name="reason" rows="3"></textarea>
      </div>
      <div class="filter-actions">
        <button type="button" class="btn-secondary" data-modal-cancel>Cancel</button>
        <button type="submit" class="btn" style="background:var(--color-danger);width:auto;padding:11px 20px;">Deactivate</button>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
