<?php
/**
 * Plugin Name: Houseplant Moon Calendar
 * Description: Легкий лунный календарь для ухода за комнатными растениями
 * Version: 1.1.0
 * Author: Your Name
 * Text Domain: houseplant-moon-calendar
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Запрет прямого доступа
defined('ABSPATH') || exit;

// Константы плагина
define('HMC_VERSION', '1.1.0');
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
        
        // Очищаем настройки
        delete_option('hmc_version');
        delete_option('hmc_waxing_category');
        delete_option('hmc_waning_category');
        delete_option('hmc_new_moon_category');
        delete_option('hmc_full_moon_category');
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
        add_shortcode('moon_related_posts', [$this, 'safe_related_posts_shortcode']);
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
     * Безопасный шорткод для связанных статей
     */
    public function safe_related_posts_shortcode($atts) {
        if (!$this->is_core_loaded()) {
            return $this->get_plugin_inactive_message();
        }
        
        return $this->core->shortcode_related_posts($atts);
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

if (!function_exists('hmc_get_related_posts')) {
    function hmc_get_related_posts($lunar_day = null, $phase_type = null, $limit = 3) {
        $plugin = Houseplant_Moon_Calendar::instance();
        if (!$plugin->is_core_loaded()) {
            return [];
        }
        return $plugin->core->get_related_posts($lunar_day, $phase_type, $limit);
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

// Добавляем страницу настроек в админку
add_action('admin_menu', function() {
    add_options_page(
        'Лунный календарь - Настройки',
        'Лунный календарь',
        'manage_options',
        'hmc-settings',
        'hmc_settings_page'
    );
});

function hmc_settings_page() {
    if (isset($_POST['hmc_settings'])) {
        check_admin_referer('hmc-save-settings');
        
        update_option('hmc_waxing_category', intval($_POST['hmc_waxing_category']));
        update_option('hmc_waning_category', intval($_POST['hmc_waning_category']));
        update_option('hmc_new_moon_category', intval($_POST['hmc_new_moon_category']));
        update_option('hmc_full_moon_category', intval($_POST['hmc_full_moon_category']));
        
        echo '<div class="notice notice-success"><p>Настройки сохранены!</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Настройки лунного календаря</h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('hmc-save-settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Категория для растущей луны</th>
                    <td>
                        <?php wp_dropdown_categories([
                            'show_option_none' => '-- Не выбрана --',
                            'name' => 'hmc_waxing_category',
                            'selected' => get_option('hmc_waxing_category'),
                            'hide_empty' => false
                        ]); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Категория для убывающей луны</th>
                    <td>
                        <?php wp_dropdown_categories([
                            'show_option_none' => '-- Не выбрана --',
                            'name' => 'hmc_waning_category',
                            'selected' => get_option('hmc_waning_category'),
                            'hide_empty' => false
                        ]); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Категория для новолуния</th>
                    <td>
                        <?php wp_dropdown_categories([
                            'show_option_none' => '-- Не выбрана --',
                            'name' => 'hmc_new_moon_category',
                            'selected' => get_option('hmc_new_moon_category'),
                            'hide_empty' => false
                        ]); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Категория для полнолуния</th>
                    <td>
                        <?php wp_dropdown_categories([
                            'show_option_none' => '-- Не выбрана --',
                            'name' => 'hmc_full_moon_category',
                            'selected' => get_option('hmc_full_moon_category'),
                            'hide_empty' => false
                        ]); ?>
                    </td>
                </tr>
            </table>
            
            <input type="hidden" name="hmc_settings" value="1">
            <?php submit_button('Сохранить настройки'); ?>
        </form>
        
        <div class="card" style="margin-top: 20px;">
            <h3>Как использовать связанные статьи:</h3>
            <p><strong>Способ 1 - через категории:</strong></p>
            <p>Назначьте статьям соответствующие категории, выбранные выше</p>
            
            <p><strong>Способ 2 - через метаполя:</strong></p>
            <p>Добавьте в статьи произвольные поля:</p>
            <ul>
                <li><code>hmc_lunar_day</code> - номер лунного дня (1-30)</li>
                <li><code>hmc_moon_phase</code> - тип фазы (waxing, waning, new, full)</li>
            </ul>
            
            <p><strong>Способ 3 - через теги:</strong></p>
            <p>Добавьте теги в формате:</p>
            <ul>
                <li><code>лунный_день_X</code> (где X - номер дня)</li>
                <li><code>фаза_луны_XXX</code> (где XXX - waxing, waning, new, full)</li>
            </ul>
            
            <p><strong>Шорткоды:</strong></p>
            <ul>
                <li><code>[moon_related_posts]</code> - статьи для текущего дня</li>
                <li><code>[moon_related_posts limit="5"]</code> - больше статей</li>
                <li><code>[moon_related_posts show_date="true"]</code> - с датами</li>
            </ul>
        </div>
    </div>
    <?php
}

// Добавляем метабокс для привязки статей к лунным дням
add_action('add_meta_boxes', function() {
    add_meta_box(
        'hmc_moon_meta',
        'Привязка к лунному календарю',
        'hmc_meta_box_callback',
        'post',
        'side'
    );
});

function hmc_meta_box_callback($post) {
    wp_nonce_field('hmc_save_meta', 'hmc_meta_nonce');
    
    $lunar_day = get_post_meta($post->ID, 'hmc_lunar_day', true);
    $moon_phase = get_post_meta($post->ID, 'hmc_moon_phase', true);
    ?>
    <p>
        <label for="hmc_lunar_day">Лунный день:</label>
        <input type="number" id="hmc_lunar_day" name="hmc_lunar_day" 
               value="<?php echo esc_attr($lunar_day); ?>" min="1" max="30" 
               style="width: 60px;">
        <span class="description">(1-30)</span>
    </p>
    <p>
        <label for="hmc_moon_phase">Фаза луны:</label>
        <select id="hmc_moon_phase" name="hmc_moon_phase" style="width: 100%;">
            <option value="">-- Не выбрана --</option>
            <option value="waxing" <?php selected($moon_phase, 'waxing'); ?>>Растущая луна</option>
            <option value="waning" <?php selected($moon_phase, 'waning'); ?>>Убывающая луна</option>
            <option value="new" <?php selected($moon_phase, 'new'); ?>>Новолуние</option>
            <option value="full" <?php selected($moon_phase, 'full'); ?>>Полнолуние</option>
        </select>
    </p>
    <p class="description">
        Привяжите статью к лунному дню или фазе для автоматического показа в календаре.
    </p>
    <?php
}

// Сохраняем метаданные
add_action('save_post', function($post_id) {
    if (!isset($_POST['hmc_meta_nonce']) || 
        !wp_verify_nonce($_POST['hmc_meta_nonce'], 'hmc_save_meta') ||
        defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    if (isset($_POST['hmc_lunar_day'])) {
        $lunar_day = sanitize_text_field($_POST['hmc_lunar_day']);
        if ($lunar_day >= 1 && $lunar_day <= 30) {
            update_post_meta($post_id, 'hmc_lunar_day', $lunar_day);
        } else {
            delete_post_meta($post_id, 'hmc_lunar_day');
        }
    }
    
    if (isset($_POST['hmc_moon_phase'])) {
        $moon_phase = sanitize_text_field($_POST['hmc_moon_phase']);
        if (in_array($moon_phase, ['waxing', 'waning', 'new', 'full'])) {
            update_post_meta($post_id, 'hmc_moon_phase', $moon_phase);
        } else {
            delete_post_meta($post_id, 'hmc_moon_phase');
        }
    }
});
?>