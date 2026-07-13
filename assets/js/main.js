(() => {
  const POLL_MS = 1200;
  const API = {
    create: 'api/create_room.php',
    join: 'api/join_room.php',
    leave: 'api/leave.php',
    kick: 'api/kick.php',
    start: 'api/start_game.php',
    play: 'api/play_card.php',
    draw: 'api/draw_card.php',
    pass: 'api/pass_turn.php',
    chat: 'api/send_chat.php',
    poll: 'api/game_poll.php',
    playAgain: 'api/play_again.php',
    newRound: 'api/new_round.php'
  };

  const state = {
    sid: getSessionId(),
    name: '',
    code: '',
    status: 'home',
    hostId: null,
    targetScore: window.UNO_RULES.defaultTargetScore,
    players: [],
    round: null,       // parsed <round> or null
    lastEventId: 0,
    suppressEffects: true,
    pollTimer: null,
    gameOverShown: false,
    pendingWildCardId: null,
    timerRingHandle: null,
    myTurnAnnouncedAt: null
  };

  function getSessionId() {
    let sid = sessionStorage.getItem('uno_sid');
    if (!sid) {
      sid = (window.crypto && crypto.randomUUID)
        ? crypto.randomUUID()
        : 'sid-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      sessionStorage.setItem('uno_sid', sid);
    }
    return sid;
  }

  const $ = (sel) => document.querySelector(sel);
  const $all = (sel) => Array.from(document.querySelectorAll(sel));

  function showScreen(id) {
    $all('.screen').forEach((s) => s.classList.remove('active'));
    $(`#${id}`).classList.add('active');
  }

  function toast(message, cls = '') {
    const el = document.createElement('div');
    el.className = `toast ${cls}`;
    el.textContent = message;
    $('#toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3000);
  }

  document.addEventListener('click', () => Sound.unlock(), { once: true });

  // ---------- API helpers (XML in, XML out) ----------
  async function apiPost(url, params) {
    const body = new URLSearchParams(params);
    const res = await fetch(url, { method: 'POST', body });
    const text = await res.text();
    return new DOMParser().parseFromString(text, 'application/xml');
  }
  async function apiGet(url, params) {
    const qs = new URLSearchParams(params).toString();
    const res = await fetch(`${url}?${qs}`);
    const text = await res.text();
    return new DOMParser().parseFromString(text, 'application/xml');
  }
  function ackOk(doc) {
    const root = doc.documentElement;
    return !!root && root.getAttribute('ok') === '1';
  }
  function ackError(doc) {
    const el = doc.querySelector('error');
    return el ? el.textContent : 'Something went wrong.';
  }
  function attr(el, name, fallback = '') {
    if (!el) return fallback;
    const v = el.getAttribute(name);
    return v === null ? fallback : v;
  }

  // ---------- HOME SCREEN ----------
  $all('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      $all('.tab-btn').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      $all('.tab-panel').forEach((p) => p.classList.add('hidden'));
      $(`#tab-${btn.dataset.tab}`).classList.remove('hidden');
    });
  });

  function homeError(msg) {
    const el = $('#home-error');
    el.textContent = msg;
    el.classList.remove('hidden');
  }

  $('#btn-create').addEventListener('click', async () => {
    const name = $('#create-name').value.trim() || 'Host';
    const targetScore = parseInt($('#create-target').value, 10) || window.UNO_RULES.defaultTargetScore;
    Sound.unlock();
    const doc = await apiPost(API.create, { session_id: state.sid, name, target_score: targetScore });
    if (!ackOk(doc)) return homeError(ackError(doc));
    const code = attr(doc.documentElement, 'code');
    enterRoom(code, name);
  });

  $('#btn-join').addEventListener('click', async () => {
    const name = $('#join-name').value.trim() || 'Player';
    const code = $('#join-code').value.trim().toUpperCase();
    if (!code) return homeError('Enter a room code.');
    Sound.unlock();
    const doc = await apiPost(API.join, { session_id: state.sid, name, code });
    if (!ackOk(doc)) return homeError(ackError(doc));
    enterRoom(code, name);
  });

  function enterRoom(code, name) {
    state.code = code;
    state.name = name;
    state.lastEventId = 0;
    state.suppressEffects = true;
    state.gameOverShown = false;
    startPolling();
  }

  // ---------- LEAVE / KICK ----------
  async function doLeave() {
    stopPolling();
    try { await apiPost(API.leave, { session_id: state.sid, code: state.code }); } catch (e) { /* ignore */ }
    location.reload();
  }
  $('#btn-leave-lobby').addEventListener('click', doLeave);
  $('#btn-leave-game').addEventListener('click', doLeave);

  async function doKick(targetId) {
    const doc = await apiPost(API.kick, { session_id: state.sid, code: state.code, target_session_id: targetId });
    if (!ackOk(doc)) toast(ackError(doc));
  }

  $('#btn-start-game').addEventListener('click', async () => {
    const doc = await apiPost(API.start, { session_id: state.sid, code: state.code });
    if (!ackOk(doc)) toast(ackError(doc));
  });

  $('#btn-new-round').addEventListener('click', async () => {
    if (!confirm('Cancel the current round and deal a fresh one? No points will be awarded for the cancelled round.')) return;
    const doc = await apiPost(API.newRound, { session_id: state.sid, code: state.code });
    if (!ackOk(doc)) toast(ackError(doc));
  });

  // ---------- RULES MODAL ----------
  function openRules() { $('#rules-overlay').classList.remove('hidden'); }
  function closeRules() { $('#rules-overlay').classList.add('hidden'); }
  $('#btn-rules-home').addEventListener('click', openRules);
  $('#btn-rules-lobby').addEventListener('click', openRules);
  $('#btn-rules-game').addEventListener('click', openRules);
  $('#btn-close-rules').addEventListener('click', closeRules);
  $('#rules-overlay').addEventListener('click', (e) => {
    if (e.target.id === 'rules-overlay') closeRules();
  });

  // ---------- POLLING LOOP ----------
  function startPolling() {
    stopPolling();
    pollOnce();
    state.pollTimer = setInterval(pollOnce, POLL_MS);
  }
  function stopPolling() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    state.pollTimer = null;
  }

  async function pollOnce() {
    let doc;
    try {
      doc = await apiGet(API.poll, { session_id: state.sid, code: state.code, since_event: state.lastEventId });
    } catch (e) {
      return; // transient network hiccup; try again next tick
    }
    const root = doc.documentElement;
    if (!root || attr(root, 'found') !== '1') {
      toast('This room no longer exists.');
      stopPolling();
      setTimeout(() => location.reload(), 1500);
      return;
    }

    state.status = attr(root, 'status');
    state.hostId = attr(root, 'hostId');
    state.targetScore = parseInt(attr(root, 'targetScore', '500'), 10);
    state.code = attr(root, 'code', state.code);

    state.players = Array.from(root.querySelectorAll('players > p')).map((p) => ({
      id: attr(p, 'id'), name: attr(p, 'name'), color: attr(p, 'color'),
      role: attr(p, 'role'), isHost: attr(p, 'isHost') === '1', score: parseInt(attr(p, 'score', '0'), 10)
    }));

    const roundEl = root.querySelector('round');
    state.round = roundEl ? parseRound(roundEl) : null;

    processEvents(Array.from(root.querySelectorAll('events > event')));
    state.suppressEffects = false;

    render();
  }

  function parseRound(roundEl) {
    const pendingEl = roundEl.querySelector('pendingDraw');
    const topEl = roundEl.querySelector('topCard');
    const curEl = roundEl.querySelector('currentPlayer');
    const turnOrder = Array.from(roundEl.querySelectorAll('turnOrder > p')).map((p) => ({
      id: attr(p, 'id'), cards: parseInt(attr(p, 'cards', '0'), 10)
    }));
    const myHand = Array.from(roundEl.querySelectorAll('myHand > card')).map((c) => ({
      id: attr(c, 'id'), color: attr(c, 'color'), type: attr(c, 'type'),
      value: attr(c, 'value') === '' ? null : parseInt(attr(c, 'value'), 10)
    }));
    return {
      active: attr(roundEl, 'active') === '1',
      number: parseInt(attr(roundEl, 'number', '0'), 10),
      color: attr(roundEl, 'color') || null,
      direction: parseInt(attr(roundEl, 'direction', '1'), 10),
      drawPileCount: parseInt(attr(roundEl, 'drawPileCount', '0'), 10),
      drawnCardPendingId: attr(roundEl, 'drawnCardPendingId') || null,
      pendingDraw: {
        amount: parseInt(attr(pendingEl, 'amount', '0'), 10),
        type: attr(pendingEl, 'type') || null
      },
      topCard: topEl ? {
        id: attr(topEl, 'id'), color: attr(topEl, 'color'), type: attr(topEl, 'type'),
        value: attr(topEl, 'value') === '' ? null : parseInt(attr(topEl, 'value'), 10)
      } : null,
      turnOrder,
      currentPlayerId: curEl ? attr(curEl, 'id') : null,
      turnEndsAt: curEl ? parseInt(attr(curEl, 'endsAt', '0'), 10) : null,
      turnSeconds: curEl ? parseInt(attr(curEl, 'seconds', String(window.UNO_RULES.turnSeconds)), 10) : window.UNO_RULES.turnSeconds,
      myHand
    };
  }

  function playerName(id) {
    const p = state.players.find((pl) => pl.id === id);
    return p ? p.name : '???';
  }
  function playerColor(id) {
    const p = state.players.find((pl) => pl.id === id);
    return p ? p.color : '#999';
  }

  function processEvents(eventEls) {
    eventEls.forEach((ev) => {
      const id = parseInt(attr(ev, 'id', '0'), 10);
      if (id > state.lastEventId) state.lastEventId = id;
      const type = attr(ev, 'type');
      const suppressed = state.suppressEffects;

      if (type === 'kicked') {
        const byName = attr(ev, 'byName');
        $('#kicked-text').textContent = `${byName} kicked you.`;
        $('#kicked-overlay').classList.remove('hidden');
        stopPolling();
        Sound.kicked();
        return;
      }

      if (suppressed) return; // don't replay historical sounds/toasts/chat on first load

      switch (type) {
        case 'system':
          appendChat(escapeHtml(attr(ev, 'message')), true);
          break;
        case 'chat':
          appendChat(`<span class="chat-from" style="color:${attr(ev, 'color')}">${escapeHtml(attr(ev, 'from'))}:</span>${escapeHtml(attr(ev, 'message'))}`);
          Sound.chatPop();
          break;
        case 'card_played':
          Sound.playCard();
          break;
        case 'card_drawn':
          Sound.drawCard();
          break;
        case 'uno':
          Sound.uno();
          toast(`🔥 UNO! — ${attr(ev, 'name')}`, 'uno-toast');
          break;
        case 'not_enough_players':
          toast('Not enough players to continue — the game has ended.');
          break;
        case 'round_ended': {
          Sound.roundWin();
          const winnerName = attr(ev, 'winnerName');
          const finished = attr(ev, 'finished') === '1';
          const rows = Array.from(ev.querySelectorAll('breakdown > p'))
            .map((p) => ({ name: attr(p, 'name'), cardsLeft: attr(p, 'cardsLeft'), pointsAdded: parseInt(attr(p, 'pointsAdded', '0'), 10) }))
            .sort((a, b) => b.pointsAdded - a.pointsAdded)
            .map((r) => `<tr><td>${escapeHtml(r.name)}</td><td>${r.cardsLeft}</td><td>+${r.pointsAdded}</td></tr>`)
            .join('');
          $('#round-end-title').textContent = `🎉 ${winnerName} won the round!`;
          $('#round-end-body').innerHTML = `<table><thead><tr><th>Player</th><th>Cards Left</th><th>Points</th></tr></thead><tbody>${rows}</tbody></table>`;
          $('#round-end-next').textContent = finished ? '' : 'Next round starting soon — order will reshuffle…';
          $('#round-end-overlay').classList.remove('hidden');
          setTimeout(() => $('#round-end-overlay').classList.add('hidden'), 7500);
          break;
        }
        case 'game_over': {
          $('#round-end-overlay').classList.add('hidden');
          Sound.gameOver();
          showGameOverOverlay(attr(ev, 'overallWinnerName'), Array.from(ev.querySelectorAll('scoreboard > p')).map((p) => ({
            name: attr(p, 'name'), score: parseInt(attr(p, 'score', '0'), 10)
          })));
          break;
        }
      }
    });
  }

  function showGameOverOverlay(winnerName, scoreboard) {
    state.gameOverShown = true;
    $('#game-over-winner').textContent = winnerName
      ? `🏆 ${winnerName} wins the game with the fewest points!`
      : 'Game ended.';
    const list = $('#game-over-scores');
    list.innerHTML = '';
    scoreboard.sort((a, b) => a.score - b.score).forEach((row, i) => {
      const li = document.createElement('li');
      if (i === 0) li.classList.add('rank-1');
      li.innerHTML = `<span><span class="rank-num">#${i + 1}</span>${escapeHtml(row.name)}</span><span>${row.score} pts</span>`;
      list.appendChild(li);
    });
    const iAmHost = state.hostId === state.sid;
    $('#btn-play-again').classList.toggle('hidden', !iAmHost);
    $('#game-over-overlay').classList.remove('hidden');
  }

  $('#btn-play-again').addEventListener('click', async () => {
    await apiPost(API.playAgain, { session_id: state.sid, code: state.code });
    $('#game-over-overlay').classList.add('hidden');
    state.gameOverShown = false;
  });
  $('#btn-back-home').addEventListener('click', () => location.reload());
  $('#btn-kicked-ok').addEventListener('click', () => location.reload());

  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  function appendChat(html, isSystem = false) {
    const log = $('#chat-log');
    const div = document.createElement('div');
    div.className = isSystem ? 'chat-system' : 'chat-msg';
    div.innerHTML = html;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
  }

  $('#chat-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = $('#chat-input');
    const msg = input.value.trim();
    if (!msg) return;
    input.value = '';
    await apiPost(API.chat, { session_id: state.sid, code: state.code, message: msg });
  });

  // ---------- RENDER ----------
  function render() {
    if (state.status === 'lobby') {
      state.gameOverShown = false;
      renderLobby();
      showScreen('screen-lobby');
    } else {
      renderLobby(); // keeps host id / player list current for kick buttons in-game too
      renderGame();
      showScreen('screen-game');
      if (state.status === 'finished' && !state.gameOverShown) {
        showGameOverOverlay(null, state.players.filter((p) => p.role === 'player').map((p) => ({ name: p.name, score: p.score })));
      }
    }

    const me = state.players.find((p) => p.id === state.sid);
    const isSpectator = me ? me.role === 'spectator' : false;
    $('#spectator-badge').classList.toggle('hidden', !isSpectator);

    const rulesTargetEl = $('#rules-target-score');
    if (rulesTargetEl) rulesTargetEl.textContent = state.targetScore;

    const iAmHost = state.hostId === state.sid;
    $('#btn-new-round').classList.toggle('hidden', !(iAmHost && state.status === 'playing'));
  }

  function renderLobby() {
    $('#lobby-code').textContent = state.code;
    $('#game-code').textContent = state.code;
    const activePlayers = state.players.filter((p) => p.role === 'player');
    $('#lobby-count').textContent = activePlayers.length;

    const iAmHost = state.hostId === state.sid;

    const list = $('#lobby-player-list');
    list.innerHTML = '';
    state.players.forEach((p) => {
      const isMe = p.id === state.sid;
      const li = document.createElement('li');
      li.innerHTML = `
        <span class="player-dot" style="background:${p.color}"></span>
        <span class="player-name-lobby">${escapeHtml(p.name)}${isMe ? ' (you)' : ''}</span>
        ${p.isHost ? '<span class="host-tag">HOST</span>' : ''}
        ${p.role === 'spectator' ? '<span class="spectator-tag">spectating</span>' : ''}
      `;
      if (iAmHost && !isMe) {
        const btn = document.createElement('button');
        btn.className = 'kick-btn';
        btn.textContent = 'Kick';
        btn.addEventListener('click', () => doKick(p.id));
        li.appendChild(btn);
      }
      list.appendChild(li);
    });

    $('#host-controls').classList.toggle('hidden', !iAmHost || state.status !== 'lobby');
    $('#guest-wait').classList.toggle('hidden', iAmHost && state.status === 'lobby');
    $('#lobby-target').value = state.targetScore;
    $('#score-target').textContent = state.targetScore;
  }

  const CARD_LABELS = { skip: '⛔', reverse: '🔁', draw2: '+2', wild: '★', wild4: '+4' };
  function cardColorClass(card) {
    return (card.type === 'wild' || card.type === 'wild4') ? 'wild' : card.color;
  }
  function cardLabel(card) {
    return card.type === 'number' ? String(card.value) : (CARD_LABELS[card.type] || '?');
  }
  function buildCardEl(card) {
    const el = document.createElement('div');
    el.className = `card ${cardColorClass(card)}`;
    el.dataset.cardId = card.id;
    const face = document.createElement('div');
    face.className = 'card-face';
    face.textContent = cardLabel(card);
    el.appendChild(face);
    return el;
  }

  function isMyTurn() {
    return !!state.round && state.round.active && state.round.currentPlayerId === state.sid;
  }

  function isPlayableClient(card) {
    if (!isMyTurn()) return false;
    const g = state.round;
    if (g.pendingDraw.amount > 0) {
      return g.pendingDraw.type === 'draw2' ? card.type === 'draw2' : false;
    }
    if (card.type === 'wild') return true;
    if (card.type === 'wild4') return !g.myHand.some((c) => c.color === g.color);
    if (card.color === g.color) return true;
    const top = g.topCard;
    if (top && top.type === 'number' && card.type === 'number') return card.value === top.value;
    if (top && card.type === top.type && card.type !== 'number') return true;
    return false;
  }

  function renderGame() {
    const g = state.round;
    const me = state.players.find((p) => p.id === state.sid);
    const isSpectator = me ? me.role === 'spectator' : true;
    const iAmHost = state.hostId === state.sid;

    const strip = $('#opponents-strip');
    strip.innerHTML = '';
    if (g) {
      g.turnOrder.filter((t) => t.id !== state.sid).forEach((t) => {
        const wrap = document.createElement('div');
        wrap.className = 'opponent-card' + (t.id === g.currentPlayerId ? ' active-turn' : '');
        wrap.innerHTML = `
          <span class="player-dot" style="background:${playerColor(t.id)}"></span>
          <div class="opponent-name">${escapeHtml(playerName(t.id))}</div>
          <div class="opponent-count">🂠 × ${t.cards}</div>
          ${t.cards === 1 ? '<div class="uno-flag">UNO!</div>' : ''}
        `;
        if (iAmHost) {
          const btn = document.createElement('button');
          btn.className = 'kick-btn';
          btn.textContent = 'Kick';
          btn.style.marginTop = '6px';
          btn.addEventListener('click', () => doKick(t.id));
          wrap.appendChild(btn);
        }
        strip.appendChild(wrap);
      });
    }

    const discardSlot = $('#discard-pile');
    discardSlot.innerHTML = '';
    if (g && g.topCard) discardSlot.appendChild(buildCardEl(g.topCard));

    $('#draw-pile').onclick = () => {
      if (!isMyTurn()) return;
      Sound.unlock();
      apiPost(API.draw, { session_id: state.sid, code: state.code }).then((doc) => {
        if (!ackOk(doc)) toast(ackError(doc));
      });
    };

    const colorInd = $('#color-indicator');
    colorInd.className = `color-indicator ${(g && g.color) || ''}`;

    const banner = $('#turn-banner');
    if (!g || !g.active) {
      banner.textContent = '';
    } else if (isSpectator) {
      banner.textContent = `👀 Spectating — ${playerName(g.currentPlayerId)}'s turn`;
    } else if (isMyTurn()) {
      banner.textContent = g.pendingDraw.amount > 0
        ? `Your turn — stack another +2 or draw ${g.pendingDraw.amount}!`
        : 'Your turn!';
    } else {
      banner.textContent = `Waiting for ${playerName(g.currentPlayerId)}…`;
    }

    $('#my-name-label').textContent = isSpectator ? 'Spectating' : `${state.name} (You)`;
    const handEl = $('#my-hand');
    handEl.innerHTML = '';
    if (g && !isSpectator) {
      g.myHand.forEach((card) => {
        const el = buildCardEl(card);
        const playable = isPlayableClient(card);
        el.classList.add(playable ? 'playable' : 'unplayable');
        if (g.drawnCardPendingId === card.id) el.classList.add('pending-draw');
        el.addEventListener('click', () => onHandCardClick(card, playable));
        handEl.appendChild(el);
      });
    }

    const passBtn = $('#btn-pass');
    const showPass = isMyTurn() && g && g.drawnCardPendingId && g.myHand.some((c) => c.id === g.drawnCardPendingId);
    passBtn.classList.toggle('hidden', !showPass);

    if (g && g.active) startTimerRing(g.turnEndsAt, g.turnSeconds, g.currentPlayerId);
    else $('#turn-timer').classList.add('hidden');

    renderScoreboard();
  }

  function onHandCardClick(card, playable) {
    if (!playable) return;
    Sound.unlock();
    if (card.type === 'wild' || card.type === 'wild4') {
      state.pendingWildCardId = card.id;
      $('#color-picker').classList.remove('hidden');
    } else {
      apiPost(API.play, { session_id: state.sid, code: state.code, card_id: card.id }).then((doc) => {
        if (!ackOk(doc)) toast(ackError(doc));
      });
    }
  }

  $all('.color-choice').forEach((btn) => {
    btn.addEventListener('click', async () => {
      $('#color-picker').classList.add('hidden');
      if (!state.pendingWildCardId) return;
      const doc = await apiPost(API.play, { session_id: state.sid, code: state.code, card_id: state.pendingWildCardId, color: btn.dataset.color });
      if (!ackOk(doc)) toast(ackError(doc));
      state.pendingWildCardId = null;
    });
  });

  $('#btn-pass').addEventListener('click', async () => {
    const doc = await apiPost(API.pass, { session_id: state.sid, code: state.code });
    if (!ackOk(doc)) toast(ackError(doc));
  });

  function renderScoreboard() {
    const scoreboard = state.players
      .filter((p) => p.role === 'player')
      .sort((a, b) => a.score - b.score);
    const list = $('#score-list');
    list.innerHTML = '';
    scoreboard.forEach((row, i) => {
      const li = document.createElement('li');
      if (i === 0) li.classList.add('rank-1');
      li.innerHTML = `<span><span class="rank-num">#${i + 1}</span>${escapeHtml(row.name)}</span><span>${row.score} pts</span>`;
      list.appendChild(li);
    });
  }

  // ---------- side panels ----------
  $('#btn-toggle-chat').addEventListener('click', () => {
    $('#chat-panel').classList.toggle('hidden');
    $('#score-panel').classList.add('hidden');
  });
  $('#btn-toggle-score').addEventListener('click', () => {
    $('#score-panel').classList.toggle('hidden');
    $('#chat-panel').classList.add('hidden');
  });
  $all('.btn-close').forEach((btn) => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.close === 'chat' ? '#chat-panel' : '#score-panel';
      $(target).classList.add('hidden');
    });
  });

  // ---------- timer ring ----------
  function startTimerRing(endsAt, seconds, currentPlayerId) {
    clearInterval(state.timerRingHandle);
    const ring = $('#turn-timer');
    ring.classList.remove('hidden');
    const fg = $('#timer-fg');
    const numEl = $('#timer-num');

    if (currentPlayerId === state.sid && state.myTurnAnnouncedAt !== endsAt) {
      state.myTurnAnnouncedAt = endsAt;
      Sound.turnAlert();
    }

    function tick() {
      const remainMs = endsAt - Date.now();
      const remain = Math.max(0, Math.ceil(remainMs / 1000));
      numEl.textContent = remain;
      const pct = Math.max(0, remainMs / (seconds * 1000)) * 100;
      fg.style.strokeDasharray = `${pct} 100`;
      fg.classList.toggle('warn', remain <= 5);
      if (remainMs <= 0) clearInterval(state.timerRingHandle);
    }
    tick();
    state.timerRingHandle = setInterval(tick, 250);
  }
})();
