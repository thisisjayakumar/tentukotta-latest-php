<?php
/**
 * Whitelist for the generic admin CRUD endpoints (`admin/delete_record`,
 * `admin/update_status`).
 *
 * SECURITY-AUDIT.md CRIT-01: these endpoints accepted an arbitrary table and
 * column name from the request, letting an authenticated (or, before Task 3,
 * an anonymous) caller delete or rewrite any row in any table, including
 * `user`, `config`, and `ci_sessions`.
 *
 * Every entry below must be an explicit, intentional admin-mutable table.
 * Never add: `config`, `ci_sessions`, `keys`, `logs`, `rest_logins`,
 * `temp_config`. `user` is present below for the existing status/delete UI
 * only; never widen it beyond the single `status` column.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$config['admin_tables'] = [

    // --- Content ---
    'videos' => [
        'pk'      => 'videos_id',
        'columns' => ['publication', 'is_paid', 'enable_download', 'featured', 'trailer'],
    ],
    'episodes' => [
        'pk'      => 'episodes_id',
        'columns' => [],
    ],
    'seasons' => [
        'pk'      => 'seasons_id',
        'columns' => [],
    ],
    'video_file' => [
        'pk'      => 'video_file_id',
        'columns' => [],
    ],
    'subtitle' => [
        'pk'      => 'subtitle_id',
        'columns' => [],
    ],
    'tvseries_subtitle' => [
        'pk'      => 'tvseries_subtitle_id',
        'columns' => [],
    ],
    'download_link' => [
        'pk'      => 'download_link_id',
        'columns' => [],
    ],
    'episode_download_link' => [
        'pk'      => 'episode_download_link_id',
        'columns' => [],
    ],
    'live_tv' => [
        'pk'      => 'live_tv_id',
        'columns' => ['featured', 'publish'],
    ],
    'live_tv_category' => [
        'pk'      => 'live_tv_category_id',
        'columns' => [],
    ],
    'slider' => [
        'pk'      => 'slider_id',
        'columns' => ['publish'],
    ],
    'homepage_sections' => [
        'pk'      => 'id',
        'columns' => ['publish'],
    ],

    // --- Taxonomy ---
    'genre' => [
        'pk'      => 'genre_id',
        'columns' => [],
    ],
    'country' => [
        'pk'      => 'country_id',
        'columns' => [],
    ],
    'star' => [
        'pk'      => 'star_id',
        'columns' => [],
    ],
    'video_type' => [
        'pk'      => 'video_type_id',
        'columns' => [],
    ],
    'quality' => [
        'pk'      => 'quality_id',
        'columns' => [],
    ],
    'language' => [
        'pk'      => 'language_id',
        'columns' => ['publication'],
    ],

    // --- Editorial ---
    'page' => [
        'pk'      => 'page_id',
        'columns' => ['publish'],
    ],
    'posts' => [
        'pk'      => 'posts_id',
        'columns' => ['publish'],
    ],
    'post_category' => [
        'pk'      => 'post_category_id',
        'columns' => [],
    ],
    'post_comments' => [
        'pk'      => 'post_comments_id',
        'columns' => [],
    ],
    'comments' => [
        'pk'      => 'comments_id',
        'columns' => [],
    ],

    // --- Commerce ---
    'plan' => [
        'pk'      => 'plan_id',
        'columns' => ['status'],
    ],
    'subscription' => [
        'pk'      => 'subscription_id',
        'columns' => ['status'],
    ],

    // --- Support ---
    'request' => [
        'pk'      => 'request_id',
        'columns' => [],
    ],
    'report' => [
        'pk'      => 'report_id',
        'columns' => [],
    ],

    // --- Accounts ---
    // User deletion is an existing admin feature. It is now reachable only
    // behind the central admin gate (Task 3); plaintext identifiers are still
    // whitelisted so the generic endpoint cannot touch `config`, `ci_sessions`
    // or `keys`.
    'user' => [
        'pk'      => 'user_id',
        'columns' => ['status'],
    ],
];
