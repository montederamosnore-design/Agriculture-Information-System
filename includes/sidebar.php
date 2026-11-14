<?php
/*
 * includes/sidebar.php
 * The green navigation menu on the left. It is loaded by header.php.
 * To add a new page to the menu, add one line to the $menu list below.
 */

$user = current_user();

$menu = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => 'dashboard.php'],
    ['key' => 'farmers',   'label' => 'Farmers',   'url' => 'modules/farmers.php'],
    ['key' => 'farms',     'label' => 'Farms',     'url' => 'modules/farms.php'],
    ['key' => 'crops',     'label' => 'Crops',     'url' => 'modules/crops.php'],
    ['key' => 'plantings', 'label' => 'Plantings', 'url' => 'modules/plantings.php'],
    ['key' => 'harvests',  'label' => 'Harvests',  'url' => 'modules/harvests.php'],
    ['key' => 'reports',   'label' => 'Reports',   'url' => 'modules/reports.php'],
];

// Role-based menu: only Administrators see the Users link.
if (is_admin()) {
    $menu[] = ['key' => 'users', 'label' => 'Users', 'url' => 'modules/users.php'];
}
?>
<aside class="sidebar">

    <div class="sidebar-brand">
        Agriculture
        <small>Information System</small>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($menu as $item): ?>
            <a class="sidebar-link <?= ($active_page === $item['key']) ? 'active' : '' ?>"
               href="<?= BASE_URL ?>/<?= e($item['url']) ?>">
                <?= e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-user">
        <div class="fw-semibold"><?= e($user['full_name']) ?></div>
        <div class="sidebar-role"><?= e($user['role_name']) ?></div>

        <form method="POST" action="<?= BASE_URL ?>/logout.php" class="mt-3">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-light btn-sm w-100">Log Out</button>
        </form>
    </div>

</aside>