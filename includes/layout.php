<?php

function renderHead(string $title = 'DMARC Analyzer'): void {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<link rel="stylesheet" href="{$base}/assets/style.css">
</head>
<body>
<header class="page-header">
  <div class="wrapper">
    <a class="logo" href="{$base}/index.php">DMARC<span>Lens</span></a>
    <nav class="nav-links">
      <a href="{$base}/index.php">Dashboard</a>
      <a href="{$base}/upload.php">Upload</a>
    </nav>
  </div>
</header>
<main><div class="wrapper">
HTML;
}

function renderFoot(): void {
    echo <<<HTML
</div></main>
<script>
// Drag-and-drop enhancement for upload areas
document.querySelectorAll('.upload-area').forEach(el => {
    el.addEventListener('dragover',  e => { e.preventDefault(); el.classList.add('drag-over'); });
    el.addEventListener('dragleave', ()  => el.classList.remove('drag-over'));
    el.addEventListener('drop', e => {
        e.preventDefault();
        el.classList.remove('drag-over');
        const inp = el.querySelector('input[type=file]');
        if (inp && e.dataTransfer.files.length) {
            inp.files = e.dataTransfer.files;
            el.closest('form')?.submit();
        }
    });
});
</script>
</body></html>
HTML;
}

function badge(string $value, string $type = ''): string {
    $class = match(strtolower($value)) {
        'pass'        => 'badge-pass',
        'fail'        => 'badge-fail',
        'none'        => 'badge-none',
        'quarantine'  => 'badge-quarantine',
        'reject'      => 'badge-reject',
        default       => 'badge-neutral',
    };
    if ($type) $class = $type;
    $esc = htmlspecialchars($value);
    return "<span class=\"badge {$class}\">{$esc}</span>";
}

function fmtDate(int $ts): string {
    return $ts ? date('Y-m-d', $ts) : '—';
}

function fmtNum(int $n): string {
    return number_format($n);
}

function passRate(int $pass, int $total): string {
    if ($total === 0) return '—';
    return round($pass / $total * 100, 1) . '%';
}
