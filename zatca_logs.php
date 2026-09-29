<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$pdo = db();

function pretty_json(?string $s): string
{
    if ($s === null || $s === '') return '';
    $j = json_decode($s, true);
    if (!is_array($j)) return $s;
    foreach (['clearedInvoice', 'invoice'] as $k) {
        if (isset($j[$k]) && is_string($j[$k]) && strlen($j[$k]) > 200) $j[$k] = substr($j[$k], 0, 80) . '… [' . strlen($j[$k]) . ' chars]';
    }
    return json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Download the cleared XML returned by ZATCA
if (isset($_GET['cleared'])) {
    $st = $pdo->prepare("SELECT invoice_number, response_body FROM zatca_logs WHERE id = ?"); $st->execute([(int)$_GET['cleared']]);
    $row = $st->fetch();
    $j = $row ? json_decode((string)$row['response_body'], true) : null;
    if (empty($j['clearedInvoice'])) { http_response_code(404); exit('No cleared invoice in this response'); }
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="cleared_' . preg_replace('/[^A-Za-z0-9]/', '-', $row['invoice_number']) . '.xml"');
    echo base64_decode($j['clearedInvoice']); exit;
}

// Detail view
if (isset($_GET['id'])) {
    $st = $pdo->prepare("SELECT l.*, u.username FROM zatca_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ?"); $st->execute([(int)$_GET['id']]);
    $lg = $st->fetch();
    if (!$lg) { http_response_code(404); exit('Log entry not found'); }
    $resp = json_decode((string)$lg['response_body'], true);
    page_header('Log #' . $lg['id'], 'logs');
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="mb-0">Log entry #<?= $lg['id'] ?> <?= log_result_badge($lg['result']) ?></h3><a href="zatca_logs.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to log</a></div>
    <div class="card shadow-sm mb-3"><div class="card-body"><dl class="row mb-0 small">
      <dt class="col-sm-2">Invoice</dt><dd class="col-sm-4"><?= $lg['invoice_id'] ? '<a href="invoice_view.php?id=' . (int)$lg['invoice_id'] . '">' . h($lg['invoice_number']) . '</a>' : '—' ?></dd>
      <dt class="col-sm-2">Time</dt><dd class="col-sm-4"><?= h($lg['created_at']) ?></dd>
      <dt class="col-sm-2">Action</dt><dd class="col-sm-4"><?= h($lg['action']) ?></dd>
      <dt class="col-sm-2">Environment</dt><dd class="col-sm-4"><?= h($lg['environment'] ?? '—') ?></dd>
      <dt class="col-sm-2">Endpoint</dt><dd class="col-sm-4 text-break"><?= h($lg['endpoint'] ?? '—') ?></dd>
      <dt class="col-sm-2">HTTP status</dt><dd class="col-sm-4"><?= h($lg['http_status'] ?? '—') ?></dd>
      <dt class="col-sm-2">ZATCA status</dt><dd class="col-sm-4"><?= h($lg['zatca_status'] ?? '—') ?></dd>
      <dt class="col-sm-2">Duration</dt><dd class="col-sm-4"><?= $lg['duration_ms'] !== null ? (int)$lg['duration_ms'] . ' ms' : '—' ?></dd>
      <dt class="col-sm-2">User</dt><dd class="col-sm-10"><?= h($lg['username'] ?? '—') ?></dd>
    </dl></div></div>

    <?php foreach (['errorMessages' => ['Errors', 'danger'], 'warningMessages' => ['Warnings', 'warning'], 'infoMessages' => ['Info', 'info']] as $k => [$label, $color]):
        $items = $resp['validationResults'][$k] ?? []; if (!$items) continue; ?>
    <div class="card shadow-sm mb-3 border-<?= $color ?>"><div class="card-header bg-white"><strong><?= $label ?></strong> <span class="badge bg-<?= $color ?>"><?= count($items) ?></span></div>
      <table class="table table-sm mb-0"><thead class="table-light"><tr><th>Code</th><th>Category</th><th>Message</th></tr></thead><tbody>
      <?php foreach ($items as $m): ?><tr><td class="text-nowrap"><code><?= h($m['code'] ?? '') ?></code></td><td><?= h($m['category'] ?? '') ?></td><td><?= h($m['message'] ?? '') ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-3"><div class="card-header bg-white"><strong>Message</strong></div><div class="card-body"><pre class="mb-0 small" style="white-space:pre-wrap"><?= h($lg['message'] ?? '') ?></pre></div></div>
    <div class="row g-3">
      <div class="col-lg-6"><div class="card shadow-sm"><div class="card-header bg-white"><strong>Request</strong></div><div class="card-body"><pre class="mb-0 small" style="white-space:pre-wrap;word-break:break-all"><?= h(pretty_json($lg['request_body']) ?: '— nothing sent —') ?></pre></div></div></div>
      <div class="col-lg-6"><div class="card shadow-sm"><div class="card-header bg-white d-flex justify-content-between"><strong>Response</strong>
        <?php if (!empty($resp['clearedInvoice'])): ?><a class="small" href="zatca_logs.php?cleared=<?= $lg['id'] ?>"><i class="bi bi-download me-1"></i>Cleared XML</a><?php endif; ?></div>
        <div class="card-body"><pre class="mb-0 small" style="white-space:pre-wrap;word-break:break-all"><?= h(pretty_json($lg['response_body']) ?: '— no response —') ?></pre></div></div></div>
    </div>
    <?php
    page_footer(); exit;
}

// List view
$q = trim($_GET['q'] ?? '');
$result = $_GET['result'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$where = ' WHERE 1'; $params = [];
if ($q !== '') { $where .= " AND (invoice_number LIKE ? OR message LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if (in_array($result, ['success', 'warning', 'error', 'not_sent'], true)) { $where .= " AND result = ?"; $params[] = $result; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND created_at >= ?"; $params[] = "$from 00:00:00"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where .= " AND created_at <= ?"; $params[] = "$to 23:59:59"; }
$st = $pdo->prepare("SELECT * FROM zatca_logs $where ORDER BY id DESC LIMIT 300"); $st->execute($params); $rows = $st->fetchAll();
$counts = $pdo->query("SELECT result, COUNT(*) c FROM zatca_logs GROUP BY result")->fetchAll(PDO::FETCH_KEY_PAIR);

page_header('ZATCA Response Log', 'logs');
?>
<h3 class="mb-4">ZATCA Response Log</h3>
<div class="row g-3 mb-3">
<?php foreach (['success' => 'Accepted', 'warning' => 'Accepted with warnings', 'error' => 'Rejected / failed', 'not_sent' => 'Not sent'] as $k => $label): ?>
  <div class="col-md-3"><a class="text-decoration-none" href="zatca_logs.php?result=<?= $k ?>"><div class="card stat-card shadow-sm"><div class="card-body"><div class="text-muted small"><?= $label ?></div><div class="value"><?= (int)($counts[$k] ?? 0) ?></div></div></div></a></div>
<?php endforeach; ?>
</div>
<form class="row g-2 mb-3">
  <div class="col-md-4"><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Invoice number or message text"></div>
  <div class="col-md-2"><select class="form-select" name="result"><option value="">All results</option><?php foreach (['success', 'warning', 'error', 'not_sent'] as $r): ?><option value="<?= $r ?>" <?= $result === $r ? 'selected' : '' ?>><?= str_replace('_', ' ', $r) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><input type="date" class="form-control" name="from" value="<?= h($from) ?>"></div>
  <div class="col-md-2"><input type="date" class="form-control" name="to" value="<?= h($to) ?>"></div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
</form>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>#</th><th>Time</th><th>Invoice</th><th>Action</th><th>Env</th><th>Result</th><th>HTTP</th><th>ZATCA status</th><th>Message</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No log entries.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td><?= $r['id'] ?></td><td class="text-nowrap small"><?= h($r['created_at']) ?></td>
<td><?= $r['invoice_id'] ? '<a href="invoice_view.php?id=' . (int)$r['invoice_id'] . '">' . h($r['invoice_number']) . '</a>' : '—' ?></td>
<td><?= h($r['action']) ?></td><td><?= h($r['environment'] ?? '') ?></td><td><?= log_result_badge($r['result']) ?></td><td><?= h($r['http_status'] ?? '—') ?></td><td><?= h($r['zatca_status'] ?? '—') ?></td>
<td class="small"><?= h(mb_strimwidth((string)$r['message'], 0, 120, '…')) ?></td>
<td><a class="btn btn-sm btn-outline-secondary" href="zatca_logs.php?id=<?= $r['id'] ?>">Details</a></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php page_footer(); ?>
