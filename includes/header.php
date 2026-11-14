<?php
/*
 * includes/header.php
 * The top part of every page after login: HTML head, sidebar and page title.
 *
 * Before including this file, set these variables in your page:
 *     $page_title    = 'Dashboard';
 *     $page_subtitle = 'Short text under the title';   (optional)
 *     $active_page   = 'dashboard';                    (highlights the sidebar link)
 */
require_once __DIR__ . '/auth.php';
require_login();   // not logged in? -> sent to login.php

$page_title    = $page_title    ?? 'Agriculture Information System';
$page_subtitle = $page_subtitle ?? '';
$active_page   = $active_page   ?? '';
$flash         = get_flash();   // one-time message, e.g. "Farmer added."
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> | Agriculture Information System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="app-layout">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <h1><?= e($page_title) ?></h1>
            <?php if ($page_subtitle !== ''): ?>
                <p><?= e($page_subtitle) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="alert">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>