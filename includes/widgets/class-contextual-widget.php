<?php
/** Native reading-page widget with safe, configurable content selection. */
if (!defined('ABSPATH')) { exit; }
class Revayat_Companion_Contextual_Widget extends WP_Widget {
    const MODES = ['other_dossiers' => 'سایر پرونده‌های ویژه', 'related' => 'مطالب هم‌موضوع', 'dossier' => 'مطالب همین پرونده', 'parents' => 'پرونده‌های این مطلب', 'recent' => 'تازه‌ترین مطالب'];
    const STYLES = ['compact' => 'ردیف فشرده با عکس افقی', 'featured' => 'یک کارت تصویری و ادامه فشرده'];
    const SOURCES = ['auto' => 'متناسب با صفحه', 'post' => 'نوشته‌ها', 'analyst_post' => 'تحلیل‌ها', 'multimedia' => 'چندرسانه‌ای', 'special_dossier' => 'پرونده‌های ویژه'];
    public function __construct() {
        parent::__construct('revayat_contextual', 'روایت ایران: مطالب مرتبط و پرونده', ['description' => 'انتخاب محتوا برای صفحات تکی، با کنترل تعداد، عکس، تاریخ و ارتباط موضوعی/پرونده.']);
    }
    public static function defaults(): array {
        return ['title' => 'مطالب پیشنهادی', 'mode' => 'related', 'source' => 'auto', 'number' => 4, 'thumbnail' => true, 'date' => true, 'fallback' => true, 'style' => 'compact'];
    }
    public function update($new, $old) {
        return [
            'title' => sanitize_text_field($new['title'] ?? ''),
            'mode' => isset(self::MODES[$new['mode'] ?? '']) ? $new['mode'] : 'related',
            'style' => isset(self::STYLES[$new['style'] ?? '']) ? $new['style'] : 'compact',
            'source' => isset(self::SOURCES[$new['source'] ?? '']) ? $new['source'] : 'auto',
            'number' => min(8, max(1, absint($new['number'] ?? 4))),
            'thumbnail' => !empty($new['thumbnail']), 'date' => !empty($new['date']), 'fallback' => !empty($new['fallback']),
        ];
    }
    public function form($instance) {
        $values = wp_parse_args($instance, self::defaults());
        ?><p><label for="<?php echo esc_attr($this->get_field_id('title')); ?>">عنوان</label><input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" value="<?php echo esc_attr($values['title']); ?>"></p><?php
        foreach (['mode' => ['روش انتخاب', self::MODES], 'source' => ['نوع محتوا', self::SOURCES], 'style' => ['سبک نمایش', self::STYLES]] as $key => $field) {
            ?><p><label for="<?php echo esc_attr($this->get_field_id($key)); ?>"><?php echo esc_html($field[0]); ?></label><select class="widefat" id="<?php echo esc_attr($this->get_field_id($key)); ?>" name="<?php echo esc_attr($this->get_field_name($key)); ?>"><?php foreach ($field[1] as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($values[$key], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p><?php
        }
        ?><p><label for="<?php echo esc_attr($this->get_field_id('number')); ?>">تعداد (۱ تا ۸)</label><input class="tiny-text" type="number" min="1" max="8" id="<?php echo esc_attr($this->get_field_id('number')); ?>" name="<?php echo esc_attr($this->get_field_name('number')); ?>" value="<?php echo esc_attr($values['number']); ?>"></p><?php
        foreach (['thumbnail' => 'نمایش عکس', 'date' => 'نمایش تاریخ', 'fallback' => 'اگر مطلب هم‌موضوع/هم‌پرونده نبود، تازه‌ترین مطالب نمایش داده شوند'] as $key => $label) {
            ?><p><label><input type="checkbox" name="<?php echo esc_attr($this->get_field_name($key)); ?>" value="1" <?php checked($values[$key]); ?>> <?php echo esc_html($label); ?></label></p><?php
        }
        ?><p class="description">در حالت «پرونده‌های این مطلب»، نوع محتوا و جایگزین تازه‌ترین مطالب اعمال نمی‌شود. «مطالب همین پرونده» از اتصال‌های ثبت‌شده پرونده استفاده می‌کند. مدیریت مجوز و ذخیره از ابزارک بومی وردپرس انجام می‌شود.</p><?php
    }
    public static function items(array $settings, int $current): array {
        $settings = wp_parse_args($settings, self::defaults());
        $types = array_values(array_diff(array_keys(self::SOURCES), ['auto']));
        $source = $settings['source'] === 'auto' ? get_post_type($current) : $settings['source'];
        if (!in_array($source, $types, true)) { $source = 'post'; }
        $query = ['post_type' => $source, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => min(8, max(1, (int) $settings['number'])), 'post__not_in' => [$current], 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true];
        if ($settings['mode'] === 'other_dossiers') {
            $excluded = array_merge([$current], array_column(Revayat_Companion_Dossier_Links::parents($current), 'id'));
            return get_posts(array_merge($query, ['post_type' => 'special_dossier', 'post__not_in' => $excluded]));
        }
        if ($settings['mode'] === 'parents') {
            $ids = array_column(Revayat_Companion_Dossier_Links::parents($current), 'id');
            return $ids ? get_posts(array_merge($query, ['post_type' => 'special_dossier', 'post__in' => $ids, 'orderby' => 'post__in'])) : [];
        }
        if ($settings['mode'] === 'dossier') {
            $parents = get_post_type($current) === 'special_dossier' ? [['id' => $current]] : Revayat_Companion_Dossier_Links::parents($current);
            $ids = [];
            foreach ($parents as $parent) { $ids = array_merge($ids, array_column(Revayat_Companion_Dossier_Links::get($parent['id'])['content'], 'id')); }
            $ids = array_values(array_diff(array_unique($ids), [$current]));
            $query['post_type'] = $settings['source'] === 'auto' ? ['post', 'analyst_post', 'multimedia'] : $source;
            $matches = $ids ? get_posts(array_merge($query, ['post__in' => $ids])) : [];
            if ($matches || !$settings['fallback']) { return $matches; }
        } elseif ($settings['mode'] === 'related') {
            $tax_query = ['relation' => 'OR'];
            foreach (get_object_taxonomies(get_post_type($current), 'objects') as $taxonomy) {
                if (!$taxonomy->public) { continue; }
                $terms = wp_get_object_terms($current, $taxonomy->name, ['fields' => 'ids']);
                if (!is_wp_error($terms) && $taxonomy->name === 'category') { $terms = array_values(array_diff($terms, [(int) get_option('default_category')])); }
                if (!is_wp_error($terms) && $terms) { $tax_query[] = ['taxonomy' => $taxonomy->name, 'field' => 'term_id', 'terms' => $terms]; }
            }
            $matches = count($tax_query) > 1 ? get_posts(array_merge($query, ['tax_query' => $tax_query])) : [];
            if ($matches || !$settings['fallback']) { return $matches; }
        }
        return get_posts($query);
    }
    public function widget($args, $instance) {
        if (!is_singular(['post', 'analyst_post', 'multimedia', 'special_dossier'])) { return; }
        $settings = wp_parse_args($instance, self::defaults());
        $items = self::items($settings, get_queried_object_id());
        if (!$items || (is_singular('special_dossier') && in_array($settings['mode'], ['related', 'recent', 'other_dossiers'], true))) { return; }
        echo $args['before_widget'];
        if ($settings['title']) { echo $args['before_title'] . esc_html(apply_filters('widget_title', $settings['title'], $instance, $this->id_base)) . $args['after_title']; }
        $style = isset(self::STYLES[$settings['style']]) ? $settings['style'] : 'compact';
        $parent = $settings['mode'] === 'parents' ? ' rv-context-list--parents' : ($settings['mode'] === 'other_dossiers' ? ' rv-context-list--dossiers' : '');
        echo '<ul class="rv-context-list rv-context-list--' . esc_attr($style) . $parent . '">';
        foreach ($items as $index => $item) {
            $lead = $style === 'featured' && $index === 0 && $settings['thumbnail'] && has_post_thumbnail($item->ID);
            echo '<li' . ($lead ? ' class="rv-context-lead"' : '') . '><a href="' . esc_url(get_permalink($item->ID)) . '">';
            if ($settings['thumbnail'] && has_post_thumbnail($item->ID)) { echo get_the_post_thumbnail($item->ID, $lead ? 'medium' : 'thumbnail', ['loading' => 'lazy', 'alt' => '']); }
            if ($settings['mode'] === 'other_dossiers') { echo '<span class="rv-context-dossier-body"><small class="rv-context-kind">' . esc_html__('پرونده ویژه', 'revayat-companion') . '</small>'; }
            else { echo '<span>'; }
            echo '<strong>' . esc_html(get_the_title($item->ID)) . '</strong>';
            if ($settings['date']) { $date = get_the_date('', $item->ID); echo '<small>' . esc_html(function_exists('revayat_en2fa') ? revayat_en2fa($date) : $date) . '</small>'; }
            if ($settings['mode'] === 'parents') { echo '<small class="rv-context-action">' . esc_html__('بازگشت به پرونده ←', 'revayat-companion') . '</small>'; }
            if ($settings['mode'] === 'other_dossiers') { echo '<small class="rv-context-action">' . esc_html__('مشاهده پرونده ←', 'revayat-companion') . '</small>'; }
            echo '</span></a></li>';
        }
        echo '</ul>' . $args['after_widget'];
    }
    public static function register(): void {
        register_widget(__CLASS__);
        add_filter('widget_display_callback', [__CLASS__, 'hide_search'], 10, 3);
    }
    public static function hide_search($instance, $widget, $args) {
        if ($widget->id_base === 'search' || ($widget->id_base === 'block' && strpos($instance['content'] ?? '', 'wp:search') !== false)) { return false; }
        return $instance;
    }
    /** Apply the requested sidebar cleanup once; retain inactive widget settings. */
    public static function refine_sidebars(): void {
        if (get_stylesheet() !== 'revayatiran' || !current_user_can('edit_theme_options') || get_option('revayat_single_sidebar_refined_v2')) { return; }
        $areas = get_option('sidebars_widgets', []);
        $widgets = get_option('widget_revayat_contextual', []);
        $blocks = get_option('widget_block', []);
        foreach ($areas as $area => $ids) {
            if (!is_array($ids) || $area === 'wp_inactive_widgets') { continue; }
            $keep = [];
            foreach ($ids as $name) {
                if (str_starts_with($name, 'search-')) { continue; }
                if (preg_match('/^block-(\d+)$/', $name, $m) && strpos($blocks[(int) $m[1]]['content'] ?? '', 'wp:search') !== false) { continue; }
                if (preg_match('/^revayat_contextual-(\d+)$/', $name, $m)) {
                    $id = (int) $m[1]; $mode = $widgets[$id]['mode'] ?? '';
                    if ($area === 'single-special_dossier-sidebar' && in_array($mode, ['dossier', 'other_dossiers', 'related', 'recent'], true)) { continue; }
                    if (str_starts_with($area, 'single-') && $mode === 'dossier') {
                        $widgets[$id] = array_merge($widgets[$id], ['mode' => 'other_dossiers', 'title' => 'سایر پرونده‌های ویژه', 'style' => 'compact']);
                    }
                }
                $keep[] = $name;
            }
            $areas[$area] = $keep;
        }
        update_option('widget_revayat_contextual', $widgets);
        update_option('sidebars_widgets', $areas);
        update_option('revayat_single_sidebar_refined_v2', 1, false);
    }
    /** One-time editable starter layout; never recreates widgets removed by the operator. */
    public static function seed_defaults(): void {
        if (get_stylesheet() !== 'revayatiran' || !current_user_can('edit_theme_options') || get_option('revayat_single_widgets_initialized_v1')) { return; }
        $sidebars = get_option('sidebars_widgets', []);
        $widgets = get_option('widget_revayat_contextual', ['_multiwidget' => 1]);
        $search = get_option('widget_search', ['_multiwidget' => 1]);
        $next = static function ($options): int { $keys = array_filter(array_keys($options), 'is_int'); return $keys ? max($keys) + 1 : 2; };
        foreach (['post', 'analyst_post', 'multimedia', 'special_dossier'] as $type) {
            $area = 'single-' . $type . '-sidebar';
            if (!empty($sidebars[$area])) { continue; }
            $sidebars[$area] = [];
            if ($type !== 'special_dossier') {
                $id = $next($widgets);
                $widgets[$id] = array_merge(self::defaults(), ['title' => 'پرونده‌های این مطلب', 'mode' => 'parents', 'number' => 3, 'style' => 'featured']);
                $sidebars[$area][] = 'revayat_contextual-' . $id;
            }
            $id = $next($widgets);
            $widgets[$id] = array_merge(self::defaults(), ['title' => 'سایر پرونده‌های ویژه', 'mode' => 'other_dossiers', 'number' => 3, 'fallback' => false]);
            $sidebars[$area][] = 'revayat_contextual-' . $id;
            if ($type !== 'special_dossier') {
                $id = $next($widgets);
                $widgets[$id] = array_merge(self::defaults(), ['number' => 3]);
                $sidebars[$area][] = 'revayat_contextual-' . $id;
            }

        }
        update_option('widget_revayat_contextual', $widgets);
        update_option('widget_search', $search);
        update_option('sidebars_widgets', $sidebars);
        update_option('revayat_single_widgets_initialized_v1', 1, false);
    }
}
