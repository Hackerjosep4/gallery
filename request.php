<?php
// ─── AJAX: MARCAR COM A CENSURAT ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'censor') {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Nom buit']); exit; }
    $path = __DIR__ . '/censored.json';
    $data = [];
    if (file_exists($path)) {
        $d = json_decode(file_get_contents($path), true);
        if (is_array($d)) $data = $d;
    }
    foreach ($data as $e) {
        if (($e['name'] ?? '') === $name) { echo json_encode(['ok' => true, 'already' => true]); exit; }
    }
    $data[] = ['name' => $name];
    @chmod($path, 0664);
    if (@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        echo json_encode(['ok' => false, 'error' => 'No s\'ha pogut escriure censored.json']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─── AJAX: ELIMINAR PETICIONS ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Nom buit']); exit; }
    $path = __DIR__ . '/request.json';
    $data = [];
    if (file_exists($path)) {
        $d = json_decode(file_get_contents($path), true);
        if (is_array($d)) $data = $d;
    }
    $data = array_values(array_filter($data, fn($e) => ($e['name'] ?? '') !== $name));
    @chmod($path, 0664);
    if (@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        echo json_encode(['ok' => false, 'error' => 'No s\'ha pogut escriure request.json']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─── CONFIG ────────────────────────────────────────────────────────────────
$PREVIEW_DIR = __DIR__ . '/_previews';
$IMAGE_EXTS  = ['jpg','jpeg','png','webp','gif'];
$VIDEO_EXTS  = ['mov','mp4','avi','mkv','webm','m4v','3gp'];

// ─── READ & GROUP REQUESTS ─────────────────────────────────────────────────
$jsonPath = __DIR__ . '/request.json';
$allRequests = [];
if (file_exists($jsonPath)) {
    $decoded = json_decode(file_get_contents($jsonPath), true);
    if (is_array($decoded)) $allRequests = $decoded;
}

$grouped = [];
foreach ($allRequests as $req) {
    $name = trim($req['name'] ?? '');
    if ($name === '') continue;
    $grouped[$name][] = $req['reason'] ?? '(sense motiu)';
}

$items = [];
foreach ($grouped as $file => $reasons) {
    $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $isVideo = in_array($ext, $VIDEO_EXTS);

    $previewName = $isVideo ? ($file . '.jpg') : $file;
    $previewPath = $PREVIEW_DIR . '/' . $previewName;
    $origPath    = __DIR__ . '/' . $file;

    $previewUrl = null;
    if (file_exists($previewPath))     $previewUrl = '_previews/' . $previewName;
    elseif (file_exists($origPath))    $previewUrl = $file;

    $ratio = $isVideo ? 0.5625 : 0.75;
    if ($previewUrl) {
        $sizeFile = file_exists($previewPath) ? $previewPath : $origPath;
        if (!($isVideo && !file_exists($previewPath))) {
            $pSize = @getimagesize($sizeFile);
            if ($pSize && $pSize[0] > 0) $ratio = $pSize[1] / $pSize[0];
        }
    }

    $items[] = [
        'file'    => $file,
        'preview' => $previewUrl,
        'ratio'   => $ratio,
        'isVideo' => $isVideo,
        'reasons' => $reasons,
        'count'   => count($reasons),
    ];
}

usort($items, fn($a, $b) => $b['count'] - $a['count']);

$filesJson   = json_encode(array_map(fn($i) => $i['file'],    $items));
$previewJson = json_encode(array_column($items, 'preview'));
$typesJson   = json_encode(array_map(fn($i) => $i['isVideo'] ? 'video' : 'image', $items));
$reasonsJson = json_encode(array_column($items, 'reasons'));
$totalCount  = count($items);
$totalReqs   = count($allRequests);
?>
<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Peticions de censura</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
/* ── RESET & BASE ───────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:      #0f0e0d;
  --surface: #1a1917;
  --border:  #2e2c2a;
  --text:    #f0ece6;
  --muted:   #7a736a;
  --accent:  #e8c47a;
  --accent2: #c97b4b;
  --danger:  #c94b4b;
  --radius:  6px;
  --gap:     10px;
}
html { background: var(--bg); color: var(--text); }
body { font-family: 'DM Sans', sans-serif; font-weight: 300; min-height: 100dvh; overscroll-behavior: none; }

/* ── HEADER ─────────────────────────────────────────────── */
header {
  padding: 32px 20px 16px;
  text-align: center;
  border-bottom: 1px solid var(--border);
  position: relative;
}
header h1 {
  font-family: 'Playfair Display', serif;
  font-size: clamp(1.4rem, 4vw, 2rem);
  font-weight: 400;
  letter-spacing: 0.04em;
  color: var(--accent2);
}
header p {
  font-size: .8rem;
  color: var(--muted);
  margin-top: 4px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}
.header-nav {
  position: absolute;
  top: 50%;
  left: 20px;
  transform: translateY(-50%);
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.back-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: transparent;
  border: 1px solid var(--border);
  color: var(--muted);
  padding: 6px 14px;
  border-radius: 20px;
  font-size: .78rem;
  font-family: 'DM Sans', sans-serif;
  letter-spacing: .08em;
  cursor: pointer;
  text-decoration: none;
  transition: all .2s;
  white-space: nowrap;
}
.back-btn:hover { border-color: var(--accent); color: var(--accent); }
.back-btn svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }

/* ── GRID ───────────────────────────────────────────────── */
#gallery {
  padding: var(--gap);
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  grid-auto-rows: 6px;
  gap: var(--gap);
}
@media (min-width: 480px)  { #gallery { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 768px)  { #gallery { grid-template-columns: repeat(4, 1fr); } }
@media (min-width: 1100px) { #gallery { grid-template-columns: repeat(5, 1fr); } }
@media (min-width: 1400px) { #gallery { grid-template-columns: repeat(6, 1fr); } }

.thumb-wrap {
  position: relative;
  border-radius: var(--radius);
  overflow: hidden;
  cursor: pointer;
  background: var(--surface);
  transition: transform .18s, box-shadow .18s;
  align-self: start;
}
.thumb-wrap:hover { transform: scale(1.02); box-shadow: 0 8px 24px rgba(0,0,0,.5); }
.thumb-wrap img { display: block; width: 100%; height: auto; }

.thumb-no-media {
  aspect-ratio: 4/3;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--muted);
}
.thumb-no-media svg { width: 36px; opacity: .25; }

/* Badge de peticions */
.req-badge {
  position: absolute;
  top: 6px; left: 6px;
  background: var(--accent2);
  color: #0f0e0d;
  font-size: .65rem;
  font-weight: 500;
  letter-spacing: .05em;
  padding: 2px 8px;
  border-radius: 10px;
  pointer-events: none;
}

/* Video badge */
.video-badge {
  position: absolute;
  bottom: 6px; right: 6px;
  background: rgba(15,14,13,.75);
  color: var(--accent);
  font-size: .6rem;
  letter-spacing: .1em;
  text-transform: uppercase;
  padding: 2px 6px;
  border-radius: 4px;
  pointer-events: none;
}
.play-overlay {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,.25); transition: background .18s;
}
.thumb-wrap:hover .play-overlay { background: rgba(0,0,0,.4); }
.play-btn {
  width: 44px; height: 44px; border-radius: 50%;
  background: rgba(232,196,122,.92);
  display: flex; align-items: center; justify-content: center;
  transition: transform .18s, background .18s;
}
.thumb-wrap:hover .play-btn { transform: scale(1.1); background: var(--accent); }
.play-btn svg { width: 18px; height: 18px; fill: #0f0e0d; margin-left: 2px; }

@keyframes fadeUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
.thumb-wrap { animation: fadeUp .4s ease backwards; }

/* Empty state */
#empty {
  text-align: center; padding: 80px 20px;
  color: var(--muted); font-size: .95rem;
  display: <?= $totalCount === 0 ? 'block' : 'none' ?>;
}
#empty svg { width: 48px; opacity: .3; margin: 0 auto 16px; display: block; }

/* ── SPLIT LIGHTBOX ─────────────────────────────────────── */
#lightbox {
  display: none;
  position: fixed; inset: 0;
  background: rgba(10,10,9,.97);
  z-index: 1000;
  flex-direction: row;
}
#lightbox.open { display: flex; }

/* Media side */
#lb-media-side {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  position: relative;
}
#lb-img-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
#lb-img {
  max-width: 100%; max-height: 100%;
  object-fit: contain;
  user-select: none; -webkit-user-drag: none;
  transition: opacity .25s;
  padding: 8px 60px;
}
#lb-video {
  max-width: 100%; max-height: 100%;
  object-fit: contain;
  display: none;
  padding: 8px 60px;
  outline: none;
}
@media (max-width: 720px) {
  #lb-img, #lb-video { padding: 8px 48px; }
}

/* Nav arrows */
.lb-arrow {
  position: absolute; top: 50%; transform: translateY(-50%);
  background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.12);
  color: var(--text);
  width: 40px; height: 64px;
  border-radius: var(--radius);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .15s; z-index: 10;
}
.lb-arrow:hover { background: rgba(255,255,255,.18); }
#lb-prev { left: 8px; }
#lb-next { right: 8px; }
.lb-arrow svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }

/* Spinner */
@keyframes spin { to { transform: translate(-50%,-50%) rotate(360deg); } }
#lb-spinner {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%,-50%);
  width: 32px; height: 32px;
  border: 2px solid rgba(255,255,255,.1);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .7s linear infinite;
  opacity: 0; transition: opacity .2s;
  pointer-events: none;
}
#lb-spinner.show { opacity: 1; }

/* Close */
#lb-close {
  position: absolute; top: 10px; right: 10px;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.1);
  color: var(--text);
  width: 36px; height: 36px;
  border-radius: 50%;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .15s; z-index: 20;
}
#lb-close:hover { background: rgba(255,255,255,.15); }
#lb-close svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; }

/* Bottom bar (comptador) */
#lb-media-bar {
  padding: 8px 16px;
  border-top: 1px solid var(--border);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
#lb-counter { font-size: .72rem; letter-spacing: .12em; color: var(--muted); text-transform: uppercase; }

/* ── PANEL DRET ─────────────────────────────────────────── */
#lb-panel {
  width: 300px;
  flex-shrink: 0;
  background: var(--surface);
  border-left: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
#lb-panel-header {
  padding: 20px 18px 14px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
#lb-panel-filename {
  font-size: .85rem;
  font-weight: 500;
  color: var(--text);
  word-break: break-all;
  margin-bottom: 5px;
  line-height: 1.4;
}
#lb-panel-count {
  font-size: .7rem;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--accent2);
}
#lb-panel-list {
  flex: 1;
  overflow-y: auto;
  padding: 12px 18px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
#lb-panel-list::-webkit-scrollbar { width: 4px; }
#lb-panel-list::-webkit-scrollbar-track { background: transparent; }
#lb-panel-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
.req-item {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 10px 12px;
}
.req-item-num {
  font-size: .62rem;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 4px;
}
.req-item-reason { font-size: .82rem; color: var(--text); line-height: 1.5; }

/* Actions */
#lb-panel-actions {
  padding: 12px 18px 16px;
  border-top: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex-shrink: 0;
}
.action-btn {
  width: 100%; padding: 9px;
  border-radius: var(--radius);
  font-size: .82rem;
  font-family: 'DM Sans', sans-serif;
  font-weight: 500;
  cursor: pointer;
  transition: opacity .2s, color .2s, border-color .2s;
  text-align: center;
}
.action-btn:disabled { opacity: .45; cursor: default; }
#lb-censor-btn {
  background: var(--accent); border: none; color: var(--bg);
}
#lb-censor-btn:hover:not(:disabled) { opacity: .85; }
#lb-delete-btn {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--muted);
}
#lb-delete-btn:hover:not(:disabled) { border-color: var(--danger); color: var(--danger); }
#lb-action-msg {
  font-size: .75rem; color: var(--muted);
  text-align: center; min-height: 1em;
}

/* Responsive: vertical en mòbil */
@media (max-width: 720px) {
  #lightbox { flex-direction: column; }
  #lb-panel { width: 100%; flex-shrink: 0; height: 260px; border-left: none; border-top: 1px solid var(--border); }
}
</style>
</head>
<body>

<header>
  <div class="header-nav">
    <a href="./" class="back-btn">
      <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      Galeria
    </a>
    <a href="all.php" class="back-btn">
      <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      Sense censurar
    </a>
  </div>
  <h1>Peticions de censura</h1>
  <p>
    <?= $totalCount ?> <?= $totalCount === 1 ? 'imatge' : 'imatges' ?>
    · <?= $totalReqs ?> <?= $totalReqs === 1 ? 'petició' : 'peticions' ?> totals
  </p>
</header>

<div id="gallery">
<?php foreach ($items as $i => $item): ?>
  <div class="thumb-wrap"
       data-index="<?= $i ?>"
       style="animation-delay:<?= min($i * 0.04, 1.2) ?>s">
    <?php if ($item['preview']): ?>
      <img src="<?= htmlspecialchars($item['preview']) ?>" loading="lazy" alt="">
    <?php else: ?>
      <div class="thumb-no-media">
        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="4" y="10" width="40" height="30" rx="3"/>
          <circle cx="17" cy="22" r="4"/>
          <path d="M4 34l11-9 8 7 6-5 15 12"/>
        </svg>
      </div>
    <?php endif; ?>
    <?php if ($item['isVideo']): ?>
    <div class="play-overlay">
      <div class="play-btn">
        <svg viewBox="0 0 24 24"><polygon points="5,3 19,12 5,21"/></svg>
      </div>
    </div>
    <span class="video-badge">vídeo</span>
    <?php endif; ?>
    <span class="req-badge"><?= $item['count'] ?> petició<?= $item['count'] !== 1 ? 'ns' : '' ?></span>
  </div>
<?php endforeach; ?>
</div>

<div id="empty">
  <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5">
    <rect x="4" y="10" width="40" height="30" rx="3"/>
    <circle cx="17" cy="22" r="4"/>
    <path d="M4 34l11-9 8 7 6-5 15 12"/>
  </svg>
  <p>No hi ha peticions de censura pendents.</p>
</div>

<!-- SPLIT LIGHTBOX -->
<div id="lightbox" role="dialog" aria-modal="true">

  <!-- Media side -->
  <div id="lb-media-side">
    <button id="lb-close" aria-label="Tancar">
      <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <div id="lb-img-wrap">
      <button class="lb-arrow" id="lb-prev" aria-label="Anterior">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <img id="lb-img" src="" alt="">
      <video id="lb-video" controls playsinline></video>
      <div id="lb-spinner"></div>
      <button class="lb-arrow" id="lb-next" aria-label="Següent">
        <svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18"/></svg>
      </button>
    </div>
    <div id="lb-media-bar">
      <span id="lb-counter"></span>
    </div>
  </div>

  <!-- Requests panel -->
  <div id="lb-panel">
    <div id="lb-panel-header">
      <div id="lb-panel-filename"></div>
      <div id="lb-panel-count"></div>
    </div>
    <div id="lb-panel-list"></div>
    <div id="lb-panel-actions">
      <button id="lb-censor-btn" class="action-btn">Marcar com a censurat</button>
      <button id="lb-delete-btn" class="action-btn">Eliminar totes les peticions</button>
      <p id="lb-action-msg"></p>
    </div>
  </div>

</div>

<script>
const IMAGES   = <?= $filesJson ?>;
const PREVIEWS = <?= $previewJson ?>;
const TYPES    = <?= $typesJson ?>;
const REASONS  = <?= $reasonsJson ?>;

let currentIdx = 0;

// ── DOM REFS ──────────────────────────────────────────────
const lightbox     = document.getElementById('lightbox');
const lbImg        = document.getElementById('lb-img');
const lbVideo      = document.getElementById('lb-video');
const lbSpinner    = document.getElementById('lb-spinner');
const lbCounter    = document.getElementById('lb-counter');
const lbPanelFile  = document.getElementById('lb-panel-filename');
const lbPanelCount = document.getElementById('lb-panel-count');
const lbPanelList  = document.getElementById('lb-panel-list');
const lbActionMsg  = document.getElementById('lb-action-msg');
const lbCensorBtn  = document.getElementById('lb-censor-btn');
const lbDeleteBtn  = document.getElementById('lb-delete-btn');

// ── OPEN / CLOSE ──────────────────────────────────────────
function openLightbox(idx) {
  currentIdx = idx;
  showMedia();
  lightbox.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  lbVideo.pause(); lbVideo.src = ''; lbImg.src = '';
  lightbox.classList.remove('open');
  document.body.style.overflow = '';
}

// ── SHOW MEDIA + PANEL ────────────────────────────────────
function showMedia() {
  const file    = IMAGES[currentIdx];
  const preview = PREVIEWS[currentIdx];
  const type    = TYPES[currentIdx];
  const reasons = REASONS[currentIdx] || [];

  lbCounter.textContent = `${currentIdx + 1} / ${IMAGES.length}`;
  document.getElementById('lb-prev').style.visibility = currentIdx > 0 ? 'visible' : 'hidden';
  document.getElementById('lb-next').style.visibility = currentIdx < IMAGES.length - 1 ? 'visible' : 'hidden';

  lbVideo.pause(); lbVideo.src = '';

  if (type === 'video') {
    lbImg.style.display   = 'none';
    lbVideo.style.display = 'block';
    lbSpinner.classList.add('show');
    lbVideo.src = file;
    lbVideo.load();
    lbVideo.oncanplay = () => lbSpinner.classList.remove('show');
  } else {
    lbVideo.style.display = 'none';
    lbImg.style.display   = 'block';
    lbImg.src = preview || '';
    if (file) {
      lbSpinner.classList.add('show');
      const full = new Image();
      full.onload  = () => { if (IMAGES[currentIdx] === file) { lbImg.src = full.src; lbSpinner.classList.remove('show'); } };
      full.onerror = () => lbSpinner.classList.remove('show');
      full.src = file;
    }
  }

  // Panel de peticions
  lbPanelFile.textContent  = file;
  lbPanelCount.textContent = `${reasons.length} petició${reasons.length !== 1 ? 'ns' : ''}`;
  lbPanelList.innerHTML = reasons.map((r, i) => `
    <div class="req-item">
      <div class="req-item-num">Petició #${i + 1}</div>
      <div class="req-item-reason">${esc(r)}</div>
    </div>
  `).join('');
  lbActionMsg.textContent   = '';
  lbCensorBtn.disabled      = false;
  lbCensorBtn.textContent   = 'Marcar com a censurat';
  lbDeleteBtn.disabled      = false;
}

function esc(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function prevMedia() { if (currentIdx > 0)                 { currentIdx--; showMedia(); } }
function nextMedia() { if (currentIdx < IMAGES.length - 1) { currentIdx++; showMedia(); } }

// ── EVENTS ────────────────────────────────────────────────
document.getElementById('lb-prev').addEventListener('click', prevMedia);
document.getElementById('lb-next').addEventListener('click', nextMedia);
document.getElementById('lb-close').addEventListener('click', closeLightbox);

document.addEventListener('keydown', e => {
  if (!lightbox.classList.contains('open')) return;
  if (e.key === 'ArrowLeft')  prevMedia();
  if (e.key === 'ArrowRight') nextMedia();
  if (e.key === 'Escape')     closeLightbox();
});

let tsx = 0, tsy = 0;
lightbox.addEventListener('touchstart', e => { tsx = e.touches[0].clientX; tsy = e.touches[0].clientY; }, { passive: true });
lightbox.addEventListener('touchend', e => {
  const dx = e.changedTouches[0].clientX - tsx;
  const dy = e.changedTouches[0].clientY - tsy;
  if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 40) dx < 0 ? nextMedia() : prevMedia();
}, { passive: true });

document.getElementById('gallery').addEventListener('click', e => {
  const wrap = e.target.closest('.thumb-wrap');
  if (!wrap) return;
  openLightbox(parseInt(wrap.dataset.index));
});

// ── ACTIONS ───────────────────────────────────────────────
async function postAction(action, name) {
  const fd = new FormData();
  fd.append('name', name);
  const res = await fetch(`?action=${action}`, { method: 'POST', body: fd });
  return await res.json();
}

lbCensorBtn.addEventListener('click', async () => {
  lbCensorBtn.disabled = true;
  lbActionMsg.textContent = '';
  try {
    const data = await postAction('censor', IMAGES[currentIdx]);
    if (data.ok) {
      lbActionMsg.textContent = 'Marcat com a censurat ✓';
      lbCensorBtn.textContent = 'Ja censurat ✓';
    } else {
      lbActionMsg.textContent = data.error || 'Error';
      lbCensorBtn.disabled = false;
    }
  } catch {
    lbActionMsg.textContent = 'Error de connexió.';
    lbCensorBtn.disabled = false;
  }
});

lbDeleteBtn.addEventListener('click', async () => {
  if (!confirm('Eliminar totes les peticions d\'aquesta imatge?')) return;
  lbDeleteBtn.disabled = true;
  lbActionMsg.textContent = '';
  try {
    const data = await postAction('delete', IMAGES[currentIdx]);
    if (data.ok) {
      const idx = currentIdx;
      IMAGES.splice(idx, 1);
      PREVIEWS.splice(idx, 1);
      TYPES.splice(idx, 1);
      REASONS.splice(idx, 1);
      // Eliminar del grid i re-indexar
      const wraps = document.querySelectorAll('.thumb-wrap');
      wraps[idx].remove();
      document.querySelectorAll('.thumb-wrap').forEach((w, i) => w.dataset.index = i);
      if (IMAGES.length === 0) {
        closeLightbox();
        document.getElementById('empty').style.display = 'block';
      } else {
        currentIdx = Math.min(idx, IMAGES.length - 1);
        showMedia();
      }
    } else {
      lbActionMsg.textContent = data.error || 'Error eliminant.';
      lbDeleteBtn.disabled = false;
    }
  } catch {
    lbActionMsg.textContent = 'Error de connexió.';
    lbDeleteBtn.disabled = false;
  }
});

// ── MASONRY ───────────────────────────────────────────────
const ROW_H = 6, ROW_GAP = 10;
function setSpan(wrap) {
  const img = wrap.querySelector('img');
  if (!img) return;
  const h = img.offsetHeight;
  if (h === 0) return;
  wrap.style.gridRowEnd = 'span ' + Math.max(Math.ceil((h + ROW_GAP) / (ROW_H + ROW_GAP)), 4);
}
function resizeAll() { document.querySelectorAll('.thumb-wrap').forEach(setSpan); }
document.querySelectorAll('.thumb-wrap img').forEach(img => {
  const wrap = img.closest('.thumb-wrap');
  if (img.complete && img.naturalHeight > 0) setSpan(wrap);
  else img.addEventListener('load', () => setSpan(wrap));
});
let rt;
window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(resizeAll, 100); });
</script>
</body>
</html>
