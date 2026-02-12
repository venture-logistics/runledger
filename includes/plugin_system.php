<?php
class PluginSystem
{
    private static $actions = [];
    private static $filters = [];

    // Actions - do something at a specific point
    public static function add_action($hook, $callback)
    {
        self::$actions[$hook][] = $callback;
    }

    public static function do_action($hook, ...$args)
    {
        if (isset(self::$actions[$hook])) {
            foreach (self::$actions[$hook] as $callback) {
                call_user_func_array($callback, $args);
            }
        }
    }

    // Filters - modify a value
    public static function add_filter($hook, $callback)
    {
        self::$filters[$hook][] = $callback;
    }

    public static function apply_filters($hook, $value, ...$args)
    {
        if (isset(self::$filters[$hook])) {
            foreach (self::$filters[$hook] as $callback) {
                $value = call_user_func($callback, $value, ...$args);
            }
        }
        return $value;
    }
}

// Helper functions
function add_action($hook, $callback)
{
    PluginSystem::add_action($hook, $callback);
}

function do_action($hook, ...$args)
{
    PluginSystem::do_action($hook, ...$args);
}

function add_filter($hook, $callback)
{
    PluginSystem::add_filter($hook, $callback);
}

function apply_filters($hook, $value, ...$args)
{
    return PluginSystem::apply_filters($hook, $value, ...$args);
}