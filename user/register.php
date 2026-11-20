<?php
require __DIR__ . '/includes/user_auth.php';
send_user_headers();
user_session_start();
capture_first_touch();

if (current_user()) { redirect('account.php'); }

$message = '';
$v = ['first' => '', 'last' => '', 'email' => '', 'updates' => false, 'terms' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = client_ip();
    $v['first'] = trim((string)($_POST['first_name'] ?? ''));
    $v['last'] = trim((string)($_POST['last_name'] ?? ''));
    $v['email'] = strtolower(trim((string)($_POST['email'] ?? '')));
    $v['updates'] = !empty($_POST['updates']);
    $v['terms'] = !empty($_POST['terms']);
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password_confirm'] ?? '');

    if (!csrf_check()) {
        $message = 'Your session expired. Please refresh the page and try again.';
    } elseif (($_POST['website'] ?? '') !== '') {
        $message = 'Registration failed. Please try again.';
    } elseif (registration_rate_limited($ip)) {
        $message = 'Too many accounts were created from this network. Please try again later.';
    } elseif ($v['first'] === '' || $v['last'] === '' || mb_strlen($v['first']) > 50 || mb_strlen($v['last']) > 50) {
        $message = 'Please enter your first and last name.';
    } elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) {
        $message = 'Please enter a valid email address.';
    } elseif (strlen($pass) < 8 || strlen($pass) > 256) {
        $message = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $message = 'Passwords do not match.';
    } elseif (!$v['terms']) {
        $message = 'Please accept the Terms and Privacy Policy.';
    } elseif (q('SELECT COUNT(*) FROM users WHERE email = ?', [$v['email']])->fetchColumn()) {
        $message = 'An account with this email already exists. Please sign in instead.';
    } else {
        $geo = geo_lookup($ip);
        $ua = parse_ua($_SERVER['HTTP_USER_AGENT'] ?? '');
        $src = resolve_source();

        q('INSERT INTO users (name, email, password_hash, marketing_opt_in, reg_ip, reg_country, reg_country_code, reg_region, reg_city,
                              reg_device, reg_browser, reg_os, reg_user_agent, reg_source, reg_medium, reg_campaign, reg_referrer, reg_landing_page)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $v['first'] . ' ' . $v['last'], $v['email'], password_hash($pass, PASSWORD_DEFAULT), $v['updates'] ? 1 : 0,
            $ip, $geo['country'], $geo['country_code'], $geo['region'], $geo['city'],
            $ua['device'], $ua['browser'], $ua['os'], $ua['ua'],
            $src['source'], $src['medium'], $src['campaign'], $src['referrer'], $src['landing'],
        ]);
        $uid = (int)db()->lastInsertId();
        session_regenerate_id(true);
        start_user_session($uid);
        redirect('account.php?welcome=1');
    }
}

auth_page_start('Create Account', 'Create your Smart Prediction Hub account.', ['label' => 'Login', 'href' => 'login.php']);
?>
<section class="auth-section">
    <div class="auth-wrap">
        <div class="auth-panel">
            <span class="auth-kicker"><i class="fa-solid fa-wand-magic-sparkles"></i> JOIN SMART PREDICTION HUB</span>
            <h1>Build your own prediction-market research hub.</h1>
            <p>Create an account to save useful pages, follow platform updates and keep your research organized as Smart Prediction Hub grows.</p>
            <div class="auth-benefits">
                <div class="auth-benefit"><i class="fa-solid fa-bookmark"></i><span>Save reviews, guides and comparison pages.</span></div>
                <div class="auth-benefit"><i class="fa-solid fa-layer-group"></i><span>Keep a shortlist of platforms you are researching.</span></div>
                <div class="auth-benefit"><i class="fa-solid fa-envelope-open-text"></i><span>Choose the updates you want to receive.</span></div>
            </div>
            <div class="auth-mini-card"><small>FREE ACCOUNT</small><strong>Start with the essentials. No payment details required.</strong></div>
        </div>

        <div class="auth-form-card">
            <div class="auth-form-head">
                <div>
                    <h2>Create your account</h2>
                    <p>Set up your Smart Prediction Hub profile.</p>
                </div>
                <a class="auth-switch" href="login.php">Already a member? Sign in →</a>
            </div>

            <?= auth_message($message) ?>

            <form id="signupForm" method="post" action="register.php" novalidate>
                <?= csrf_field() ?>
                <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
                <div class="form-grid">
                    <div class="form-group"><label for="firstName">First name</label>
                        <div class="input-wrap"><i class="fa-regular fa-user"></i><input id="firstName" name="first_name" type="text" placeholder="First name" autocomplete="given-name" maxlength="50" required value="<?= e($v['first']) ?>"></div>
                    </div>
                    <div class="form-group"><label for="lastName">Last name</label>
                        <div class="input-wrap"><i class="fa-regular fa-user"></i><input id="lastName" name="last_name" type="text" placeholder="Last name" autocomplete="family-name" maxlength="50" required value="<?= e($v['last']) ?>"></div>
                    </div>
                </div>
                <div class="form-group"><label for="signupEmail">Email address</label>
                    <div class="input-wrap"><i class="fa-regular fa-envelope"></i><input id="signupEmail" name="email" type="email" placeholder="you@example.com" autocomplete="email" maxlength="190" required value="<?= e($v['email']) ?>"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label for="signupPassword">Password</label>
                        <div class="input-wrap"><i class="fa-solid fa-lock"></i><input id="signupPassword" name="password" type="password" placeholder="Minimum 8 characters" autocomplete="new-password" minlength="8" maxlength="256" required>
                            <button type="button" class="password-toggle" data-target="signupPassword" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
                    </div>
                    <div class="form-group"><label for="confirmPassword">Confirm password</label>
                        <div class="input-wrap"><i class="fa-solid fa-shield-halved"></i><input id="confirmPassword" name="password_confirm" type="password" placeholder="Repeat password" autocomplete="new-password" minlength="8" maxlength="256" required>
                            <button type="button" class="password-toggle" data-target="confirmPassword" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
                    </div>
                </div>
                <label class="check-row" style="margin-bottom:12px"><input type="checkbox" id="termsCheck" name="terms" value="1" required <?= $v['terms'] ? 'checked' : '' ?>><span>I agree to the <a href="/coming-soon">Terms</a> and <a href="/coming-soon">Privacy Policy</a>.</span></label>
                <label class="check-row" style="margin-bottom:20px"><input type="checkbox" name="updates" value="1" <?= $v['updates'] ? 'checked' : '' ?>><span>Send me occasional platform and market research updates.</span></label>
                <button class="auth-submit" type="submit">Create account <i class="fa-solid fa-arrow-right"></i></button>
            </form>

            <div class="auth-divider"><span>OR SIGN UP WITH</span></div>
            <div class="social-auth">
                <a href="/coming-soon"><i class="fa-brands fa-google"></i> Google</a>
            </div>
            <div class="form-note">Already have an account? <a href="login.php">Sign in</a>.</div>
        </div>
    </div>
</section>
<?php auth_page_end();
