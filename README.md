# UNO Online 🎴

A real-time(ish) multiplayer **UNO** card game for **2–8 players**, playable entirely in the browser. Built on a classic **XAMPP stack** — **PHP** + **MySQL** on the backend, **HTML/CSS/JavaScript** on the frontend, with **XML** as the wire format for every AJAX call and for the game's rules configuration.

![players](https://img.shields.io/badge/players-2--8-e63946) ![stack](https://img.shields.io/badge/stack-PHP%20%7C%20MySQL%20%7C%20XML-2a9d5c) ![license](https://img.shields.io/badge/license-MIT-3a6fd8)

## Description

UNO Online is a self-hosted, no-signup UNO table: one player creates a room and shares a 5-letter code, everyone else joins from their own browser, and the whole match — dealing, turns, stacking penalties, scoring, chat, kicks — is refereed authoritatively by PHP and persisted in MySQL. There's no Node.js, no WebSocket server, and no build step: drop the folder into `htdocs`, import one SQL file, and open it in a browser. The frontend polls a small set of PHP endpoints roughly every second and renders whatever XML comes back, including a live 30-second turn countdown.

## Features

- 🎮 **2 to 8 players** per room, with quick room-code create/join
- 👑 **Host controls** — the host can kick any player from the lobby *or* mid-game (the kicked player sees `(Host Name) kicked you.`, everyone else sees `(Host Name) kicked (Player Name)` in chat), and can cancel the round in progress with **New Round** — no points are scored for the cancelled round, and a fresh one is dealt immediately with turn order reshuffled
- 📜 **In-app rules reference** — a "How to Play" / "Rules" button on the home screen, lobby, and game table opens a modal explaining setup, turns, special cards, scoring, and winning, generated from the same `config/rules.xml` the engine itself reads
- 🚪 **Leave anytime** — a Leave button is available in the lobby and in the game view (including while spectating); a player who leaves mid-round is simply skipped for the rest of that round
- 👀 **Late joiners spectate** — joining mid-game puts you in spectator mode until the current round ends, then you're shuffled into the next round; spectators never appear on the scoreboard
- 🔀 **Turn order reshuffles** after every round
- ⏱️ **30-second turn timer**, enforced server-side — if a player goes idle, any other connected client's next poll resolves the timeout for them (auto-draw/pass)
- 💬 **In-table chat** with system messages for joins/leaves/kicks
- 🔊 **Sound effects** for drawing, playing, and calling UNO (synthesized in-browser via the Web Audio API — no audio files needed)
- 📣 **Automatic UNO calls** — no penalty risk, the table announces it the instant a player is down to one card
- 🏆 **Live scoreboard**, sorted lowest score first — lowest cumulative score wins when the target score is reached
- 🗄️ Presence detection without WebSockets — idle browser tabs (closed/crashed) are lazily reaped from a room

## Rules implemented

Card rules follow the official UNO rules; scoring follows this project's spec. Every constant below lives in [`config/rules.xml`](config/rules.xml) and is loaded once by [`includes/rules.php`](includes/rules.php) — edit that file to retune the game without touching PHP code.

- **Deck**: standard 108-card deck (4 colors × 0–9 numbers, Skip, Reverse, Draw Two, plus 4 Wild and 4 Wild Draw Four).
- **Stacking**: **Draw Two (+2) cards can be stacked**, passing an increasing draw penalty down the line. **Wild Draw Four (+4) cannot be stacked.**
- **Wild Draw Four restriction**: a player may only play a +4 when they hold **no card matching the currently active color** — enforced server-side, not the honor system.
- **Scoring** (added to the *player's own* running total whenever a round ends and they're still holding cards):
  - Number cards → face value (0–9)
  - Skip, Reverse, Draw Two → **10 points**
  - Wild, Wild Draw Four → **50 points**
  - The round winner adds **0** points that round.
- **Winning**: once any player's cumulative score reaches the room's target score (default **500**), the game ends and the player with the **lowest** total score is declared the winner. The scoreboard is always sorted ascending (lowest on top).
- **Turns**: 30 seconds per turn. If time runs out, the server automatically draws a card (or resolves a pending stacked draw) on the player's behalf and passes the turn.
- **Reshuffle**: after every round, turn order is randomly reshuffled before the next round begins.

## Tech stack & architecture

| Layer | Technology |
|---|---|
| Server logic | PHP 8 (procedural game engine + PDO) |
| Database | MySQL / MariaDB (InnoDB) |
| Client ↔ server transport | AJAX (`fetch`) polling, **XML** requests/responses |
| Config | XML (`config/rules.xml`), parsed with SimpleXML |
| Frontend | HTML5, CSS3, vanilla JavaScript (`DOMParser` for XML) |
| Sound | Web Audio API (synthesized, no binary assets) |

There is no long-running server process. Instead:

- Each room's mutable game state (deck, hands, discard pile, turn info) is stored as a JSON blob in `rooms.game_state` and mutated inside a `SELECT ... FOR UPDATE` transaction on every action, so concurrent requests for the same room never race.
- The client polls `api/game_poll.php` about every 1.2 seconds. Every poll (from *any* connected client) also lazily resolves an expired 30-second turn timer and, once a round-end pause has elapsed, advances the room to the next round — so the game keeps moving even if the player whose turn it is has gone idle.
- A single append-only `events` table (chat, system messages, card played/drawn, UNO calls, round-end/game-over summaries, private "you were kicked" notices) is the source of truth for everything the client needs to react to. Clients pass `since_event` on each poll and only receive what's new.

## Getting started (XAMPP)

### Prerequisites

- [XAMPP](https://www.apachefriends.org/) (or any Apache/Nginx + PHP 8 + MySQL setup) with the `pdo_mysql`, `SimpleXML`, and `dom` PHP extensions enabled (all on by default in XAMPP).

### Setup

1. Place this folder at `htdocs/uno` (i.e. `c:\xampp\htdocs\uno`).
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Import the schema:
   ```bash
   mysql -u root < sql/schema.sql
   ```
   This creates the `uno_game` database and its tables.
4. If your MySQL isn't the XAMPP default (`root` with no password on `127.0.0.1`), set connection details via environment variables (or edit `config/db.php` directly):
   - `UNO_DB_HOST`, `UNO_DB_NAME`, `UNO_DB_USER`, `UNO_DB_PASS`
5. Open **http://localhost/uno/** in your browser. Share the room code with friends (each opens the same URL and clicks *Join Room*).

## Project structure

```
uno/
├── config/
│   ├── db.php          # PDO connection
│   └── rules.xml        # Game rules/constants (deck, scoring, timer, target score)
├── sql/
│   └── schema.sql       # rooms / players / events tables
├── includes/
│   ├── rules.php         # Parses rules.xml into constants
│   ├── deck.php          # Deck construction, shuffling, card point values
│   ├── game_engine.php   # Authoritative round/turn/scoring state machine
│   ├── db_helpers.php    # Room locking, player/event persistence, timeout & round-advance resolution
│   ├── xml_helper.php    # DOMDocument response builders
│   ├── events_xml.php    # Renders the events table as XML
│   └── util.php          # Input helpers/validation
├── api/                  # AJAX endpoints (XML in, XML out)
│   ├── create_room.php, join_room.php, leave.php, kick.php
│   ├── start_game.php, play_again.php, new_round.php
│   ├── play_card.php, draw_card.php, pass_turn.php
│   ├── send_chat.php
│   └── lobby_poll.php, game_poll.php
├── assets/
│   ├── css/style.css
│   ├── js/main.js        # Polling, XML parsing, rendering, all client logic
│   ├── js/sound.js        # Web Audio synthesized sound effects
│   └── img/logo.svg, favicon.svg
├── index.php              # Single-page shell (home / lobby / game screens)
└── README.md
```

## Security notes

- All SQL access goes through PDO prepared statements.
- Every room mutation happens inside a locked transaction (`SELECT ... FOR UPDATE`) to prevent race conditions between simultaneous requests.
- XML output is built with `DOMDocument`, which escapes text/attribute content automatically.
- Player identity is a client-generated random id (stored in `sessionStorage`, not a cookie) — there are no accounts or passwords to protect.

## License

MIT
