<?php
require __DIR__ . '/includes/user_auth.php';
send_user_headers();
user_session_start();
capture_first_touch();

if (current_user()) { redirect('account.php'); }

$message = '';
$msgType = 'error';
$email = '';
$remember = true;
if (isset($_GET['logout'])) { $message = 'You have been signed out.'; $msgType = 'success'; }
if (isset($_GET['registered'])) { $message = 'Your account is ready. Please sign in.'; $msgType = 'success'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = client_ip();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);
    $msgType = 'error';

    if (!csrf_check()) {
        $message = 'Your session expired. Please refresh the page and try again.';
    } elseif (user_login_rate_limited($ip)) {
        $message = 'Too many failed attempts. Please try again in 15 minutes.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '' || strlen($pass) > 256) {
        $message = 'Please enter a valid email address and password.';
    } else {
        $user = q('SELECT id, password_hash, status FROM users WHERE email = ? LIMIT 1', [$email])->fetch();
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($pass, $hash);

        if ($user && $ok && $user['status'] === 'blocked') {
            log_user_attempt($ip, $email, false);
            $message = 'This account has been suspended. Please contact support.';
        } elseif ($user && $ok) {
            log_user_attempt($ip, $email, true);
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
            }
            session_regenerate_id(true);
            start_user_session((int)$user['id'], null, $remember);
            redirect('account.php');
        } else {
            log_user_attempt($ip, $email, false);
            $message = 'Incorrect email or password.';
        }
    }
}

auth_page_start('Sign In', 'Sign in to your Smart Prediction Hub account.', ['label' => 'Register', 'href' => 'register.php']);
?>
<section class="auth-section">
    <div class="auth-wrap">
        <div class="auth-panel">
            <span class="auth-kicker"><i class="fa-solid fa-chart-line"></i> SMART PREDICTION HUB</span>
            <h1>Welcome back to your market workspace.</h1>
            <p>Sign in to keep your platform research, saved reading and market tools together in one place.</p>
            <div class="auth-benefits">
                <div class="auth-benefit"><i class="fa-solid fa-bookmark"></i><span>Save platform reviews and guides for later.</span></div>
                <div class="auth-benefit"><i class="fa-solid fa-scale-balanced"></i><span>Keep track of platforms you want to compare.</span></div>
                <div class="auth-benefit"><i class="fa-solid fa-bell"></i><span>Get useful updates without losing your place.</span></div>
            </div>
            <div class="auth-mini-card"><small>SMARTER RESEARCH</small><strong>One account. Your saved prediction-market research.</strong></div>
        </div>

        <div class="auth-form-card">
            <div class="auth-form-head">
                <div>
                    <h2>Sign in</h2>
                    <p>Enter your account details to continue.</p>
                </div>
                <a class="auth-switch" href="register.php">Create account →</a>
            </div>

            <?= auth_message($message, $msgType) ?>

            <form id="loginForm" method="post" action="login.php" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="loginEmail">Email address</label>
                    <div class="input-wrap"><i class="fa-regular fa-envelope"></i>
                        <input id="loginEmail" name="email" type="email" placeholder="you@example.com" autocomplete="email" maxlength="190" required value="<?= e($email) ?>" <?= $email === '' ? 'autofocus' : '' ?>>
                    </div>
                </div>
                <div class="form-group">
                    <label for="loginPassword"><span>Password</span><a href="/coming-soon.html">Forgot password?</a></label>
                    <div class="input-wrap"><i class="fa-solid fa-lock"></i>
                        <input id="loginPassword" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" maxlength="256" required <?= $email !== '' ? 'autofocus' : '' ?>>
                        <button type="button" class="password-toggle" data-target="loginPassword" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                    </div>
                </div>
                <label class="check-row" style="margin:2px 0 20px"><input type="checkbox" name="remember" value="1" <?= $remember ? 'checked' : '' ?>><span>Keep me signed in on this device.</span></label>
                <button class="auth-submit" type="submit">Sign in <i class="fa-solid fa-arrow-right"></i></button>
            </form>

            <div class="auth-divider"><span>OR CONTINUE WITH</span></div>
            <div class="social-auth">
                <a href="/coming-soon.html"><i class="fa-brands fa-google"></i> Google</a>
            </div>
            <div class="form-note">Don’t have an account? <a href="register.php">Create one here</a>.</div>
        </div>
    </div>
</section>
<?php auth_page_end();
