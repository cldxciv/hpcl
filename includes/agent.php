<?php
declare(strict_types=1);

// On-site Pro HPCL membership sales agent. A chat widget (assets/js/agent.js) posts visitor
// messages to /agent/chat; this file answers them with Claude and its main job is to turn
// visitors into membership sign-ups and to capture a name + WhatsApp number so the team (and,
// in phase 2, the WhatsApp agent) can follow up. Conversations and captured leads are stored in
// agent_conversations / agent_messages.
//
// The Claude API key is read from the environment (ANTHROPIC_API_KEY), like the database
// credentials and never committed. Without a key the agent still works with a scripted
// fallback so the widget is usable in development.
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/membership.php';

const AGENT_API = 'https://api.anthropic.com/v1/messages';
const AGENT_VERSION = '2023-06-01';
const AGENT_MAX_INPUT = 1500;        // characters accepted from one visitor message
const AGENT_HISTORY_TURNS = 20;      // most recent messages sent to the model
const AGENT_MAX_PER_SESSION = 60;    // messages from one session before we stop (abuse guard)

function agentApiKey(): string {
    return trim((string) getenv('ANTHROPIC_API_KEY'));
}
function agentModel(): string {
    // Default to the current Opus; set ANTHROPIC_MODEL=claude-haiku-5-5 in .env for a far cheaper
    // widget (recommended for high traffic).
    return getenv('ANTHROPIC_MODEL') ?: 'claude-opus-5-5';
}
function agentConfigured(): bool {
    return agentApiKey() !== '';
}

/** The sales agent's system prompt, built from the live membership tiers so it never drifts. */
function agentSystemPrompt(string $channel = 'web'): string {
    $channelNote = $channel === 'whatsapp'
        ? "\nYou are chatting on WhatsApp, so you already have the person's number — don't ask for it. Keep messages short for mobile. Focus on qualifying them and getting them to join."
        : '';
    $tiers = '';
    foreach (MEMBERSHIP_TIERS as $tier) {
        $tiers .= "\n- {$tier['name']} — " . membershipPriceLabel($tier['price']) . "/year ({$tier['tagline']}); "
            . "{$tier['discount']}% off Shimla Cricket Ground match fees. Key benefits: "
            . implode('; ', array_slice($tier['benefits'], 0, 5)) . '.';
    }
    return <<<PROMPT
You are "Pro HPCL Assistant", the membership advisor on the Pro HPCL (Pro Himachal Premium Cricket League) website — Himachal's biggest franchise cricket league.

YOUR GOAL: help visitors understand the Membership Programme and get as many of them as possible to sign up, while being genuinely helpful and honest. Secondary goal: capture the visitor's name and WhatsApp number so the team can follow up, even if they don't join right now.

MEMBERSHIP TIERS (annual business memberships):{$tiers}

HOW TO SELL (do this naturally, never pushy):
- Open warm and brief. Ask one question at a time about their business and what they want (visibility, PR content, networking, ground discount).
- Recommend the tier that fits their goal and budget; explain why in one or two lines. Diamond is the most popular.
- Handle objections with the concrete benefits above. Emphasise real value: a dedicated business profile on the official site, professional non-AI promo posts/videos, and graded match-fee discounts.
- Mention the mission when it helps: supporting local cricket talent, youth opportunity and a drug-free Himachal.
- Always move toward action: invite them to open the join page, or offer to have the team reach out on WhatsApp — then ask for their name and WhatsApp number.

STYLE: concise and friendly, 2–4 short sentences per reply, plain language, Indian Rupees. You may use a little warmth but no emoji spam.

RULES:
- Only state benefits, prices and discounts listed above. Do NOT invent tiers, numbers, offers, discounts or guarantees, and do not negotiate custom pricing — say the team can discuss specifics.
- You cannot take payments in chat. To join, direct them to the membership join page on this site.
- If asked something outside membership (fixtures, players, teams), answer briefly if you can and steer back to how membership helps their business.
- Never ask for payment card details, OTPs or passwords. A name and WhatsApp number is all you collect.{$channelNote}
PROMPT;
}

/** Finds or creates the conversation for a session on a channel ('web' or 'whatsapp'); returns its row. */
function agentConversation(string $sessionId, ?string $pageUrl, string $channel = 'web', ?string $phone = null): array {
    $row = query('SELECT * FROM agent_conversations WHERE channel = ? AND session_id = ?', [$channel, $sessionId])[0] ?? null;
    if ($row) return $row;
    db()->prepare('INSERT INTO agent_conversations (channel, session_id, phone, status, page_url, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$channel, $sessionId, $phone, 'open', $pageUrl ? substr($pageUrl, 0, 255) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
    return query('SELECT * FROM agent_conversations WHERE id = ?', [(int) db()->lastInsertId()])[0];
}

/** The shared brain for both agents: stores the message, captures lead info, returns the reply. */
function agentReply(array $conversation, string $message, string $channel = 'web'): string {
    agentStore($conversation['id'], 'user', $message);
    agentCaptureLead($conversation, $message);
    $reply = agentConfigured() ? agentCallClaude(agentHistory($conversation['id']), $channel) : null;
    if ($reply === null) $reply = agentFallbackReply($message);
    agentStore($conversation['id'], 'assistant', $reply);
    return $reply;
}

function agentHistory(int $conversationId): array {
    $rows = query('SELECT role, content FROM agent_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?', [$conversationId, AGENT_HISTORY_TURNS]);
    return array_reverse($rows);
}

function agentStore(int $conversationId, string $role, string $content): void {
    db()->prepare('INSERT INTO agent_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$conversationId, $role, $content]);
}

/** Pulls a name, Indian mobile number, email and tier interest out of the chat and saves them as a lead. */
function agentCaptureLead(array $conversation, string $userText): void {
    $fields = [];
    $all = $userText;
    if (!$conversation['phone'] && preg_match('~(?:\+?91[\s-]?|0)?([6-9]\d{9})\b~', preg_replace('~[\s().-]+~', '', $all), $m)) {
        $fields['phone'] = $m[1];
    }
    if (!$conversation['email'] && preg_match('~[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}~', $all, $m)) {
        $fields['email'] = strtolower($m[0]);
    }
    if (!$conversation['interested_tier']) {
        foreach (array_keys(MEMBERSHIP_TIERS) as $key) {
            if (preg_match('~\b' . $key . '\b~i', $all)) { $fields['interested_tier'] = $key; break; }
        }
    }
    if (!$conversation['visitor_name']) {
        // "my name is X [Y]", "this is X", or "I'm/I am X" where X looks like a name (capitalised).
        if (preg_match('~(?:my name is|this is)\s+([\p{L}]{2,})(?:\s+([\p{L}]{2,}))?~ui', $userText, $m)
            || preg_match("~\bi(?:'m| am)\s+([\p{Lu}][\p{L}]{1,})(?:\s+([\p{Lu}][\p{L}]{1,}))?~u", $userText, $m)) {
            $name = trim($m[1] . ' ' . ($m[2] ?? ''));
            if (!preg_match('~^(interested|looking|from|here|trying|not|just|really|the|a|an)$~i', $m[1])) {
                $fields['visitor_name'] = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            }
        }
    }
    $hasContact = ($fields['phone'] ?? $conversation['phone']) || ($fields['email'] ?? $conversation['email']);
    if ($hasContact && $conversation['status'] === 'open') $fields['status'] = 'lead';
    if (!$fields) { db()->prepare('UPDATE agent_conversations SET updated_at = NOW() WHERE id = ?')->execute([$conversation['id']]); return; }
    $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($fields)));
    db()->prepare("UPDATE agent_conversations SET $sets, updated_at = NOW() WHERE id = ?")
        ->execute([...array_values($fields), $conversation['id']]);
}

/** Calls Claude with the conversation and returns the assistant's reply text, or null on failure. */
function agentCallClaude(array $history, string $channel = 'web'): ?string {
    $messages = [];
    foreach ($history as $m) $messages[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']];
    $payload = [
        'model' => agentModel(),
        'max_tokens' => 1024,
        'system' => agentSystemPrompt($channel),
        'messages' => $messages,
        // Keep the widget snappy and cheap; raise effort in code if you want deeper answers.
        'output_config' => ['effort' => 'low'],
    ];
    $curl = curl_init(AGENT_API);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'anthropic-version: ' . AGENT_VERSION,
            'x-api-key: ' . agentApiKey(),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) { error_log("Claude call failed: $error"); return null; }
    $data = json_decode((string) $response, true);
    if ($status !== 200) { error_log('Claude API ' . $status . ': ' . substr((string) $response, 0, 500)); return null; }
    if (($data['stop_reason'] ?? '') === 'refusal') return 'Sorry, I can’t help with that — but I’m happy to tell you about Pro HPCL membership. What would you like to know?';
    $text = '';
    foreach ($data['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    $text = trim($text);
    return $text !== '' ? $text : null;
}

/** A useful reply without the model, so the widget still works before an API key is set. */
function agentFallbackReply(string $userText): string {
    $low = strtolower($userText);
    if (preg_match('~price|cost|fee|tier|plan|silver|gold|diamond|platinum|how much~', $low)) {
        $lines = [];
        foreach (MEMBERSHIP_TIERS as $t) $lines[] = $t['name'] . ' — ' . membershipPriceLabel($t['price']) . '/year (' . $t['discount'] . '% off ground match fees)';
        return "Here are the Pro HPCL membership tiers:\n• " . implode("\n• ", $lines)
            . "\n\nEach includes a business profile on our website plus promotion that grows with the tier. Want me to help you pick one, or shall I have our team reach out on WhatsApp? Just share your name and number.";
    }
    return "Thanks for your interest in Pro HPCL membership! We have four annual tiers — Silver, Gold, Diamond and Platinum — with a business profile on our site, social promotion, merchandise and graded Shimla Cricket Ground discounts. Tell me about your business and what you'd like to get out of it, or share your name and WhatsApp number and our team will reach out.";
}

/** POST /agent/chat — JSON in {session_id, message, page_url}, JSON out {reply, session_id, cta}. */
function handleAgentChat(): never {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) { http_response_code(400); echo json_encode(['error' => 'Bad request']); exit; }

    $sessionId = (string) ($input['session_id'] ?? '');
    if (!preg_match('~^[A-Za-z0-9_-]{8,64}$~', $sessionId)) $sessionId = bin2hex(random_bytes(16));
    $message = trim((string) ($input['message'] ?? ''));
    if ($message === '') { echo json_encode(['session_id' => $sessionId, 'reply' => 'Ask me anything about Pro HPCL membership!']); exit; }
    if (mb_strlen($message) > AGENT_MAX_INPUT) $message = mb_substr($message, 0, AGENT_MAX_INPUT);

    $conversation = agentConversation($sessionId, (string) ($input['page_url'] ?? ''));
    $count = (int) (query('SELECT COUNT(*) AS n FROM agent_messages WHERE conversation_id = ?', [$conversation['id']])[0]['n'] ?? 0);
    if ($count >= AGENT_MAX_PER_SESSION) { echo json_encode(['session_id' => $sessionId, 'reply' => 'Thanks for chatting! To go further, please open the membership page or leave your WhatsApp number and our team will reach out.', 'cta' => ['label' => 'See membership plans', 'url' => '/membership']]); exit; }

    $reply = agentReply($conversation, $message, 'web');

    echo json_encode([
        'session_id' => $sessionId,
        'reply' => $reply,
        'cta' => ['label' => 'See membership plans', 'url' => '/membership'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
