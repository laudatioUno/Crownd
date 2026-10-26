-- Crownd: initial schema. Times in *_at columns are UTC; kickoff_at is the local
-- match time as entered (a calendar value, never converted).

CREATE TABLE crownd_settings (
    `key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crownd_admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(40) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    first_failed_at DATETIME NULL,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crownd_rate_limits (
    bucket VARCHAR(32) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, key_hash, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A team (own club or opponent) with its player pool.
CREATE TABLE crownd_teams (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    short_name VARCHAR(20) NULL,
    color CHAR(7) NULL,
    logo_file VARCHAR(40) NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crownd_players (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    number SMALLINT UNSIGNED NULL,
    position VARCHAR(40) NULL,
    photo_file VARCHAR(40) NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY ix_players_team (team_id, archived),
    CONSTRAINT fk_players_team FOREIGN KEY (team_id) REFERENCES crownd_teams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Squad templates ("Vorlagen"), e.g. "NLA" or "U17": a named selection of a team's players.
CREATE TABLE crownd_squads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY ix_squads_team (team_id),
    CONSTRAINT fk_squads_team FOREIGN KEY (team_id) REFERENCES crownd_teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crownd_squad_players (
    squad_id INT UNSIGNED NOT NULL,
    player_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (squad_id, player_id),
    CONSTRAINT fk_squad_players_squad FOREIGN KEY (squad_id) REFERENCES crownd_squads (id) ON DELETE CASCADE,
    CONSTRAINT fk_squad_players_player FOREIGN KEY (player_id) REFERENCES crownd_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One poll per match. status: draft (prepared), open (voting runs), closed.
CREATE TABLE crownd_matches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    home_team_id INT UNSIGNED NOT NULL,
    away_team_id INT UNSIGNED NOT NULL,
    competition VARCHAR(100) NULL,
    venue VARCHAR(100) NULL,
    kickoff_at DATETIME NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    auto_close_minutes SMALLINT UNSIGNED NULL,
    results_public TINYINT(1) NOT NULL DEFAULT 0,
    opened_at DATETIME NULL,
    closes_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY ix_matches_status (status),
    KEY ix_matches_kickoff (kickoff_at),
    CONSTRAINT fk_matches_home FOREIGN KEY (home_team_id) REFERENCES crownd_teams (id),
    CONSTRAINT fk_matches_away FOREIGN KEY (away_team_id) REFERENCES crownd_teams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Players who actually play in a match (the line-up), per team.
CREATE TABLE crownd_match_players (
    match_id INT UNSIGNED NOT NULL,
    team_id INT UNSIGNED NOT NULL,
    player_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (match_id, player_id),
    KEY ix_match_players_team (match_id, team_id),
    CONSTRAINT fk_match_players_match FOREIGN KEY (match_id) REFERENCES crownd_matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_match_players_player FOREIGN KEY (player_id) REFERENCES crownd_players (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One ballot per device and match. voter_hash is derived from a random device
-- cookie; no IP address or other personal data is stored.
CREATE TABLE crownd_ballots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id INT UNSIGNED NOT NULL,
    voter_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_ballots_voter (match_id, voter_hash),
    CONSTRAINT fk_ballots_match FOREIGN KEY (match_id) REFERENCES crownd_matches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- At most one vote per team on a ballot.
CREATE TABLE crownd_votes (
    ballot_id INT UNSIGNED NOT NULL,
    match_id INT UNSIGNED NOT NULL,
    team_id INT UNSIGNED NOT NULL,
    player_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ballot_id, team_id),
    KEY ix_votes_count (match_id, team_id, player_id),
    CONSTRAINT fk_votes_ballot FOREIGN KEY (ballot_id) REFERENCES crownd_ballots (id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_player FOREIGN KEY (player_id) REFERENCES crownd_players (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
