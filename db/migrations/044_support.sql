-- =====================================================================
-- Support: three ways for a user to reach the administrators.
--
-- Bug reports
--   bug_reports        what went wrong: a title, the page, what happened,
--                      the steps (one per line, in order), what was
--                      expected, how much it gets in the way (impact), and
--                      what the browser said about itself. Administrators
--                      set the status and priority; the reporter follows
--                      it and is told when it changes.
--   bug_comments       the conversation on a report, and its history: a
--                      comment, a status change, or both. Internal notes
--                      are for administrators only.
--   support_images     screenshots, stored privately (tmp/support/) and
--                      served only to the reporter and administrators.
--                      Each can carry pins: points marked on the image,
--                      as fractions of its width and height, with a note.
--                      An image is uploaded first (draft) and attached
--                      when the report or comment is sent.
--
-- Feedback
--   feedback_threads   a message to the administrators, with replies
--   feedback_messages  (an inbox for them, a conversation for the user).
--
-- Questionnaires
--   surveys, survey_groups, survey_questions: built by administrators,
--   for everyone or chosen users (survey_targets); survey_responses and
--   survey_answers hold what each user answered (one response per user,
--   which they can change while the questionnaire is open).
--   Every text of a questionnaire (title, introduction, section names,
--   questions, hints, choices, scale labels) is kept in each language it
--   was written in, as {"<locale>": "text"}: each person reads their own,
--   otherwise English, otherwise any. Choices have a key of their own
--   ({"key": "c1", "text": {…}}) and answers keep the key, so answers given
--   in different languages count together.
-- =====================================================================

CREATE TABLE bug_reports (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     bigint      REFERENCES users (id) ON DELETE SET NULL,
    title       text        NOT NULL CHECK (length(title) BETWEEN 3 AND 140),
    page        text        CHECK (length(page) <= 300),
    description text        NOT NULL CHECK (length(description) BETWEEN 1 AND 5000),
    steps       text        CHECK (length(steps) <= 5000),
    expected    text        CHECK (length(expected) <= 2000),
    impact      text        NOT NULL DEFAULT 'annoying' CHECK (impact IN ('blocking', 'annoying', 'cosmetic')),
    status      text        NOT NULL DEFAULT 'new'
        CHECK (status IN ('new', 'confirmed', 'in_progress', 'fixed', 'wont_fix', 'duplicate', 'need_info', 'closed')),
    priority    text        NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    duplicate_of bigint     REFERENCES bug_reports (id) ON DELETE SET NULL,
    environment jsonb       NOT NULL DEFAULT '{}'::jsonb,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    fixed_at    timestamptz,
    seen_by_admin_at timestamptz,
    seen_by_user_at  timestamptz
);

CREATE INDEX bug_reports_user   ON bug_reports (user_id, updated_at DESC);
CREATE INDEX bug_reports_status ON bug_reports (status, updated_at DESC);

CREATE TABLE bug_comments (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bug_id      bigint      NOT NULL REFERENCES bug_reports (id) ON DELETE CASCADE,
    author_id   bigint      REFERENCES users (id) ON DELETE SET NULL,
    body        text        CHECK (length(body) <= 5000),
    is_internal boolean     NOT NULL DEFAULT false,
    from_status text,
    to_status   text,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX bug_comments_bug ON bug_comments (bug_id, created_at);

CREATE TABLE support_images (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     bigint      REFERENCES users (id) ON DELETE CASCADE,
    bug_id      bigint      REFERENCES bug_reports (id) ON DELETE CASCADE,
    comment_id  bigint      REFERENCES bug_comments (id) ON DELETE CASCADE,
    path        text        NOT NULL,
    width       integer     NOT NULL,
    height      integer     NOT NULL,
    bytes       integer     NOT NULL,
    pins        jsonb       NOT NULL DEFAULT '[]'::jsonb,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX support_images_bug   ON support_images (bug_id);
CREATE INDEX support_images_draft ON support_images (user_id, created_at) WHERE bug_id IS NULL;

CREATE TABLE feedback_threads (
    id              bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         bigint      REFERENCES users (id) ON DELETE SET NULL,
    subject         text        NOT NULL CHECK (length(subject) BETWEEN 1 AND 140),
    category        text        NOT NULL DEFAULT 'other' CHECK (category IN ('idea', 'praise', 'problem', 'question', 'other')),
    is_archived     boolean     NOT NULL DEFAULT false,
    is_starred      boolean     NOT NULL DEFAULT false,
    admin_unread    boolean     NOT NULL DEFAULT true,
    user_unread     boolean     NOT NULL DEFAULT false,
    created_at      timestamptz NOT NULL DEFAULT now(),
    last_message_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX feedback_threads_inbox ON feedback_threads (is_archived, last_message_at DESC);
CREATE INDEX feedback_threads_user  ON feedback_threads (user_id, last_message_at DESC);

CREATE TABLE feedback_messages (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    thread_id   bigint      NOT NULL REFERENCES feedback_threads (id) ON DELETE CASCADE,
    author_id   bigint      REFERENCES users (id) ON DELETE SET NULL,
    from_admin  boolean     NOT NULL DEFAULT false,
    body        text        NOT NULL CHECK (length(body) BETWEEN 1 AND 5000),
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX feedback_messages_thread ON feedback_messages (thread_id, created_at);

CREATE TABLE surveys (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title       jsonb       NOT NULL,
    description jsonb       NOT NULL DEFAULT '{}'::jsonb,
    status      text        NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'open', 'closed')),
    audience    text        NOT NULL DEFAULT 'everyone' CHECK (audience IN ('everyone', 'users')),
    closes_at   timestamptz,
    created_by  bigint      REFERENCES users (id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    opened_at   timestamptz
);

CREATE TABLE survey_groups (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    survey_id   bigint      NOT NULL REFERENCES surveys (id) ON DELETE CASCADE,
    title       jsonb       NOT NULL DEFAULT '{}'::jsonb,
    description jsonb       NOT NULL DEFAULT '{}'::jsonb,
    position    integer     NOT NULL DEFAULT 0
);

CREATE TABLE survey_questions (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    survey_id   bigint      NOT NULL REFERENCES surveys (id) ON DELETE CASCADE,
    group_id    bigint      NOT NULL REFERENCES survey_groups (id) ON DELETE CASCADE,
    type        text        NOT NULL CHECK (type IN ('short_text', 'long_text', 'radio', 'checkbox', 'select', 'scale', 'yes_no', 'number', 'date')),
    label       jsonb       NOT NULL,
    help        jsonb       NOT NULL DEFAULT '{}'::jsonb,
    required    boolean     NOT NULL DEFAULT false,
    options     jsonb       NOT NULL DEFAULT '{}'::jsonb,
    position    integer     NOT NULL DEFAULT 0
);

CREATE INDEX survey_questions_survey ON survey_questions (survey_id, position);

CREATE TABLE survey_targets (
    survey_id   bigint      NOT NULL REFERENCES surveys (id) ON DELETE CASCADE,
    user_id     bigint      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    PRIMARY KEY (survey_id, user_id)
);

CREATE TABLE survey_responses (
    id           bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    survey_id    bigint      NOT NULL REFERENCES surveys (id) ON DELETE CASCADE,
    user_id      bigint      REFERENCES users (id) ON DELETE SET NULL,
    submitted_at timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (survey_id, user_id)
);

CREATE TABLE survey_answers (
    response_id  bigint NOT NULL REFERENCES survey_responses (id) ON DELETE CASCADE,
    question_id  bigint NOT NULL REFERENCES survey_questions (id) ON DELETE CASCADE,
    value        jsonb  NOT NULL,
    PRIMARY KEY (response_id, question_id)
);
