-- =====================================================================
-- Interface texts edited in Administration → Languages.
--
-- The lang/<locale>.php files in the code are the defaults; edits made in
-- the admin tool are kept here (the files are replaced on every deploy)
-- and applied over the files when the texts load. path is the dotted key
-- ("ui.nav.games").
-- =====================================================================

CREATE TABLE lang_overrides (
    locale     text        NOT NULL CHECK (locale ~ '^[A-Za-z]{2}(-[A-Za-z0-9]{2,8})?$'),
    path       text        NOT NULL CHECK (length(path) <= 200 AND path ~ '^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)+$'),
    value      text        NOT NULL CHECK (length(value) <= 4000),
    updated_by bigint      REFERENCES users (id) ON DELETE SET NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (locale, path)
);
