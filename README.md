# Rink Micro-Sim (Phase 1)

## What you get
- Matchmaking: tries human opponent; if none, falls back to AI bot
- Plans (plan_json): submit lineup/tactics
- Server sim: 3 periods × 40 ticks (40s real = 20:00 hockey time)
- Stores play-by-play in `match_events` (payload includes `text`)
- Result endpoint returns score + events (for replay UI)

## Setup
1) Create a MySQL DB named `hockeysim` (or change DB_NAME in `api/bootstrap.php`)
2) Run `schema.sql`
3) Point your PHP server docroot at this project folder so `/api/*` and `/public/*` are reachable

## Quick test
Phase 1 uses `team_id` in the query string instead of real auth.

### Create a team
Example SQL:
```sql
INSERT INTO teams(user_id,name,rating,is_bot) VALUES(NULL,'My Team',1000,0);
```

### Add a roster
Easiest: create a one-off PHP script that calls `create_basic_roster($teamId)` from `api/bootstrap.php`,
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

Open `/public/replay.html` and set TEAM_ID + MATCH_ID.
