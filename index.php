<?php
// ─── CONFIG ────────────────────────────────────────────────────────────────
$SELF        = basename(__FILE__);
$PREVIEW_DIR = __DIR__ . '/_previews';
$PREVIEW_W   = 500;   // amplada màxima de les previews (px)
$IMAGE_EXTS  = ['jpg','jpeg','png','webp','gif'];
$VIDEO_EXTS  = ['mov','mp4','avi','mkv','webm','m4v','3gp'];

// ─── SETUP ─────────────────────────────────────────────────────────────────
if (!is_dir($PREVIEW_DIR)) mkdir($PREVIEW_DIR, 0755, true);

// ─── BACKGROUND GENERATION ─────────────────────────────────────────────────
// Si hi ha fitxers sense preview, llança make_previews.php en segon pla
// i retorna la pàgina immediatament sense bloquejar.
$allExtsBg  = array_unique(array_merge($IMAGE_EXTS, $VIDEO_EXTS));
$globExtsBg = implode(',', array_merge($allExtsBg, array_map('strtoupper', $allExtsBg)));
$missingPreview = false;
foreach (glob(__DIR__ . '/*.{' . $globExtsBg . '}', GLOB_BRACE) as $_p) {
    $_f   = basename($_p);
    if ($_f === basename(__FILE__)) continue;
    $_ext = strtolower(pathinfo($_f, PATHINFO_EXTENSION));
    $_vid = in_array($_ext, $VIDEO_EXTS);
    $_pv  = $PREVIEW_DIR . '/' . ($_vid ? $_f . '.jpg' : $_f);
    if (!file_exists($_pv)) { $missingPreview = true; break; }
}
if ($missingPreview) {
    $script = escapeshellarg(__DIR__ . '/make_previews.php');
    exec("nohup php {$script} > /dev/null 2>&1 &");
}

// ─── HELPERS ───────────────────────────────────────────────────────────────
function getMediaDate(string $path, bool $isVideo): int {
    if (!$isVideo && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path, 'EXIF', false);
        if ($exif) {
            $dt = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
            if ($dt) {
                $ts = DateTime::createFromFormat('Y:m:d H:i:s', $dt);
                if ($ts) return $ts->getTimestamp();
            }
        }
    }

    if ($isVideo) {
        // Opció B: ffprobe — metadades internes del vídeo
        $escaped = escapeshellarg($path);
        $out = shell_exec(
            "ffprobe -v quiet -select_streams v:0 " .
            "-show_entries format_tags=creation_time " .
            "-of default=noprint_wrappers=1:nokey=1 {$escaped} 2>/dev/null"
        );
        if ($out) {
            $out = trim($out);
            // Format típic: 2023-08-15T14:30:22.000000Z
            $ts = strtotime($out);
            if ($ts && $ts > 0) return $ts;
        }

        // Opció C: data de creació del fitxer (inode change time)
        $ctime = filectime($path);
        if ($ctime && $ctime > 0) return $ctime;
    }

    // Fallback: data de modificació
    return filemtime($path);
}


// ─── CENSORED LIST ─────────────────────────────────────────────────────────
$censoredSet = [];
$censoredJsonPath = __DIR__ . '/censored.json';
if (file_exists($censoredJsonPath)) {
    $censoredData = json_decode(file_get_contents($censoredJsonPath), true);
    if (is_array($censoredData)) {
        foreach ($censoredData as $entry) {
            if (!empty($entry['name'])) $censoredSet[$entry['name']] = true;
        }
    }
}

// ─── SCAN MEDIA (sense generació — fer servir make_previews.php) ───────────
$allExts  = array_unique(array_merge($IMAGE_EXTS, $VIDEO_EXTS));
$globExts = implode(',', array_merge($allExts, array_map('strtoupper', $allExts)));

$items = [];
foreach (glob(__DIR__ . '/*.{' . $globExts . '}', GLOB_BRACE) as $path) {
    $file = basename($path);
    if ($file === $SELF) continue;
    if ($file === 'censored.jpg') continue;

    $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $isVideo = in_array($ext, $VIDEO_EXTS);

    $previewName = $isVideo ? ($file . '.jpg') : $file;
    $previewPath = $PREVIEW_DIR . '/' . $previewName;
    $previewUrl  = file_exists($previewPath)
        ? '_previews/' . $previewName
        : $file; // fallback: original si encara no hi ha preview

    // Ratio: llegir de la preview si existeix, sinó de l'original (imatges) o default (vídeos)
    if ($isVideo) {
        $pSize = file_exists($previewPath) ? @getimagesize($previewPath) : false;
        $ratio = ($pSize && $pSize[0] > 0) ? $pSize[1] / $pSize[0] : 0.5625;
    } else {
        // getimagesize en originals de 10MB és ràpid (només llegeix capçalera)
        $pSize = @getimagesize(file_exists($previewPath) ? $previewPath : $path);
        $ratio = ($pSize && $pSize[0] > 0) ? $pSize[1] / $pSize[0] : 0.75;
    }

    $isCensored = isset($censoredSet[$file]);

    $items[] = [
        'file'     => $file,
        'preview'  => $previewUrl,
        'ts'       => getMediaDate($path, $isVideo),
        'ratio'    => $ratio,
        'isVideo'  => $isVideo,
        'censored' => $isCensored,
    ];
}

usort($items, fn($a, $b) => $b['ts'] - $a['ts']);

// Tots els items van al lightbox; els censurats mostren censored.jpg però el nom real
$lightboxItems   = array_values($items);
// Mapa grid → lightbox ara és 1:1
$gridMapJson     = json_encode(array_keys($lightboxItems));

$filesJson       = json_encode(array_map(fn($i) => $i['file'], $lightboxItems));
$lbSrcJson       = json_encode(array_map(fn($i) => $i['censored'] ? 'censored.jpg' : $i['file'], $lightboxItems));
$previewJson     = json_encode(array_column($lightboxItems, 'preview'));
$typesJson       = json_encode(array_map(fn($i) => $i['isVideo'] ? 'video' : 'image', $lightboxItems));
$censoredJson    = json_encode(array_map(fn($i) => $i['censored'], $lightboxItems));
$totalCount      = count($items);
$imgCount        = count(array_filter($items, fn($i) => !$i['isVideo']));
$vidCount        = count(array_filter($items, fn($i) =>  $i['isVideo']));
?>
<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Galeria</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

<style>
/* ── RESET & BASE ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:      #0f0e0d;
  --surface: #1a1917;
  --border:  #2e2c2a;
  --text:    #f0ece6;
  --muted:   #7a736a;
  --accent:  #e8c47a;
  --accent2: #c97b4b;
  --radius:  6px;
  --gap:     10px;
}
html { background: var(--bg); color: var(--text); }
body { font-family: 'DM Sans', sans-serif; font-weight: 300; min-height: 100dvh; overscroll-behavior: none; }

/* ── HEADER ───────────────────────────────────────────────── */
header {
  padding: 32px 20px 16px;
  text-align: center;
  border-bottom: 1px solid var(--border);
}
header h1 {
  font-family: 'Playfair Display', serif;
  font-size: clamp(1.6rem, 5vw, 2.4rem);
  font-weight: 400;
  letter-spacing: 0.04em;
  color: var(--accent);
}
header p {
  font-size: .8rem;
  color: var(--muted);
  margin-top: 4px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

/* ── TOOLBAR ──────────────────────────────────────────────── */
#toolbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  padding: 10px 16px;
  min-height: 48px;
}
#select-toggle {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--muted);
  padding: 6px 14px;
  border-radius: 20px;
  font-size: .78rem;
  font-family: 'DM Sans', sans-serif;
  letter-spacing: .08em;
  cursor: pointer;
  transition: all .2s;
  opacity: 0;
  pointer-events: none;
  transform: translateY(4px);
}
#select-toggle.visible  { opacity: 1; pointer-events: auto; transform: translateY(0); }
#select-toggle.active   { background: var(--accent); border-color: var(--accent); color: var(--bg); font-weight: 500; }
#download-sel-btn {
  display: none;
  background: var(--accent);
  border: none;
  color: var(--bg);
  padding: 6px 16px;
  border-radius: 20px;
  font-size: .78rem;
  font-family: 'DM Sans', sans-serif;
  font-weight: 500;
  letter-spacing: .08em;
  cursor: pointer;
  transition: background .2s;
}
#download-sel-btn:hover { background: var(--accent2); }
#sel-count { font-size: .75rem; color: var(--muted); display: none; }

/* ── GRID ─────────────────────────────────────────────────── */
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
.thumb-wrap img {
  display: block;
  width: 100%;
  height: auto;
}

/* Play button overlay for videos */
.play-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,.25);
  transition: background .18s;
}
.thumb-wrap:hover .play-overlay { background: rgba(0,0,0,.4); }
.play-btn {
  width: 44px; height: 44px;
  border-radius: 50%;
  background: rgba(232,196,122,.92);
  display: flex; align-items: center; justify-content: center;
  transition: transform .18s, background .18s;
  flex-shrink: 0;
}
.thumb-wrap:hover .play-btn { transform: scale(1.1); background: var(--accent); }
.play-btn svg { width: 18px; height: 18px; fill: #0f0e0d; margin-left: 2px; }

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

/* Selection */
.check-overlay {
  position: absolute; inset: 0;
  background: rgba(232,196,122,.18);
  border: 3px solid var(--accent);
  border-radius: var(--radius);
  opacity: 0; transition: opacity .15s;
  pointer-events: none;
}
.check-mark {
  position: absolute; top: 8px; right: 8px;
  width: 24px; height: 24px;
  border-radius: 50%;
  background: var(--accent);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transform: scale(.5);
  transition: opacity .15s, transform .15s;
  pointer-events: none;
}
.check-mark svg { width: 14px; height: 14px; fill: none; stroke: var(--bg); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.thumb-wrap.selected .check-overlay { opacity: 1; }
.thumb-wrap.selected .check-mark   { opacity: 1; transform: scale(1); }

/* ── LIGHTBOX ─────────────────────────────────────────────── */
#lightbox {
  display: none;
  position: fixed; inset: 0;
  background: rgba(10,10,9,.97);
  z-index: 1000;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  touch-action: pan-y;
}
#lightbox.open { display: flex; }

#lb-img-wrap {
  position: relative;
  flex: 1;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

#lb-img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  user-select: none;
  -webkit-user-drag: none;
  transition: opacity .25s;
  padding: 8px 60px;
}
#lb-video {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  display: none;
  padding: 8px 60px;
  outline: none;
}
@media (max-width: 600px) {
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
  transition: background .15s;
  z-index: 10;
}
.lb-arrow:hover { background: rgba(255,255,255,.18); }
#lb-prev { left: 8px; }
#lb-next { right: 8px; }
.lb-arrow svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }

#lb-counter {
  font-size: .72rem; letter-spacing: .12em; color: var(--muted);
  text-transform: uppercase;
  white-space: nowrap;
  flex-shrink: 0;
}

#lb-close {
  position: absolute; top: 10px; right: 10px;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.1);
  color: var(--text);
  width: 36px; height: 36px;
  border-radius: 50%;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .15s;
  z-index: 20;
}
#lb-close:hover { background: rgba(255,255,255,.15); }
#lb-close svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; }

/* Bottom bar */
#lb-bar {
  width: 100%;
  padding: 10px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid var(--border);
  gap: 8px;
  flex-shrink: 0;
}
#lb-filename { font-size: .75rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
#lb-download {
  background: var(--accent); border: none; color: var(--bg);
  padding: 8px 18px; border-radius: 20px;
  font-size: .8rem; font-family: 'DM Sans', sans-serif; font-weight: 500;
  cursor: pointer; display: flex; align-items: center; gap: 6px;
  white-space: nowrap; transition: background .2s; flex-shrink: 0;
}
#lb-download:hover { background: var(--accent2); }
#lb-download svg { width: 14px; height: 14px; fill: var(--bg); }

/* Loading spinner */
@keyframes spin { to { transform: translate(-50%,-50%) rotate(360deg); } }
#lb-spinner {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 32px; height: 32px;
  border: 2px solid rgba(255,255,255,.1);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .7s linear infinite;
  opacity: 0; transition: opacity .2s;
  pointer-events: none;
}
#lb-spinner.show { opacity: 1; }

/* Swipe hint */
#swipe-hint {
  position: absolute; bottom: 70px; left: 50%; transform: translateX(-50%);
  font-size: .68rem; letter-spacing: .14em; color: var(--muted);
  text-transform: uppercase;
  opacity: 0; transition: opacity .4s;
  pointer-events: none; white-space: nowrap;
}
#swipe-hint.show { opacity: 1; }

/* Thumbnail index badge */
.thumb-index {
  position: absolute;
  top: 5px; right: 6px;
  font-size: .58rem;
  letter-spacing: .06em;
  color: rgba(240,236,230,.7);
  background: rgba(15,14,13,.55);
  padding: 1px 5px;
  border-radius: 3px;
  pointer-events: none;
  font-family: 'DM Sans', sans-serif;
  font-weight: 400;
  line-height: 1.6;
}

/* Censored thumbnail */
.thumb-censored { cursor: pointer; }
.thumb-censored img { filter: brightness(0.6); }

/* Fade-in animation */
@keyframes fadeUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
.thumb-wrap { animation: fadeUp .4s ease backwards; }

/* Empty state */
#empty {
  text-align: center; padding: 80px 20px;
  color: var(--muted); font-size: .95rem;
  display: <?= $totalCount === 0 ? 'block' : 'none' ?>;
}
#empty svg { width: 48px; opacity: .3; margin-bottom: 16px; }
</style>
</head>
<body>

<header>
  <h1>Galeria</h1>
  <p>
    <?php
      $parts = [];
      if ($imgCount > 0) $parts[] = $imgCount . ' foto' . ($imgCount !== 1 ? 's' : '');
      if ($vidCount > 0) $parts[] = $vidCount . ' vídeo' . ($vidCount !== 1 ? 's' : '');
      echo implode(' · ', $parts);
    ?>
  </p>
</header>

<div id="toolbar">
  <span id="sel-count"></span>
  <button id="download-sel-btn">
    <svg viewBox="0 0 16 16" fill="currentColor" style="width:12px;height:12px;display:inline;vertical-align:middle;margin-right:4px"><path d="M8 12l-4-4h2.5V2h3v6H12L8 12z"/><path d="M2 14h12v-1.5H2V14z"/></svg>
    Descarregar seleccionades
  </button>
  <button id="select-toggle">Seleccionar</button>
</div>

<div id="gallery">
<?php foreach ($items as $i => $item): ?>
  <?php if ($item['censored']): ?>
  <div class="thumb-wrap thumb-censored"
       data-index="<?= $i ?>"
       style="animation-delay:<?= min($i * 0.04, 1.2) ?>s">
    <img src="censored.jpg" loading="lazy" alt="Censurat">
    <div class="check-overlay"></div>
    <div class="check-mark">
      <svg viewBox="0 0 12 12"><polyline points="1,6 5,10 11,2"/></svg>
    </div>
    <span class="thumb-index"><?= $i + 1 ?></span>
  </div>
  <?php else: ?>
  <div class="thumb-wrap"
       data-index="<?= $i ?>"
       data-type="<?= $item['isVideo'] ? 'video' : 'image' ?>"
       style="animation-delay:<?= min($i * 0.04, 1.2) ?>s">
    <img src="<?= htmlspecialchars($item['preview']) ?>" loading="lazy" alt="">
    <?php if ($item['isVideo']): ?>
    <div class="play-overlay">
      <div class="play-btn">
        <svg viewBox="0 0 24 24"><polygon points="5,3 19,12 5,21"/></svg>
      </div>
    </div>
    <span class="video-badge">vídeo</span>
    <?php endif; ?>
    <div class="check-overlay"></div>
    <div class="check-mark">
      <svg viewBox="0 0 12 12"><polyline points="1,6 5,10 11,2"/></svg>
    </div>
    <span class="thumb-index"><?= $i + 1 ?></span>
  </div>
  <?php endif; ?>
<?php endforeach; ?>
</div>

<div id="empty">
  <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5">
    <rect x="4" y="10" width="40" height="30" rx="3"/>
    <circle cx="17" cy="22" r="4"/>
    <path d="M4 34l11-9 8 7 6-5 15 12"/>
  </svg>
  <p>No hi ha imatges ni vídeos en aquesta carpeta.</p>
</div>

<!-- LIGHTBOX -->
<div id="lightbox" role="dialog" aria-modal="true">
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
    <div id="swipe-hint">← llisca per navegar →</div>
  </div>
  <div id="lb-bar">
    <div id="lb-counter"></div>
    <span id="lb-filename"></span>
    <button id="lb-download">
      <svg viewBox="0 0 16 16"><path d="M8 12l-4-4h2.5V2h3v6H12L8 12z"/><path d="M2 14h12v-1.5H2V14z"/></svg>
      Descarregar
    </button>
  </div>
</div>

<script>
const IMAGES   = <?= $filesJson ?>;
const PREVIEWS = <?= $previewJson ?>;
const TYPES    = <?= $typesJson ?>;   // 'image' | 'video'
const GRID_MAP = <?= $gridMapJson ?>;
const LB_SRC   = <?= $lbSrcJson ?>;
const CENSORED = <?= $censoredJson ?>;

let currentIdx = 0;
let selectMode = false;
const selected = new Set();

// ── LIGHTBOX ───────────────────────────────────────────────
const lightbox   = document.getElementById('lightbox');
const lbImg      = document.getElementById('lb-img');
const lbVideo    = document.getElementById('lb-video');
const lbFilename = document.getElementById('lb-filename');
const lbCounter  = document.getElementById('lb-counter');
const lbSpinner  = document.getElementById('lb-spinner');
const swipeHint  = document.getElementById('swipe-hint');

function openLightbox(idx) {
  currentIdx = idx;
  showMedia();
  lightbox.classList.add('open');
  document.body.style.overflow = 'hidden';
  if (!sessionStorage.getItem('hintSeen')) {
    swipeHint.classList.add('show');
    setTimeout(() => swipeHint.classList.remove('show'), 2500);
    sessionStorage.setItem('hintSeen', '1');
  }
}

function closeLightbox() {
  lbVideo.pause();
  lbVideo.src = '';
  lbImg.src = '';
  lightbox.classList.remove('open');
  document.body.style.overflow = '';
}

function showMedia() {
  const file       = IMAGES[currentIdx];
  const src        = LB_SRC[currentIdx];
  const isCensored = CENSORED[currentIdx];
  const type       = TYPES[currentIdx];

  lbFilename.textContent = file;
  lbCounter.textContent  = `${currentIdx + 1} / ${IMAGES.length}`;
  document.getElementById('lb-prev').style.visibility = currentIdx > 0 ? 'visible' : 'hidden';
  document.getElementById('lb-next').style.visibility = currentIdx < IMAGES.length - 1 ? 'visible' : 'hidden';

  // Atura i neteja l'element anterior
  lbVideo.pause();
  lbVideo.src = '';

  if (!isCensored && type === 'video') {
    lbImg.style.display   = 'none';
    lbVideo.style.display = 'block';
    lbSpinner.classList.add('show');
    lbVideo.src = src;
    lbVideo.load();
    lbVideo.oncanplay = () => lbSpinner.classList.remove('show');
  } else {
    lbVideo.style.display = 'none';
    lbImg.style.display   = 'block';

    if (isCensored) {
      lbImg.src = src;
      lbSpinner.classList.remove('show');
    } else {
      // Primer mostra preview, després l'original en alta
      lbImg.src = PREVIEWS[currentIdx];
      lbSpinner.classList.add('show');

      const full = new Image();
      full.onload = () => {
        if (IMAGES[currentIdx] === file) {
          lbImg.src = full.src;
          lbSpinner.classList.remove('show');
        }
      };
      full.src = src;
    }
  }
}

function prevMedia() { if (currentIdx > 0)                  { currentIdx--; showMedia(); } }
function nextMedia() { if (currentIdx < IMAGES.length - 1)  { currentIdx++; showMedia(); } }

document.getElementById('lb-prev').addEventListener('click', prevMedia);
document.getElementById('lb-next').addEventListener('click', nextMedia);
document.getElementById('lb-close').addEventListener('click', closeLightbox);
lightbox.addEventListener('click', e => { if (e.target === lightbox) closeLightbox(); });

document.addEventListener('keydown', e => {
  if (!lightbox.classList.contains('open')) return;
  if (e.key === 'ArrowLeft')  prevMedia();
  if (e.key === 'ArrowRight') nextMedia();
  if (e.key === 'Escape')     closeLightbox();
});

document.getElementById('lb-download').addEventListener('click', () => {
  downloadFile(IMAGES[currentIdx]);
});

// ── SWIPE ──────────────────────────────────────────────────
let touchStartX = 0, touchStartY = 0;
lightbox.addEventListener('touchstart', e => {
  touchStartX = e.touches[0].clientX;
  touchStartY = e.touches[0].clientY;
}, { passive: true });
lightbox.addEventListener('touchend', e => {
  const dx = e.changedTouches[0].clientX - touchStartX;
  const dy = e.changedTouches[0].clientY - touchStartY;
  if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 40) {
    dx < 0 ? nextMedia() : prevMedia();
  }
}, { passive: true });

// ── GALLERY CLICK ──────────────────────────────────────────
document.getElementById('gallery').addEventListener('click', e => {
  const wrap = e.target.closest('.thumb-wrap');
  if (!wrap) return;
  const idx = parseInt(wrap.dataset.index);
  selectMode ? toggleSelect(wrap, idx) : openLightbox(idx);
});

// ── SELECTION ──────────────────────────────────────────────
const selectToggle   = document.getElementById('select-toggle');
const downloadSelBtn = document.getElementById('download-sel-btn');
const selCount       = document.getElementById('sel-count');

function toggleSelect(wrap, idx) {
  selected.has(idx) ? (selected.delete(idx), wrap.classList.remove('selected'))
                    : (selected.add(idx),    wrap.classList.add('selected'));
  updateSelUI();
}

function updateSelUI() {
  const n = selected.size;
  if (n > 0) {
    selCount.style.display    = 'block';
    selCount.textContent      = `${n} seleccionat${n !== 1 ? 's' : ''}`;
    downloadSelBtn.style.display = 'block';
    downloadSelBtn.innerHTML  = `<svg viewBox="0 0 16 16" fill="currentColor" style="width:12px;height:12px;display:inline;vertical-align:middle;margin-right:4px"><path d="M8 12l-4-4h2.5V2h3v6H12L8 12z"/><path d="M2 14h12v-1.5H2V14z"/></svg> Descarregar ${n}`;
  } else {
    selCount.style.display    = 'none';
    downloadSelBtn.style.display = 'none';
  }
}

selectToggle.addEventListener('click', () => {
  selectMode = !selectMode;
  selectToggle.textContent = selectMode ? 'Cancel·lar' : 'Seleccionar';
  selectToggle.classList.toggle('active', selectMode);
  if (!selectMode) {
    selected.clear();
    document.querySelectorAll('.thumb-wrap.selected').forEach(el => el.classList.remove('selected'));
    updateSelUI();
  }
  resetIdleTimer();
});

// ── DOWNLOAD (individual, sense zip) ──────────────────────
downloadSelBtn.addEventListener('click', async () => {
  const files = [...selected].map(i => IMAGES[i]);
  for (let i = 0; i < files.length; i++) {
    await new Promise(r => setTimeout(r, i * 200));
    downloadFile(files[i]);
  }
});

function downloadFile(filename) {
  const a = document.createElement('a');
  a.href = filename; a.download = filename;
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

// ── MASONRY GRID ───────────────────────────────────────────
// Calcula el span real un cop cada imatge ha carregat,
// basant-se en l'alçada renderitzada real (no en l'aspect ratio estimat).
const GRID    = document.getElementById('gallery');
const ROW_H   = 6;   // ha de coincidir amb grid-auto-rows del CSS
const ROW_GAP = 10;  // ha de coincidir amb gap del CSS

function setSpan(wrap) {
    const img = wrap.querySelector('img');
    if (!img) return;
    const h = img.offsetHeight;
    if (h === 0) return;
    const span = Math.ceil((h + ROW_GAP) / (ROW_H + ROW_GAP));
    wrap.style.gridRowEnd = 'span ' + Math.max(span, 4);
}

function resizeAll() {
    document.querySelectorAll('.thumb-wrap').forEach(setSpan);
}

// Cada imatge: corregeix el span quan carrega
document.querySelectorAll('.thumb-wrap img').forEach(img => {
    const wrap = img.closest('.thumb-wrap');
    if (img.complete && img.naturalHeight > 0) {
        setSpan(wrap);
    } else {
        img.addEventListener('load', () => setSpan(wrap));
    }
});

// Corregeix en redimensionar la finestra (canvi de columnes responsive)
let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(resizeAll, 100);
});

let idleTimer = null;
function resetIdleTimer() {
  clearTimeout(idleTimer);
  if (!selectMode) selectToggle.classList.remove('visible');
  idleTimer = setTimeout(() => selectToggle.classList.add('visible'), 3000);
}
['touchstart','mousemove','scroll','click'].forEach(evt =>
  document.addEventListener(evt, resetIdleTimer, { passive: true })
);
resetIdleTimer();
</script>
</body>
</html>
