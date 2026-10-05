-- =====================================================================
-- Labels for codes that live in the database (key sites, platforms,
-- genres), entered from the administration screens.
--
-- The language files ship labels for the codes the app knows about, but
-- rows added at runtime (a new key site, say) have none. Writing those
-- into lang/*.php would not survive a deploy, so they are kept here and
-- Lang uses them wherever a file has no label for the code.
-- =====================================================================

CREATE TABLE code_labels (
    grp        text        NOT NULL,
    code       text        NOT NULL,
    locale     text        NOT NULL,
    label      text        NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    updated_by bigint      REFERENCES users (id) ON DELETE SET NULL,
    PRIMARY KEY (grp, code, locale)
);
