<?php

$adminItems = [
    'Knowledge' => [
        'Knowledge',
        'knowledge',
        [
            'admin_knowledge_categories.php' => ['Knowledge Categories', 'kb_cats'],
            'admin_knowledge_document_add.php' => ['Add Knowledge Document', 'kb_add'],
            'admin_knowledge_documents.php' => ['Manage Knowledge Documents', 'kb_manage'],
            'admin_docs_quality.php' => ['Document Quality', 'kb_quality'],
        ],
    ],
    'Members' => [
        'Members',
        'members',
        [
            'admin.php' => ['Manage Members', 'members_manage'],
        ],
    ],
    'Site Settings' => [
        'Site Settings',
        'site',
        [
            'admin_site_settings.php' => ['General Settings', 'site_settings'],
            'admin_site_categories.php' => ['Forum Categories', 'site_categories'],
            'admin_site_welcome.php' => ['Welcome Message', 'site_welcome'],
            'admin_notice.php' => ['Site Notices', 'site_notices'],
        ],
    ],
    'Maintenance' => [
        'Maintenance',
        'maintenance',
        [
            'admin_db_manager.php' => ['Database Management', 'db_manager'],
            'admin_plugin_manager.php' => ['Plugin Manager', 'plugin_manager'],
            'admin_banning.php' => ['Warning / Banning', 'banning'],
            'admin_seo_manager.php' => ['SEO Manager', 'seo_manager'],
            'admin_updates.php' => ['Updates', 'updates'],
        ],
    ],
];

// current script name
$currentFile = basename($_SERVER['SCRIPT_NAME']);

// label shown on the button
$currentLabel = '';
foreach ($adminItems as $file => $item) {
    if (is_array($item) && count($item) == 3 && $file == $currentFile) {
        $currentLabel = $item[0];
        break;
    } elseif (is_array($item) && count($item) == 2 && $file == $currentFile) {
        $currentLabel = $item[0];
        break;
    }
}
if (empty($currentLabel)) {
    $currentLabel = 'Admin';
}

?>

<style>
  .dropdown-submenu {
    position: relative;
  }
  .dropdown-submenu .dropdown-submenu {
    position: absolute;
    top: 0;
    left: 100%;
    background-color: #f9f9f9;
    border: 1px solid #ccc;
    padding: 0;
    margin: 0;
    list-style: none;
    display: none;
  }
    .navbar-dark.bg-white {
        color: #333;
    }
</style>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarSupportedContent">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php foreach ($adminItems as $file => $item): ?>
          <?php if (is_array($item) && count($item) == 3): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <?= h($item[0]) ?>
              </a>
              <ul class="dropdown-menu">
                <?php foreach ($item[2] as $subFile => $subItem): ?>
                  <li>
                    <a class="dropdown-item <?= ($subFile === $currentFile) ? 'active' : '' ?>" href="<?= h($subFile) ?>">
                      <?= h($subItem[0]) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php else: ?>
            <li class="nav-item">
              <a class="nav-link <?= ($file === $currentFile) ? 'active' : '' ?>" href="<?= h($file) ?>">
                <?= h($item[0]) ?>
              </a>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</nav>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const dropdownSubmenu = document.querySelectorAll('.dropdown-submenu');

    dropdownSubmenu.forEach(function(dropdown) {
      const dropdownToggle = dropdown.querySelector('.dropdown-toggle');
      const dropdownMenu = dropdown.querySelector('.dropdown-submenu');

      if (dropdownToggle && dropdownMenu) {
        dropdownToggle.addEventListener('click', function(event) {
          event.preventDefault();
          event.stopPropagation();
          dropdownMenu.style.display = 'block';
        });

        document.addEventListener('click', function(event) {
          if (!dropdown.contains(event.target)) {
            dropdownMenu.style.display = 'none';
          }
        });
      }
    });
  });
</script>