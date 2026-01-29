const statusMessage = document.getElementById('statusMessage');
const matchStatus = document.getElementById('matchStatus');
const matchIdInput = document.getElementById('matchId');
const teamIdInput = document.getElementById('teamId');
const refreshRate = document.getElementById('refreshRate');
const liveToggle = document.getElementById('liveToggle');
const loadButton = document.getElementById('loadMatch');
const clearButton = document.getElementById('clearFeed');
const scoreboard = document.getElementById('scoreboard');
const lastUpdated = document.getElementById('lastUpdated');
const highlightStats = document.getElementById('highlightStats');
const highlightList = document.getElementById('highlightList');
const highlightMeta = document.getElementById('highlightMeta');
const playByPlay = document.getElementById('playByPlay');

const state = {
  events: [],
  match: null,
  timer: null,
  lastFetch: null,
};

const highlightTypes = new Set(['GOAL', 'SAVE', 'HIT', 'TURNOVER', 'BLOCK']);
const statLabels = {
  GOAL: 'Goals',
  SAVE: 'Saves',
  HIT: 'Hits',
  TURNOVER: 'Turnovers',
  BLOCK: 'Blocks',
  SHOT: 'Shots',
};

function formatTime(seconds) {
  if (typeof seconds !== 'number') return '--:--';
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function readParams() {
  const params = new URLSearchParams(window.location.search);
  const matchId = params.get('match_id');
  const teamId = params.get('team_id');
  if (matchId) matchIdInput.value = matchId;
  if (teamId) teamIdInput.value = teamId;
}

function setStatus(message, type = 'info') {
  statusMessage.textContent = message;
  statusMessage.dataset.type = type;
}

function updateStatusBadge(match) {
  if (!match) {
    matchStatus.textContent = 'Waiting for a match…';
    return;
  }
  const status = match.status || 'UNKNOWN';
  matchStatus.textContent = `Match ${match.id} • ${status}`;
}

function updateLastUpdated() {
  if (!state.lastFetch) {
    lastUpdated.textContent = 'Last updated —';
    return;
  }
  lastUpdated.textContent = `Last updated ${state.lastFetch.toLocaleTimeString()}`;
}

function renderScoreboard(match) {
  if (!match) {
    scoreboard.innerHTML = '<div class="scoreboard__placeholder">Load a match to see the score.</div>';
    return;
  }
  const awayName = match.away_team?.name || 'TBD';
  scoreboard.innerHTML = `
    <div class="scoreboard__team">
      <div>
        <h3>${match.home_team.name}</h3>
        <p class="muted">Home</p>
      </div>
      <div class="scoreboard__score">${match.home_score}</div>
    </div>
    <div class="scoreboard__team">
      <div>
        <h3>${awayName}</h3>
        <p class="muted">Away</p>
      </div>
      <div class="scoreboard__score">${match.away_score}</div>
    </div>
  `;
}

function renderHighlights(events) {
  if (!events.length) {
    highlightStats.innerHTML = '<div class="muted">No highlight data yet.</div>';
    highlightList.innerHTML = '';
    highlightMeta.textContent = '—';
    return;
  }

  const counts = events.reduce((acc, ev) => {
    acc[ev.event_type] = (acc[ev.event_type] || 0) + 1;
    return acc;
  }, {});

  const statsHtml = ['GOAL', 'SHOT', 'SAVE', 'HIT', 'TURNOVER', 'BLOCK']
    .map((type) => {
      const value = counts[type] || 0;
      const label = statLabels[type] || type;
      return `
        <div class="stat">
          <div class="stat__value">${value}</div>
          <div class="stat__label">${label}</div>
        </div>
      `;
    })
    .join('');

  highlightStats.innerHTML = statsHtml;

  const highlightEvents = events.filter((ev) => highlightTypes.has(ev.event_type));
  const latest = highlightEvents.slice(-6).reverse();

  highlightList.innerHTML = latest
    .map((ev) => {
      const text = ev.text || ev.payload?.text || `[${ev.event_type}]`;
      return `<div class="highlight">${text}</div>`;
    })
    .join('');

  highlightMeta.textContent = `${highlightEvents.length} highlight moments logged`;
}

function renderPlayByPlay(events) {
  if (!events.length) {
    playByPlay.innerHTML = '<div class="muted">No play-by-play events yet.</div>';
    return;
  }

  let html = '';
  let currentPeriod = null;

  events.forEach((ev) => {
    if (ev.period !== currentPeriod) {
      currentPeriod = ev.period;
      html += `<div class="feed__period">Period ${currentPeriod}</div>`;
    }

    const text = ev.text || ev.payload?.text || `[${ev.event_type}]`;
    const time = ev.payload?.time || formatTime(ev.game_time_left);
    const className = ev.event_type === 'GOAL'
      ? 'play play--goal'
      : ev.event_type === 'SAVE'
        ? 'play play--save'
        : ev.event_type === 'HIT'
          ? 'play play--hit'
          : 'play';

    html += `
      <div class="${className}">
        <div class="play__time">${time}</div>
        <div>
          <strong>${ev.event_type}</strong>
          <div>${text}</div>
        </div>
      </div>
    `;
  });

  playByPlay.innerHTML = html;
}

async function fetchMatch() {
  const matchId = Number(matchIdInput.value);
  if (!matchId) {
    setStatus('Enter a match ID to load play-by-play.', 'warning');
    return;
  }
  const teamId = teamIdInput.value ? Number(teamIdInput.value) : null;
  const params = new URLSearchParams({ match_id: matchId });
  if (teamId) params.set('team_id', teamId);

  try {
    const res = await fetch(`/api/match_result.php?${params.toString()}`);
    if (!res.ok) {
      throw new Error(`Request failed (${res.status})`);
    }
    const data = await res.json();
    state.match = data.match;
    state.events = data.events || [];
    state.lastFetch = new Date();

    updateStatusBadge(state.match);
    updateLastUpdated();
    renderScoreboard(state.match);
    renderHighlights(state.events);
    renderPlayByPlay(state.events);
    setStatus(`Loaded ${state.events.length} events.`, 'info');
  } catch (error) {
    setStatus(`Unable to load match: ${error.message}`, 'error');
  }
}

function startPolling() {
  stopPolling();
  if (!liveToggle.checked) return;
  const intervalMs = Number(refreshRate.value) * 1000;
  state.timer = window.setInterval(fetchMatch, intervalMs);
}

function stopPolling() {
  if (state.timer) {
    window.clearInterval(state.timer);
    state.timer = null;
  }
}

loadButton.addEventListener('click', () => {
  fetchMatch();
  startPolling();
});

clearButton.addEventListener('click', () => {
  stopPolling();
  state.events = [];
  state.match = null;
  renderScoreboard(null);
  renderHighlights([]);
  renderPlayByPlay([]);
  updateStatusBadge(null);
  updateLastUpdated();
  setStatus('Cleared view.', 'info');
});

liveToggle.addEventListener('change', () => {
  if (liveToggle.checked) {
    startPolling();
  } else {
    stopPolling();
  }
});

refreshRate.addEventListener('change', () => {
  if (liveToggle.checked) {
    startPolling();
  }
});

readParams();
