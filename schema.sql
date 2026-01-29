-- Phase 1 schema for Rink Manager Micro-Sim (Multiplayer + AI fallback + Play-by-Play)
-- Run this once in MySQL.

-- USERS (minimal; replace with your real auth later)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(60) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- TEAMS
CREATE TABLE IF NOT EXISTS teams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  name VARCHAR(60) NOT NULL,
  rating INT NOT NULL DEFAULT 1000,
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  bot_difficulty TINYINT NULL,
  coach_style VARCHAR(20) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id),
  INDEX(is_bot),
  INDEX(rating)
);

-- PLAYERS
CREATE TABLE IF NOT EXISTS players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  team_id INT NOT NULL,
  name VARCHAR(60) NOT NULL,
  pos ENUM('C','LW','RW','D','G') NOT NULL,
  level INT NOT NULL DEFAULT 1,
  xp INT NOT NULL DEFAULT 0,

  shot TINYINT NOT NULL DEFAULT 50,
  pass_attr TINYINT NOT NULL DEFAULT 50,
  speed TINYINT NOT NULL DEFAULT 50,
  defense_attr TINYINT NOT NULL DEFAULT 50,
  grit TINYINT NOT NULL DEFAULT 50,
  goalie TINYINT NOT NULL DEFAULT 50, -- only meaningful for G

  stamina_max TINYINT NOT NULL DEFAULT 100,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(team_id),
  INDEX(pos)
);

-- MATCHES
CREATE TABLE IF NOT EXISTS matches (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  season_id INT NOT NULL DEFAULT 1,
  home_team_id INT NOT NULL,
  away_team_id INT NULL,
  status ENUM('WAITING_OPPONENT','WAITING_SUBMISSIONS','SIMULATING','DONE','CANCELLED') NOT NULL DEFAULT 'WAITING_OPPONENT',
  submit_deadline DATETIME NULL,
  seed BIGINT NOT NULL,
  home_score TINYINT NULL,
  away_score TINYINT NULL,
  simulated_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(status),
  INDEX(home_team_id),
  INDEX(away_team_id)
);

-- SEASONS
CREATE TABLE IF NOT EXISTS seasons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  starts_at DATE NULL,
  ends_at DATE NULL,
  length_days INT NOT NULL DEFAULT 30,
  rating_soft_reset_pct DECIMAL(5,2) NOT NULL DEFAULT 0.75,
  playoff_team_count INT NOT NULL DEFAULT 8,
  status ENUM('PLANNED','ACTIVE','COMPLETED') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- TEAM SEASON STATS (standings)
CREATE TABLE IF NOT EXISTS team_season_stats (
  season_id INT NOT NULL,
  team_id INT NOT NULL,
  games_played INT NOT NULL DEFAULT 0,
  wins INT NOT NULL DEFAULT 0,
  losses INT NOT NULL DEFAULT 0,
  ties INT NOT NULL DEFAULT 0,
  goals_for INT NOT NULL DEFAULT 0,
  goals_against INT NOT NULL DEFAULT 0,
  points INT NOT NULL DEFAULT 0,
  PRIMARY KEY (season_id, team_id),
  INDEX(team_id),
  INDEX(season_id)
);

-- PLAYER SEASON STATS (leaders)
CREATE TABLE IF NOT EXISTS player_season_stats (
  season_id INT NOT NULL,
  player_id INT NOT NULL,
  team_id INT NOT NULL,
  games_played INT NOT NULL DEFAULT 0,
  goals INT NOT NULL DEFAULT 0,
  assists INT NOT NULL DEFAULT 0,
  points INT NOT NULL DEFAULT 0,
  shots INT NOT NULL DEFAULT 0,
  saves INT NOT NULL DEFAULT 0,
  shots_against INT NOT NULL DEFAULT 0,
  wins INT NOT NULL DEFAULT 0,
  PRIMARY KEY (season_id, player_id),
  INDEX(team_id),
  INDEX(season_id),
  INDEX(player_id)
);

-- PLAYER MATCH STATS (box score)
CREATE TABLE IF NOT EXISTS player_match_stats (
  match_id BIGINT NOT NULL,
  season_id INT NOT NULL,
  player_id INT NOT NULL,
  team_id INT NOT NULL,
  games_played INT NOT NULL DEFAULT 1,
  goals INT NOT NULL DEFAULT 0,
  assists INT NOT NULL DEFAULT 0,
  points INT NOT NULL DEFAULT 0,
  shots INT NOT NULL DEFAULT 0,
  saves INT NOT NULL DEFAULT 0,
  shots_against INT NOT NULL DEFAULT 0,
  wins INT NOT NULL DEFAULT 0,
  PRIMARY KEY (match_id, player_id),
  INDEX(team_id),
  INDEX(season_id),
  INDEX(player_id)
);

-- GAMES
CREATE TABLE IF NOT EXISTS games (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  season_id INT NOT NULL DEFAULT 1,
  home_team_id INT NOT NULL,
  away_team_id INT NOT NULL,
  status ENUM('SIMULATING','DONE','CANCELLED') NOT NULL DEFAULT 'SIMULATING',
  seed BIGINT NOT NULL,
  home_score TINYINT NULL,
  away_score TINYINT NULL,
  simulated_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(status),
  INDEX(home_team_id),
  INDEX(away_team_id)
);

-- PLANS
CREATE TABLE IF NOT EXISTS match_submissions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  match_id BIGINT NOT NULL,
  team_id INT NOT NULL,
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  plan_json JSON NOT NULL,
  UNIQUE KEY uniq_match_team (match_id, team_id),
  INDEX(match_id),
  INDEX(team_id)
);

-- PLAY-BY-PLAY EVENTS
CREATE TABLE IF NOT EXISTS match_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  match_id BIGINT NOT NULL,
  period TINYINT NOT NULL,          -- 1..3
  tick TINYINT NOT NULL,            -- 0..39 (40 ticks/period)
  game_time_left SMALLINT NOT NULL, -- 0..1200 (20:00)
  event_type VARCHAR(30) NOT NULL,
  payload JSON NOT NULL,            -- includes "text" plus details
  INDEX(match_id),
  INDEX(match_id, period, tick),
  INDEX(event_type)
);

-- GAME EVENTS
CREATE TABLE IF NOT EXISTS game_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT NOT NULL,
  period TINYINT NOT NULL,          -- 1..3
  tick TINYINT NOT NULL,            -- 0..39 (40 ticks/period)
  game_time_left SMALLINT NOT NULL, -- 0..1200 (20:00)
  event_type VARCHAR(30) NOT NULL,
  payload JSON NOT NULL,            -- includes "text" plus details
  INDEX(game_id),
  INDEX(game_id, period, tick),
  INDEX(event_type)
);
