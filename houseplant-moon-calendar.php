<?php
/**
 * Plugin Name: Houseplant Moon Calendar
 * Description: Легкий лунный календарь для ухода за комнатными растениями
 * Version: 1.0.3
 * Author: Your Name
 * Text Domain: houseplant-moon-calendar
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Запрет прямого доступа
defined('ABSPATH') || exit;

// Константы плагина
define('HMC_VERSION', '1.0.3');
define('HMC_PLUGIN_FILE', __FILE__);
define('HMC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HMC_PLUGIN_URL', plugin_dir_url(__FILE__));

final class Houseplant_Moon_Calendar {
    
    private static $instance = null;
    private $core = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        register_activation_hook(HMC_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(HMC_PLUGIN_FILE, [$this, 'deactivate']);
        register_uninstall_hook(HMC_PLUGIN_FILE, [__CLASS__, 'uninstall']);
        
        // Всегда регистрируем шорткоды
        add_action('init', [$this, 'register_shortcodes']);
        
        // Инициализация основного функционала только если плагин активен
        add_action('plugins_loaded', [$this, 'init_plugin']);
        
        add_action('hmc_daily_cache_cleanup', [$this, 'daily_cache_cleanup']);
    }
    
    public function activate() {
        // Устанавливаем крон-задачу для ежедневной очистки кэша в 00:01
        if (!wp_next_scheduled('hmc_daily_cache_cleanup')) {
            wp_schedule_event($this->get_midnight_timestamp() + 60, 'daily', 'hmc_daily_cache_cleanup');
        }
        
        update_option('hmc_version', HMC_VERSION);
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        $this->clear_cache();
        wp_clear_scheduled_hook('hmc_daily_cache_cleanup');
        flush_rewrite_rules();
    }
    
    public static function uninstall() {
        // Очищаем все данные плагина при удалении
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hmc_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_hmc_%'");
        delete_option('hmc_version');
    }
    
    public function init_plugin() {
        // Подключаем основной функционал
        require_once HMC_PLUGIN_DIR . 'includes/class-hmc-core.php';
        $this->core = HMC_Core::instance();
    }
    
    /**
     * Регистрация безопасных шорткодов
     */
    public function register_shortcodes() {
        add_shortcode('moon_phase', [$this, 'safe_moon_phase_shortcode']);
        add_shortcode('plant_care_tips', [$this, 'safe_plant_care_tips_shortcode']);
        add_shortcode('clear_moon_cache', [$this, 'safe_clear_cache_shortcode']);
    }
    
    /**
     * Безопасный шорткод для фазы луны
     */
    public function safe_moon_phase_shortcode($atts) {
        // Если основной функционал не загружен, возвращаем пустую строку
        if (!$this->is_core_loaded()) {
            return $this->get_plugin_inactive_message();
        }
        
        // Плагин активен - используем нормальный функционал
        return $this->core->shortcode_moon_phase($atts);
    }
    
    /**
     * Безопасный шорткод для советов по уходу
     */
    public function safe_plant_care_tips_shortcode($atts) {
        if (!$this->is_core_loaded()) {
            return $this->get_plugin_inactive_message();
        }
        
        return $this->core->shortcode_plant_care_tips($atts);
    }
    
    /**
     * Безопасный шорткод для очистки кэша
     */
    public function safe_clear_cache_shortcode($atts) {
        if (!$this->is_core_loaded()) {
            return '';
        }
        
        if (!current_user_can('manage_options')) {
            return '';
        }
        
        $this->clear_cache();
        return '<p>Кэш лунного календаря очищен!</p>';
    }
    
    /**
     * Сообщение когда плагин не активен
     */
    private function get_plugin_inactive_message() {
        // Можно вернуть пустую строку или информационное сообщение
        if (current_user_can('activate_plugins')) {
            return '<!-- Houseplant Moon Calendar plugin is not active -->';
        }
        
        return ''; // Для обычных пользователей - ничего не показываем
    }
    
    /**
     * Проверка загрузки основного функционала
     */
    private function is_core_loaded() {
        return $this->core !== null && method_exists($this->core, 'get_moon_data');
    }
    
    /**
     * Ежедневная очистка кэша в полночь
     */
    public function daily_cache_cleanup() {
        if ($this->is_core_loaded()) {
            $this->clear_cache();
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Houseplant Moon Calendar: Daily cache cleanup executed at ' . current_time('mysql'));
            }
        }
    }
    
    /**
     * Получает timestamp на 00:01 следующего дня
     */
    private function get_midnight_timestamp() {
        return strtotime('tomorrow 00:01');
    }
    
    public function clear_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hmc_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_hmc_%'");
        
        if ($this->is_core_loaded()) {
            $this->core->clear_memory_cache();
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Houseplant Moon Calendar: Cache cleared at ' . current_time('mysql'));
        }
    }
}

// Запуск плагина
Houseplant_Moon_Calendar::instance();

// Безопасные вспомогательные функции
if (!function_exists('hmc_get_moon_data')) {
    function hmc_get_moon_data($date = null) {
        $plugin = Houseplant_Moon_Calendar::instance();
        if (!$plugin->is_core_loaded()) {
            return null;
        }
        return $plugin->core->get_moon_data($date);
    }
}

if (!function_exists('hmc_clear_cache')) {
    function hmc_clear_cache() {
        $plugin = Houseplant_Moon_Calendar::instance();
        return $plugin->clear_cache();
    }
}

// Добавляем интервал для ежедневного выполнения
add_filter('cron_schedules', function($schedules) {
    $schedules['hmc_daily'] = [
        'interval' => 24 * HOUR_IN_SECONDS,
        'display' => __('Once Daily (Houseplant Moon Calendar)')
    ];
    return $schedules;
});
?>