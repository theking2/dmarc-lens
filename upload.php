<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/parser.php';
require_once __DIR__ . '/includes/layout.php';

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $files = $_FILES['reports'] ?? null;

    if (!$files || empty($files['name'][0])) {
        $messages[] = ['type' => 'error', 'text' => 'No file selected.'];
    } else {
        $db = Database::getInstance();
        $uploadDir = __DIR__ . '/uploads/';

        // Normalise the $_FILES array for multiple uploads
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $name  = $files['name'][$i];
            $tmp   = $files['tmp_name'][$i];
            $error = $files['error'][$i];

            if ($error !== UPLOAD_ERR_OK) {
                $messages[] = ['type' => 'error', 'text' => "Upload error for '$name': code $error"];
                continue;
            }

            // Validate extension
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xml', 'gz', 'zip'], true)) {
                $messages[] = ['type' => 'error', 'text' => "Unsupported file type: '$name'. Expected .xml, .gz, or .zip"];
                continue;
            }

            // Move to uploads dir with a safe name
            $safeName  = date('YmdHis') . '_' . preg_replace('/[^a-z0-9._-]/i', '_', $name);
            $destPath  = $uploadDir . $safeName;
            if (!move_uploaded_file($tmp, $destPath)) {
                $messages[] = ['type' => 'error', 'text' => "Failed to save '$name'."];
                continue;
            }

            try {
                $parsed   = DmarcParser::parseFile($destPath);
                $reportId = $db->insertReport($parsed['report']);

                if ($reportId === 0) {
                    $messages[] = ['type' => 'warn', 'text' => "Report '$name' already exists (duplicate report_id), skipped."];
                    @unlink($destPath);
                    continue;
                }

                foreach ($parsed['records'] as $rec) {
                    $rec[':report_db_id'] = $reportId;
                    $db->insertRecord($rec);
                }

                $domain = htmlspecialchars($parsed['report'][':domain']);
                $cnt    = count($parsed['records']);
                $messages[] = ['type' => 'success', 'text' => "Imported '$name': $cnt record(s) for domain <strong>$domain</strong>. <a href=\"view.php?id=$reportId\">View report &rarr;</a>"];

            } catch (Throwable $e) {
                @unlink($destPath);
                $messages[] = ['type' => 'error', 'text' => "Parse error for '$name': " . htmlspecialchars($e->getMessage())];
            }
        }
    }
}

// Also handle raw XML paste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['xml_paste'])) {
    $xml = trim($_POST['xml_paste']);
    if ($xml) {
        try {
            $db     = Database::getInstance();
            $parsed = DmarcParser::parseXml($xml);
            $reportId = $db->insertReport($parsed['report']);

            if ($reportId === 0) {
                $messages[] = ['type' => 'warn', 'text' => 'This report already exists (duplicate report_id).'];
            } else {
                foreach ($parsed['records'] as $rec) {
                    $rec[':report_db_id'] = $reportId;
                    $db->insertRecord($rec);
                }
                $domain = htmlspecialchars($parsed['report'][':domain']);
                $cnt    = count($parsed['records']);
                $messages[] = ['type' => 'success', 'text' => "Imported pasted XML: $cnt record(s) for domain <strong>$domain</strong>. <a href=\"view.php?id=$reportId\">View report &rarr;</a>"];
            }
        } catch (Throwable $e) {
            $messages[] = ['type' => 'error', 'text' => 'Parse error: ' . htmlspecialchars($e->getMessage())];
        }
    }
}

renderHead('Upload — DMARC Analyzer');
?>

<div class="page-title">Upload Reports</div>
<div class="page-subtitle">Supports DMARC aggregate XML files, .gz (gzip), and .zip archives</div>

<?php foreach ($messages as $m): ?>
<div class="alert alert-<?= $m['type'] === 'warn' ? 'info' : $m['type'] ?>"><?= $m['text'] ?></div>
<?php endforeach; ?>

<div class="two-col section">

  <!-- File upload -->
  <div class="card">
    <h2>File Upload</h2>
    <form method="post" enctype="multipart/form-data">
      <label class="upload-area" for="file-input">
        <div class="icon">📂</div>
        <strong>Click to select files</strong> or drag &amp; drop here
        <p>Accepts .xml · .xml.gz · .zip (multiple files OK)</p>
        <input type="file" id="file-input" name="reports[]" multiple
               accept=".xml,.gz,.zip,application/xml,application/zip,application/gzip">
      </label>
      <br>
      <button type="submit" class="btn btn-primary" style="width:100%">Upload &amp; Parse</button>
    </form>
  </div>

  <!-- Paste XML -->
  <div class="card">
    <h2>Paste XML</h2>
    <form method="post">
      <div class="form-group">
        <label for="xml_paste">Paste raw DMARC XML report content</label>
        <textarea id="xml_paste" name="xml_paste" rows="10"
                  style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;color:var(--text);padding:10px;font-family:monospace;font-size:12px;resize:vertical"
                  placeholder="&lt;?xml version=&quot;1.0&quot;?&gt;&#10;&lt;feedback&gt;&#10;  ...&#10;&lt;/feedback&gt;"></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Parse &amp; Import</button>
    </form>
  </div>
</div>

<!-- Format reference -->
<div class="card section">
  <h2>Expected Format</h2>
  <p style="color:var(--muted);margin-bottom:12px">DMARC aggregate reports (RUA) follow RFC 7489. Files are typically emailed to your <code style="color:var(--accent)">rua=</code> address as .zip or .gz attachments.</p>
  <div class="three-col" style="margin-top:16px">
    <div class="policy-item">
      <div class="key">XML</div>
      <div class="val" style="font-size:14px;color:var(--accent)">Plain report.xml</div>
    </div>
    <div class="policy-item">
      <div class="key">Gzip</div>
      <div class="val" style="font-size:14px;color:var(--accent)">report.xml.gz</div>
    </div>
    <div class="policy-item">
      <div class="key">ZIP</div>
      <div class="val" style="font-size:14px;color:var(--accent)">report.zip (contains XML)</div>
    </div>
  </div>
</div>

<?php renderFoot(); ?>
