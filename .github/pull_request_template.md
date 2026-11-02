## What

<!-- One paragraph: what the service does and how (Postgres as the queue, the worker as a separate process). -->

## Scope (BRIEF tiers, IDs from docs/PRD.md)

- [ ] **Must**: M1 `POST /events` → 202 + id · M2 input validation · M3 delivery with a timeout · M4 `GET /health`
- [ ] **Should**: S1 outcome table · S2 backoff with jitter, `dead` after 8 · S3 HMAC signature · S4 stable event id · S5 safe claiming · S6 unit tests
- [ ] **Stretch**: <!-- which X items, if any -->

## Cut, and why

<!-- What is not done, and why it was the right thing to cut in 60 minutes. -->

## How to check

- [ ] Every change comes with a test that fails without it (unit, functional or e2e)

```bash
tests/run-all.sh   # unit → build and start the stack → functional → e2e
```

## Decisions worth a look

<!-- 2–3 bullets. Details are in NOTES.md and in docs/PRD.md §5. -->

## Next

<!-- What you would do with another day, or before production. -->

## AI assistance

Written together with Claude Code; the commits carry a `Co-Authored-By: Claude` trailer. The kit it worked from: `CLAUDE.md`, `docs/`, `.claude/skills/test/`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
