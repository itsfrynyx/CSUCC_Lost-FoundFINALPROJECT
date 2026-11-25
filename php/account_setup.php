<?php
session_start();
require_once __DIR__ . '/../DB/conn.php';
require_once __DIR__ . '/includes/account_setup_helper.php';
require_once __DIR__ . '/includes/account_input_validation.php';

$user_id = account_setup_id_from_session();
if (!$user_id) {
    header('Location: Sign_in.php');
    exit();
}

$stmt = $conn->prepare('SELECT * FROM infos WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account) {
    session_destroy();
    header('Location: Sign_in.php');
    exit();
}

$is_customer = $account['role'] === 'customer';
$setup_complete = (int) $account['must_complete_setup'] !== 1;
$show_completion = $setup_complete && $is_customer && ($_GET['complete'] ?? '') === '1';
if ($setup_complete && !$show_completion) {
    header('Location: ' . ($is_customer ? 'Dashboard.php' : ($account['role'] === 'super_admin' ? 'admin_dashboard.php' : 'unified_admin_dashboard.php')));
    exit();
}

// Use the same profile storage as the Profile page.
$conn->query("CREATE TABLE IF NOT EXISTS user_profiles (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            postal_code VARCHAR(20) DEFAULT NULL,
            profile_picture VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_profile (user_id),
            KEY idx_user_id (user_id),
            CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES infos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
$profile_stmt = $conn->prepare('SELECT phone, address, city, country, postal_code FROM user_profiles WHERE user_id = ? LIMIT 1');
$profile_stmt->bind_param('i', $user_id);
$profile_stmt->execute();
$profile = $profile_stmt->get_result()->fetch_assoc() ?: [];
$profile_stmt->close();
$username = $account['username'];
$stored_middle_name = trim($account['middlename'] ?? '');
preg_match('/^\p{L}\p{M}*/u', $stored_middle_name, $initial_match);
$original_middle_initial = $initial_match[0] ?? '';
$middle_initial = $original_middle_initial;
$personal = [
    'firstname' => $account['firstname'] ?? '',
    'lastname' => $account['lastname'] ?? '',
    'email' => $account['email'] ?? '',
    'phone' => $profile['phone'] ?? '',
    'city' => $profile['city'] ?? ($account['municipality'] ?? ''),
    'postal_code' => $profile['postal_code'] ?? ($account['postal'] ?? ''),
    'country' => ($profile['country'] ?? ($account['country'] ?? '')) ?: 'Philippines',
    'address' => $profile['address'] ?? ($account['street'] ?? '')
];
$personal_limits = ['firstname' => 250, 'lastname' => 250, 'email' => 250, 'phone' => 50, 'city' => 100, 'postal_code' => 20, 'country' => 100, 'address' => 250];

$questions = [
    'What was your first pet\'s name?',
    'What was your first car?',
    'What is your favorite sport?',
    'What is your mother\'s maiden name?',
    'What city were you born in?',
    'What was the name of your elementary school?'
];
$error_message = '';
$field_errors = [];
$_SESSION['setup_form_token'] ??= bin2hex(random_bytes(32));

if (!$setup_complete && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_customer) {
        $username = account_input('username');
    }
    $middle_initial = account_input('middle_initial');
    if ($middle_initial !== '' && !preg_match('/^\p{L}\p{M}*\.?\z/u', $middle_initial)) {
        $field_errors['middle_initial'] = 'Enter one letter for your middle initial.';
    } else {
        $middle_initial = rtrim($middle_initial, '.');
    }
    if (!$is_customer && $username !== $account['username']) {
        if (!preg_match('/^[A-Za-z0-9_]{4,30}$/D', $username)) {
            $field_errors['username'] = 'Use 4 to 30 letters, numbers, or underscores.';
        } else {
            $username_check = $conn->prepare('SELECT 1 FROM infos WHERE username = ? AND id <> ? LIMIT 1');
            $username_check->bind_param('si', $username, $user_id);
            $username_check->execute();
            if ($username_check->get_result()->num_rows > 0) {
                $field_errors['username'] = 'That username is already taken.';
            }
            $username_check->close();
        }
    }

    foreach ($personal as $field => $value) {
        $personal[$field] = account_input($field);
        $text = $field === 'address' ? str_replace(["\r", "\n"], ' ', $personal[$field]) : $personal[$field];
        if (!account_text_is_valid($text, $personal_limits[$field])) {
            $field_errors[$field] = 'Use shorter text without control characters.';
        }
    }
    foreach (['firstname', 'lastname'] as $field) {
        if (!account_name_is_valid($personal[$field])) {
            $field_errors[$field] = 'Enter a name using letters, spaces, periods, apostrophes, or hyphens.';
        }
    }
    $address_labels = ['city' => 'city', 'postal_code' => 'postal code', 'country' => 'country', 'address' => 'street address'];
    foreach ($address_labels as $field => $label) {
        if ($personal[$field] === '') {
            $field_errors[$field] = "Enter your $label.";
        }
    }
    if ($personal['phone'] !== '' && !account_phone_is_valid($personal['phone'])) {
        $field_errors['phone'] = 'Enter a phone number with 7 to 15 digits.';
    }
    if ($personal['postal_code'] !== '' && !account_postal_is_valid($personal['postal_code'], $personal['country'])) {
        $field_errors['postal_code'] = 'Enter a valid postal code. Philippine postal codes have four digits.';
    }
    if ($personal['email'] !== '' && !filter_var($personal['email'], FILTER_VALIDATE_EMAIL)) {
        $field_errors['email'] = 'Enter a valid email address.';
    } elseif ($personal['email'] !== '' && !isset($field_errors['email'])) {
        $email_check = $conn->prepare('SELECT id FROM infos WHERE email = ? AND id <> ? LIMIT 1');
        $email_check->bind_param('si', $personal['email'], $user_id);
        $email_check->execute();
        if ($email_check->get_result()->num_rows > 0) {
            $field_errors['email'] = 'This email address is already used by another account.';
        }
        $email_check->close();
    }
    $new_password = account_input('new_password', false);
    $confirm_password = account_input('confirm_password', false);
    $security_q1 = account_input('security_q1');
    $security_q2 = account_input('security_q2');
    $security_q3 = account_input('security_q3');
    $security_a1 = account_input('security_a1');
    $security_a2 = account_input('security_a2');
    $security_a3 = account_input('security_a3');

    if (!account_password_is_valid($new_password)) {
        $field_errors['new_password'] = 'Use 8 to 15 characters with uppercase and lowercase letters and a number, without spaces.';
    }
    if ($confirm_password === '' || $new_password !== $confirm_password) {
        $field_errors['confirm_password'] = 'Enter the same password again.';
    }
    $chosen = [];
    foreach ([$security_q1, $security_q2, $security_q3] as $index => $question) {
        $field = 'security_q' . ($index + 1);
        if (!in_array($question, $questions, true)) {
            $field_errors[$field] = 'Choose a security question from the list.';
        } elseif (isset($chosen[$question])) {
            $field_errors[$field] = 'Choose a different security question.';
        }
        $chosen[$question] = true;
    }
    foreach ([$security_a1, $security_a2, $security_a3] as $index => $answer) {
        if ($answer === '' || !account_text_is_valid($answer, 72)) {
            $field_errors['security_a' . ($index + 1)] = 'Enter a security answer of up to 72 bytes without control characters.';
        }
    }

    if (!hash_equals($_SESSION['setup_form_token'], account_input('setup_token', false))) {
        $error_message = 'This form expired. Reload the page and try again.';
    } elseif ($field_errors) {
        $error_message = 'Check the highlighted fields and try again.';
    } else {
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $answer1_hash = password_hash($security_a1, PASSWORD_DEFAULT);
        $answer2_hash = password_hash($security_a2, PASSWORD_DEFAULT);
        $answer3_hash = password_hash($security_a3, PASSWORD_DEFAULT);
        $middle_name = $middle_initial === $original_middle_initial ? $stored_middle_name : $middle_initial;
        $display_middle_name = preg_match('/^\p{L}\p{M}*\z/u', $middle_name) ? $middle_name . '.' : $middle_name;
        $full_name = implode(' ', array_filter([$personal['firstname'], $display_middle_name, $personal['lastname'], $account['suffix'] ?? ''], static fn($part) => $part !== ''));
        $conn->begin_transaction();
        try {
            if ($is_customer) {
                $username = available_customer_username($conn, $personal['lastname'], $personal['firstname'], $user_id);
            }
            $update = $conn->prepare('UPDATE infos SET username = ?, firstname = ?, middlename = ?, lastname = ?, full_name = ?, email = ?, municipality = ?, postal = ?, country = ?, street = ?, passw = ?, security_q1 = ?, security_a1 = ?, security_q2 = ?, security_a2 = ?, security_q3 = ?, security_a3 = ?, must_complete_setup = 0 WHERE id = ?');
            $update->bind_param('sssssssssssssssssi', $username, $personal['firstname'], $middle_name, $personal['lastname'], $full_name, $personal['email'], $personal['city'], $personal['postal_code'], $personal['country'], $personal['address'], $password_hash, $security_q1, $answer1_hash, $security_q2, $answer2_hash, $security_q3, $answer3_hash, $user_id);
            if (!$update->execute()) {
                throw new RuntimeException('Account update failed.');
            }
            $profile_update = $conn->prepare('INSERT INTO user_profiles (user_id, phone, address, city, country, postal_code) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE phone = VALUES(phone), address = VALUES(address), city = VALUES(city), country = VALUES(country), postal_code = VALUES(postal_code)');
            $profile_update->bind_param('isssss', $user_id, $personal['phone'], $personal['address'], $personal['city'], $personal['country'], $personal['postal_code']);
            if (!$profile_update->execute() || !$conn->commit()) {
                throw new RuntimeException('Profile update failed.');
            }
            unset($_SESSION['account_setup_pending']);
            if ($account['role'] === 'customer') {
                $_SESSION['user_fname'] = $personal['firstname'];
                $_SESSION['user_email'] = $personal['email'];
            } else {
                $_SESSION['admin_username'] = $username;
                $_SESSION['admin_full_name'] = $full_name;
                $_SESSION['admin_email'] = $personal['email'];
            }
            $destination = $is_customer ? 'account_setup.php?complete=1' : ($account['role'] === 'super_admin' ? 'admin_dashboard.php' : 'unified_admin_dashboard.php');
            header('Location: ' . $destination);
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Account setup save failed: ' . $e->getMessage());
            $error_message = 'Unable to update your account. Please try again.';
        }
    }
}
function available_customer_username(mysqli $conn, string $lastName, string $firstName, int $userId): string
{
    $part = static function (string $name, string $fallback): string {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $clean = preg_replace('/[^a-z]/', '', strtolower($ascii === false ? '' : $ascii));
        return $clean !== '' ? $clean : $fallback;
    };
    $last = $part($lastName, 'last');
    $first = $part($firstName, 'first');
    $check = $conn->prepare('SELECT 1 FROM infos WHERE username = ? AND id <> ? LIMIT 1');
    $candidate = '';
    $check->bind_param('si', $candidate, $userId);
    for ($number = 1; ; $number++) {
        $suffix = $number === 1 ? '' : '_' . $number;
        $partLimit = 29 - strlen($suffix);
        $lastPart = substr($last, 0, $partLimit - 1);
        $firstPart = substr($first, 0, $partLimit - strlen($lastPart));
        $candidate = $lastPart . '_' . $firstPart . $suffix;
        if (strlen($candidate) < 4) {
            continue;
        }
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $check->close();
            return $candidate;
        }
    }
}

function setup_field_error(array $errors, string $field): string
{
    $message = $errors[$field] ?? '';
    return '<small id="' . $field . '-error" class="field-error"' . ($message === '' ? ' hidden' : '')
        . '>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</small>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Account Setup | CSUCC FIND</title>
    <style>
        :root { --ink: #173229; --muted: #64726d; --green: #0b563a; --gold: #d9b64a; --line: #dce7e1; --mist: #f4f7f5; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; color: var(--ink); background: linear-gradient(135deg, #eef7f1, #fff9e8); font-family: Arial, sans-serif; }
        .panel { width: min(900px, 100%); padding: 34px; background: #fff; border: 1px solid var(--line); border-top: 5px solid var(--green); box-shadow: 0 18px 50px rgba(23, 50, 41, .12); }
        h1 { margin: 0 0 8px; font-size: 28px; } p { color: var(--muted); line-height: 1.5; }
        .notice { padding: 14px 16px; margin: 20px 0; background: #fff8df; border-left: 4px solid var(--gold); }
        .error { padding: 12px 14px; color: #8a1c1c; background: #fff0f0; border-left: 4px solid #c33; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; } .full { grid-column: 1 / -1; }
        label { display: block; margin: 12px 0 6px; font-weight: 700; font-size: 14px; } input, select, textarea { width: 100%; padding: 11px 12px; border: 1px solid #cbd8d1; border-radius: 4px; font: inherit; background: #fff; }
        button { margin-top: 24px; padding: 12px 18px; border: 0; border-radius: 4px; color: #fff; background: var(--green); font-weight: 700; cursor: pointer; } button:hover { background: #073d2a; }
        .section-title { grid-column: 1 / -1; margin: 18px 0 0; padding-top: 18px; border-top: 1px solid var(--line); font-size: 17px; }
        textarea { resize: vertical; }
        .password-field { position: relative; }
        .password-field input { padding-right: 70px; }
        .password-toggle { position: absolute; top: 50%; right: 4px; transform: translateY(-50%); margin: 0; padding: 10px; color: var(--green); background: transparent; }
        .required-mark { color: #a12626; }
        [aria-invalid="true"] { border-color: #a12626; }
        .field-hint { display: block; margin-top: 4px; color: var(--muted); }
        .field-error[hidden] { display: none; }
        .field-error { display: block; margin-top: 4px; color: #a12626; }
        .password-toggle:hover { background: var(--mist); }
        .password-toggle:focus-visible { outline: 2px solid var(--green); outline-offset: 1px; }
        @media (max-width: 600px) { .panel { padding: 24px 18px; } .grid { grid-template-columns: 1fr; } .full { grid-column: auto; } }
    </style>
</head>
<body>
<main class="panel">
<?php if ($show_completion): ?>
    <h1>Account setup complete</h1>
    <p>Use <strong><?= htmlspecialchars($account['username'], ENT_QUOTES, 'UTF-8') ?></strong> and your new password to sign in from now on.</p>
    <a href="Dashboard.php">Continue to dashboard</a>
<?php else: ?>
    <h1><?= trim($account['firstname'] ?? '') !== '' ? 'Welcome, ' . htmlspecialchars($account['firstname']) . '.' : 'Welcome! Complete your account.' ?></h1>
    <p>Your account was created by an administrator with a temporary password. For your security, complete this setup before using your account.</p>
    <div class="notice">Fill in your personal information and address, choose a new password, and answer all three security questions. Your answers are protected and can help recover your account later.</div>
    <?php if ($error_message): ?><div class="error"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <p id="setup-validation-note">Fields marked with an asterisk (*) are required. Check the guidance beside each field before saving.</p>
    <form method="post" autocomplete="off" aria-describedby="setup-validation-note">
        <input type="hidden" name="setup_token" value="<?= htmlspecialchars($_SESSION['setup_form_token']) ?>">
        <div class="grid">
            <h2 class="section-title">Personal Information</h2>
            <div><label for="firstname">First Name <span class="required-mark" aria-hidden="true">*</span></label><input id="firstname" name="firstname" aria-describedby="firstname-hint" type="text" maxlength="<?= $personal_limits['firstname'] ?>" value="<?= htmlspecialchars($personal['firstname'], ENT_QUOTES, 'UTF-8') ?>" required><small class="field-hint" id="firstname-hint">Use your real first name. Letters, spaces, periods, apostrophes, and hyphens are accepted.</small><?= setup_field_error($field_errors, 'firstname') ?></div>
            <div>
                <label for="middle_initial">Middle Initial (optional)</label>
                <input id="middle_initial" name="middle_initial" aria-describedby="middle_initial-hint" type="text" maxlength="4"
                    value="<?= htmlspecialchars($middle_initial, ENT_QUOTES, 'UTF-8') ?>">
                <small class="field-hint" id="middle_initial-hint">Enter one letter, with or without a period.</small><?= setup_field_error($field_errors, 'middle_initial') ?>
            </div>
            <div><label for="lastname">Last Name <span class="required-mark" aria-hidden="true">*</span></label><input id="lastname" name="lastname" aria-describedby="lastname-hint" type="text" maxlength="<?= $personal_limits['lastname'] ?>" value="<?= htmlspecialchars($personal['lastname'], ENT_QUOTES, 'UTF-8') ?>" required><small class="field-hint" id="lastname-hint">Use your real last name.</small><?= setup_field_error($field_errors, 'lastname') ?></div>
            <div class="full">
                <?php if ($is_customer): ?>
                    <label for="username">Username</label>
                    <input id="username" type="text" readonly aria-describedby="username-help" placeholder="lastname_firstname">
                    <small id="username-help">Generated from your last and first names. If it is taken, a number will be added.</small>
                <?php else: ?>
                <label for="username">Username <span class="required-mark" aria-hidden="true">*</span></label>
                <input id="username" name="username" type="text" autocomplete="username" aria-describedby="username-help"
                    value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required>
                <?= setup_field_error($field_errors, 'username') ?>
                <small id="username-help">Keep this username or choose a new one using 4 to 30 letters, numbers, or underscores.</small>
                <?php endif; ?>
            </div>
            <div><label for="email">Email Address (optional)</label><input id="email" name="email" aria-describedby="email-hint" type="email" maxlength="<?= $personal_limits['email'] ?>" value="<?= htmlspecialchars($personal['email'], ENT_QUOTES, 'UTF-8') ?>"><small class="field-hint" id="email-hint">Use a unique email address you can access for password recovery.</small><?= setup_field_error($field_errors, 'email') ?></div>
            <div><label for="phone">Phone Number (optional)</label><input id="phone" name="phone" aria-describedby="phone-hint" type="tel" inputmode="tel" maxlength="<?= $personal_limits['phone'] ?>" value="<?= htmlspecialchars($personal['phone'], ENT_QUOTES, 'UTF-8') ?>"><small class="field-hint" id="phone-hint">Enter 7 to 15 digits. A country code may start with +.</small><?= setup_field_error($field_errors, 'phone') ?></div>
            <h2 class="section-title">Address</h2>
            <div><label for="city">City <span class="required-mark" aria-hidden="true">*</span></label><input id="city" name="city" type="text" maxlength="<?= $personal_limits['city'] ?>" value="<?= htmlspecialchars($personal['city'], ENT_QUOTES, 'UTF-8') ?>" required><?= setup_field_error($field_errors, 'city') ?></div>
            <div><label for="postal_code">Postal Code <span class="required-mark" aria-hidden="true">*</span></label><input id="postal_code" name="postal_code" aria-describedby="postal_code-hint" type="text" maxlength="<?= $personal_limits['postal_code'] ?>" value="<?= htmlspecialchars($personal['postal_code'], ENT_QUOTES, 'UTF-8') ?>" required><small class="field-hint" id="postal_code-hint">Use four digits for a Philippine address.</small><?= setup_field_error($field_errors, 'postal_code') ?></div>
            <div><label for="country">Country <span class="required-mark" aria-hidden="true">*</span></label><input id="country" name="country" type="text" maxlength="<?= $personal_limits['country'] ?>" value="<?= htmlspecialchars($personal['country'], ENT_QUOTES, 'UTF-8') ?>" required><?= setup_field_error($field_errors, 'country') ?></div>
            <div class="full"><label for="address">Street Address <span class="required-mark" aria-hidden="true">*</span></label><textarea id="address" name="address" rows="3" maxlength="250" required><?= htmlspecialchars($personal['address'], ENT_QUOTES, 'UTF-8') ?></textarea><?= setup_field_error($field_errors, 'address') ?></div>
            <h2 class="section-title">Account Security</h2>

            <div>
                <label for="new_password">New password <span class="required-mark" aria-hidden="true">*</span></label>
                <div class="password-field">
                    <input id="new_password" name="new_password" aria-describedby="new_password-hint" type="password" minlength="8" maxlength="15" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])[!-~]{8,15}" required>
                    <button type="button" class="password-toggle" aria-controls="new_password" aria-label="Show new password" aria-pressed="false" data-password-label="new password">Show</button>
                </div>
                <small class="field-hint" id="new_password-hint">Use 8 to 15 characters, including uppercase and lowercase letters and a number. Leave out spaces.</small><?= setup_field_error($field_errors, 'new_password') ?>
            </div>
            <div>
                <label for="confirm_password">Confirm new password <span class="required-mark" aria-hidden="true">*</span></label>
                <div class="password-field">
                    <input id="confirm_password" name="confirm_password" aria-describedby="confirm_password-hint" type="password" minlength="8" maxlength="15" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])[!-~]{8,15}" required>
                    <button type="button" class="password-toggle" aria-controls="confirm_password" aria-label="Show confirmed password" aria-pressed="false" data-password-label="confirmed password">Show</button>
                </div>
                <small class="field-hint" id="confirm_password-hint">Enter the new password again.</small><?= setup_field_error($field_errors, 'confirm_password') ?>
            </div>
            <small class="full field-hint" id="security-questions-hint">Choose three different questions and enter an answer for each. Answers are case-sensitive.</small>
            <?php for ($i = 1; $i <= 3; $i++): ?>
                <div><label for="security_q<?php echo $i; ?>">Security question <?php echo $i; ?> <span class="required-mark" aria-hidden="true">*</span></label><select id="security_q<?php echo $i; ?>" name="security_q<?php echo $i; ?>" aria-describedby="security-questions-hint" required><option value="">Choose a question</option><?php foreach ($questions as $question): ?><option value="<?php echo htmlspecialchars($question); ?>" <?php echo (($_POST['security_q' . $i] ?? '') === $question) ? 'selected' : ''; ?>><?php echo htmlspecialchars($question); ?></option><?php endforeach; ?></select><?= setup_field_error($field_errors, 'security_q' . $i) ?></div>
                <div>
                    <label for="security_a<?= $i ?>">Answer <?= $i ?> <span class="required-mark" aria-hidden="true">*</span></label>
                    <div class="password-field">
                        <input id="security_a<?= $i ?>" name="security_a<?= $i ?>" type="password" autocomplete="off" maxlength="72" aria-describedby="security-questions-hint" required>
                        <button type="button" class="password-toggle" aria-controls="security_a<?= $i ?>"
                            aria-label="Show security answer <?= $i ?>" aria-pressed="false" data-password-label="security answer <?= $i ?>">Show</button>
                    </div>
                    <?= setup_field_error($field_errors, 'security_a' . $i) ?>
                </div>
            <?php endfor; ?>
        </div>
        <button type="submit">Update My Account</button>
    </form>
<?php endif; ?>
</main>
<?php if (!$show_completion): ?>
<script>
const setupForm = document.querySelector('main form');
const {firstname, middle_initial, lastname, phone, postal_code, country, new_password, confirm_password} = setupForm.elements;
const validName = /^(?=.*\p{L})[\p{L}\p{M} .\u2019'-]+$/u;

const usernamePreview = document.getElementById('username');
if (usernamePreview.readOnly) {
    const namePart = value => value.normalize('NFKD').toLowerCase().replace(/[^a-z]/g, '');
    const updateUsername = () => {
        const last = namePart(lastname.value);
        const first = namePart(firstname.value);
        const lastPart = last.slice(0, 28);
        usernamePreview.value = last && first ? lastPart + '_' + first.slice(0, 29 - lastPart.length) : '';
    };
    firstname.addEventListener('input', updateUsername);
    lastname.addEventListener('input', updateUsername);
    updateUsername();
}

const savedUsername = <?= json_encode($account['username'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function validateSetupInput() {
    if (!usernamePreview.readOnly) {
        const username = usernamePreview.value.trim();
        usernamePreview.setCustomValidity(username === savedUsername || /^[A-Za-z0-9_]{4,30}$/.test(username)
            ? '' : 'Use 4 to 30 letters, numbers, or underscores.');
    }

    for (const field of [firstname, lastname]) {
        field.setCustomValidity(validName.test(field.value.trim()) ? '' : 'Enter a name using letters, spaces, periods, apostrophes, or hyphens.');
    }

    const initial = middle_initial.value.trim();
    middle_initial.setCustomValidity(!initial || /^\p{L}\p{M}*\.?$/u.test(initial) ? '' : 'Enter one letter for your middle initial.');

    const number = phone.value.trim();
    const digits = number.replace(/[^0-9]/g, '').length;
    phone.setCustomValidity(!number || (/^\+?[0-9 ()-]+$/.test(number) && digits >= 7 && digits <= 15)
        ? '' : 'Enter a phone number with 7 to 15 digits.');

    const postal = postal_code.value.trim();
    const postalPattern = country.value.trim().toLowerCase() === 'philippines' ? /^[0-9]{4}$/ : /^[A-Za-z0-9][A-Za-z0-9 -]{0,19}$/;
    postal_code.setCustomValidity(!postal || postalPattern.test(postal) ? '' : 'Enter a valid postal code.');

    confirm_password.setCustomValidity(!confirm_password.value || confirm_password.value === new_password.value
        ? '' : 'The new passwords do not match.');
}
const questionSelects = Array.from(document.querySelectorAll('select[name^="security_q"]'));
const questionOptions = questionSelects.map(select =>
    Array.from(select.options, option => ({ value: option.value, text: option.text }))
);

function updateQuestionChoices() {
    const selectedQuestions = questionSelects.map(select => select.value);
    questionSelects.forEach((select, index) => {
        const selected = selectedQuestions[index];
        const usedElsewhere = new Set(selectedQuestions.filter((value, otherIndex) => otherIndex !== index && value));
        const available = questionOptions[index].filter(option =>
            !option.value || option.value === selected || !usedElsewhere.has(option.value)
        );
        select.replaceChildren(...available.map(option =>
            new Option(option.text, option.value, false, option.value === selected)
        ));
        select.setCustomValidity(selected && usedElsewhere.has(selected) ? 'Choose three different security questions.' : '');
    });
}

const setupFields = [...setupForm.querySelectorAll('input:not([type="hidden"]):not([readonly]), select, textarea')];
const checkedFields = new Set();
const serverErrors = new Map();
const textEncoder = new TextEncoder();

function showFieldError(field, message) {
    const error = document.getElementById(field.id + '-error');
    if (!error) return;
    error.textContent = message;
    error.hidden = !message;
    field.setAttribute('aria-invalid', String(Boolean(message)));
    const describedBy = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== error.id);
    if (message) describedBy.push(error.id);
    if (describedBy.length) field.setAttribute('aria-describedby', describedBy.join(' '));
    else field.removeAttribute('aria-describedby');
}

for (const field of setupFields) {
    const message = document.getElementById(field.id + '-error')?.textContent.trim();
    if (message) {
        serverErrors.set(field, message);
        showFieldError(field, message);
    }
}

function checkSetupFields() {
    validateSetupInput();
    updateQuestionChoices();
    for (const field of setupFields) {
        const value = field.value.trim();
        let message = '';
        if (field.required && !value) {
            message = 'This field is required.';
        } else if (value && field.maxLength > 0 && textEncoder.encode(value).length > field.maxLength
            && field.name !== 'middle_initial') {
            message = 'Shorten this value to fit the field limit.';
        } else if (field.name.startsWith('security_a') && /\p{Cc}/u.test(value)) {
            message = 'Remove control characters from the answer.';
        } else if (!field.validity.valid) {
            message = field.validity.patternMismatch && field.name.endsWith('password')
                ? 'Use 8 to 15 characters with uppercase and lowercase letters and a number, without spaces.'
                : field.validationMessage;
        }
        if (checkedFields.has(field)) showFieldError(field, serverErrors.get(field) || message);
    }
}

setupForm.noValidate = true;
setupForm.addEventListener('input', event => {
    if (!setupFields.includes(event.target)) return;
    checkedFields.add(event.target);
    serverErrors.delete(event.target);
    if (event.target === new_password) serverErrors.delete(confirm_password);
    if (questionSelects.includes(event.target)) questionSelects.forEach(field => serverErrors.delete(field));
    checkSetupFields();
});
setupForm.addEventListener('focusout', event => {
    if (!setupFields.includes(event.target)) return;
    checkedFields.add(event.target);
    checkSetupFields();
});
setupForm.addEventListener('submit', event => {
    setupFields.forEach(field => checkedFields.add(field));
    checkSetupFields();
    const invalid = setupFields.find(field => field.getAttribute('aria-invalid') === 'true');
    if (invalid) {
        event.preventDefault();
        invalid.focus();
        return;
    }
    const submit = setupForm.querySelector('button[type="submit"]');
    if (submit.disabled) {
        event.preventDefault();
        return;
    }
    submit.disabled = true;
    submit.textContent = 'Saving account...';
});

questionSelects.forEach(select => select.addEventListener('change', updateQuestionChoices));
window.addEventListener('pageshow', () => {
    updateQuestionChoices();
    const submit = setupForm.querySelector('button[type="submit"]');
    submit.disabled = false;
    submit.textContent = 'Update My Account';
});
updateQuestionChoices();

document.querySelectorAll('.password-toggle').forEach(button => {
    button.addEventListener('click', function() {
        const input = document.getElementById(this.getAttribute('aria-controls'));
        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';
        this.textContent = showPassword ? 'Hide' : 'Show';
        this.setAttribute('aria-pressed', String(showPassword));
        this.setAttribute('aria-label', `${showPassword ? 'Hide' : 'Show'} ${this.dataset.passwordLabel}`);
    });
});
</script>
<?php endif; ?>
</body>
</html>
