const DEFAULT_TEAM_ID = 1;
const REFRESH_INTERVAL_MS = 8000;

const state = {
  teamId: DEFAULT_TEAM_ID,
  matchId: null,
  events: [],
  match: null,
  roster: [],
  live: false,
  timer: null,
  replayTimer: null,
  replayIndex: 0,
};

const elements = {
  homeName: document.getElementById('homeName'),
  awayName: document.getElementById('awayName'),
  homeScore: document.getElementById('homeScore'),
  awayScore: document.getElementById('awayScore'),
  status: document.getElementById('gameStatus'),
  lastUpdated: document.getElementById('lastUpdated'),
  teamIdInput: document.getElementById('teamIdInput'),
  matchIdInput: document.getElementById('matchIdInput'),
  findMatchButton: document.getElementById('findMatch'),
  submitPlanButton: document.getElementById('submitPlan'),
  loadReplayButton: document.getElementById('loadReplay'),
  refreshButton: document.getElementById('refresh'),
  matchStatus: document.getElementById('matchStatus'),
  matchOpponent: document.getElementById('matchOpponent'),
  matchId: document.getElementById('matchId'),
  matchSeed: document.getElementById('matchSeed'),
  lineupContainer: document.getElementById('lineupContainer'),
  goalieSelect: document.getElementById('goalieSelect'),
  planJson: document.getElementById('planJson'),
  planStatus: document.getElementById('planStatus'),
  aggression: document.getElementById('aggression'),
  forecheck: document.getElementById('forecheck'),
  shootBias: document.getElementById('shootBias'),
  risk: document.getElementById('risk'),
  aggressionValue: document.getElementById('aggressionValue'),
  forecheckValue: document.getElementById('forecheckValue'),
  shootBiasValue: document.getElementById('shootBiasValue'),
  riskValue: document.getElementById('riskValue'),
  replayPlay: document.getElementById('replayPlay'),
  replayPause: document.getElementById('replayPause'),
  replayReset: document.getElementById('replayReset'),
  replaySpeed: document.getElementById('replaySpeed'),
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
  if (!state.match) return;
  elements.homeName.textContent = state.match.home_team.name;
  elements.awayName.textContent = state.match.away_team?.name ?? 'TBD';
  elements.homeScore.textContent = state.match.home_score;
  elements.awayScore.textContent = state.match.away_score;
  elements.status.textContent = state.match.status || 'UNKNOWN';
  elements.lastUpdated.textContent = `Updated ${new Date().toLocaleTimeString()}`;
}

function renderFeed(limit = state.events.length) {
  elements.feed.innerHTML = '';
  if (state.events.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'empty';
    empty.textContent = 'No play-by-play events yet.';
    elements.feed.appendChild(empty);
    return;
  }

  let currentPeriod = null;
  state.events.slice(0, limit).forEach((event) => {
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

function updatePlanStatus(text, variant = 'info') {
  elements.planStatus.textContent = text;
  elements.planStatus.classList.toggle('status-success', variant === 'success');
  elements.planStatus.classList.toggle('status-error', variant === 'error');
}

function stopLive() {
  if (state.timer) {
    clearInterval(state.timer);
  }
  state.timer = null;
}

async function loadMatch() {
  if (!state.matchId) return;
  const res = await fetch(`/api/match_result.php?match_id=${state.matchId}`);
  const data = await res.json();
  if (data.error) {
    elements.feed.innerHTML = `<div class="empty">${data.error}</div>`;
    return;
  }

  state.match = data.match;
  state.events = data.events || [];
  renderScoreboard();
  if (state.events.length === 0) {
    resetReplay();
  } else if (state.replayIndex === 0) {
    renderFeed(state.events.length);
  } else {
    renderFeed(state.replayIndex);
  }
}

async function loadRoster() {
  if (!state.teamId) return;
  const res = await fetch(`/api/teams.php?team_id=${state.teamId}`);
  const data = await res.json();
  if (data.error) {
    updatePlanStatus(data.error, 'error');
    return;
  }
  state.roster = data.players || [];
  renderRoster();
}

function createSelect(options, selected) {
  const select = document.createElement('select');
  options.forEach((option) => {
    const opt = document.createElement('option');
    opt.value = option.id;
    opt.textContent = `${option.name} (${option.pos})`;
    if (selected && Number(selected) === Number(option.id)) {
      opt.selected = true;
    }
    select.appendChild(opt);
  });
  return select;
}

function renderRoster() {
  elements.lineupContainer.innerHTML = '';
  elements.goalieSelect.innerHTML = '';

  const forwards = state.roster.filter((p) => p.pos !== 'D' && p.pos !== 'G');
  const defense = state.roster.filter((p) => p.pos === 'D');
  const goalies = state.roster.filter((p) => p.pos === 'G');

  const defaultLines = {
    L1: { F: forwards.slice(0, 3), D: defense.slice(0, 2) },
    L2: { F: forwards.slice(3, 6), D: defense.slice(2, 4) },
    L3: { F: forwards.slice(6, 9), D: defense.slice(4, 6) },
    L4: { F: forwards.slice(9, 12), D: defense.slice(0, 2) },
  };

  Object.keys(defaultLines).forEach((lineKey) => {
    const line = defaultLines[lineKey];
    const row = document.createElement('div');
    row.className = 'lineup-row';
    const label = document.createElement('label');
    label.textContent = lineKey;
    const selects = document.createElement('div');
    selects.className = 'row-selects';
    for (let i = 0; i < 3; i += 1) {
      const player = line.F[i];
      const select = createSelect(forwards, player?.id);
      select.dataset.line = lineKey;
      select.dataset.group = 'F';
      select.dataset.index = String(i);
      selects.appendChild(select);
    }
    for (let i = 0; i < 2; i += 1) {
      const player = line.D[i];
      const select = createSelect(defense, player?.id);
      select.dataset.line = lineKey;
      select.dataset.group = 'D';
      select.dataset.index = String(i);
      selects.appendChild(select);
    }
    row.append(label, selects);
    elements.lineupContainer.appendChild(row);
  });

  goalies.forEach((goalie, index) => {
    const opt = document.createElement('option');
    opt.value = goalie.id;
    opt.textContent = `${goalie.name} (G)`;
    if (index === 0) opt.selected = true;
    elements.goalieSelect.appendChild(opt);
  });

  updatePlanJson();
}

function getLineSelections() {
  const lines = {};
  const selects = elements.lineupContainer.querySelectorAll('select');
  selects.forEach((select) => {
    const line = select.dataset.line;
    const group = select.dataset.group;
    const index = Number(select.dataset.index);
    if (!lines[line]) lines[line] = { F: [], D: [] };
    lines[line][group][index] = Number(select.value);
  });
  return lines;
}

function updateSliderValue(slider, display) {
  display.textContent = slider.value;
}

function buildPlan() {
  return {
    lines: getLineSelections(),
    goalie: Number(elements.goalieSelect.value),
    tactics: {
      aggression: Number(elements.aggression.value),
      forecheck: Number(elements.forecheck.value),
      shoot_bias: Number(elements.shootBias.value),
      risk: Number(elements.risk.value),
    },
  };
}

function updatePlanJson() {
  const plan = buildPlan();
  elements.planJson.value = JSON.stringify(plan, null, 2);
  updatePlanStatus('Plan updated', 'info');
}

async function findMatch() {
  if (!state.teamId) {
    updatePlanStatus('Team ID required', 'error');
    return;
  }
  const res = await fetch(`/api/match_find.php?team_id=${state.teamId}`);
  const data = await res.json();
  if (data.error) {
    updatePlanStatus(data.error, 'error');
    return;
  }
  state.matchId = data.match_id;
  elements.matchIdInput.value = state.matchId;
  elements.matchStatus.textContent = data.status;
  elements.matchOpponent.textContent = data.opponent === 'AI' ? 'Opponent: AI' : 'Opponent: Human';
  elements.matchId.textContent = data.match_id;
  elements.matchSeed.textContent = data.opponent === 'AI' ? 'AI assigned' : 'Waiting submissions';
  updatePlanStatus('Match ready - submit your plan', 'success');
  loadMatch();
}

async function submitPlan() {
  if (!state.matchId || !state.teamId) {
    updatePlanStatus('Match ID and Team ID required', 'error');
    return;
  }
  let plan = null;
  try {
    plan = JSON.parse(elements.planJson.value);
  } catch (error) {
    updatePlanStatus('Plan JSON invalid', 'error');
    return;
  }
  const res = await fetch(`/api/match_submit.php?team_id=${state.teamId}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ match_id: state.matchId, plan }),
  });
  const data = await res.json();
  if (data.error) {
    updatePlanStatus(data.error, 'error');
    return;
  }
  updatePlanStatus(`Plan submitted (${data.status})`, 'success');
  if (data.status === 'DONE') {
    await loadMatch();
    resetReplay();
  }
}

function startReplay() {
  stopReplay();
  const speed = Number(elements.replaySpeed.value);
  state.replayTimer = setInterval(() => {
    if (state.replayIndex >= state.events.length) {
      stopReplay();
      return;
    }
    state.replayIndex += 1;
    renderFeed(state.replayIndex);
  }, speed);
}

function stopReplay() {
  if (state.replayTimer) {
    clearInterval(state.replayTimer);
  }
  state.replayTimer = null;
}

function resetReplay() {
  stopReplay();
  state.replayIndex = 0;
  renderFeed(0);
}

function init() {
  const params = new URLSearchParams(window.location.search);
  const teamIdParam = Number(params.get('team_id'));
  const matchIdParam = Number(params.get('match_id'));
  if (teamIdParam > 0) {
    state.teamId = teamIdParam;
  }
  if (matchIdParam > 0) {
    state.matchId = matchIdParam;
  }
  elements.teamIdInput.value = state.teamId;
  elements.matchIdInput.value = state.matchId ?? '';

  elements.teamIdInput.addEventListener('change', () => {
    const value = Number(elements.teamIdInput.value);
    if (value > 0) {
      state.teamId = value;
      loadRoster();
    }
  });

  elements.matchIdInput.addEventListener('change', () => {
    const value = Number(elements.matchIdInput.value);
    if (value > 0) {
      state.matchId = value;
      loadMatch();
    }
  });

  elements.findMatchButton.addEventListener('click', findMatch);
  elements.submitPlanButton.addEventListener('click', submitPlan);
  elements.loadReplayButton.addEventListener('click', loadMatch);
  elements.refreshButton.addEventListener('click', loadMatch);

  elements.replayPlay.addEventListener('click', startReplay);
  elements.replayPause.addEventListener('click', stopReplay);
  elements.replayReset.addEventListener('click', resetReplay);

  [elements.aggression, elements.forecheck, elements.shootBias, elements.risk].forEach((slider) => {
    slider.addEventListener('input', () => {
      updateSliderValue(elements.aggression, elements.aggressionValue);
      updateSliderValue(elements.forecheck, elements.forecheckValue);
      updateSliderValue(elements.shootBias, elements.shootBiasValue);
      updateSliderValue(elements.risk, elements.riskValue);
      updatePlanJson();
    });
  });

  elements.lineupContainer.addEventListener('change', updatePlanJson);
  elements.goalieSelect.addEventListener('change', updatePlanJson);

  updateSliderValue(elements.aggression, elements.aggressionValue);
  updateSliderValue(elements.forecheck, elements.forecheckValue);
  updateSliderValue(elements.shootBias, elements.shootBiasValue);
  updateSliderValue(elements.risk, elements.riskValue);

  loadRoster();
  if (state.matchId) {
    loadMatch();
  }
}

init();
