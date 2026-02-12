<?php
class PluginLoader
{
    private $plugins = [];
    private $plugin_dir;

    public function __construct($plugin_dir)
    {
        $this->plugin_dir = $plugin_dir;
    }

    public function load_all()
    {
        if (!is_dir($this->plugin_dir)) {
            return;
        }

        foreach (glob("{$this->plugin_dir}/*.php") as $plugin_file) {
            $plugin_data = $this->parse_plugin_header($plugin_file);
            $plugin_data['file'] = $plugin_file;
            $plugin_data['slug'] = basename($plugin_file, '.php');

            // Load the plugin file
            include_once $plugin_file;

            $this->plugins[$plugin_data['slug']] = $plugin_data;
        }

        return $this->plugins;
    }

    private function parse_plugin_header($file)
    {
        $file_data = file_get_contents($file, false, null, 0, 8192);

        return [
            'name' => $this->get_header($file_data, 'Plugin Name') ?: basename($file, '.php'),
            'version' => $this->get_header($file_data, 'Version') ?: '1.0.0',
            'description' => $this->get_header($file_data, 'Description') ?: '',
            'author' => $this->get_header($file_data, 'Author') ?: '',
        ];
    }

    private function get_header($content, $header)
    {
        if (preg_match('/' . preg_quote($header, '/') . ':\s*(.+)/i', $content, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    public function get_plugins()
    {
        return $this->plugins;
    }
}