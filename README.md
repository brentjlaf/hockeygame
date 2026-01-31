# Rink Micro-Sim (Phase 1)

## Project layout
- `public/` public web root (front controller at `public/index.php`)
- `api/` API endpoints (loaded by the front controller)
- `src/` PHP classes
- `config/` environment + database configuration
- `schema.sql` MySQL schema

## Local setup
1) Create a MySQL DB named `hockeysim` (or set `DB_NAME`).
2) Run `schema.sql` against that database.
3) Configure DB credentials in `config/database.php`, via `config/.env.php`, or via `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` env vars.

### Installer
Start the PHP server and visit `/install.php` to generate `config/.env.php` and import `schema.sql`.

## Run the app
From the repo root:
```bash
php -S localhost:8000 -t public public/index.php
```

Then visit:
- `http://localhost:8000/` for the game center UI
- `http://localhost:8000/install.php` to configure the database
- `http://localhost:8000/api/match_find.php?team_id=1` for API endpoints

## Quick test
Phase 1 uses `team_id` in the query string instead of real auth.

## Phase 0 rules
- Multiplayer default: asynchronous quick match (waits briefly for a human, then fills with an AI).
- Match timing: 3 periods × 40 ticks per period (1 real second per tick = 30 game seconds).
- Standings: win = 2 points, loss = 0 points (ties tracked but no points yet).
- Simulation is server-side only; results and play-by-play live in the DB.

## Maintenance
Unused scaffolding files have been removed (the `src/App.php` placeholder, `config/env.php`, `db/migrations/001_init.sql`, and the root `.gitkeep`). Keep new scaffolding files only when they are actively referenced by the app or docs.

### Create a team
```sql
INSERT INTO teams(user_id,name,rating,is_bot) VALUES(NULL,'My Team',1000,0);
```

### Add a roster
Create a one-off PHP script that calls `create_basic_roster($teamId)` from `api/bootstrap.php`,
or insert players manually.

### Run a match
- `GET /api/match_find.php?team_id=1` -> returns `match_id`
- `POST /api/match_submit.php?team_id=1` with JSON:
```json
{
  "match_id": 123,
  "plan": {
    "lines": {
      "L1": {"F":[1,2,3],"D":[13,14]},
      "L2": {"F":[4,5,6],"D":[15,16]},
      "L3": {"F":[7,8,9],"D":[17,18]},
      "L4": {"F":[10,11,12],"D":[13,14]}
    },
    "goalie": 19,
    "tactics": {"aggression":55,"forecheck":50,"shoot_bias":60,"risk":45}
  }
}
```

Open `/replay.html` and set TEAM_ID + MATCH_ID.
