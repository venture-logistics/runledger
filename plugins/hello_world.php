<?php
/**
 * Plugin Name: Hello World
 * Version: 1.0.0
 * Description: A simple test plugin
 * Author: Lee Miller
 */

add_action('plugin_content_hello_world', function () {
    global $currentUser;
    ?>
    
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">👋 Hello World!</h1>
            <p>This content is coming from the hello_world.php plugin.</p>
            <?php if (!empty($currentUser)): ?>
                <p>Welcome, <?= htmlspecialchars($currentUser['name']) ?>!</p>
            <?php else: ?>
                <p>Welcome, guest!</p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php
});