<?php
// includes/menu_helpers.php
// Helper functions for loading and rendering custom menu items

/**
 * Fetch custom menu items from database based on current user's role
 * 
 * @param mysqli $conn Database connection
 * @param array|null $currentUser Current user array (or null if not logged in)
 * @return array Array of menu items with children nested
 */
function getCustomMenuItems(mysqli $conn, ?array $currentUser): array
{
    // Determine visibility level based on user role
    if (empty($currentUser)) {
        // Not logged in - only show public items
        $visibilityCondition = "visibility = 'public'";
    } elseif (($currentUser['role'] ?? '') === 'admin') {
        // Admin - show all items
        $visibilityCondition = "1=1";
    } else {
        // Regular member - show public and member items
        $visibilityCondition = "visibility IN ('public', 'members')";
    }

    $stmt = $conn->prepare("
        SELECT id, label, url, parent_id, sort_order, visibility, is_active
        FROM menu_items
        WHERE is_active = 1 AND {$visibilityCondition}
        ORDER BY parent_id IS NULL DESC, parent_id ASC, sort_order ASC, id ASC
    ");

    if (!$stmt) {
        return [];
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }

    $stmt->close();

    // Organize into parent-child structure
    $topLevel = [];
    $childrenMap = [];

    foreach ($items as $item) {
        if ($item['parent_id'] === null) {
            $topLevel[] = $item;
        } else {
            if (!isset($childrenMap[$item['parent_id']])) {
                $childrenMap[$item['parent_id']] = [];
            }
            $childrenMap[$item['parent_id']][] = $item;
        }
    }

    // Attach children to parents
    foreach ($topLevel as &$parent) {
        if (isset($childrenMap[$parent['id']])) {
            $parent['children'] = $childrenMap[$parent['id']];
        } else {
            $parent['children'] = [];
        }
    }

    return $topLevel;
}

/**
 * Render custom menu items as HTML for the offcanvas menu
 * 
 * @param array $menuItems Array of top-level menu items with nested children
 * @return string HTML string
 */
function renderCustomMenuItems(array $menuItems): string
{
    if (empty($menuItems)) {
        return '';
    }

    $html = '';

    foreach ($menuItems as $item) {
        $hasChildren = !empty($item['children']);
        $hasUrl = !empty($item['url']);

        if ($hasChildren) {
            // Dropdown menu
            $html .= '<div class="dropdown">';
            $html .= '<a class="nav-link dropdown-toggle text-white" href="#" data-bs-toggle="dropdown" aria-expanded="false">';
            $html .= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
            $html .= '</a>';
            $html .= '<ul class="dropdown-menu dropdown-menu-dark w-100">';

            foreach ($item['children'] as $child) {
                $childUrl = htmlspecialchars($child['url'] ?? '#', ENT_QUOTES, 'UTF-8');
                $childLabel = htmlspecialchars($child['label'], ENT_QUOTES, 'UTF-8');
                $html .= '<li><a class="dropdown-item" href="' . $childUrl . '">' . $childLabel . '</a></li>';
            }

            $html .= '</ul>';
            $html .= '</div>';
        } elseif ($hasUrl) {
            // Simple link
            $url = htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
            $html .= '<a class="nav-link text-white" href="' . $url . '">' . $label . '</a>';
        }
    }

    return $html;
}