-- =====================================================================
-- Game names get a ceiling (GameCatalog::TITLE_MAX, 200 characters).
-- Real titles pass 130 ("AVIÃOZINHO DO TRÁFICO PRÉ 4: …"), so the
-- screens handle long names themselves; this only stops a runaway one.
-- Any name already longer is cut with "…" first.
-- =====================================================================

UPDATE games SET title = rtrim(left(title, 199)) || '…' WHERE char_length(title) > 200;

ALTER TABLE games ADD CONSTRAINT games_title_length CHECK (char_length(title) BETWEEN 1 AND 200);
