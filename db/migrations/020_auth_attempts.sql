-- =====================================================================
-- Password-guessing protection
--
-- Every password check (sign-in, vault unlock, password and vault-mode
-- changes) is recorded here. AuthThrottle refuses further attempts for an
-- account or an address that has failed too often recently, before any
-- password is verified — which also keeps a flood of guesses from
-- burning the CPU on Argon2id / PBKDF2.
-- =====================================================================

CREATE TABLE auth_attempts (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- login: subject is the normalised username typed in.
    -- vault, password: subject is the signed-in user's id.
    scope        text        NOT NULL CHECK (scope IN ('login', 'vault', 'password')),
    subject      text        NOT NULL,
    ip           text,
    succeeded    boolean     NOT NULL,
    attempted_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX auth_attempts_subject ON auth_attempts (scope, subject, attempted_at);
CREATE INDEX auth_attempts_ip      ON auth_attempts (ip, attempted_at) WHERE NOT succeeded;
