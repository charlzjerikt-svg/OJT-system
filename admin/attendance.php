<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';

$admin = require_role('admin');
$navUser = $admin;

global $pdo;

function valid_date_admin(?string $v): ?string {
    if (!$v) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return ($dt && $dt->format('Y-m-d') === $v) ? $v : null;
}

$search = trim($_GET['search'] ?? '');
$allowedRanges = ['today', 'week', 'month', 'all'];
$range = in_array($_GET['range'] ?? '', $allowedRanges, true) ? $_GET['range'] : 'today';
$allowedStatuses = ['all', 'present', 'late', 'incomplete'];
$statusFilter = in_array($_GET['status'] ?? '', $allowedStatuses, true) ? $_GET['status'] : 'all';
$fromDate = valid_date_admin($_GET['from'] ?? null);
$toDate = valid_date_admin($_GET['to'] ?? null);
$course = trim($_GET['course'] ?? '');
$company = trim($_GET['company'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$now = new DateTime();
$hasCustomDate = $fromDate || $toDate;

if ($hasCustomDate) {
    $rangeStart = $fromDate;
    $rangeEnd = $toDate;
} else {
    switch ($range) {
        case 'today':
            $rangeStart = $rangeEnd = $now->format('Y-m-d');
            break;
        case 'week':
            $dow = (int) $now->format('N');
            $rangeStart = (clone $now)->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
            $rangeEnd = (clone $now)->modify('+' . (7 - $dow) . ' days')->format('Y-m-d');
            break;
        case 'month':
            $rangeStart = $now->format('Y-m-01');
            $rangeEnd = $now->format('Y-m-t');
            break;
        default:
            $rangeStart = $rangeEnd = null;
    }
}

$filters = [
    'search' => $search, 'from' => $rangeStart, 'to' => $rangeEnd,
    'status' => $statusFilter, 'course' => $course, 'company' => $company,
    'page' => $page, 'per_page' => $perPage,
];
$list = get_admin_attendance_list($filters);
$totalPages = max(1, (int) ceil($list['total'] / $perPage));

$activeStudents = $pdo->query(
    "SELECT id, full_name, student_id FROM users WHERE role = 'student' AND status = 'active' ORDER BY full_name"
)->fetchAll();

function attendance_admin_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return APP_URL . '/admin/attendance.php' . ($params ? '?' . http_build_query($params) : '');
}

function fmt_time_admin2(?string $datetime): string {
    return $datetime ? date('g:i A', strtotime($datetime)) : '--';
}

$rangeLabels = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'all' => 'All Records'];

$pageTitle = 'Attendance Management';
$extraScripts = ['/assets/js/admin.js'];
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>Attendance Management</h1>
  <p class="subtitle">View, correct, and export student attendance.</p>

  <nav class="tab-row">
    <?php foreach ($rangeLabels as $key => $label): ?>
      <a class="tab-link<?= $range === $key && !$hasCustomDate ? ' active' : '' ?>"
         href="<?= e(attendance_admin_url(['range' => $key, 'from' => null, 'to' => null, 'page' => null])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <form method="get" action="" class="filter-form">
    <input type="hidden" name="range" value="<?= e($range) ?>">
    <div class="filter-field"><label for="a_search">Search Student</label><input type="text" id="a_search" name="search" placeholder="Name or Student ID" value="<?= e($search) ?>"></div>
    <div class="filter-field"><label for="a_status">Status</label>
      <select id="a_status" name="status">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
        <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
        <option value="late" <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late</option>
        <option value="incomplete" <?= $statusFilter === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
      </select>
    </div>
    <div class="filter-field"><label for="a_course">Course</label><input type="text" id="a_course" name="course" value="<?= e($course) ?>"></div>
    <div class="filter-field"><label for="a_company">Company</label><input type="text" id="a_company" name="company" value="<?= e($company) ?>"></div>
    <div class="filter-field"><label for="a_from">From</label><input type="date" id="a_from" name="from" value="<?= e($fromDate ?? '') ?>"></div>
    <div class="filter-field"><label for="a_to">To</label><input type="date" id="a_to" name="to" value="<?= e($toDate ?? '') ?>"></div>
    <div class="filter-actions">
      <button type="submit" class="btn-secondary">Apply</button>
      <a class="btn-secondary" href="<?= APP_URL ?>/admin/attendance.php">Clear</a>
    </div>
  </form>

  <div class="admin-row-actions" style="margin-bottom:16px;">
    <button type="button" class="btn-secondary" id="openManualEntry">+ Manual Attendance</button>
    <a class="btn-secondary" href="<?= APP_URL ?>/admin/attendance_export.php?<?= http_build_query(array_filter([
      'search' => $search, 'from' => $rangeStart, 'to' => $rangeEnd, 'status' => $statusFilter, 'course' => $course, 'company' => $company,
    ])) ?>">Export CSV</a>
  </div>

  <?php if (!$list['rows']): ?>
    <p class="field-hint">No attendance records found for this view.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Student</th><th>Date</th><th>Time In</th><th>Break</th><th>Time Out</th><th>Worked</th><th>Status</th><th>Source</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($list['rows'] as $row): $incomplete = !$row['time_out']; $breakMin = calculate_break_minutes_for_attendance((int) $row['id']); ?>
        <tr>
          <td><a href="<?= APP_URL ?>/admin/student_profile.php?id=<?= (int) $row['user_id'] ?>"><?= e($row['full_name']) ?></a><br><span class="field-hint" style="margin:0;"><?= e($row['student_id'] ?? '') ?></span></td>
          <td><?= e(date('M j, Y', strtotime($row['attendance_date']))) ?></td>
          <td><?= fmt_time_admin2($row['time_in']) ?></td>
          <td><?= $breakMin > 0 ? e(format_minutes($breakMin)) : '--' ?></td>
          <td><?= fmt_time_admin2($row['time_out']) ?></td>
          <td><?= $incomplete ? 'In Progress' : e(format_minutes(calculate_worked_minutes($row, false))) ?></td>
          <td><span class="status-pill status-<?= $incomplete ? 'incomplete' : e($row['status']) ?>"><?= $incomplete ? 'Incomplete' : e(ucfirst($row['status'])) ?></span></td>
          <td><?= $row['source'] === 'manual' ? '<span class="status-pill status-late">Manual</span>' : 'System' ?></td>
          <td>
            <button type="button" class="btn-secondary btn-tiny"
              data-edit-attendance="<?= (int) $row['id'] ?>"
              data-edit-student="<?= e($row['full_name']) ?>"
              data-edit-time-in="<?= $row['time_in'] ? e(date('H:i', strtotime($row['time_in']))) : '' ?>"
              data-edit-time-out="<?= $row['time_out'] ? e(date('H:i', strtotime($row['time_out']))) : '' ?>">Edit</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a href="<?= e(attendance_admin_url(['page' => $page - 1])) ?>">&laquo; Previous</a><?php else: ?><span class="disabled">&laquo; Previous</span><?php endif; ?>
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <?php if ($p === $page): ?><span class="active"><?= $p ?></span><?php else: ?><a href="<?= e(attendance_admin_url(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="<?= e(attendance_admin_url(['page' => $page + 1])) ?>">Next &raquo;</a><?php else: ?><span class="disabled">Next &raquo;</span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Edit attendance dialog -->
<div class="modal-backdrop" id="editModal" style="display:none;">
  <div class="modal-box">
    <h3>Correct Attendance</h3>
    <p id="editModalText" class="field-hint"></p>
    <form method="post" action="<?= APP_URL ?>/admin/attendance_action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="attendance_id" id="editAttendanceId" value="">
      <div class="form-grid form-grid-2">
        <div class="form-group"><label for="edit_time_in">Time In</label><input type="time" id="edit_time_in" name="time_in" required></div>
        <div class="form-group"><label for="edit_time_out">Time Out</label><input type="time" id="edit_time_out" name="time_out"></div>
      </div>
      <div class="form-group"><label for="edit_reason">Reason (required)</label><textarea id="edit_reason" name="reason" rows="2" required></textarea></div>
      <div class="filter-actions">
        <button type="button" class="btn-secondary" data-modal-cancel>Cancel</button>
        <button type="submit" class="btn" style="width:auto;padding:11px 20px;">Save Correction</button>
      </div>
    </form>
  </div>
</div>

<!-- Manual attendance dialog -->
<div class="modal-backdrop" id="manualModal" style="display:none;">
  <div class="modal-box">
    <h3>Manual Attendance</h3>
    <form method="post" action="<?= APP_URL ?>/admin/attendance_action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="manual">
      <div class="form-group">
        <label for="manual_student">Student</label>
        <select id="manual_student" name="student_id" required>
          <option value="">Select a student</option>
          <?php foreach ($activeStudents as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['full_name']) ?> (<?= e($s['student_id'] ?? '—') ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label for="manual_date">Date</label><input type="date" id="manual_date" name="attendance_date" required max="<?= date('Y-m-d') ?>"></div>
      <div class="form-grid form-grid-2">
        <div class="form-group"><label for="manual_time_in">Time In</label><input type="time" id="manual_time_in" name="time_in" required></div>
        <div class="form-group"><label for="manual_time_out">Time Out</label><input type="time" id="manual_time_out" name="time_out"></div>
      </div>
      <div class="form-group"><label for="manual_reason">Reason (required)</label><textarea id="manual_reason" name="reason" rows="2" required placeholder="e.g. Forgot to time in"></textarea></div>
      <div class="filter-actions">
        <button type="button" class="btn-secondary" data-modal-cancel>Cancel</button>
        <button type="submit" class="btn" style="width:auto;padding:11px 20px;">Create Record</button>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
