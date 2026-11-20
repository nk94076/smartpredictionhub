<?php
/**
 * All permissions are defined here. When you add a feature, add its permission here
 * and call require_perm('key') on the page — it appears automatically on the Role/Admin edit screens.
 * Note: only a Super Admin can manage admins and roles.
 */
const PERMISSIONS = [
    'Blog & News' => [
        'posts.write'       => ['Write posts', 'Create blog/news posts and edit their own drafts (sent for review)'],
        'posts.publish'     => ['Publish posts', 'Publish, unpublish and delete any post, and edit everyone\'s posts'],
        'posts.categories'  => ['Manage categories', 'Create, rename and delete blog/news categories'],
        'media.manage'      => ['Media library', 'Upload and delete images'],
    ],
    'Website' => [
        'pages.edit'        => ['Edit pages', 'Edit page content, header & footer, create pages (full HTML — give only to trusted admins)'],
        'seo.manage'        => ['SEO & redirects', 'Edit page titles/descriptions and manage URL redirects'],
        'settings.manage'   => ['Site settings', 'Announcement bar, social links, tracking scripts and other site-wide settings'],
        'markets.manage'    => ['Manage markets', 'Hide, pin or rename live markets, turn platforms on/off and refresh data'],
    ],
    'Users' => [
        'users.view'        => ['View users', 'View the user list and full profiles'],
        'users.edit'        => ['Manage users', 'Block / unblock users and force sign-out'],
        'users.delete'      => ['Delete users', 'Permanently delete user accounts'],
        'users.impersonate' => ['Login as user', 'Sign in to a user\'s account with "Login as"'],
        'users.export'      => ['Export users', 'Download user data as CSV'],
    ],
    'Logs & Activity' => [
        'sessions.view'     => ['User login logs', 'View user sign-in / sign-out logs'],
        'audit.view'        => ['Admin activity logs', 'View the log of actions taken by admins'],
    ],
    'Security' => [
        'security.view'     => ['View blocked IPs', 'View blocked IPs and failed attempts'],
        'security.manage'   => ['Unblock IPs', 'Manually unblock a blocked IP'],
    ],
];

function all_perm_keys(): array
{
    $keys = [];
    foreach (PERMISSIONS as $group) { $keys = array_merge($keys, array_keys($group)); }
    return $keys;
}

function perm_label(string $key): string
{
    foreach (PERMISSIONS as $group) {
        if (isset($group[$key])) { return $group[$key][0]; }
    }
    return $key;
}

/** Keep only valid permission keys from submitted form data */
function sanitize_perms($input): array
{
    $valid = all_perm_keys();
    return array_values(array_intersect($valid, is_array($input) ? $input : []));
}
