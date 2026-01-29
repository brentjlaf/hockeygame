const DEFAULT_GAME_ID = 1;
const REFRESH_INTERVAL_MS = 8000;

const state = {
  gameId: DEFAULT_GAME_ID,
  events: [],
  game: null,
  live: true,
  timer: null,
};

const elements = {
  homeName: document.getElementById('homeName'),
  awayName: document.getElementById('awayName'),
  homeScore: document.getElementById('homeScore'),
  awayScore: document.getElementById('awayScore'),
  status: document.getElementById('gameStatus'),
  lastUpdated: document.getElementById('lastUpdated'),
  gameIdInput: document.getElementById('gameIdInput'),
  loadButton: document.getElementById('loadGame'),
  refreshButton: document.getElementById('refresh'),
  liveButton: document.getElementById('toggleLive'),
  statGrid: document.getElementById('statGrid'),
  highlightList: document.getElementById('highlightList'),
  feed: document.getElementById('feed'),
};

function formatClock(seconds) {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

function formatTimestamp(event) {
  if (typeof event.game_time_left === 'number') {
    return `${formatClock(event.game_time_left)} left`;
  }
  return '—';
}

function renderScoreboard() {
  if (!state.game) return;
  elements.homeName.textContent = state.game.home_team.name;
  elements.awayName.textContent = state.game.away_team.name;
  elements.homeScore.textContent = state.game.home_score;
  elements.awayScore.textContent = state.game.away_score;
  elements.status.textContent = state.game.status || 'UNKNOWN';
  elements.lastUpdated.textContent = `Updated ${new Date().toLocaleTimeString()}`;
}

function statCard(label, value, accent) {
  const card = document.createElement('div');
  card.className = 'stat-card';
  if (accent) {
    card.style.borderColor = accent;
  }
  const title = document.createElement('div');
  title.textContent = label;
  const val = document.createElement('div');
  val.className = 'value';
  val.textContent = value;
  card.append(title, val);
  return card;
}

function renderHighlights() {
  elements.statGrid.innerHTML = '';
  elements.highlightList.innerHTML = '';

  if (!state.game) return;

  const events = state.events;
  const counts = {
    goals: events.filter((e) => e.event_type === 'GOAL').length,
    shots: events.filter((e) => e.event_type === 'SHOT').length,
    hits: events.filter((e) => e.event_type === 'HIT').length,
    saves: events.filter((e) => e.event_type === 'SAVE').length,
    blocks: events.filter((e) => e.event_type === 'BLOCK').length,
    turnovers: events.filter((e) => e.event_type === 'TURNOVER').length,
  };

  elements.statGrid.append(
    statCard('Goals', counts.goals, '#22c55e'),
    statCard('Shots', counts.shots, '#3b82f6'),
    statCard('Hits', counts.hits, '#f97316'),
    statCard('Saves', counts.saves, '#14b8a6'),
    statCard('Blocks', counts.blocks, '#a855f7'),
    statCard('Turnovers', counts.turnovers, '#ef4444')
  );

  const highlightTypes = new Set(['GOAL', 'SAVE', 'HIT', 'BLOCK', 'TURNOVER', 'MISS']);
  const highlights = [...events]
    .reverse()
    .filter((event) => highlightTypes.has(event.event_type))
    .slice(0, 6);

  if (highlights.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'empty';
    empty.textContent = 'No highlight-worthy events yet.';
    elements.highlightList.appendChild(empty);
    return;
  }

  highlights.forEach((event) => {
    const item = document.createElement('li');
    item.className = 'highlight-item';
    const title = document.createElement('strong');
    title.textContent = `${event.event_type} · Period ${event.period}`;
    const detail = document.createElement('div');
    detail.textContent = event.text || 'Key moment logged.';
    const meta = document.createElement('div');
    meta.className = 'meta';
    meta.textContent = formatTimestamp(event);
    item.append(title, detail, meta);
    elements.highlightList.appendChild(item);
  });
}

function renderFeed() {
  elements.feed.innerHTML = '';
  if (state.events.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'empty';
    empty.textContent = 'No play-by-play events yet.';
    elements.feed.appendChild(empty);
    return;
  }

  let currentPeriod = null;
  state.events.forEach((event) => {
    if (event.period !== currentPeriod) {
      currentPeriod = event.period;
      const header = document.createElement('div');
      header.className = 'feed-period';
      header.textContent = `Period ${currentPeriod}`;
      elements.feed.appendChild(header);
    }

    const row = document.createElement('div');
    row.className = 'feed-item';
    const meta = document.createElement('div');
    meta.className = 'meta';
    meta.textContent = formatTimestamp(event);

    const detail = document.createElement('div');
    const label = document.createElement('span');
    label.className = 'event-type';
    label.textContent = event.event_type;
    const text = document.createElement('span');
    text.textContent = event.text || 'Play recorded.';
    detail.append(label, text);

    row.append(meta, detail);
    elements.feed.appendChild(row);
  });
}

function updateLiveButton() {
  elements.liveButton.textContent = state.live ? 'Live: On' : 'Live: Off';
  elements.liveButton.classList.toggle('live', state.live);
}

function stopLive() {
  if (state.timer) {
    clearInterval(state.timer);
  }
  state.timer = null;
}

function startLive() {
  stopLive();
  state.timer = setInterval(loadGame, REFRESH_INTERVAL_MS);
}

async function loadGame() {
  if (!state.gameId) return;
  const res = await fetch(`/api/game_result.php?game_id=${state.gameId}`);
  const data = await res.json();
  if (data.error) {
    elements.feed.innerHTML = `<div class="empty">${data.error}</div>`;
    return;
  }

  state.game = data.game;
  state.events = data.events || [];
  renderScoreboard();
  renderHighlights();
  renderFeed();
}

function init() {
  const params = new URLSearchParams(window.location.search);
  const gameIdParam = Number(params.get('game_id'));
  if (gameIdParam > 0) {
    state.gameId = gameIdParam;
  }
  elements.gameIdInput.value = state.gameId;

  elements.loadButton.addEventListener('click', () => {
    const value = Number(elements.gameIdInput.value);
    if (value > 0) {
      state.gameId = value;
      loadGame();
    }
  });

  elements.refreshButton.addEventListener('click', loadGame);

  elements.liveButton.addEventListener('click', () => {
    state.live = !state.live;
    updateLiveButton();
    if (state.live) {
      startLive();
    } else {
      stopLive();
    }
  });

  updateLiveButton();
  loadGame();
  if (state.live) {
    startLive();
  }
}

init();
