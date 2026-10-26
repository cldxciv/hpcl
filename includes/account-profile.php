<?php
declare(strict_types=1);

// The signed-in user's pages: /account (their details), /account/edit (name, mobile, city and
// favourite team) and /account/password (change the password). Loaded by handleAccountRequest()
// in account.php, which has already started the session; every page starts with requireUser().
//
// The forms are sent with AJAX by assets/js/forms.js and answered with formResponse(), like the
// membership join form; without JavaScript they are ordinary forms. The email address can't be
// changed here: it is the sign-in name, so a change goes through the team.
//
// Changing the password asks for the current one. Wrong guesses are recorded in
// user_login_attempts with the login page's limits, so a session left open on a shared computer
// can't be used to find the password. The new password signs out every other session (their
// fingerprint of the old hash no longer matches in currentUser()) and keeps this one.
require_once __DIR__ . '/committee.php'; // reuse committeePhone(), committeeCleanText(), COMMITTEE_NAME_PATTERN
require_once __DIR__ . '/teams.php'; // teamSeasonUrl()

const PROFILE_FIELDS = ['name', 'phone', 'city', 'favourite_team_id'];
const PROFILE_PASSWORD_FIELDS = ['current_password', 'new_password', 'confirm_password'];
const PROFILE_CITY_LENGTH = 120;
const PROFILE_DEFAULT_LOGO = '/assets/media/logo.png';

/** /account: the user's details, with links to edit them, change the password and log out. */
function handleAccountHome(): never {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) errorPage(405);
    $user = requireUser();
    $missing = '<span class="acct-details__missing">Not added</span>';
    $team = profileFavouriteTeam((int) $user['favourite_team_id']);
    accountPage(view('templates.account.home', [
        'flash' => accountTakeFlash(),
        'name' => escape($user['name']),
        'account_email' => escape($user['email']),
        'phone' => $user['phone'] ? profilePhoneLabel($user['phone']) : $missing,
        'city' => $user['city'] ? escape($user['city']) : $missing,
        'team' => $team ? profileTeamHtml($team) : '<span class="acct-details__missing">Not chosen</span>',
        'member_since' => date('j M Y', strtotime($user['created_at'])),
        'csrf' => accountCsrfToken(),
    ]));
}

/** /account/edit: GET shows the form filled in with the user's details; POST saves them. */
function handleAccountEdit(): never {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) errorPage(405);
    $user = requireUser();
    if ($method !== 'POST') {
        $input = ['name' => $user['name'], 'phone' => $user['phone'] ?? '', 'city' => $user['city'] ?? '', 'favourite_team_id' => (string) ($user['favourite_team_id'] ?? '')];
        accountPage(profileEditForm($user, $input, []));
    }
    [$input, $errors] = validateProfile();
    if (!accountCsrfValid()) $errors = ['form' => USER_FORM_EXPIRED];
    elseif (!$errors) {
        accountExecute('UPDATE users SET name = ?, phone = ?, city = ?, favourite_team_id = ?, updated_at = NOW() WHERE id = ?',
            [$input['name'], $input['phone'] ?: null, $input['city'] ?: null, $input['favourite_team_id'] === '' ? null : (int) $input['favourite_team_id'], $user['id']]);
        accountFlash('Your profile is saved.');
    }
    accountFormResponse($errors, 'Your profile is saved.', '/account', fn () => profileEditForm($user, $input, $errors));
}

/** /account/password: GET shows the form; POST changes the password and returns to /account. */
function handleAccountPassword(): never {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) errorPage(405);
    $user = requireUser();
    if ($method !== 'POST') accountPage(profilePasswordForm($user, []));
    $errors = changeProfilePassword($user);
    accountFormResponse($errors, 'Your password is changed.', '/account', fn () => profilePasswordForm($user, $errors));
}

/** Checks the posted profile fields: cleaned values (phone as 10 digits or '') and errors by field. */
function validateProfile(): array {
    $input = [
        'name' => committeeCleanText(postedText('name')),
        'phone' => postedText('phone'),
        'city' => committeeCleanText(postedText('city')),
        'favourite_team_id' => postedText('favourite_team_id'),
    ];
    $errors = [];
    [$nMin, $nMax] = USER_NAME_LENGTH;
    $nLen = mb_strlen($input['name']);
    if ($nLen === 0) $errors['name'] = 'Enter your name.';
    elseif ($nLen < $nMin || $nLen > $nMax) $errors['name'] = "Your name must be $nMin to $nMax characters long.";
    elseif (!preg_match(COMMITTEE_NAME_PATTERN, $input['name'])) $errors['name'] = 'Use only letters, spaces, dots, hyphens and apostrophes in the name.';

    // Optional: an empty mobile number removes the saved one.
    if ($input['phone'] !== '') {
        $phone = committeePhone($input['phone']);
        if ($phone === null) $errors['phone'] = 'Enter a valid 10-digit Indian mobile number, like 98160 12345, or leave it blank.';
        else $input['phone'] = $phone;
    }

    if (mb_strlen($input['city']) > PROFILE_CITY_LENGTH) $errors['city'] = 'The city name is too long.';

    if ($input['favourite_team_id'] !== '') {
        if (!ctype_digit($input['favourite_team_id']) || !isset(accountTeams()[(int) $input['favourite_team_id']])) $errors['favourite_team_id'] = 'Choose a team from the list.';
        else $input['favourite_team_id'] = (string) (int) $input['favourite_team_id'];
    }
    return [$input, $errors];
}

/**
 * Checks the posted passwords and saves the new one; returns errors by field (none when the
 * password is changed). This session stays signed in with the new password's fingerprint.
 */
function changeProfilePassword(array $user): array {
    if (!accountCsrfValid()) return ['form' => USER_FORM_EXPIRED];
    [$current, $new, $confirm] = array_map('postedPassword', PROFILE_PASSWORD_FIELDS);
    $errors = accountNewPasswordErrors($new, $confirm, 'new_password', 'confirm_password');

    if ($current === '') return ['current_password' => 'Enter your current password.'] + $errors;
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $blocked = accountLoginBlocked($user['email'], $ip);
    if ($blocked || !password_verify($current, $user['password_hash'])) {
        accountExecute('INSERT INTO user_login_attempts (ip, email, attempted_at) VALUES (?, ?, NOW())', [$ip, $user['email']]);
        error_log('Account password change failed for user ' . $user['id'] . ' from ' . $ip . ($blocked ? ' (too many attempts)' : ' (wrong current password)'));
        return $blocked ? ['form' => 'Too many wrong passwords. Please wait ' . USER_FAILURE_WINDOW . ' minutes and try again.']
            : ['current_password' => 'That isn’t your current password.'] + $errors;
    }
    if (!$errors && $new === $current) $errors['new_password'] = 'Choose a new password that’s different from your current one.';
    if ($errors) return $errors;

    $hash = userPasswordHash($new);
    accountExecute('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?', [$hash, $user['id']]);
    accountExecute('DELETE FROM user_login_attempts WHERE email = ?', [$user['email']]);
    // A reset link sent before the change could otherwise still undo it.
    accountExecute("UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = 'reset' AND used_at IS NULL", [$user['id']]);
    session_regenerate_id(true);
    $_SESSION['hash'] = userHashFingerprint($hash);
    accountFlash('Your password is changed. You’re signed out on other devices.');
    return [];
}

/** A mobile number as shown on the site: "98160 12345". */
function profilePhoneLabel(string $phone): string {
    return substr($phone, 0, 5) . ' ' . substr($phone, 5);
}

/** The favourite team (name, slug, logo) with the route_key of its latest season (null if none), or null. */
function profileFavouriteTeam(int $teamId): ?array {
    if (!$teamId) return null;
    return query('SELECT t.name, t.slug, t.logo, (SELECT s.route_key FROM season_teams st JOIN seasons s ON s.id = st.season_id
        WHERE st.team_id = t.id ORDER BY s.start_date DESC, s.id DESC LIMIT 1) AS route_key FROM teams t WHERE t.id = ? AND t.deleted_at IS NULL', [$teamId])[0] ?? null;
}

/** The team's logo and name, linking to its latest season's team page when it has played one. */
function profileTeamHtml(array $team): string {
    $html = '<img class="acct-team__logo" src="' . escape(mediaUrl($team['logo'], PROFILE_DEFAULT_LOGO)) . '" alt="" width="40" height="40" loading="lazy"'
        . ' onerror="this.onerror=null;this.src=\'{{BASE}}' . PROFILE_DEFAULT_LOGO . '\';"><span>' . escape($team['name']) . '</span>';
    return $team['route_key'] === null ? '<span class="acct-team">' . $html . '</span>'
        : '<a class="acct-team" href="' . teamSeasonUrl(['route_key' => $team['route_key']], $team) . '">' . $html . '</a>';
}

function profileEditForm(array $user, array $input, array $errors): string {
    $teams = '<option value="">No favourite team</option>';
    foreach (accountTeams() as $id => $name) {
        $teams .= '<option value="' . $id . '"' . ((string) $id === $input['favourite_team_id'] ? ' selected' : '') . '>' . escape($name) . '</option>';
    }
    [$nMin, $nMax] = USER_NAME_LENGTH;
    return view('templates.account.edit', accountFormData($input, $errors, PROFILE_FIELDS) + [
        'account_email' => escape($user['email']), 'teams' => $teams, 'name_min' => $nMin, 'name_max' => $nMax, 'city_max' => PROFILE_CITY_LENGTH,
    ]);
}

/** The change-password form; the passwords are never filled back in. */
function profilePasswordForm(array $user, array $errors): string {
    [$pMin, $pMax] = USER_PASSWORD_LENGTH;
    return view('templates.account.password', accountFormData([], $errors, PROFILE_PASSWORD_FIELDS) + [
        'account_email' => escape($user['email']), 'password_min' => $pMin, 'password_max' => $pMax,
    ]);
}
