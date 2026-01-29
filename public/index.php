<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Rink Micro-Sim Play-by-Play</title>
  <link rel="stylesheet" href="/public/css/styles.css" />
</head>
<body>
  <main class="page">
    <header class="page__header">
      <div>
        <p class="eyebrow">Rink Micro-Sim</p>
        <h1>Play-by-Play + Highlights</h1>
        <p class="subhead">Track every shift, shot, and score in real time or after the final horn.</p>
      </div>
      <div class="status" id="matchStatus">Waiting for a match…</div>
    </header>

    <section class="panel controls">
      <div class="controls__inputs">
        <label>
          <span>Team ID</span>
          <input type="number" id="teamId" min="1" placeholder="e.g. 1" />
        </label>
        <label>
          <span>Match ID</span>
          <input type="number" id="matchId" min="1" placeholder="e.g. 123" required />
        </label>
        <label class="toggle">
          <input type="checkbox" id="liveToggle" checked />
          <span>Live updates</span>
        </label>
        <label>
          <span>Refresh (sec)</span>
          <select id="refreshRate">
            <option value="5">5</option>
            <option value="10" selected>10</option>
            <option value="20">20</option>
          </select>
        </label>
      </div>
      <div class="controls__actions">
        <button class="btn btn--primary" id="loadMatch">Load play-by-play</button>
        <button class="btn" id="clearFeed">Clear</button>
      </div>
      <p class="controls__note" id="statusMessage"></p>
    </section>

    <section class="grid">
      <div class="panel scoreboard">
        <div class="scoreboard__meta">
          <p class="label">Scoreboard</p>
          <p class="muted" id="lastUpdated">Last updated —</p>
        </div>
        <div class="scoreboard__teams" id="scoreboard">
          <div class="scoreboard__placeholder">Load a match to see the score.</div>
        </div>
      </div>

      <div class="panel highlights">
        <div class="highlights__header">
          <p class="label">Highlights summary</p>
          <p class="muted" id="highlightMeta">—</p>
        </div>
        <div class="highlights__stats" id="highlightStats"></div>
        <div class="highlights__list" id="highlightList"></div>
      </div>
    </section>

    <section class="panel feed">
      <div class="feed__header">
        <p class="label">Play-by-play</p>
        <div class="feed__legend">
          <span class="pill">Goal</span>
          <span class="pill pill--secondary">Save</span>
          <span class="pill pill--neutral">Hit</span>
          <span class="pill pill--neutral">Turnover</span>
        </div>
      </div>
      <div class="feed__body" id="playByPlay"></div>
    </section>
  </main>

  <script src="/public/js/playbyplay.js"></script>
</body>
</html>
