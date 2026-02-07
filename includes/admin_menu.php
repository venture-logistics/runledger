<?php
// admin_menu.php

$adminItems = [
  'admin.php'                       => ['Members', 'members'],
  'admin_site_settings.php'         => ['Site Settings', 'site'],
  'admin_site_categories.php'       => ['Forum Categories', 'categories'],
  'admin_site_welcome.php'          => ['Welcome Message', 'welcome'],
  'admin_notice.php'                => ['Site Notices', 'notices'],
  'admin_knowledge_categories.php'  => ['Knowledge Categories', 'kb_cats'],
  'admin_knowledge_document_add.php'=> ['Add Document', 'kb_add'],
  'admin_knowledge_documents.php'   => ['Manage Documents', 'kb_manage'],
  'admin_menu_items.php'            => ['Menu Management', 'menu'],
  'admin_docs_quality.php'          => ['Document Quality', 'kb_quality'],
  'admin_db_manager.php'            => ['Database Management', 'db'],
  'admin_plugin_manager.php'        => ['Plugin Manager', 'plugin'],
  'admin_updates.php'               => ['Updates', 'updates'],
];

// current script name
$currentFile = basename($_SERVER['SCRIPT_NAME']);

// label shown on the button
$currentLabel = $adminItems[$currentFile][0] ?? 'Admin';
?>

<div class="dropdown mb-3">
  <button class="btn btn-success dropdown-toggle w-sm-auto"
          type="button" data-bs-toggle="dropdown" aria-expanded="false">
    <?= h($currentLabel) ?>
  </button>

  <ul class="dropdown-menu">
    <?php foreach ($adminItems as $file => [$label, $key]): ?>
      <li>
        <a class="dropdown-item <?= ($file === $currentFile) ? 'active' : '' ?>"
           href="<?= h($file) ?>">
          <?= h($label) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>