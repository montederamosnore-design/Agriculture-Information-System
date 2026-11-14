<?php
/*
 * index.php
 * The home address of the project. It only sends the visitor to the right page.
 */
require_once __DIR__ . '/includes/auth.php';

redirect(is_logged_in() ? 'dashboard.php' : 'login.php');