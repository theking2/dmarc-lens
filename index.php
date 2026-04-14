<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/layout.php';

$db      = Database::getInstance();
$stats   = $db->getStats();
$domains = $db->getDomainSummary();
$reports = $db->getRecentReports(30);

$totalMsg    = (int)$stats['total_messages'];
$fullyAligned = (int)$stats['fully_aligned'];
$fullFail     = (int)$stats['full_fail'];
$passRate     = $totalMsg > 0 ? round($fullyAligned / $totalMsg * 100, 1) : 0;

renderHead('DMARC Analyzer — Dashboard');
?>

<div class="page-title">Dashboard</div>
<div class="page-subtitle">Aggregate DMARC report analysis</div>

<!-- Stats -->
<div class="stat-grid section">
  <div class="stat-card">
    <div class="label">Total Reports</div>
    <div class="value blue"><?= fmtNum((int)$stats['total_reports']) ?></div>
    <div class="sub"><?= fmtNum((int)$stats['total_domains']) ?> domain(s)</div>
  </div>
  <div class="stat-card">
    <div class="label">Total Messages</div>
    <div class="value blue"><?= fmtNum($totalMsg) ?></div>
    <div class="sub">across all reports</div>
  </div>
  <div class="stat-card">
    <div class="label">Fully Aligned</div>
    <div class="value green"><?= fmtNum($fullyAligned) ?></div>
    <div class="sub"><?= passRate($fullyAligned, $totalMsg) ?> pass rate</div>
  </div>
  <div class="stat-card">
    <div class="label">Full Fail</div>
    <div class="value red"><?= fmtNum($fullFail) ?></div>
    <div class="sub"><?= passRate($fullFail, $totalMsg) ?> fail rate</div>
  </div>
</div>

<?php if ($totalMsg > 0): ?>
<div class="section">
  <div class="pass-bar-wrap">
    <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:4px;">
      <span>DKIM + SPF pass rate</span>
      <span><?= $passRate ?>%</span>
    </div>
    <div class="pass-bar-track"><div class="pass-bar-fill" style="width:<?= $passRate ?>%"></div></div>
  </div>
</div>
<?php endif; ?>

<!-- Domain summary -->
<?php if (!empty($domains)): ?>
<div class="section card">
  <h2>By Domain</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Domain</th>
          <th>Reports</th>
          <th>Messages</th>
          <th>Aligned</th>
          <th>Failures</th>
          <th>Pass Rate</th>
          <th>Last Report</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($domains as $d):
            $dm = (int)$d['total_messages'];
            $dp = (int)$d['pass_count'];
            $df = (int)$d['fail_count'];
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($d['domain']) ?></strong></td>
          <td><?= $d['report_count'] ?></td>
          <td><?= fmtNum($dm) ?></td>
          <td><?= fmtNum($dp) ?></td>
          <td><?= $df > 0 ? '<span style="color:var(--red)">' . fmtNum($df) . '</span>' : '0' ?></td>
          <td>
            <?php $pr = $dm > 0 ? round($dp/$dm*100,1) : 0; ?>
            <span style="color:<?= $pr >= 90 ? 'var(--green)' : ($pr >= 70 ? 'var(--yellow)' : 'var(--red)') ?>">
              <?= $pr ?>%
            </span>
          </td>
          <td><?= fmtDate((int)$d['last_report']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Recent Reports -->
<div class="section card">
  <div class="actions-row">
    <h2 style="margin:0">Recent Reports</h2>
    <div class="spacer"></div>
    <a href="upload.php" class="btn btn-primary">+ Upload Report</a>
  </div>

  <?php if (empty($reports)): ?>
  <div class="empty-state">
    <div class="icon">📭</div>
    <h3>No reports yet</h3>
    <p>Upload your first DMARC aggregate report to get started.</p>
    <br>
    <a href="upload.php" class="btn btn-primary">Upload Report</a>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Reporter</th>
          <th>Domain</th>
          <th>Date Range</th>
          <th>Policy</th>
          <th>Messages</th>
          <th>Pass</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reports as $r):
            $rm  = (int)$r['total_messages'];
            $rp  = (int)$r['pass_count'];
            $rpr = $rm > 0 ? round($rp/$rm*100,1) : 0;
        ?>
        <tr>
          <td><?= htmlspecialchars($r['org_name'] ?: '—') ?></td>
          <td><strong><?= htmlspecialchars($r['domain']) ?></strong></td>
          <td style="white-space:nowrap">
            <?= fmtDate((int)$r['date_begin']) ?> – <?= fmtDate((int)$r['date_end']) ?>
          </td>
          <td><?= badge($r['policy_p'] ?? 'none') ?></td>
          <td><?= fmtNum($rm) ?></td>
          <td>
            <span style="color:<?= $rpr >= 90 ? 'var(--green)' : ($rpr >= 70 ? 'var(--yellow)' : 'var(--red)') ?>">
              <?= $rpr ?>%
            </span>
          </td>
          <td>
            <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-ghost" style="padding:4px 10px;font-size:12px">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php renderFoot(); ?>
