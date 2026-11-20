# Smart Prediction Hub — Admin Panel & User Accounts

## Folder structure (upload into public_html)
```
public_html/
├── app/     → config, database, helpers (blocked from the browser)
├── admin/   → admin panel        (yourdomain.com/admin/)
└── user/    → register / sign in / account (yourdomain.com/user/)
```

## Install / upgrade
1. Upload all three folders (replace any old `admin/` folder).
2. Make sure `app/sessions/` is writable (755).
3. Open `yourdomain.com/admin/setup.php` and enter the setup key (`SETUP_KEY` in `app/config.php`).
   - An existing v1 admin is automatically promoted to **Super Admin**.
   - On a fresh install, create the Super Admin on this screen.
4. **Delete `setup.php` from the server.**

## Track where users come from
Add this before `</body>` on every page of your site:
```html
<script src="/user/assets/track.js" defer></script>
```
Link your site's buttons to `/user/register.php` and `/user/login.php`.

## Get the signed-in user on your own PHP pages
```php
require $_SERVER['DOCUMENT_ROOT'] . '/user/includes/user_auth.php';
$user = current_user(); // null = signed out
```

## Roles & permissions
- **Super Admin** — full access, plus managing admins and roles.
- **Admin** — gets its role's permissions, or "Custom for this admin".
- To add a new permission: add it in `admin/includes/permissions.php`, then call `require_perm('key')` on the page.
