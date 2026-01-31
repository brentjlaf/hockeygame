const elements = {
  matchIdInput: document.getElementById('adminMatchId'),
  simulateButton: document.getElementById('simulateMatch'),
  cancelButton: document.getElementById('cancelMatch'),
  status: document.getElementById('adminStatus'),
  refreshButton: document.getElementById('refreshMatches'),
  matchesTable: document.getElementById('matchesTable'),
};

function setStatus(text, variant = 'info') {
  elements.status.textContent = text;
  elements.status.classList.toggle('status-success', variant === 'success');
  elements.status.classList.toggle('status-error', variant === 'error');
}

async function sendAction(action) {
  const matchId = Number(elements.matchIdInput.value);
  if (!matchId) {
    setStatus('Enter a match ID first.', 'error');
    return;
  }

  setStatus('Working…');
  const res = await fetch('/api/admin/match_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, match_id: matchId }),
  });
  const data = await res.json();
  if (data.error) {
    setStatus(data.error, 'error');
    return;
  }
  setStatus(data.message || 'Action completed.', 'success');
  await loadMatches();
}

function renderMatches(matches) {
  if (!matches.length) {
    elements.matchesTable.innerHTML = '<div class="empty">No matches found.</div>';
    return;
  }

  const header = `
    <div class="admin-row admin-row--header">
      <div>Match</div>
      <div>Home</div>
      <div>Away</div>
      <div>Status</div>
      <div>Score</div>
      <div>Created</div>
    </div>
  `;

  const rows = matches.map((match) => {
    const home = match.home_name ? `${match.home_name} (#${match.home_team_id})` : `Team #${match.home_team_id}`;
    const away = match.away_name ? `${match.away_name} (#${match.away_team_id})` : (match.away_team_id ? `Team #${match.away_team_id}` : 'TBD');
    const score = (match.home_score === null || match.away_score === null)
      ? '—'
      : `${match.home_score}-${match.away_score}`;
    return `
      <div class="admin-row">
        <div>#${match.id}</div>
        <div>${home}</div>
        <div>${away}</div>
        <div><span class="status-pill">${match.status}</span></div>
        <div>${score}</div>
        <div>${match.created_at}</div>
      </div>
    `;
  }).join('');

  elements.matchesTable.innerHTML = header + rows;
}

async function loadMatches() {
  const res = await fetch('/api/admin/matches.php');
  const data = await res.json();
  if (data.error) {
    elements.matchesTable.innerHTML = `<div class="empty">${data.error}</div>`;
    return;
  }
  renderMatches(data.matches || []);
}

function init() {
  elements.simulateButton.addEventListener('click', () => sendAction('simulate'));
  elements.cancelButton.addEventListener('click', () => sendAction('cancel'));
  elements.refreshButton.addEventListener('click', loadMatches);
  loadMatches();
}

init();
