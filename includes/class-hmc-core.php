<?php
/**
 * Основной функционал лунного календаря для комнатных растений
 */

class HMC_Core {
    
    private static $instance = null;
    private $calculation_cache = [];
    
    // Константы для расчета
    const LUNAR_MONTH = 29.530588853;
    const NEW_MOON_2000 = 2451550.26;
    
    // Данные фаз луны с правильными названиями
    private $moon_phases = [
        'new-moon' => ['name' => 'Новолуние', 'icon' => '🌑', 'type' => 'new'],
        'waxing-crescent' => ['name' => 'Растущая луна', 'icon' => '🌒', 'type' => 'waxing'],
        'first-quarter' => ['name' => 'Первая четверть', 'icon' => '🌓', 'type' => 'waxing'],
        'waxing-gibbous' => ['name' => 'Растущая луна', 'icon' => '🌔', 'type' => 'waxing'],
        'full-moon' => ['name' => 'Полнолуние', 'icon' => '🌕', 'type' => 'full'],
        'waning-gibbous' => ['name' => 'Убывающая луна', 'icon' => '🌖', 'type' => 'waning'],
        'last-quarter' => ['name' => 'Последняя четверть', 'icon' => '🌗', 'type' => 'waning'],
        'waning-crescent' => ['name' => 'Убывающая луна', 'icon' => '🌘', 'type' => 'waning']
    ];
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Регистрируем стили
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    
    public function enqueue_assets() {
        if (apply_filters('hmc_load_styles', true)) {
            wp_add_inline_style('wp-block-library', $this->get_css());
        }
    }
    
    private function get_css() {
        return "
        .hmc-moon-phase {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            border-left: 4px solid #28a745;
        }
        .hmc-phase-simple {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: #e8f5e8;
            border-radius: 20px;
            font-size: 14px;
        }
        .hmc-plant-tips {
            background: white;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #e0e0e0;
            margin: 15px 0;
        }
        .hmc-tip {
            color: #28a745;
            margin: 5px 0;
            font-size: 14px;
        }
        .hmc-moon-icon {
            font-size: 20px;
        }
        .hmc-phase-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        ";
    }
    
    /**
     * Основной метод получения данных
     */
    public function get_moon_data($date = null) {
        $timestamp = $this->normalize_timestamp($date);
        $date_key = date('Y-m-d', $timestamp);
        
        // In-memory cache
        if (isset($this->calculation_cache[$date_key])) {
            return $this->calculation_cache[$date_key];
        }
        
        // Transient cache на 24 часа
        $cache_key = 'hmc_data_' . $date_key;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->calculation_cache[$date_key] = $cached;
            return $cached;
        }
        
        // Расчет данных
        $moon_data = $this->calculate_moon_data($timestamp);
        
        // Кэшируем на 24 часа (до конца дня + запас)
        $time_until_midnight = $this->get_seconds_until_midnight();
        set_transient($cache_key, $moon_data, $time_until_midnight + 3600); // +1 час запаса
        
        $this->calculation_cache[$date_key] = $moon_data;
        
        return $moon_data;
    }
    
    /**
     * Очистка in-memory кэша
     */
    public function clear_memory_cache() {
        $this->calculation_cache = [];
    }
    
    /**
     * Получает количество секунд до полуночи
     */
    private function get_seconds_until_midnight() {
        $now = current_time('timestamp');
        $midnight = strtotime('tomorrow 00:00:00');
        return $midnight - $now;
    }
    
    /**
     * Быстрый расчет лунных данных
     */
    private function calculate_moon_data($timestamp) {
        $year = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);
        $day = (int) date('j', $timestamp);
        
        $jd = $this->julian_date($year, $month, $day);
        $moon_age = $this->calculate_moon_age($jd);
        $lunar_day = $this->calculate_lunar_day($moon_age);
        $phase_data = $this->calculate_phase($moon_age);
        
        return [
            'day' => $lunar_day,
            'phase' => $phase_data['name'],
            'icon' => $phase_data['icon'],
            'is_waxing' => $phase_data['type'] === 'waxing',
            'is_waning' => $phase_data['type'] === 'waning',
            'date' => date('Y-m-d', $timestamp),
            'date_display' => date_i18n('d.m.Y', $timestamp),
            'care_tips' => $this->get_care_tips($lunar_day, $phase_data['type'])
        ];
    }
    
    /**
     * Расчет юлианской даты (оптимизированный)
     */
    private function julian_date($year, $month, $day) {
        if ($month <= 2) {
            $year--;
            $month += 12;
        }
        $a = floor($year / 100);
        $b = 2 - $a + floor($a / 4);
        return floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
    }
    
    /**
     * Расчет возраста луны
     */
    private function calculate_moon_age($jd) {
        $days_since_new_moon = $jd - self::NEW_MOON_2000;
        $cycles = floor($days_since_new_moon / self::LUNAR_MONTH);
        $age = $days_since_new_moon - ($cycles * self::LUNAR_MONTH);
        return $age < 0 ? $age + self::LUNAR_MONTH : $age;
    }
    
    /**
     * Расчет лунного дня
     */
    private function calculate_lunar_day($moon_age) {
        $day = floor($moon_age) + 1;
        return $day > 30 ? 1 : (int) $day;
    }
    
    /**
     * Расчет фазы луны
     */
    private function calculate_phase($moon_age) {
        $phase = $moon_age / self::LUNAR_MONTH;
        
        if ($phase < 0.03 || $phase >= 0.97) return $this->moon_phases['new-moon'];
        if ($phase < 0.22) return $this->moon_phases['waxing-crescent'];
        if ($phase < 0.28) return $this->moon_phases['first-quarter'];
        if ($phase < 0.47) return $this->moon_phases['waxing-gibbous'];
        if ($phase < 0.53) return $this->moon_phases['full-moon'];
        if ($phase < 0.72) return $this->moon_phases['waning-gibbous'];
        if ($phase < 0.78) return $this->moon_phases['last-quarter'];
        return $this->moon_phases['waning-crescent'];
    }
    
    /**
     * Советы по уходу для комнатных растений
     */
    private function get_care_tips($lunar_day, $phase_type) {
        $tips = [];
        
        // Советы по фазам
        if ($phase_type === 'waxing') {
            $tips[] = 'Благоприятное время для пересадки растений';
            $tips[] = 'Можно вносить жидкие удобрения';
            $tips[] = 'Хорошо укореняются черенки';
            $tips[] = 'Подходящий период для посадки новых растений';
        } elseif ($phase_type === 'waning') {
            $tips[] = 'Подходящее время для обрезки';
            $tips[] = 'Можно обрабатывать от вредителей';
            $tips[] = 'Хорошо рыхлить почву';
            $tips[] = 'Рекомендуется удаление сухих листьев';
        }
        
        if ($phase_type === 'new') {
            $tips[] = 'Дайте растениям отдохнуть';
            $tips[] = 'Не рекомендуется пересадка';
            $tips[] = 'Минимальный полив';
        }
        
        if ($phase_type === 'full') {
            $tips[] = 'Растения полны энергии';
            $tips[] = 'Можно собирать лекарственные травы';
            $tips[] = 'Хорошее время для подкормки';
        }
        
        // Советы по лунным дням
        $favorable_days = [2, 3, 6, 7, 8, 11, 12, 13, 16, 17, 21, 22, 26, 27];
        if (in_array($lunar_day, $favorable_days)) {
            $tips[] = 'Благоприятный день для ухода за растениями';
        }
        
        $unfavorable_days = [1, 5, 9, 15, 19, 25, 29, 30];
        if (in_array($lunar_day, $unfavorable_days)) {
            $tips[] = 'Не рекомендуется активный уход';
        }
        
        return array_slice($tips, 0, 3); // Максимум 3 совета
    }
    
    private function normalize_timestamp($date) {
        if (!$date) return current_time('timestamp');
        if (is_string($date)) {
            $timestamp = strtotime($date);
            return $timestamp ?: current_time('timestamp');
        }
        return $date;
    }
    
    /**
     * Шорткод: фаза луны
     */
    public function shortcode_moon_phase($atts) {
        $atts = shortcode_atts([
            'date' => '',
            'simple' => 'false'
        ], $atts);
        
        $moon_data = $this->get_moon_data($atts['date']);
        
        if ($atts['simple'] === 'true') {
            return sprintf(
                '<span class="hmc-phase-simple">%s %s (день %d)</span>',
                $moon_data['icon'],
                $moon_data['phase'],
                $moon_data['day']
            );
        }
        
        ob_start();
        ?>
        <div class="hmc-moon-phase">
            <div class="hmc-phase-header">
                <span class="hmc-moon-icon"><?php echo $moon_data['icon']; ?></span>
                <strong><?php echo $moon_data['phase']; ?></strong>
            </div>
            <p>Лунный день: <?php echo $moon_data['day']; ?></p>
            <p><?php echo $moon_data['date_display']; ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Шорткод: советы по уходу
     */
    public function shortcode_plant_care_tips($atts) {
        $atts = shortcode_atts(['date' => ''], $atts);
        
        $moon_data = $this->get_moon_data($atts['date']);
        
        if (empty($moon_data['care_tips'])) {
            return '<p>Сегодня растениям нужен отдых.</p>';
        }
        
        ob_start();
        ?>
        <div class="hmc-plant-tips">
            <strong>🌱 Советы по уходу:</strong>
            <?php foreach ($moon_data['care_tips'] as $tip): ?>
                <div class="hmc-tip">✓ <?php echo $tip; ?></div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
?>