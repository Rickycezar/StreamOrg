-- =====================================================================
-- Sessions ended because an administrator deactivated the account.
-- =====================================================================

ALTER TABLE user_sessions DROP CONSTRAINT IF EXISTS user_sessions_revoked_reason_check;

ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_revoked_reason_check
    CHECK (revoked_reason IN ('logout', 'revoked', 'password_changed', 'password_reset', 'expired', 'deactivated'));
