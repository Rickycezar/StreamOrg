-- =====================================================================
-- Overlays can be told to stop: the settings page's "Interrupt" clears
-- what an open overlay is showing, its queue and its sound.
-- =====================================================================

ALTER TABLE overlay_signals DROP CONSTRAINT IF EXISTS overlay_signals_kind_check;
ALTER TABLE overlay_signals ADD CONSTRAINT overlay_signals_kind_check CHECK (kind IN ('test', 'settings', 'reload', 'stop'));
