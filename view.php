<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/layout.php';

$db = Database::getInstance();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete']) && $id) {
    $db->deleteReport($id);
    header('Location: index.php?deleted=1');
    exit;
}

if (!$id || !($report = $db->getReport($id))) {
    renderHead('Not Found — DMARC Analyzer');
    echo '<div class="empty-state"><div class="icon">🔍</div><h3>Report not found</h3><p><a href="index.php">Back to dashboard</a></p></div>';
    renderFoot();
    exit;
}

$records = $db->getReportRecords($id);

// Aggregate stats for this report
$totalMsg    = array_sum(array_column($records, 'count'));
$passCount   = 0;
$failCount   = 0;
$dkimOnly    = 0;
$spfOnly     = 0;

foreach ($records as $r) {
    $c = (int)$r['count'];
    $d = $r['dkim_result'] === 'pass';
    $s = $r['spf_result']  === 'pass';
    if ($d && $s)       $passCount += $c;
    elseif (!$d && !$s) $failCount += $c;
    elseif ($d)         $dkimOnly  += $c;
    elseif ($s)         $spfOnly   += $c;
}

$passRate = $totalMsg > 0 ? round($passCount / $totalMsg * 100, 1) : 0;

// Source IP breakdown
$ipStats = [];
foreach ($records as $r) {
    $ip = $r['source_ip'];
    if (!isset($ipStats[$ip])) {
        $ipStats[$ip] = ['count' => 0, 'pass' => 0, 'fail' => 0, 'org' => $r['auth_spf_domain'] ?: $r['auth_dkim_domain']];
    }
    $ipStats[$ip]['count'] += (int)$r['count'];
    if ($r['dkim_result'] === 'pass' && $r['spf_result'] === 'pass') {
        $ipStats[$ip]['pass'] += (int)$r['count'];
    } elseif ($r['dkim_result'] !== 'pass' && $r['spf_result'] !== 'pass') {
        $ipStats[$ip]['fail'] += (int)$r['count'];
    }
}
arsort($ipStats);

renderHead(htmlspecialchars($report['domain']) . ' — DMARC Report');
?>

<div class="actions-row">
  <a href="index.php" class="btn btn-ghost">← Dashboard</a>
  <div class="spacer"></div>
  <form method="post" onsubmit="return confirm('Delete this report and all its records?')">
    <input type="hidden" name="delete" value="1">
    <button class="btn btn-danger" type="submit">Delete Report</button>
  </form>
</div>

<!-- Header -->
<div class="page-title"><?= htmlspecialchars($report['domain']) ?></div>
<div class="page-subtitle">
  Report from <strong><?= htmlspecialchars($report['org_name'] ?: 'Unknown') ?></strong>
  &nbsp;·&nbsp;
  <?= fmtDate((int)$report['date_begin']) ?> to <?= fmtDate((int)$report['date_end']) ?>
  &nbsp;·&nbsp;
  ID: <code style="color:var(--accent);font-size:11px"><?= htmlspecialchars($report['report_id']) ?></code>
</div>

<!-- Stats row -->
<div class="stat-grid section">
  <div class="stat-card">
    <div class="label">Total Messages</div>
    <div class="value blue"><?= fmtNum($totalMsg) ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Fully Aligned</div>
    <div class="value green"><?= fmtNum($passCount) ?></div>
    <div class="sub"><?= $passRate ?>% pass rate</div>
  </div>
  <div class="stat-card">
    <div class="label">DKIM Only</div>
    <div class="value yellow"><?= fmtNum($dkimOnly) ?></div>
    <div class="sub">SPF failed</div>
  </div>
  <div class="stat-card">
    <div class="label">SPF Only</div>
    <div class="value yellow"><?= fmtNum($spfOnly) ?></div>
    <div class="sub">DKIM failed</div>
  </div>
  <div class="stat-card">
    <div class="label">Full Fail</div>
    <div class="value red"><?= fmtNum($failCount) ?></div>
    <div class="sub">Both failed</div>
  </div>
</div>

<!-- Pass rate bar -->
<?php if ($totalMsg > 0): ?>
<div class="section">
  <div class="pass-bar-wrap">
    <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:4px;">
      <span>Alignment pass rate (DKIM + SPF)</span><span><?= $passRate ?>%</span>
    </div>
    <div class="pass-bar-track"><div class="pass-bar-fill" style="width:<?= $passRate ?>%"></div></div>
  </div>
</div>
<?php endif; ?>

<div class="two-col section">

  <!-- Published Policy -->
  <div class="card">
    <h2>Published Policy</h2>
    <div class="policy-grid">
      <div class="policy-item">
        <div class="key">Domain Policy (p=)</div>
        <div class="val"><?= badge($report['policy_p'] ?? 'none') ?></div>
      </div>
      <div class="policy-item">
        <div class="key">Subdomain (sp=)</div>
        <div class="val"><?= badge($report['policy_sp'] ?? 'none') ?></div>
      </div>
      <div class="policy-item">
        <div class="key">Percentage (pct=)</div>
        <div class="val"><?= (int)($report['policy_pct'] ?? 100) ?>%</div>
      </div>
      <div class="policy-item">
        <div class="key"><abbr title="DKIM alignment mode: r=relaxed, s=strict">DKIM align (adkim=)</abbr></div>
        <div class="val"><?= htmlspecialchars($report['adkim'] ?? 'r') === 's' ? 'strict' : 'relaxed' ?></div>
      </div>
      <div class="policy-item">
        <div class="key"><abbr title="SPF alignment mode: r=relaxed, s=strict">SPF align (aspf=)</abbr></div>
        <div class="val"><?= htmlspecialchars($report['aspf'] ?? 'r') === 's' ? 'strict' : 'relaxed' ?></div>
      </div>
    </div>
  </div>

  <!-- Reporter info -->
  <div class="card">
    <h2>Reporter</h2>
    <div class="meta-row" style="flex-direction:column;gap:10px">
      <div class="meta-item">
        <div class="key">Organisation</div>
        <div class="val"><?= htmlspecialchars($report['org_name'] ?: '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="key">Email</div>
        <div class="val"><?= htmlspecialchars($report['org_email'] ?: '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="key">Report Period</div>
        <div class="val"><?= fmtDate((int)$report['date_begin']) ?> → <?= fmtDate((int)$report['date_end']) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Source IP summary -->
<?php if (!empty($ipStats)): ?>
<div class="card section">
  <h2>Source IP Summary</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Source IP</th>
          <th>Auth Domain</th>
          <th>Messages</th>
          <th>Aligned</th>
          <th>Failed</th>
          <th>Pass Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ipStats as $ip => $s):
            $pr = $s['count'] > 0 ? round($s['pass']/$s['count']*100,1) : 0;
        ?>
        <tr>
          <td><span class="ip-mono"><?= htmlspecialchars($ip) ?></span></td>
          <td><?= htmlspecialchars($s['org'] ?: '—') ?></td>
          <td><?= fmtNum($s['count']) ?></td>
          <td><?= $s['pass'] > 0 ? '<span style="color:var(--green)">' . fmtNum($s['pass']) . '</span>' : '0' ?></td>
          <td><?= $s['fail'] > 0 ? '<span style="color:var(--red)">' . fmtNum($s['fail']) . '</span>' : '0' ?></td>
          <td>
            <span style="color:<?= $pr >= 90 ? 'var(--green)' : ($pr >= 70 ? 'var(--yellow)' : 'var(--red)') ?>">
              <?= $pr ?>%
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Records detail -->
<div class="card section">
  <h2>All Records (<?= count($records) ?>)</h2>
  <?php if (empty($records)): ?>
  <p style="color:var(--muted)">No records in this report.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Source IP</th>
          <th>Count</th>
          <th>Disposition</th>
          <th><abbr title="DKIM alignment result (evaluated)">DKIM</abbr></th>
          <th><abbr title="SPF alignment result (evaluated)">SPF</abbr></th>
          <th>Header From</th>
          <th>Envelope To</th>
          <th>Auth DKIM</th>
          <th>Auth SPF</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
          <td><span class="ip-mono"><?= htmlspecialchars($r['source_ip'] ?: '—') ?></span></td>
          <td><?= fmtNum((int)$r['count']) ?></td>
          <td><?= badge($r['disposition'] ?? 'none') ?></td>
          <td><?= badge($r['dkim_result'] ?? '—') ?></td>
          <td><?= badge($r['spf_result']  ?? '—') ?></td>
          <td><?= htmlspecialchars($r['header_from']  ?: '—') ?></td>
          <td><?= htmlspecialchars($r['envelope_to']  ?: '—') ?></td>
          <td>
            <?php if ($r['auth_dkim_domain']): ?>
            <small>
              <?= htmlspecialchars($r['auth_dkim_domain']) ?>
              <?= $r['auth_dkim_selector'] ? "(<em>s=</em>" . htmlspecialchars($r['auth_dkim_selector']) . ")" : '' ?>
              → <?= badge($r['auth_dkim_result'] ?? '—') ?>
            </small>
            <?php else: echo '—'; endif; ?>
          </td>
          <td>
            <?php if ($r['auth_spf_domain']): ?>
            <small>
              <?= htmlspecialchars($r['auth_spf_domain']) ?>
              → <?= badge($r['auth_spf_result'] ?? '—') ?>
            </small>
            <?php else: echo '—'; endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php renderFoot(); ?>
