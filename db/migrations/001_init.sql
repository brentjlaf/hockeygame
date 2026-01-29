-- Initial schema for hockey game tables
-- Designed for MySQL 8+

CREATE TABLE IF NOT EXISTS teams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  owner_user_id INT NULL,
  rating INT NOT NULL DEFAULT 1000,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_team_name (name),
  INDEX idx_teams_owner (owner_user_id),
  INDEX idx_teams_rating (rating)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  current_team_id INT NULL,
  name VARCHAR(80) NOT NULL,
  position ENUM('C','LW','RW','D','G') NOT NULL,
  level INT NOT NULL DEFAULT 1,
  xp INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_players_team (current_team_id),
  INDEX idx_players_position (position),
  CONSTRAINT fk_players_team
    FOREIGN KEY (current_team_id) REFERENCES teams(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS team_rosters (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  team_id INT NOT NULL,
  player_id INT NOT NULL,
  roster_role ENUM('ACTIVE','RESERVE','IR','SCRATCH') NOT NULL DEFAULT 'ACTIVE',
  start_date DATE NOT NULL DEFAULT (CURRENT_DATE),
  end_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_active_player (player_id, is_active),
  INDEX idx_roster_team_active (team_id, is_active),
  INDEX idx_roster_player (player_id),
  CONSTRAINT fk_roster_team
    FOREIGN KEY (team_id) REFERENCES teams(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_roster_player
    FOREIGN KEY (player_id) REFERENCES players(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seasons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  season_year INT NOT NULL,
  starts_at DATE NOT NULL,
  ends_at DATE NOT NULL,
  status ENUM('PLANNED','ACTIVE','COMPLETED') NOT NULL DEFAULT 'PLANNED',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_season_year (season_year),
  INDEX idx_seasons_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS games (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  season_id INT NOT NULL,
  home_team_id INT NOT NULL,
  away_team_id INT NOT NULL,
  scheduled_at DATETIME NOT NULL,
  status ENUM('SCHEDULED','IN_PROGRESS','FINAL','CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
  home_score TINYINT NULL,
  away_score TINYINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_games_season (season_id),
  INDEX idx_games_home (home_team_id),
  INDEX idx_games_away (away_team_id),
  INDEX idx_games_status (status),
  CONSTRAINT fk_games_season
    FOREIGN KEY (season_id) REFERENCES seasons(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_games_home_team
    FOREIGN KEY (home_team_id) REFERENCES teams(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_games_away_team
    FOREIGN KEY (away_team_id) REFERENCES teams(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS game_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT NOT NULL,
  period TINYINT NOT NULL,
  clock_seconds_remaining SMALLINT NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  team_id INT NULL,
  player_id INT NULL,
  details JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_game (game_id),
  INDEX idx_events_game_time (game_id, period, clock_seconds_remaining),
  INDEX idx_events_type (event_type),
  CONSTRAINT fk_events_game
    FOREIGN KEY (game_id) REFERENCES games(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_events_team
    FOREIGN KEY (team_id) REFERENCES teams(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_events_player
    FOREIGN KEY (player_id) REFERENCES players(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS player_stats (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  player_id INT NOT NULL,
  season_id INT NOT NULL,
  team_id INT NOT NULL,
  games_played SMALLINT NOT NULL DEFAULT 0,
  goals SMALLINT NOT NULL DEFAULT 0,
  assists SMALLINT NOT NULL DEFAULT 0,
  points SMALLINT NOT NULL DEFAULT 0,
  penalty_minutes SMALLINT NOT NULL DEFAULT 0,
  shots SMALLINT NOT NULL DEFAULT 0,
  saves SMALLINT NOT NULL DEFAULT 0,
  wins SMALLINT NOT NULL DEFAULT 0,
  losses SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_player_season (player_id, season_id),
  INDEX idx_player_stats_season (season_id),
  INDEX idx_player_stats_team (team_id),
  CONSTRAINT fk_player_stats_player
    FOREIGN KEY (player_id) REFERENCES players(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_player_stats_season
    FOREIGN KEY (season_id) REFERENCES seasons(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_player_stats_team
    FOREIGN KEY (team_id) REFERENCES teams(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS standings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  season_id INT NOT NULL,
  team_id INT NOT NULL,
  games_played SMALLINT NOT NULL DEFAULT 0,
  wins SMALLINT NOT NULL DEFAULT 0,
  losses SMALLINT NOT NULL DEFAULT 0,
  ot_losses SMALLINT NOT NULL DEFAULT 0,
  points SMALLINT NOT NULL DEFAULT 0,
  goals_for SMALLINT NOT NULL DEFAULT 0,
  goals_against SMALLINT NOT NULL DEFAULT 0,
  streak VARCHAR(10) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_standings_season_team (season_id, team_id),
  INDEX idx_standings_points (season_id, points),
  CONSTRAINT fk_standings_season
    FOREIGN KEY (season_id) REFERENCES seasons(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_standings_team
    FOREIGN KEY (team_id) REFERENCES teams(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leaderboards (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  season_id INT NOT NULL,
  stat_type VARCHAR(40) NOT NULL,
  entity_type ENUM('PLAYER','TEAM') NOT NULL,
  entity_id BIGINT NOT NULL,
  rank_position INT NOT NULL,
  stat_value DECIMAL(12,3) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_leaderboard_entry (season_id, stat_type, entity_type, entity_id),
  INDEX idx_leaderboards_rank (season_id, stat_type, rank_position),
  CONSTRAINT fk_leaderboards_season
    FOREIGN KEY (season_id) REFERENCES seasons(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;
