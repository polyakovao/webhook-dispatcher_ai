# Kickoff

How to do the whole task with Claude Code from the clean starter, using this kit.

## 1. Clean repo (once, about a minute)

BRIEF wants the stack folder as the repo root. Take it from the starter's first commit, `ad18fde`:

```bash
STARTER=~/PhpstormProjects/webhook-dispatcher-starter
DEST=~/PhpstormProjects/webhook-dispatcher
mkdir "$DEST" && cd "$DEST"
git -C "$STARTER" archive ad18fde:php | tar -x
git init -q && git add -A && git commit -qm "Initial import: PHP starter"
```

Then add the kit as a second commit, so that the starter and the kit stay separate in history:

```bash
tar -C "$STARTER/php" -cf - CLAUDE.md docs .claude .github/pull_request_template.md phpunit.xml.dist tests/LibTest.php tests/Functional tests/e2e.sh tests/run-all.sh | tar -x
git add -A && git commit -qm "Add Claude kit: CLAUDE.md, PRD, plan, /test skill, unit, functional and e2e tests" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Start Claude Code in `$DEST`, so that `CLAUDE.md` and `/test` load at startup.

You can also practise on a branch of the starter repo. In that case, commit the kit on `main` first, then run `git switch -c clean-run ad18fde` and `git checkout main -- php/CLAUDE.md php/docs php/.claude php/.github/pull_request_template.md php/phpunit.xml.dist php/tests/LibTest.php php/tests/Functional php/tests/e2e.sh php/tests/run-all.sh`. Start Claude in `php/`. GitHub won't run CI from `php/.github`.

## 2. First message

```text
We're doing the "Mini Webhook Dispatcher" interview task (PHP stack) in this repo.

First read CLAUDE.md, BRIEF.md, docs/PRD.md and docs/PLAN.md. Reply with a 5-line summary
of the plan and anything in them that contradicts each other. No code yet.

Then work through docs/PLAN.md one stage at a time, starting with Stage 0:
1. Before a stage, say in 2-3 lines what you'll change and which check proves it.
2. Change only what the stage needs, the simplest way that passes its check.
3. Run the check (/test or /test e2e) and show the output. If it fails, fix the code
   and run it again. Never edit tests or e2e expectations to make them pass.
4. Tick the stage in docs/PLAN.md and add a log row (done / cut).
5. Report what you verified, what you assume, and a short "Not verified" list. Then stop.
I answer "ok, go" (commit this stage) or "next" (move on without committing).

If a stage runs over its time box, propose what to cut instead of pushing on.
If BRIEF, PRD and code disagree, stop and ask.
```
