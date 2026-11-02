-- Starter schema. Candidates may change it, but should say why.
-- Status values used by the spec: pending | in_progress | retry | sent | dead

CREATE TABLE IF NOT EXISTS events (
    id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       TEXT        NOT NULL DEFAULT 'default',
    target_url      TEXT        NOT NULL,
    event_type      TEXT        NOT NULL,
    payload         JSONB       NOT NULL,
    status          TEXT        NOT NULL DEFAULT 'pending',
    attempts        INT         NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    locked_until    TIMESTAMPTZ,
    last_error      TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Supports "find work that is due" queries.
CREATE INDEX IF NOT EXISTS events_due_idx ON events (status, next_attempt_at);
