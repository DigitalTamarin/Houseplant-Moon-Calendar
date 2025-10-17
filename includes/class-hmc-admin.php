<?php
/**
 * Админ-функционал для связи с статьями
 */

class HMC_Admin {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('save_post', [$this, 'save_post_meta']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    }
    
    public function admin_init() {
        register_setting('hmc_settings', 'hmc_waxing_category');
        register_setting('hmc_settings', 'hmc_waning_category');
        register_setting('hmc_settings', 'hmc_new_moon_category');
        register_setting('hmc_settings', 'hmc_full_moon_category');
    }
    
    public function admin_menu() {
        add_options_page(
            'Лунный календарь - Настройки',
            'Лунный календарь',
            'manage_options',
            'hmc-settings',
            [$this, 'settings_page']
        );
    }
    
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Настройки лунного календаря</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('hmc_settings'); ?>
                
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
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card">
                <h3>Как использовать:</h3>
                <p>1. Создайте статьи и назначьте им соответствующие категории</p>
                <p>2. Или используйте метаполя для привязки к лунным дням:</p>
                <ul>
                    <li><code>hmc_lunar_day</code> - номер лунного дня (1-30)</li>
                    <li><code>hmc_moon_phase</code> - тип фазы (waxing, waning, new, full)</li>
                </ul>
                <p>3. Или добавляйте теги: <code>лунный_день_X</code>, <code>фаза_луны_XXX</code></p>
            </div>
        </div>
        <?php
    }
    
    public function add_meta_boxes() {
        add_meta_box(
            'hmc_moon_meta',
            'Привязка к лунному календарю',
            [$this, 'meta_box_callback'],
            'post',
            'side'
        );
    }
    
    public function meta_box_callback($post) {
        wp_nonce_field('hmc_save_meta', 'hmc_meta_nonce');
        
        $lunar_day = get_post_meta($post->ID, 'hmc_lunar_day', true);
        $moon_phase = get_post_meta($post->ID, 'hmc_moon_phase', true);
        ?>
        <p>
            <label for="hmc_lunar_day">Лунный день:</label>
            <input type="number" id="hmc_lunar_day" name="hmc_lunar_day" 
                   value="<?php echo esc_attr($lunar_day); ?>" min="1" max="30" 
                   style="width: 60px;">
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
    
    public function save_post_meta($post_id) {
        if (!isset($_POST['hmc_meta_nonce']) || 
            !wp_verify_nonce($_POST['hmc_meta_nonce'], 'hmc_save_meta') ||
            defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (isset($_POST['hmc_lunar_day'])) {
            update_post_meta($post_id, 'hmc_lunar_day', sanitize_text_field($_POST['hmc_lunar_day']));
        }
        
        if (isset($_POST['hmc_moon_phase'])) {
            update_post_meta($post_id, 'hmc_moon_phase', sanitize_text_field($_POST['hmc_moon_phase']));
        }
    }
}
?>