-- On-site membership sales agent: conversations and their messages, plus the lead
-- details captured from chats. Local dev DB; mirror to production only when approved.

CREATE TABLE IF NOT EXISTS agent_conversations (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel         VARCHAR(16)  NOT NULL DEFAULT 'web',   -- 'web' now, 'whatsapp' later
    session_id      VARCHAR(64)  NOT NULL,
    visitor_name    VARCHAR(255) DEFAULT NULL,
    phone           VARCHAR(20)  DEFAULT NULL,
    email           VARCHAR(255) DEFAULT NULL,
    interested_tier VARCHAR(16)  DEFAULT NULL,
    status          VARCHAR(16)  NOT NULL DEFAULT 'open',  -- open | lead | converted
    page_url        VARCHAR(255) DEFAULT NULL,
    ip              VARCHAR(45)  DEFAULT NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_session (channel, session_id),
    KEY idx_agent_status (status),
    KEY idx_agent_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_messages (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    role            VARCHAR(12)  NOT NULL,                 -- user | assistant
    content         TEXT         NOT NULL,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_agent_msg_conv (conversation_id),
    CONSTRAINT fk_agent_msg_conv FOREIGN KEY (conversation_id)
        REFERENCES agent_conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
