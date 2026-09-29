<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zatca.php';
require_login();

$pdo = db();
$user = current_user();
// Credit notes reduce sales, so they count as negative amounts.
$sign = "(CASE WHEN type_code = '381' THEN -1 ELSE 1 END)";
$period = function (string $from, string $to) use ($pdo, $sign): array {
    $st = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM($sign * taxable_total), 0) sales, COALESCE(SUM($sign * vat_total), 0) vat FROM invoices WHERE issue_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    return $st->fetch();
};
$cur = $period(date('Y-m-01'), date('Y-m-t'));
$prev = $period(date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month')));
$trend = function (float $now, float $before): array {
    if ($before == 0.0) return $now == 0.0 ? ['flat', '0%'] : ['up', 'new'];
    $pct = ($now - $before) / abs($before) * 100;
    return [abs($pct) < 0.5 ? 'flat' : ($pct > 0 ? 'up' : 'down'), ($pct > 0 ? '+' : '') . number_format($pct, 1) . '%'];
};

// Last 30 days, one point per day
$rows = $pdo->query("SELECT issue_date d, SUM($sign * taxable_total) s, SUM($sign * vat_total) v FROM invoices WHERE issue_date >= CURDATE() - INTERVAL 29 DAY GROUP BY issue_date")->fetchAll(PDO::FETCH_UNIQUE);
$days = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[] = ['label' => date('d M', strtotime($d)), 'sales' => round((float)($rows[$d]['s'] ?? 0), 2), 'vat' => round((float)($rows[$d]['v'] ?? 0), 2)];
}
$hasSales = (bool)$rows;

$statusCounts = $pdo->query("SELECT zatca_status, COUNT(*) c FROM invoices GROUP BY zatca_status")->fetchAll(PDO::FETCH_KEY_PAIR);
$accepted = (int)($statusCounts['cleared'] ?? 0) + (int)($statusCounts['reported'] ?? 0);
$waiting = (int)($statusCounts['not_submitted'] ?? 0) + (int)($statusCounts['pending'] ?? 0);
$refused = (int)($statusCounts['rejected'] ?? 0) + (int)($statusCounts['failed'] ?? 0);
$totalDocs = $accepted + $waiting + $refused;
$b2b = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE subtype = '01'")->fetchColumn();
$customers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$recent = $pdo->query("SELECT i.*, c.name customer_name FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id ORDER BY i.id DESC LIMIT 7")->fetchAll();
$unit = $pdo->query("SELECT * FROM egs_units ORDER BY id LIMIT 1")->fetch();

$sellerOk = seller_configured(seller_settings());
$unitOk = !zatca_onboarding_problems();
$hasCert = setting('zatca_production_cert') !== '';
$ready = zatca_signing_ready() !== null;
$enabled = setting('zatca_enabled') === '1';
$certDays = null;
if ($hasCert) { try { $certDays = (int)floor((strtotime(cert_info(setting('zatca_production_cert'))['valid_to']) - time()) / 86400); } catch (Throwable $e) {} }
$checks = [
    ['Company details', $sellerOk, $sellerOk ? 'Complete' : 'Missing fields', 'settings.php'],
    ['Invoicing unit details', $unitOk, $unitOk ? 'Complete' : 'Missing fields', 'zatca.php'],
    ['ZATCA certificate', $hasCert && $ready, $certDays !== null ? "Expires in $certDays days" : 'Not installed', 'zatca.php'],
    ['Submission enabled', $enabled && $ready, $enabled ? ucfirst(setting('zatca_env', 'sandbox')) : 'Off', 'zatca.php'],
];
$done = count(array_filter($checks, fn($c) => $c[1]));
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

function status_badge(string $s): string
{
    $map = ['not_submitted' => ['secondary', 'bi-dash-circle'], 'pending' => ['warning', 'bi-hourglass-split'], 'cleared' => ['success', 'bi-check-circle'], 'reported' => ['success', 'bi-check-circle'], 'rejected' => ['danger', 'bi-x-circle'], 'failed' => ['danger', 'bi-exclamation-circle']];
    [$tone, $icon] = $map[$s] ?? ['secondary', 'bi-dash-circle'];
    return '<span class="badge bg-' . $tone . '"><i class="bi ' . $icon . ' me-1"></i>' . h(str_replace('_', ' ', $s)) . '</span>';
}
function trend_pill(array $t): string
{
    $icon = ['up' => 'bi-arrow-up-right', 'down' => 'bi-arrow-down-right', 'flat' => 'bi-dash'][$t[0]];
    return '<span class="trend trend-' . $t[0] . '"><i class="bi ' . $icon . '"></i>' . h($t[1]) . '</span>';
}

page_header('Dashboard', 'dashboard');
?>
<div class="page-head">
  <div>
    <h1><?= $greeting ?>, <?= h($user['username'] ?? '') ?></h1>
    <p><?= date('l, j F Y') ?> · here is how your invoicing is doing.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="invoices.php" class="btn btn-outline-secondary"><i class="bi bi-receipt me-1"></i>All invoices</a>
    <a href="invoice_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New invoice</a>
  </div>
</div>

<?php if (!$sellerOk): ?>
<div class="alert alert-warning d-flex align-items-center gap-2" role="alert"><i class="bi bi-exclamation-triangle"></i><div>Company details are incomplete. <a href="settings.php">Complete them</a> before issuing invoices.</div></div>
<?php endif; ?>

<div class="bento">
  <section class="card kpi span-3 rise" aria-label="Sales this month">
    <div class="kpi-top"><span class="kpi-icon tone-primary"><i class="bi bi-graph-up-arrow"></i></span><?= trend_pill($trend((float)$cur['sales'], (float)$prev['sales'])) ?></div>
    <div class="kpi-label">Sales this month</div>
    <div class="kpi-value"><?= number_format($cur['sales'], 2) ?><small>SAR</small></div>
    <div class="kpi-foot">Excluding VAT · last month <?= number_format($prev['sales'], 2) ?></div>
  </section>
  <section class="card kpi span-3 rise" aria-label="VAT this month">
    <div class="kpi-top"><span class="kpi-icon tone-warning"><i class="bi bi-percent"></i></span><?= trend_pill($trend((float)$cur['vat'], (float)$prev['vat'])) ?></div>
    <div class="kpi-label">VAT collected this month</div>
    <div class="kpi-value"><?= number_format($cur['vat'], 2) ?><small>SAR</small></div>
    <div class="kpi-foot">Last month <?= number_format($prev['vat'], 2) ?></div>
  </section>
  <section class="card kpi span-3 rise" aria-label="Documents this month">
    <div class="kpi-top"><span class="kpi-icon tone-info"><i class="bi bi-files"></i></span><?= trend_pill($trend((float)$cur['c'], (float)$prev['c'])) ?></div>
    <div class="kpi-label">Documents this month</div>
    <div class="kpi-value"><?= (int)$cur['c'] ?></div>
    <div class="kpi-foot"><?= $totalDocs ?> in total · <?= $customers ?> customers</div>
  </section>
  <section class="card kpi span-3 rise" aria-label="ZATCA acceptance">
    <div class="kpi-top"><span class="kpi-icon tone-success"><i class="bi bi-patch-check"></i></span>
      <?php if ($waiting + $refused): ?><span class="trend trend-down"><i class="bi bi-clock"></i><?= $waiting + $refused ?> open</span><?php else: ?><span class="trend trend-up"><i class="bi bi-check2"></i>all clear</span><?php endif; ?></div>
    <div class="kpi-label">Accepted by ZATCA</div>
    <div class="kpi-value"><?= $totalDocs ? number_format($accepted / $totalDocs * 100, 0) : 0 ?><small>%</small></div>
    <div class="kpi-foot"><?= $accepted ?> of <?= $totalDocs ?> documents</div>
  </section>

  <section class="card span-8 rise">
    <div class="panel-head">
      <div><h2 class="panel-title">Sales and VAT</h2><p class="panel-sub">Daily totals for the last 30 days, in SAR</p></div>
      <div class="legend p-0"><span><i class="swatch" style="background:var(--chart-1)"></i>Sales</span><span><i class="swatch" style="background:var(--chart-2)"></i>VAT</span></div>
    </div>
    <div class="chart-box">
      <?php if ($hasSales): ?><canvas id="salesChart" role="img" aria-label="Line chart of daily sales and VAT for the last 30 days"></canvas>
      <?php else: ?><div class="empty"><i class="bi bi-bar-chart-line"></i><strong>No sales in the last 30 days</strong><span>Issue your first invoice to see the trend here.</span><a class="btn btn-primary btn-sm mt-2" href="invoice_form.php">Create invoice</a></div><?php endif; ?>
    </div>
  </section>

  <section class="card span-4 rise">
    <div class="panel-head"><div><h2 class="panel-title">ZATCA status</h2><p class="panel-sub">All documents</p></div></div>
    <div class="chart-box sm">
      <?php if ($totalDocs): ?><canvas id="statusChart" role="img" aria-label="Doughnut chart: <?= $accepted ?> accepted, <?= $waiting ?> waiting, <?= $refused ?> rejected or failed"></canvas>
      <?php else: ?><div class="empty"><i class="bi bi-pie-chart"></i><span>No documents yet.</span></div><?php endif; ?>
    </div>
    <div class="legend">
      <span><i class="swatch" style="background:var(--chart-3)"></i>Accepted <b><?= $accepted ?></b></span>
      <span><i class="swatch" style="background:var(--chart-5)"></i>Waiting <b><?= $waiting ?></b></span>
      <span><i class="swatch" style="background:var(--chart-4)"></i>Rejected / failed <b><?= $refused ?></b></span>
      <span><i class="bi bi-building"></i>B2B <b><?= $b2b ?></b></span>
      <span><i class="bi bi-person"></i>B2C <b><?= $totalDocs - $b2b ?></b></span>
    </div>
  </section>

  <section class="card span-8 rise">
    <div class="panel-head pb-2"><div><h2 class="panel-title">Recent documents</h2><p class="panel-sub">Latest invoices and notes</p></div><a class="btn btn-outline-secondary btn-sm" href="invoices.php">View all</a></div>
    <?php if (!$recent): ?><div class="empty"><i class="bi bi-receipt"></i><strong>No documents yet</strong><span>Invoices you issue will be listed here.</span></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover">
      <thead><tr><th>Number</th><th>Customer</th><th>Date</th><th class="text-end">Total (SAR)</th><th>ZATCA</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><a href="invoice_view.php?id=<?= $r['id'] ?>"><?= h($r['invoice_number']) ?></a><div class="doc-type"><?= h(DOC_TYPES[$r['type_code']] ?? $r['type_code']) ?> · <?= $r['subtype'] === '02' ? 'Simplified' : 'Standard' ?></div></td>
          <td><?= h($r['customer_name'] ?? 'Walk-in customer') ?></td>
          <td class="text-nowrap"><?= h(date('d M Y', strtotime($r['issue_date']))) ?></td>
          <td class="text-end amount"><?= ($r['type_code'] === '381' ? '−' : '') . number_format($r['grand_total'], 2) ?></td>
          <td><?= status_badge($r['zatca_status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <div class="span-4 d-flex flex-column gap-3">
    <section class="card rise">
      <div class="panel-head"><div><h2 class="panel-title">ZATCA readiness</h2><p class="panel-sub"><?= $done ?> of <?= count($checks) ?> steps complete</p></div>
        <span class="badge bg-<?= $done === count($checks) ? 'success' : 'warning' ?>"><?= $done === count($checks) ? 'Live' : 'Setup needed' ?></span></div>
      <div class="progress-thin" role="progressbar" aria-valuenow="<?= $done ?>" aria-valuemin="0" aria-valuemax="<?= count($checks) ?>" aria-label="Setup progress"><span style="width:<?= $done / count($checks) * 100 ?>%"></span></div>
      <ul class="checklist mt-2">
        <?php foreach ($checks as [$label, $ok, $meta, $link]): ?>
        <li><span class="state <?= $ok ? 'ok' : 'todo' ?>"><i class="bi <?= $ok ? 'bi-check-lg' : 'bi-circle' ?>"></i></span>
          <?php if ($ok): ?><span><?= $label ?></span><?php else: ?><a href="<?= $link ?>"><?= $label ?></a><?php endif; ?>
          <span class="meta"><?= h($meta) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <div class="px-3 pb-3 pt-2 d-flex justify-content-between small text-muted"><span>Unit: <?= h($unit['name']) ?></span><span>Counter (ICV): <b><?= (int)$unit['last_icv'] ?></b></span></div>
    </section>

    <section class="card rise">
      <div class="panel-head"><h2 class="panel-title">Quick actions</h2></div>
      <div class="quick">
        <a href="invoice_form.php?subtype=01"><i class="bi bi-building tone-primary"></i>B2B invoice</a>
        <a href="invoice_form.php?subtype=02"><i class="bi bi-bag tone-info"></i>B2C invoice</a>
        <a href="customers.php?action=new"><i class="bi bi-person-plus tone-success"></i>Add customer</a>
        <a href="zatca_logs.php"><i class="bi bi-journal-text tone-warning"></i>Response log</a>
      </div>
    </section>
  </div>
</div>

<?php if ($hasSales || $totalDocs): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
  const days = <?= json_encode($days) ?>;
  const status = <?= json_encode([$accepted, $waiting, $refused]) ?>;
  const charts = [];
  const css = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const money = v => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const still = matchMedia('(prefers-reduced-motion: reduce)').matches;

  function draw() {
    charts.splice(0).forEach(c => c.destroy());
    Chart.defaults.font.family = '"Plus Jakarta Sans", system-ui, sans-serif';
    Chart.defaults.color = css('--muted');
    const tooltip = { backgroundColor: css('--text'), titleColor: css('--surface'), bodyColor: css('--surface'), padding: 10, cornerRadius: 10, displayColors: true, boxPadding: 4 };

    const sales = document.getElementById('salesChart');
    if (sales) {
      const ctx = sales.getContext('2d');
      const fill = ctx.createLinearGradient(0, 0, 0, 280);
      fill.addColorStop(0, css('--chart-1') + '55');
      fill.addColorStop(1, css('--chart-1') + '00');
      charts.push(new Chart(ctx, {
        type: 'line',
        data: { labels: days.map(d => d.label), datasets: [
          { label: 'Sales', data: days.map(d => d.sales), borderColor: css('--chart-1'), backgroundColor: fill, fill: true, tension: .35, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 5, pointHitRadius: 18 },
          { label: 'VAT', data: days.map(d => d.vat), borderColor: css('--chart-2'), borderDash: [6, 4], tension: .35, borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHitRadius: 18 }
        ] },
        options: { responsive: true, maintainAspectRatio: false, animation: still ? false : { duration: 500 }, interaction: { mode: 'index', intersect: false },
          plugins: { legend: { display: false }, tooltip: Object.assign({}, tooltip, { callbacks: { label: c => ' ' + c.dataset.label + ': SAR ' + money(c.parsed.y) } }) },
          scales: { x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0 } },
                    y: { beginAtZero: true, border: { display: false }, grid: { color: css('--border') }, ticks: { maxTicksLimit: 6, padding: 8, callback: v => Math.abs(v) >= 1000 ? (v / 1000).toLocaleString('en-US') + 'k' : v } } }, layout: { padding: { left: 4, right: 4 } } }
      }));
    }

    const st = document.getElementById('statusChart');
    if (st) {
      charts.push(new Chart(st, {
        type: 'doughnut',
        data: { labels: ['Accepted', 'Waiting', 'Rejected / failed'], datasets: [{ data: status, backgroundColor: [css('--chart-3'), css('--chart-5'), css('--chart-4')], borderColor: css('--surface'), borderWidth: 3, hoverOffset: 6 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '68%', animation: still ? false : { duration: 500 }, plugins: { legend: { display: false }, tooltip: tooltip } }
      }));
    }
  }
  draw();
  document.addEventListener('themechange', draw);
})();
</script>
<?php endif; ?>
<?php page_footer(); ?>
