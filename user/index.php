<?php
// ============================================================
// user/index.php  —  Events list + Bet Slip
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['user']);

$pdo = getPDO();

// --- Fetch events (scheduled + live) with teams and sport ---
$events = $pdo->query(
    "SELECT e.id, e.title, e.start_time, e.status,
            e.home_score, e.away_score,
            s.name  AS sport_name,
            ht.name AS home_team,
            at.name AS away_team
       FROM events e
       JOIN sports s  ON s.id  = e.sport_id
       JOIN teams  ht ON ht.id = e.home_team_id
       JOIN teams  at ON at.id = e.away_team_id
      WHERE e.status IN ('scheduled','live')
      ORDER BY e.status = 'live' DESC, e.start_time ASC"
)->fetchAll();

// --- For each event load open markets + active outcomes ---
$eventIds = array_column($events, 'id');

$markets  = [];
$outcomes = [];

if ($eventIds) {
    $in = implode(',', array_map('intval', $eventIds));

    $markets = $pdo->query(
        "SELECT em.id AS em_id, em.event_id, em.status AS market_status,
                m.code, m.name
           FROM event_markets em
           JOIN markets m ON m.id = em.market_id
          WHERE em.event_id IN ($in)
            AND em.status = 'open'
          ORDER BY em.event_id, m.name"
    )->fetchAll();

    $outcomes = $pdo->query(
        "SELECT o.id, o.event_market_id, o.name, o.code, o.odds, o.status
           FROM outcomes o
           JOIN event_markets em ON em.id = o.event_market_id
          WHERE em.event_id IN ($in)
            AND em.status = 'open'
            AND o.status IN ('active','suspended')
          ORDER BY o.event_market_id, o.id"
    )->fetchAll();
}

// Index by event_id / em_id for fast lookup
$marketsByEvent = [];
foreach ($markets as $m) {
    $marketsByEvent[$m['event_id']][] = $m;
}

$outcomesByEM = [];
foreach ($outcomes as $o) {
    $outcomesByEM[$o['event_market_id']][] = $o;
}

$pageTitle = 'События';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Актуальные события</h1>

<div class="two-col">

  <!-- ======== Events list ======== -->
  <div id="events-list">
    <?php if (empty($events)): ?>
      <div class="card">
        <p class="text-muted text-center card-empty">
          Пока нет доступных событий. Загляните позже.
        </p>
      </div>
    <?php endif; ?>

    <?php foreach ($events as $ev): ?>
      <?php
        $isLive    = $ev['status'] === 'live';
        $evMarkets = $marketsByEvent[$ev['id']] ?? [];
      ?>
      <div class="event-card <?= $isLive ? 'is-live' : '' ?>"
           id="event-<?= $ev['id'] ?>">

        <div class="event-header">
          <div class="event-teams">
            <?php if ($isLive): ?>
              <span class="live-dot"></span>
              <span class="badge badge-danger badge--mr">ЛАЙВ</span>
            <?php endif; ?>
            <?= htmlspecialchars($ev['home_team'], ENT_QUOTES) ?>
            <span class="text-muted"> vs </span>
            <?= htmlspecialchars($ev['away_team'], ENT_QUOTES) ?>
          </div>

          <?php if ($isLive): ?>
            <div class="event-score" id="score-<?= $ev['id'] ?>">
              <?= (int)$ev['home_score'] ?>:<?= (int)$ev['away_score'] ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="event-meta">
          <?= htmlspecialchars($ev['sport_name'], ENT_QUOTES) ?>
          &nbsp;·&nbsp;
          <?= htmlspecialchars(date('d.m.Y H:i', strtotime($ev['start_time'])), ENT_QUOTES) ?>
        </div>

        <?php if (empty($evMarkets)): ?>
          <p class="text-muted text-xs">Рынки ещё не добавлены.</p>
        <?php endif; ?>

        <?php foreach ($evMarkets as $mkt): ?>
          <?php $outs = $outcomesByEM[$mkt['em_id']] ?? []; ?>
          <div class="market-block">
            <div class="market-name">
              <?= htmlspecialchars($mkt['name'], ENT_QUOTES) ?>
            </div>
            <div class="outcomes-row">
              <?php foreach ($outs as $out): ?>
                <?php $suspended = $out['status'] === 'suspended'; ?>
                <button
                  class="outcome-btn <?= $suspended ? 'suspended' : '' ?>"
                  <?= $suspended ? 'disabled title="Приостановлено"' : '' ?>
                  data-outcome-id="<?= $out['id'] ?>"
                  data-outcome-name="<?= htmlspecialchars($out['name'], ENT_QUOTES) ?>"
                  data-odds="<?= $out['odds'] ?>"
                  data-event="<?= htmlspecialchars($ev['home_team'] . ' vs ' . $ev['away_team'], ENT_QUOTES) ?>"
                  data-market="<?= htmlspecialchars($mkt['name'], ENT_QUOTES) ?>"
                  onclick="slipToggle(this)"
                >
                  <span class="outcome-name"><?= htmlspecialchars($out['name'], ENT_QUOTES) ?></span>
                  <span class="outcome-odds"><?= number_format($out['odds'], 2) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

      </div><!-- .event-card -->
    <?php endforeach; ?>
  </div><!-- #events-list -->

  <!-- ======== Bet Slip ======== -->
  <div>
    <div class="bet-slip" id="bet-slip">
      <div class="bet-slip-title">
        Купон
        <button class="btn btn-ghost btn-sm" onclick="slipClear()">Очистить</button>
      </div>

      <div class="bet-slip-items" id="slip-items">
        <p class="slip-empty" id="slip-empty">Добавьте исходы из событий</p>
      </div>

      <div id="slip-calc" style="display:none;">
        <div class="slip-total-odds">
          <span>Итоговый коэф.</span>
          <span id="slip-total-odds-val">1.00</span>
        </div>

        <div class="form-group mb-1">
          <label class="form-label" for="slip-amount">Сумма ставки (VCOIN)</label>
          <input
            class="form-control"
            type="number"
            id="slip-amount"
            min="1"
            max="99999"
            step="1"
            placeholder="Введите сумму"
            oninput="slipRecalc()"
          >
        </div>

        <div class="slip-potential">
          <span>Возможный выигрыш</span>
          <span id="slip-potential-val">0.00 VCOIN</span>
        </div>

        <button class="btn btn-primary btn-block" id="slip-submit-btn" onclick="slipSubmit()">
          Сделать ставку
        </button>
      </div>
    </div>
  </div>

</div><!-- .two-col -->

<!-- ======== JS: Bet Slip + AJAX Polling ======== -->
<script>
// ---- Bet Slip State ----
const slip = {}; // outcomeId → {name, odds, event, market}

function slipToggle(btn) {
  const id   = btn.dataset.outcomeId;
  if (slip[id]) {
    slipRemove(id);
  } else {
    slip[id] = {
      name:   btn.dataset.outcomeName,
      odds:   parseFloat(btn.dataset.odds),
      event:  btn.dataset.event,
      market: btn.dataset.market,
      btn:    btn,
    };
    btn.classList.add('selected');
    slipRender();
  }
}

function slipRemove(id) {
  if (slip[id] && slip[id].btn) {
    slip[id].btn.classList.remove('selected');
  }
  delete slip[id];
  slipRender();
}

function slipClear() {
  Object.keys(slip).forEach(id => {
    if (slip[id].btn) slip[id].btn.classList.remove('selected');
  });
  Object.keys(slip).forEach(id => delete slip[id]);
  slipRender();
}

function slipRender() {
  const container = document.getElementById('slip-items');
  const empty     = document.getElementById('slip-empty');
  const calc      = document.getElementById('slip-calc');
  const ids       = Object.keys(slip);

  // Clear dynamic items
  container.querySelectorAll('.slip-item').forEach(el => el.remove());

  if (ids.length === 0) {
    empty.style.display = '';
    calc.style.display  = 'none';
    return;
  }

  empty.style.display = 'none';
  calc.style.display  = '';

  ids.forEach(id => {
    const s = slip[id];
    const div = document.createElement('div');
    div.className     = 'slip-item';
    div.dataset.slipId = id;
    div.innerHTML = `
      <div class="slip-item-info">
        <div class="slip-item-event">${escHtml(s.event)} · ${escHtml(s.market)}</div>
        <div class="slip-item-outcome">${escHtml(s.name)}</div>
      </div>
      <span class="slip-item-odds">${s.odds.toFixed(2)}</span>
      <button class="slip-remove-btn" onclick="slipRemove('${id}')" title="Удалить">×</button>
    `;
    container.insertBefore(div, empty);
  });

  slipRecalc();
}

function slipRecalc() {
  const ids    = Object.keys(slip);
  const amount = parseFloat(document.getElementById('slip-amount').value) || 0;
  const totalOdds = ids.reduce((acc, id) => acc * slip[id].odds, 1);
  const potential  = amount * totalOdds;

  document.getElementById('slip-total-odds-val').textContent = totalOdds.toFixed(2);
  document.getElementById('slip-potential-val').textContent  =
    potential > 0 ? potential.toFixed(2) + ' VCOIN' : '0.00 VCOIN';
}

function escHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ---- Place Bet via AJAX ----
function slipSubmit() {
  const ids    = Object.keys(slip);
  const amount = parseFloat(document.getElementById('slip-amount').value);

  if (ids.length === 0) { alert('Добавьте хотя бы один исход.'); return; }
  if (!amount || amount < 1) { alert('Введите корректную сумму (минимум 1).'); return; }

  const btn = document.getElementById('slip-submit-btn');
  btn.disabled = true;
  btn.textContent = 'Обработка…';

  const body = new URLSearchParams();
  body.append('csrf_token', '<?= csrf_token() ?>');
  body.append('amount', amount);
  ids.forEach(id => body.append('outcome_ids[]', id));

  fetch('<?= BASE_URL ?>/api/place_bet.php', { method: 'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        slipClear();
        showToast('Ставка принята! Возможный выигрыш: ' + data.potential_win + ' VCOIN', 'success');
      } else {
        showToast(data.error || 'Ошибка при размещении ставки.', 'error');
      }
    })
    .catch(() => showToast('Ошибка соединения.', 'error'))
    .finally(() => {
      btn.disabled = false;
      btn.textContent = 'Сделать ставку';
    });
}

// ---- Toast notifications ----
function showToast(msg, type) {
  const t = document.createElement('div');
  t.className = 'alert alert-' + type;
  t.style.cssText = 'position:fixed;top:70px;right:1.5rem;z-index:999;min-width:280px;max-width:400px;animation:fadeIn .2s ease';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

// ---- AJAX Polling: Live Events ----
function pollLive() {
  fetch('<?= BASE_URL ?>/api/live_events.php')
    .then(r => r.json())
    .then(data => {
      data.forEach(ev => {
        const scoreEl = document.getElementById('score-' + ev.id);
        if (scoreEl) {
          scoreEl.textContent = ev.home_score + ':' + ev.away_score;
        }
      });
    })
    .catch(() => {}); // silent fail on polling
}

// Poll every 10 seconds
setInterval(pollLive, 10000);
</script>

<style>
@keyframes fadeIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
