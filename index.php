<?php
require_once __DIR__ . '/includes/rules.php';
$r = rules();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>UNO Online</title>
  <meta name="description" content="Real-time multiplayer UNO for 2-8 players, right in your browser. PHP + MySQL powered." />
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg" />
  <link rel="stylesheet" href="assets/css/style.css" />
  <script>
    // Rule constants injected from config/rules.xml so the client never has
    // to guess values the PHP backend is authoritative over.
    window.UNO_RULES = {
      minPlayers: <?= (int)$r['minPlayers'] ?>,
      maxPlayers: <?= (int)$r['maxPlayers'] ?>,
      turnSeconds: <?= (int)$r['turnSeconds'] ?>,
      defaultTargetScore: <?= (int)$r['defaultTargetScore'] ?>
    };
  </script>
</head>
<body>

  <!-- HOME SCREEN -->
  <section id="screen-home" class="screen active">
    <div class="home-card">
      <img class="logo" src="assets/img/logo.svg" alt="UNO Online logo" />
      <p class="tagline">Play UNO online with 2-8 friends. No app, no sign-up.</p>
      <button type="button" id="btn-rules-home" class="btn-ghost rules-link">📜 How to Play</button>

      <div class="home-tabs">
        <button class="tab-btn active" data-tab="create">Create Room</button>
        <button class="tab-btn" data-tab="join">Join Room</button>
      </div>

      <div class="tab-panel" id="tab-create">
        <label>Your Name
          <input id="create-name" maxlength="20" placeholder="e.g. Alex" />
        </label>
        <label>Target Score (game ends when someone reaches it)
          <input id="create-target" type="number" min="50" step="50" value="<?= (int)$r['defaultTargetScore'] ?>" />
        </label>
        <button id="btn-create" class="btn-primary">Create Room</button>
      </div>

      <div class="tab-panel hidden" id="tab-join">
        <label>Your Name
          <input id="join-name" maxlength="20" placeholder="e.g. Sam" />
        </label>
        <label>Room Code
          <input id="join-code" maxlength="5" placeholder="ABCDE" style="text-transform:uppercase" />
        </label>
        <button id="btn-join" class="btn-primary">Join Room</button>
      </div>

      <p id="home-error" class="error-text hidden"></p>
    </div>
  </section>

  <!-- LOBBY SCREEN -->
  <section id="screen-lobby" class="screen">
    <header class="topbar">
      <img class="logo-small" src="assets/img/logo.svg" alt="UNO Online" />
      <div class="room-code-badge">Room Code: <span id="lobby-code">-----</span></div>
      <div class="topbar-actions">
        <button id="btn-rules-lobby" class="btn-ghost">📜 Rules</button>
        <button id="btn-leave-lobby" class="btn-ghost">Leave</button>
      </div>
    </header>

    <div class="lobby-body">
      <div class="lobby-players">
        <h2>Players (<span id="lobby-count">0</span>/8)</h2>
        <ul id="lobby-player-list"></ul>
      </div>

      <div class="lobby-side">
        <div id="host-controls" class="host-controls hidden">
          <label>Target Score
            <input id="lobby-target" type="number" min="50" step="50" disabled />
          </label>
          <div class="bot-row">
            <select id="bot-difficulty" aria-label="Bot difficulty">
              <option value="easy">Easy</option>
              <option value="medium" selected>Medium</option>
              <option value="hard">Hard</option>
            </select>
            <button id="btn-add-bot" class="btn-ghost" type="button">🤖 Add Bot</button>
          </div>
          <button id="btn-start-game" class="btn-primary">Start Game</button>
          <p class="hint">Need 2-8 players to start.</p>
        </div>
        <div id="guest-wait" class="hint">Waiting for the host to start the game…</div>
      </div>
    </div>
  </section>

  <!-- GAME SCREEN -->
  <section id="screen-game" class="screen">
    <header class="topbar game-topbar">
      <img class="logo-small" src="assets/img/logo.svg" alt="UNO Online" />
      <div class="room-code-badge">Room: <span id="game-code">-----</span></div>
      <div id="spectator-badge" class="spectator-badge hidden">👀 Spectating — you'll join next round</div>
      <div class="topbar-actions">
        <button id="btn-rules-game" class="btn-ghost">📜 Rules</button>
        <button id="btn-toggle-chat" class="btn-ghost">💬 Chat</button>
        <button id="btn-toggle-score" class="btn-ghost">🏆 Scores</button>
        <button id="btn-new-round" class="btn-ghost hidden" title="Cancel the current round and deal a fresh one">🔄 New Round</button>
        <button id="btn-leave-game" class="btn-ghost">Leave</button>
      </div>
    </header>

    <div class="game-layout">
      <div class="opponents-strip" id="opponents-strip"></div>

      <div class="table-center">
        <div class="pile-area">
          <div id="draw-pile" class="card card-back" title="Draw a card"></div>
          <div id="discard-pile" class="discard-slot"></div>
        </div>
        <div id="color-indicator" class="color-indicator"></div>
        <div id="turn-banner" class="turn-banner"></div>
        <div id="turn-timer" class="turn-timer hidden">
          <svg viewBox="0 0 36 36" class="timer-ring">
            <path class="timer-bg" d="M18 2a16 16 0 1 1 0 32 16 16 0 1 1 0-32" />
            <path id="timer-fg" class="timer-fg" d="M18 2a16 16 0 1 1 0 32 16 16 0 1 1 0-32" />
          </svg>
          <span id="timer-num">30</span>
        </div>
      </div>

      <div class="my-area">
        <div class="my-area-header">
          <span id="my-name-label" class="my-name-label"></span>
          <button id="btn-pass" class="btn-secondary hidden">Pass / Keep Card</button>
        </div>
        <div id="my-hand" class="my-hand"></div>
      </div>
    </div>

    <!-- Color picker modal -->
    <div id="color-picker" class="modal hidden">
      <div class="modal-box">
        <h3>Choose a color</h3>
        <div class="color-choices">
          <button data-color="red" class="color-choice red"></button>
          <button data-color="yellow" class="color-choice yellow"></button>
          <button data-color="green" class="color-choice green"></button>
          <button data-color="blue" class="color-choice blue"></button>
        </div>
      </div>
    </div>

    <!-- Chat panel -->
    <aside id="chat-panel" class="side-panel hidden">
      <div class="side-panel-header">Chat <button class="btn-close" data-close="chat">✕</button></div>
      <div id="chat-log" class="chat-log"></div>
      <form id="chat-form" class="chat-form">
        <input id="chat-input" maxlength="300" autocomplete="off" placeholder="Say something…" />
        <button type="submit">Send</button>
      </form>
    </aside>

    <!-- Scoreboard panel -->
    <aside id="score-panel" class="side-panel hidden">
      <div class="side-panel-header">Scoreboard <button class="btn-close" data-close="score">✕</button></div>
      <p class="hint" style="padding:0 14px">Lowest total score wins. Game ends at <span id="score-target">500</span> pts.</p>
      <ol id="score-list" class="score-list"></ol>
    </aside>

    <!-- Round end overlay -->
    <div id="round-end-overlay" class="modal hidden">
      <div class="modal-box round-summary">
        <h3 id="round-end-title">Round Over</h3>
        <div id="round-end-body"></div>
        <p class="hint" id="round-end-next">Next round starting soon…</p>
      </div>
    </div>

    <!-- Game over overlay -->
    <div id="game-over-overlay" class="modal hidden">
      <div class="modal-box round-summary">
        <h3>🏆 Game Over</h3>
        <p id="game-over-winner" class="winner-line"></p>
        <ol id="game-over-scores" class="score-list"></ol>
        <button id="btn-play-again" class="btn-primary hidden">Play Again</button>
        <button id="btn-back-home" class="btn-secondary">Back to Home</button>
      </div>
    </div>

    <!-- Kicked overlay -->
    <div id="kicked-overlay" class="modal hidden">
      <div class="modal-box round-summary">
        <h3 id="kicked-text"></h3>
        <button id="btn-kicked-ok" class="btn-primary">OK</button>
      </div>
    </div>

    <div id="toast-container" class="toast-container"></div>
  </section>

  <!-- Rules modal: reachable from every screen (home, lobby, game) -->
  <div id="rules-overlay" class="modal hidden">
    <div class="modal-box rules-box">
      <div class="rules-header">
        <h3>📜 How to Play</h3>
        <button class="btn-close" id="btn-close-rules">✕</button>
      </div>
      <div class="rules-body">
        <h4>Setup</h4>
        <p><?= (int)$r['minPlayers'] ?>–<?= (int)$r['maxPlayers'] ?> players. Each player is dealt <?= (int)$r['startingHandSize'] ?> cards from a standard 108-card deck (4 colors × 0–9, Skip, Reverse, Draw Two, plus Wild and Wild Draw Four).</p>

        <h4>Taking a turn</h4>
        <p>Play a card that matches the top card's color, number, or type — or play a Wild card at any time. If you can't (or don't want to) play, draw a card. You have <?= (int)$r['turnSeconds'] ?> seconds per turn; run out the clock and the table auto-draws/passes for you.</p>

        <h4>Special cards</h4>
        <ul>
          <li><strong>Skip</strong> — next player loses their turn.</li>
          <li><strong>Reverse</strong> — turn order flips direction.</li>
          <li><strong>Draw Two (+2)</strong> — next player draws 2 and is skipped. <em>Stackable</em> — they can play their own +2 to pass the (growing) penalty along instead.</li>
          <li><strong>Wild</strong> — choose the new color.</li>
          <li><strong>Wild Draw Four (+4)</strong> — choose the new color and the next player draws 4 and is skipped. <em>Not stackable</em>, and only playable when you hold no card matching the current color.</li>
        </ul>

        <h4>UNO!</h4>
        <p>No need to call it yourself — the table automatically announces UNO the instant a player is down to one card.</p>

        <h4>Scoring</h4>
        <ul>
          <li>Number cards → face value</li>
          <li>Skip, Reverse, Draw Two → <?= (int)$r['scoreActionCard'] ?> points</li>
          <li>Wild, Wild Draw Four → <?= (int)$r['scoreWildCard'] ?> points</li>
        </ul>
        <p>When a round ends, everyone <em>except</em> the winner adds the point value of the cards still in their hand to their own running score. The round winner adds nothing.</p>

        <h4>Winning</h4>
        <p>The game ends once any player's score reaches the target (<span id="rules-target-score"><?= (int)$r['defaultTargetScore'] ?></span> by default, set by the host). The player with the <strong>lowest</strong> total score wins — the scoreboard is always sorted lowest-first.</p>

        <h4>Other rules</h4>
        <ul>
          <li>Turn order is reshuffled after every round.</li>
          <li>Joining mid-game puts you in spectator mode until the round ends, then you're dealt into the next one. Spectators don't appear on the scoreboard.</li>
          <li>The host can kick anyone from the lobby or mid-game, and can cancel the current round early with <strong>New Round</strong>.</li>
          <li>Leaving mid-round simply skips you for the rest of that round.</li>
        </ul>
      </div>
    </div>
  </div>

  <script src="assets/js/sound.js"></script>
  <script src="assets/js/main.js"></script>
</body>
</html>
