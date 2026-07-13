-- UNO Online (PHP + MySQL edition) — database schema
-- Import with: mysql -u root < sql/schema.sql

CREATE DATABASE IF NOT EXISTS uno_game CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uno_game;

DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS players;
DROP TABLE IF EXISTS rooms;

CREATE TABLE rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(6) NOT NULL UNIQUE,
  host_session_id VARCHAR(64) NOT NULL,
  status ENUM('lobby','playing','finished') NOT NULL DEFAULT 'lobby',
  target_score INT NOT NULL DEFAULT 500,
  round_number INT NOT NULL DEFAULT 0,
  game_state LONGTEXT NULL COMMENT 'JSON blob: deck, hands, discard pile, turn state',
  overall_winner_session_id VARCHAR(64) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB;

CREATE TABLE players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  session_id VARCHAR(64) NOT NULL,
  name VARCHAR(20) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#e63946',
  role ENUM('player','spectator') NOT NULL DEFAULT 'player',
  score INT NOT NULL DEFAULT 0,
  seat_order INT NOT NULL DEFAULT 0,
  joined_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_seen DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uniq_room_session (room_id, session_id),
  CONSTRAINT fk_players_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A single append-only log of everything clients need to react to: chat, system
-- messages, card played/drawn, uno calls, round-end/game-over summaries, and
-- private "you were kicked" notices. Polling clients fetch events with
-- id > last_seen_id so nothing is missed and nothing repeats.
CREATE TABLE events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  type VARCHAR(32) NOT NULL,
  target_session_id VARCHAR(64) NULL COMMENT 'NULL = broadcast to whole room; otherwise private to one session',
  payload TEXT NOT NULL COMMENT 'JSON payload',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_events_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  INDEX idx_room_event (room_id, id)
) ENGINE=InnoDB;
