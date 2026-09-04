<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';

$user = require_role('student');
$navUser = $user;

global $pdo;

// --- Parse & validate every filter. Nothing here ever touches WHO the query is
// scoped to (always $user['id']) — only WHICH of that student's own rows are shown. ---

function valid_date_param(?string $value): ?string {
    if (!$value) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

$allowedRanges = ['today', 'week', 'month', 'all'];
$range = in_array($_GET['range'] ?? '', $allowedRanges, true) ? $_GET['range'] : 'today';

$allowedStatuses = ['all', 'present', 'late', 'incomplete'];
$statusFilter = in_array($_GET['status'] ?? '', $allowedStatuses, true) ? $_GET['status'] : 'all';

$searchDate = valid_date_param($_GET['date'] ?? null);
$fromDate = valid_date_param($_GET['from'] ?? null);
$toDate = valid_date_param($_GET['to'] ?? null);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

// Server's configured timezone (Asia/Manila, set once in config/config.php) is the
// only clock used here — never the browser's. A custom search date/range explicitly
// overrides the quick-tab preset; otherwise the active tab determines the window.
$now = new DateTime();
$hasCustomDate = $searchDate || $fromDate || $toDate;

if ($searchDate) {
    $rangeStart = $rangeEnd = $searchDate;
} elseif ($hasCustomDate) {
    $rangeStart = $fromDate;
    $rangeEnd = $toDate;
} else {
    switch ($range) {
        case 'today':
            $rangeStart = $rangeEnd = $now->format('Y-m-d');
            break;
        case 'week':
            $dayOfWeek = (int) $now->format('N'); // 1 (Mon) .. 7 (Sun)
            $rangeStart = (clone $now)->modify('-' . ($dayOfWeek - 1) . ' days')->format('Y-m-d');
            $rangeEnd = (clone $now)->modify('+' . (7 - $dayOfWeek) . ' days')->format('Y-m-d');
            break;
        case 'month':
            $rangeStart = $now->format('Y-m-01');
            $rangeEnd = $now->format('Y-m-t');
            break;
        default: // 'all'
            $rangeStart = null;
            $rangeEnd = null;
    }
}

$history = get_attendance_history($user['id'], [
    'from' => $rangeStart,
    'to' => $rangeEnd,
    'status' => $statusFilter,
    'page' => $page,
    'per_page' => $perPage,
]);

$stats = get_attendance_stats($user['id']);
$summary = calculate_ojt_summary($user['id']);
$ojtStatus = ojt_status_from_summary($summary);
$totalPages = max(1, (int) ceil($history['total'] / $perPage));

function fmt_hours(int $minutes): string {
    return number_format($minutes / 60, 2) . ' hrs';
}

/** Builds a link to this same page with $overrides merged over the current query string. */
function history_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return APP_URL . '/student/attendance_history.php' . ($params ? '?' . http_build_query($params) : '');
}

function fmt_time(?string $datetime): string {
    return $datetime ? date('g:i A', strtotime($datetime)) : '--';
}

$rangeLabels = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'all' => 'All Records'];

$pageTitle = 'Attendance History';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>Attendance History</h1>
  <p class="subtitle">View and monitor your OJT attendance.</p>

  <div class="stat-tile-row">
    <div class="stat-tile">
      <span class="stat-value"><?= (int) $stats['days_present'] ?></span>
      <span class="stat-label">Days Present</span>
    </div>
    <div class="stat-tile">
      <span class="stat-value"><?= e(fmt_hours($summary['completed_minutes'])) ?></span>
      <span class="stat-label">Total Worked Hours</span>
    </div>
    <div class="stat-tile">
      <span class="stat-value"><?= (int) $stats['late_days'] ?></span>
      <span class="stat-label">Late Days</span>
    </div>
    <div class="stat-tile">
      <span class="stat-value"><?= e(fmt_hours($summary['remaining_minutes'])) ?></span>
      <span class="stat-label">OJT Hours Remaining</span>
    </div>
  </div>

  <p class="field-hint">
    Required: <?= e(fmt_hours($summary['required_minutes'])) ?>
    &middot; Progress: <?= e(number_format($summary['percent'], 2)) ?>%
    &middot; Status: <span class="status-pill status-<?= e($ojtStatus['key']) ?>"><?= e(strtoupper($ojtStatus['label'])) ?></span>
  </p>

  <nav class="tab-row">
    <?php foreach ($rangeLabels as $key => $label): ?>
      <a class="tab-link<?= $range === $key && !$hasCustomDate ? ' active' : '' ?>"
         href="<?= e(history_url(['range' => $key, 'date' => null, 'from' => null, 'to' => null, 'page' => null])) ?>">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <form method="get" action="" class="filter-form">
    <input type="hidden" name="range" value="<?= e($range) ?>">
    <div class="filter-field">
      <label for="f_date">Search Date</label>
      <input type="date" id="f_date" name="date" value="<?= e($searchDate ?? '') ?>">
    </div>
    <div class="filter-field">
      <label for="f_status">Status</label>
      <select id="f_status" name="status">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
        <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
        <option value="late" <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late</option>
        <option value="incomplete" <?= $statusFilter === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
      </select>
    </div>
    <div class="filter-field">
      <label for="f_from">From</label>
      <input type="date" id="f_from" name="from" value="<?= e($fromDate ?? '') ?>">
    </div>
    <div class="filter-field">
      <label for="f_to">To</label>
      <input type="date" id="f_to" name="to" value="<?= e($toDate ?? '') ?>">
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn-secondary">Apply</button>
      <a class="btn-secondary" href="<?= e(history_url(['date' => null, 'from' => null, 'to' => null, 'status' => null, 'page' => null])) ?>">Clear</a>
    </div>
  </form>

  <?php if (!$history['rows']): ?>
    <p class="field-hint">
      <?php if ($range === 'today' && !$hasCustomDate && $statusFilter === 'all'): ?>
        You're not timed in today.
      <?php else: ?>
        No attendance records found for this view.
      <?php endif; ?>
    </p>
  <?php else: ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Date</th><th>Time In</th><th>Break</th><th>Time Out</th><th>Worked</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($history['rows'] as $row): ?>
        <?php
          $incomplete = !$row['time_out'];
          $statusKey = $incomplete ? 'incomplete' : $row['status'];
          $statusLabel = $incomplete ? 'Incomplete' : ucfirst($row['status']);
          $breakMin = calculate_break_minutes_for_attendance((int) $row['id']);
        ?>
        <tr>
          <td><?= e(date('M j, Y', strtotime($row['attendance_date']))) ?></td>
          <td><?= fmt_time($row['time_in']) ?></td>
          <td><?= $breakMin > 0 ? e(format_minutes($breakMin)) : '--' ?></td>
          <td><?= fmt_time($row['time_out']) ?></td>
          <td><?= $incomplete ? 'In Progress' : e(format_minutes(calculate_worked_minutes($row, false))) ?></td>
          <td><span class="status-pill status-<?= e($statusKey) ?>"><?= e($statusLabel) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="<?= e(history_url(['page' => $page - 1])) ?>">&laquo; Previous</a>
    <?php else: ?>
      <span class="disabled">&laquo; Previous</span>
    <?php endif; ?>

    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <?php if ($p === $page): ?>
        <span class="active"><?= $p ?></span>
      <?php else: ?>
        <a href="<?= e(history_url(['page' => $p])) ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
      <a href="<?= e(history_url(['page' => $page + 1])) ?>">Next &raquo;</a>
    <?php else: ?>
      <span class="disabled">Next &raquo;</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="form-footer">
    <a href="<?= APP_URL ?>/student/dashboard.php">&larr; Back to Dashboard</a>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
