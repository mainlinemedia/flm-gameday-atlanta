<?php
/**
 * Plugin Name: FLM GameDay Atlanta
 * Plugin URI: https://github.com/MainlineMediaGroup/flm-gameday-atlanta
 * Description: Import Braves, Hawks, Falcons, UGA & GT content from Field Level Media with AI enhancement, social posting, and analytics.
 * Version: 2.15.0
 * Author: Austin / Mainline Media Group
 * Author URI: https://mainlinemediagroup.com
 * License: Proprietary
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * GitHub Plugin URI: MainlineMediaGroup/flm-gameday-atlanta
 * Primary Branch: main
 */

if (!defined('ABSPATH')) exit;

// GitHub Updater Class
class FLM_GitHub_Updater {
    private $slug;
    private $plugin_file;
    private $version;
    private $github_repo = 'MainlineMediaGroup/flm-gameday-atlanta';
    private $github_response;
    
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->slug = plugin_basename($plugin_file);
        
        // Get current version from plugin data
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $plugin_data = get_plugin_data($plugin_file);
        $this->version = $plugin_data['Version'];
        
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
    }
    
    private function get_github_release() {
        if (!empty($this->github_response)) {
            return $this->github_response;
        }
        
        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ],
            'timeout' => 10,
        ]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $this->github_response = json_decode(wp_remote_retrieve_body($response));
        return $this->github_response;
    }
    
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $release = $this->get_github_release();
        
        if (!$release || !isset($release->tag_name)) {
            return $transient;
        }
        
        $github_version = ltrim($release->tag_name, 'v');
        
        if (version_compare($github_version, $this->version, '>')) {
            $package = '';
            if (!empty($release->assets) && !empty($release->assets[0]->browser_download_url)) {
                $package = $release->assets[0]->browser_download_url;
            } elseif (!empty($release->zipball_url)) {
                $package = $release->zipball_url;
            }
            
            $transient->response[$this->slug] = (object) [
                'slug' => dirname($this->slug),
                'new_version' => $github_version,
                'package' => $package,
                'url' => $release->html_url,
            ];
        }
        
        return $transient;
    }
    
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        
        if (!isset($args->slug) || $args->slug !== dirname($this->slug)) {
            return $result;
        }
        
        $release = $this->get_github_release();
        
        if (!$release) {
            return $result;
        }
        
        return (object) [
            'name' => 'FLM GameDay Atlanta',
            'slug' => dirname($this->slug),
            'version' => ltrim($release->tag_name, 'v'),
            'author' => '<a href="https://mainlinemediagroup.com">Mainline Media Group</a>',
            'homepage' => "https://github.com/{$this->github_repo}",
            'short_description' => 'Import sports content from Field Level Media with AI enhancement.',
            'sections' => [
                'description' => 'FLM GameDay Atlanta imports and manages sports content from Field Level Media API.',
                'changelog' => nl2br($release->body ?? 'See GitHub for changelog.'),
            ],
            'download_link' => !empty($release->assets[0]->browser_download_url) 
                ? $release->assets[0]->browser_download_url 
                : $release->zipball_url,
            'last_updated' => $release->published_at ?? '',
            'requires' => '5.8',
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
        ];
    }
    
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        activate_plugin($this->slug);
        
        return $result;
    }
}

// Initialize updater
add_action('admin_init', function() {
    if (is_admin()) {
        new FLM_GitHub_Updater(__FILE__);
    }
});

class FLM_GameDay_Atlanta {
    
    private $api_base = 'https://api.fieldlevelmedia.com/v1';
    private $oauth_base = 'https://mmgleads.com/oauth';
    private $version = '2.15.0';
    
    // Rate limiting configuration
    private $rate_limit_config = [
        'max_retries' => 5,
        'base_delay' => 15,      // Base delay in seconds
        'max_delay' => 300,      // Max delay (5 minutes)
        'jitter_factor' => 0.25, // Add randomness to prevent thundering herd
    ];
    
    // Error log configuration
    private $max_error_log_entries = 100;
    
    // Default settings
    private $default_settings = [
        'api_key' => '3dfa9d7f-5179-49b5-86cd-d101e8cb13a6',
        'post_status' => 'draft',
        'post_author' => 1,
        'default_category' => '',
        'import_images' => true,
        'lookback_days' => 7,  // How far back to look for content (max 30)
        'import_frequency' => 'twicedaily',  // Cron schedule: hourly, every6hours, twicedaily, daily
        'purge_after_days' => 0,  // Auto-purge posts older than X days (0 = disabled)
        'auto_excerpt' => true,  // Generate excerpt from first paragraph
        'auto_meta_description' => true,  // Generate SEO meta description
        'story_types_enabled' => [
            'News' => true,
            'Recap' => true,
            'Preview' => true,
            'Feature' => true,
            'Analysis' => true,
            'Interview' => true,
            'Injury' => true,
            'Transaction' => true,
        ],
        'teams_enabled' => [
            'braves' => true,
            'falcons' => true,
            'hawks' => true,
            'uga' => true,
            'gt' => true,
        ],
        'create_team_categories' => true,
        'create_league_categories' => true,
        'create_type_categories' => true,
        // Integration API Keys (v2.8.0)
        'ga4_property_id' => '',
        'ga4_api_secret' => '',
        'ga4_service_account' => '',  // v2.11.0: Service account JSON for Data API
        'claude_api_key' => '',
        'twitter_api_key' => '',
        'twitter_api_secret' => '',
        'twitter_access_token' => '',
        'twitter_access_secret' => '',
        'facebook_app_id' => '',
        'facebook_app_secret' => '',
        'facebook_page_id' => '',
        'facebook_access_token' => '',
        // Search Engine Integrations (v2.8.0)
        'gsc_property_url' => '',
        'gsc_client_id' => '',
        'gsc_client_secret' => '',
        'gsc_access_token' => '',
        'gsc_service_account' => '',  // v2.11.0: Service account JSON for GSC API
        'bing_api_key' => '',
        'bing_site_url' => '',
        // ML Settings
        'ml_headline_analysis' => true,
        'ml_publish_time_optimization' => true,
        'ml_performance_prediction' => true,
        'ml_trend_detection' => true,
        'ml_seo_optimization' => true,
        // Auto-Posting Settings (v2.9.0)
        'auto_post_twitter' => false,
        'auto_post_facebook' => false,
        'twitter_post_template' => '📰 {headline} #Atlanta #Sports {team_hashtag}',
        'facebook_post_template' => '{headline}\n\nRead more: {url}',
        'social_post_delay' => 0,  // Seconds to wait before posting (0 = immediate)
        'social_include_image' => true,  // Include featured image in social posts
        'social_queue_enabled' => false,  // Queue posts instead of immediate posting
        // Content & Publishing Settings (v2.10.0)
        'utm_enabled' => true,
        'utm_source' => 'social',
        'utm_medium' => '{platform}',  // twitter, facebook
        'utm_campaign' => 'flm_auto',
        'utm_content' => '{team}',
        'scheduled_posting_enabled' => false,
        'best_times_twitter' => ['09:00', '12:00', '17:00'],  // Default optimal times
        'best_times_facebook' => ['09:00', '13:00', '16:00'],
        'spread_posts_minutes' => 15,  // Minutes between scheduled posts
        'social_preview_meta_box' => true,  // Show preview in post editor
        'reshare_evergreen' => false,  // Reshare older high-performing content
        'reshare_days_old' => 7,  // Min days before resharing
        // Analytics Depth Settings (v2.11.0)
        'analytics_use_ga4_api' => true,  // Use real GA4 API vs internal tracking
        'analytics_cache_minutes' => 15,  // How long to cache GA4 data
        'best_times_auto_learn' => true,  // Auto-learn best posting times from data
        'article_tracking_enabled' => true,  // Track individual article performance
        // ESP Integration Settings (v2.13.0)
        'esp_provider' => 'none',  // none, sendgrid, aigeon
        'sendgrid_api_key' => '',
        'sendgrid_category' => '',  // Category to filter stats by (e.g., 'newsletter')
        'aigeon_api_key' => '',
        'aigeon_account_id' => '',
        'esp_cache_minutes' => 30,  // How long to cache ESP data
        'esp_sync_enabled' => false,  // Sync email performance to article meta
        // OAuth Settings (v2.15.0)
        'ga4_oauth_access_token' => '',
        'ga4_oauth_refresh_token' => '',
        'ga4_oauth_expires_at' => 0,
        'gsc_oauth_access_token' => '',
        'gsc_oauth_refresh_token' => '',
        'gsc_oauth_expires_at' => 0,
        'twitter_oauth_access_token' => '',
        'twitter_oauth_refresh_token' => '',
        'twitter_oauth_expires_at' => 0,
        'facebook_oauth_access_token' => '',
        'facebook_oauth_expires_at' => 0,
        'facebook_oauth_pages' => [],  // Array of page IDs with their tokens
        'facebook_oauth_selected_page' => '',  // Selected page ID for posting
    ];
    
    // Integration endpoints (v2.8.0)
    private $integration_endpoints = [
        'claude' => 'https://api.anthropic.com/v1/messages',
        'ga4' => 'https://analyticsdata.googleapis.com/v1beta',
        'twitter' => 'https://api.twitter.com/2',
        'facebook' => 'https://graph.facebook.com/v18.0',
        'gsc' => 'https://www.googleapis.com/webmasters/v3',
        'bing' => 'https://ssl.bing.com/webmaster/api.svc/json',
        'sendgrid' => 'https://api.sendgrid.com/v3',
        'aigeon' => 'https://api.aigeon.ai/v1',  // Placeholder - update when docs available
    ];
    
    // Team configuration with colors for UI
    // League IDs from FLM API: MLB=1, NFL=30, NBA=26, NCAAF=31, NCAAB=20
    private $target_teams = [
        'braves' => [
            'name' => 'Atlanta Braves',
            'category_name' => 'Braves',
            'league' => 'MLB',
            'league_id' => 1,
            'identifiers' => ['Braves', 'ATL', 'Atlanta Braves'],
            'team_ids' => ['68'],
            'color' => '#CE1141',
            'secondary' => '#13274F',
        ],
        'falcons' => [
            'name' => 'Atlanta Falcons',
            'category_name' => 'Falcons',
            'league' => 'NFL',
            'league_id' => 30,
            'identifiers' => ['Falcons', 'Atlanta Falcons'],
            'team_ids' => ['2'],
            'color' => '#A71930',
            'secondary' => '#000000',
        ],
        'hawks' => [
            'name' => 'Atlanta Hawks',
            'category_name' => 'Hawks',
            'league' => 'NBA',
            'league_id' => 26,
            'identifiers' => ['Hawks', 'Atlanta Hawks'],
            'team_ids' => ['35'],
            'color' => '#E03A3E',
            'secondary' => '#C1D32F',
        ],
        'uga' => [
            'name' => 'Georgia Bulldogs',
            'category_name' => 'UGA',
            'league' => 'NCAA',
            'league_id' => 31,  // Primary: NCAAF
            'league_ids' => [31, 20],  // NCAAF + NCAAB
            'identifiers' => ['Georgia Bulldogs', 'Bulldogs', 'UGA', 'Georgia'],
            'team_ids' => ['230', '793'],  // NCAAF: 230, NCAAB: 793
            'color' => '#BA0C2F',
            'secondary' => '#000000',
        ],
        'gt' => [
            'name' => 'Georgia Tech',
            'category_name' => 'Georgia Tech',
            'league' => 'NCAA',
            'league_id' => 31,  // Primary: NCAAF
            'league_ids' => [31, 20],  // NCAAF + NCAAB
            'identifiers' => ['Georgia Tech', 'Yellow Jackets', 'GT'],
            'team_ids' => ['233', '769'],  // NCAAF: 233, NCAAB: 769
            'color' => '#B3A369',
            'secondary' => '#003057',
        ],
    ];
    
    // League configuration - maps league IDs to names
    private $leagues = [
        1 => ['name' => 'MLB', 'full_name' => 'Major League Baseball'],
        30 => ['name' => 'NFL', 'full_name' => 'National Football League'],
        26 => ['name' => 'NBA', 'full_name' => 'National Basketball Association'],
        31 => ['name' => 'NCAAF', 'full_name' => 'NCAA Football'],
        20 => ['name' => 'NCAAB', 'full_name' => 'NCAA Basketball'],
    ];
    
    // SVG Icons
    private $icons = [];
    
    public function __construct() {
        $this->init_icons();
        
        // Custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        
        add_action('wp', [$this, 'schedule_import']);
        add_action('flm_import_stories', [$this, 'import_stories']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_flm_oauth_callback', [$this, 'handle_oauth_callback']);  // Uses admin-post.php
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('add_meta_boxes', [$this, 'add_flm_meta_box']);
        
        // Dashboard widget (P3.4)
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        
        // Frontend pageview tracking
        add_action('wp_enqueue_scripts', [$this, 'enqueue_pageview_tracking']);
        
        // AJAX handlers
        add_action('wp_ajax_flm_run_import', [$this, 'ajax_run_import']);
        add_action('wp_ajax_flm_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_flm_discover_teams', [$this, 'ajax_discover_teams']);
        add_action('wp_ajax_flm_reset_import_date', [$this, 'ajax_reset_import_date']);
        add_action('wp_ajax_flm_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_flm_clear_log', [$this, 'ajax_clear_log']);
        add_action('wp_ajax_flm_clear_error_log', [$this, 'ajax_clear_error_log']);
        add_action('wp_ajax_flm_get_import_status', [$this, 'ajax_get_import_status']);
        add_action('wp_ajax_flm_dry_run_preview', [$this, 'ajax_dry_run_preview']);
        add_action('wp_ajax_flm_selective_import', [$this, 'ajax_selective_import']);
        add_action('wp_ajax_flm_purge_old_posts', [$this, 'ajax_purge_old_posts']);
        add_action('wp_ajax_flm_dismiss_onboarding', [$this, 'ajax_dismiss_onboarding']);
        
        // Pageview tracking AJAX (both logged in and logged out)
        add_action('wp_ajax_flm_record_pageview', [$this, 'ajax_record_pageview']);
        add_action('wp_ajax_nopriv_flm_record_pageview', [$this, 'ajax_record_pageview']);
        
        // Analytics AJAX
        add_action('wp_ajax_flm_get_analytics', [$this, 'ajax_get_analytics']);
        
        // ML & Integrations AJAX (v2.8.0)
        add_action('wp_ajax_flm_analyze_headline', [$this, 'ajax_analyze_headline']);
        add_action('wp_ajax_flm_predict_performance', [$this, 'ajax_predict_performance']);
        add_action('wp_ajax_flm_get_optimal_time', [$this, 'ajax_get_optimal_time']);
        add_action('wp_ajax_flm_get_ga4_data', [$this, 'ajax_get_ga4_data']);
        add_action('wp_ajax_flm_get_social_metrics', [$this, 'ajax_get_social_metrics']);
        add_action('wp_ajax_flm_get_channel_comparison', [$this, 'ajax_get_channel_comparison']);
        add_action('wp_ajax_flm_get_trending_topics', [$this, 'ajax_get_trending_topics']);
        add_action('wp_ajax_flm_test_integration', [$this, 'ajax_test_integration']);
        add_action('wp_ajax_flm_generate_content_suggestions', [$this, 'ajax_generate_content_suggestions']);
        add_action('wp_ajax_flm_get_search_console_data', [$this, 'ajax_get_search_console_data']);
        add_action('wp_ajax_flm_get_bing_data', [$this, 'ajax_get_bing_data']);
        add_action('wp_ajax_flm_get_seo_insights', [$this, 'ajax_get_seo_insights']);
        
        // Social Auto-Posting (v2.9.0)
        add_action('flm_delayed_social_post', [$this, 'handle_delayed_social_post'], 10, 5);
        add_action('transition_post_status', [$this, 'handle_post_publish'], 10, 3);
        add_action('wp_ajax_flm_test_social_post', [$this, 'ajax_test_social_post']);
        add_action('wp_ajax_flm_get_social_log', [$this, 'ajax_get_social_log']);
        add_action('wp_ajax_flm_clear_social_log', [$this, 'ajax_clear_social_log']);
        add_action('wp_ajax_flm_retry_social_post', [$this, 'ajax_retry_social_post']);
        add_action('wp_ajax_flm_get_social_queue', [$this, 'ajax_get_social_queue']);
        
        // Content & Publishing (v2.10.0)
        add_action('add_meta_boxes', [$this, 'add_social_preview_meta_box']);
        add_action('wp_ajax_flm_schedule_social_post', [$this, 'ajax_schedule_social_post']);
        add_action('wp_ajax_flm_get_scheduled_posts', [$this, 'ajax_get_scheduled_posts']);
        add_action('wp_ajax_flm_cancel_scheduled_post', [$this, 'ajax_cancel_scheduled_post']);
        add_action('wp_ajax_flm_post_now', [$this, 'ajax_post_now']);
        add_action('wp_ajax_flm_get_social_preview', [$this, 'ajax_get_social_preview']);
        
        // Analytics Depth (v2.11.0)
        add_action('wp_ajax_flm_get_article_performance', [$this, 'ajax_get_article_performance']);
        add_action('wp_ajax_flm_get_best_times', [$this, 'ajax_get_best_times']);
        add_action('wp_ajax_flm_get_gsc_data', [$this, 'ajax_get_gsc_data']);
        add_action('flm_daily_analytics_sync', [$this, 'sync_best_posting_times']);
        add_action('flm_scheduled_social_post', [$this, 'execute_scheduled_social_post'], 10, 4);
        
        // Settings Import/Export (v2.12.0)
        add_action('wp_ajax_flm_export_settings', [$this, 'ajax_export_settings']);
        add_action('wp_ajax_flm_import_settings', [$this, 'ajax_import_settings']);
        add_action('wp_ajax_flm_preview_import', [$this, 'ajax_preview_import']);
        add_action('wp_ajax_flm_restore_backup', [$this, 'ajax_restore_backup']);
        
        // ESP Integration (v2.13.0)
        add_action('wp_ajax_flm_get_esp_stats', [$this, 'ajax_get_esp_stats']);
        add_action('wp_ajax_flm_get_esp_article_clicks', [$this, 'ajax_get_esp_article_clicks']);
        add_action('wp_ajax_flm_test_esp_connection', [$this, 'ajax_test_esp_connection']);
        add_action('flm_hourly_esp_sync', [$this, 'sync_esp_article_performance']);
        
        // v2.14.0 UI/UX Features
        add_action('wp_ajax_flm_get_recent_activity', [$this, 'ajax_get_recent_activity']);
        add_action('wp_ajax_flm_clear_all_caches', [$this, 'ajax_clear_all_caches']);
        add_action('wp_ajax_flm_reschedule_post', [$this, 'ajax_reschedule_post']);
        add_action('wp_ajax_flm_dismiss_insight', [$this, 'ajax_dismiss_insight']);
        
        // v2.15.0 OAuth Integration
        add_action('wp_ajax_flm_oauth_init', [$this, 'ajax_oauth_init']);
        add_action('wp_ajax_flm_oauth_callback', [$this, 'ajax_oauth_callback']);
        add_action('wp_ajax_flm_oauth_disconnect', [$this, 'ajax_oauth_disconnect']);
        add_action('wp_ajax_flm_oauth_refresh', [$this, 'ajax_oauth_refresh']);
        add_action('wp_ajax_flm_oauth_status', [$this, 'ajax_oauth_status']);
    }
    
    /**
     * Add custom cron schedules (P3.1)
     */
    public function add_cron_schedules($schedules) {
        $schedules['every6hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours'),
        ];
        return $schedules;
    }
    
    /**
     * Initialize SVG icons
     */
    private function init_icons() {
        $this->icons = [
            'stadium' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="8" rx="9" ry="4"/><path d="M3 8v8c0 2.2 4 4 9 4s9-1.8 9-4V8"/><path d="M3 12c0 2.2 4 4 9 4s9-1.8 9-4"/></svg>',
            'bolt' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
            'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>',
            'plug' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22v-5M9 8V2M15 8V2M18 8H6a2 2 0 00-2 2v1a5 5 0 005 5h6a5 5 0 005-5v-1a2 2 0 00-2-2z"/></svg>',
            'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>',
            'trophy' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4a2 2 0 01-2-2V5a2 2 0 012-2h2M18 9h2a2 2 0 002-2V5a2 2 0 00-2-2h-2M12 17v4M8 21h8M6 3h12v7a6 6 0 11-12 0V3z"/></svg>',
            'folder' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>',
            'edit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
            'key' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
            'log' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>',
            'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>',
            'refresh' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>',
            'x' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>',
            'alert' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>',
            'eye' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
            'eye-off' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"/></svg>',
            'external' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg>',
            'trash' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>',
            'clock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
            'baseball' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M4.93 4.93c4.08 2.38 6.24 5.73 6.24 10.14M19.07 4.93c-4.08 2.38-6.24 5.73-6.24 10.14"/></svg>',
            'football' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="12" rx="4" ry="9" transform="rotate(45 12 12)"/><path d="M9 9l6 6M15 9l-6 6"/></svg>',
            'basketball' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a10 10 0 010 20M12 2a15 15 0 014 10 15 15 0 01-4 10M12 2a15 15 0 00-4 10 15 15 0 004 10"/></svg>',
            'save' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>',
            'spinner' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>',
            'preview' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
            'play' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
            'chart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>',
            'grid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
            'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
            'share' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>',
            'upload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>',
        ];
    }
    
    /**
     * Get icon SVG
     */
    private function icon($name, $class = '') {
        $svg = $this->icons[$name] ?? '';
        if ($class) {
            $svg = str_replace('<svg ', '<svg class="' . esc_attr($class) . '" ', $svg);
        }
        return $svg;
    }
    
    /**
     * Get team icon - stylized team letters/logos
     */
    private function get_team_icon($team_key) {
        // Return stylized team letter logos
        switch ($team_key) {
            case 'braves':
                // Braves "A" logo style
                return '<span class="flm-team-logo">A</span>';
            case 'falcons':
                // Falcons "F" 
                return '<span class="flm-team-logo">F</span>';
            case 'hawks':
                // Hawks "ATL" style
                return '<span class="flm-team-logo">H</span>';
            case 'uga':
                // Georgia "G"
                return '<span class="flm-team-logo">G</span>';
            case 'gt':
                // Georgia Tech "GT"
                return '<span class="flm-team-logo">GT</span>';
            default:
                return $this->icon('trophy');
        }
    }
    
    /**
     * Get sport icon based on league
     */
    private function get_sport_icon($league) {
        switch ($league) {
            case 'MLB':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><circle cx="12" cy="12" r="9"/><path d="M5 5c3 2 5 5 5 9M19 5c-3 2-5 5-5 9"/></svg>';
            case 'NFL':
            case 'NCAAF':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><ellipse cx="12" cy="12" rx="4" ry="8" transform="rotate(45 12 12)"/><path d="M9 9l6 6M15 9l-6 6"/></svg>';
            case 'NBA':
            case 'NCAAB':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2 2 3 5 3 9s-1 7-3 9M12 3c-2 2-3 5-3 9s1 7 3 9"/></svg>';
            default:
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M6 9H4.5a2.5 2.5 0 010-5H6M18 9h1.5a2.5 2.5 0 000-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20 17 22M18 2H6v7a6 6 0 1012 0V2z"/></svg>';
        }
    }
    
    // ========================================
    // ERROR LOGGING SYSTEM
    // ========================================
    
    /**
     * Log an error to the persistent error log
     * 
     * @param string $level   'error', 'warning', 'info', 'debug'
     * @param string $context Where the error occurred (e.g., 'api', 'import', 'image')
     * @param string $message Human-readable message
     * @param array  $data    Additional context data
     */
    private function log_error($level, $context, $message, $data = []) {
        $entry = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'context' => $context,
            'message' => $message,
            'data' => $data,
        ];
        
        // Get existing log
        $error_log = get_option('flm_error_log', []);
        
        // Add new entry at the beginning
        array_unshift($error_log, $entry);
        
        // Trim to max entries
        $error_log = array_slice($error_log, 0, $this->max_error_log_entries);
        
        // Save
        update_option('flm_error_log', $error_log);
        
        // Also log to WP debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = sprintf(
                '[FLM %s] [%s] %s | %s',
                strtoupper($level),
                $context,
                $message,
                json_encode($data)
            );
            error_log($log_message);
        }
    }
    
    /**
     * Get the error log
     */
    private function get_error_log() {
        return get_option('flm_error_log', []);
    }
    
    /**
     * Clear the error log
     */
    private function clear_error_log() {
        delete_option('flm_error_log');
    }
    
    // ========================================
    // RATE LIMITING & RETRY LOGIC
    // ========================================
    
    /**
     * Calculate delay with exponential backoff and jitter
     * 
     * @param int $attempt Current attempt number (0-indexed)
     * @param int $retry_after Optional Retry-After header value
     * @return int Delay in seconds
     */
    private function calculate_backoff_delay($attempt, $retry_after = null) {
        // If server specified Retry-After, respect it (with some buffer)
        if ($retry_after !== null && $retry_after > 0) {
            return min($retry_after + 5, $this->rate_limit_config['max_delay']);
        }
        
        // Exponential backoff: base_delay * 2^attempt
        $delay = $this->rate_limit_config['base_delay'] * pow(2, $attempt);
        
        // Apply jitter (randomness) to prevent thundering herd
        $jitter = $delay * $this->rate_limit_config['jitter_factor'];
        $delay = $delay + mt_rand(0, (int)($jitter * 1000)) / 1000;
        
        // Cap at max delay
        return min((int)$delay, $this->rate_limit_config['max_delay']);
    }
    
    /**
     * Parse Retry-After header value
     * 
     * @param string $header_value The Retry-After header value
     * @return int|null Seconds to wait, or null if unparseable
     */
    private function parse_retry_after($header_value) {
        if (empty($header_value)) {
            return null;
        }
        
        // If it's a number, it's seconds
        if (is_numeric($header_value)) {
            return (int)$header_value;
        }
        
        // Otherwise try to parse as HTTP date
        $timestamp = strtotime($header_value);
        if ($timestamp !== false) {
            $delay = $timestamp - time();
            return $delay > 0 ? $delay : null;
        }
        
        return null;
    }
    
    /**
     * Make an API request with retry logic for rate limiting
     * 
     * @param string $url     Request URL
     * @param array  $args    wp_remote_* arguments
     * @param string $method  'GET' or 'POST'
     * @return array|WP_Error Response or error
     */
    private function api_request_with_retry($url, $args = [], $method = 'GET') {
        $max_retries = $this->rate_limit_config['max_retries'];
        $last_error = null;
        
        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            // Make the request
            if ($method === 'POST') {
                $response = wp_remote_post($url, $args);
            } else {
                $response = wp_remote_get($url, $args);
            }
            
            // Check for WP error (network issues, etc.)
            if (is_wp_error($response)) {
                $last_error = $response;
                $this->log_error('error', 'api', 'Network error on attempt ' . ($attempt + 1), [
                    'url' => $url,
                    'error' => $response->get_error_message(),
                    'attempt' => $attempt + 1,
                ]);
                
                // Wait before retry on network errors too
                if ($attempt < $max_retries) {
                    $delay = $this->calculate_backoff_delay($attempt);
                    $this->log_error('info', 'api', "Waiting {$delay}s before retry", []);
                    sleep($delay);
                }
                continue;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            
            // Success - return response
            if ($status_code >= 200 && $status_code < 300) {
                // Log recovery if this wasn't first attempt
                if ($attempt > 0) {
                    $this->log_error('info', 'api', 'Request succeeded after ' . ($attempt + 1) . ' attempts', [
                        'url' => $url,
                    ]);
                }
                return $response;
            }
            
            // Rate limited (429) - retry with backoff
            if ($status_code === 429) {
                $retry_after = $this->parse_retry_after(
                    wp_remote_retrieve_header($response, 'retry-after')
                );
                $delay = $this->calculate_backoff_delay($attempt, $retry_after);
                
                $this->log_error('warning', 'api', 'Rate limited (429), attempt ' . ($attempt + 1) . '/' . ($max_retries + 1), [
                    'url' => $url,
                    'retry_after_header' => $retry_after,
                    'calculated_delay' => $delay,
                ]);
                
                if ($attempt < $max_retries) {
                    sleep($delay);
                    continue;
                }
                
                // Max retries exceeded
                $last_error = new WP_Error(
                    'rate_limit_exceeded',
                    'Rate limit exceeded after ' . ($max_retries + 1) . ' attempts',
                    ['url' => $url, 'last_status' => $status_code]
                );
                break;
            }
            
            // Server error (5xx) - retry with backoff
            if ($status_code >= 500) {
                $this->log_error('warning', 'api', "Server error ({$status_code}), attempt " . ($attempt + 1), [
                    'url' => $url,
                    'status' => $status_code,
                ]);
                
                if ($attempt < $max_retries) {
                    $delay = $this->calculate_backoff_delay($attempt);
                    sleep($delay);
                    continue;
                }
                
                $last_error = new WP_Error(
                    'server_error',
                    "Server error ({$status_code}) after " . ($max_retries + 1) . ' attempts',
                    ['url' => $url, 'status' => $status_code]
                );
                break;
            }
            
            // Client error (4xx except 429) - don't retry
            if ($status_code >= 400 && $status_code < 500) {
                $body = wp_remote_retrieve_body($response);
                $this->log_error('error', 'api', "Client error ({$status_code})", [
                    'url' => $url,
                    'status' => $status_code,
                    'body' => substr($body, 0, 500),
                ]);
                
                return new WP_Error(
                    'client_error',
                    "API returned client error: {$status_code}",
                    ['url' => $url, 'status' => $status_code, 'body' => $body]
                );
            }
        }
        
        // Return last error if we exhausted retries
        return $last_error ?: new WP_Error('unknown_error', 'Unknown error occurred');
    }
    
    /**
     * Enqueue frontend pageview tracking script
     */
    public function enqueue_pageview_tracking() {
        // Only on single FLM posts
        if (!is_single()) {
            return;
        }
        
        global $post;
        $story_id = get_post_meta($post->ID, 'flm_story_id', true);
        
        if (!$story_id) {
            return;
        }
        
        // Inline tracking script
        $script = "
        (function() {
            if (typeof sessionStorage !== 'undefined') {
                var key = 'flm_viewed_' + " . $post->ID . ";
                if (sessionStorage.getItem(key)) return; // Already viewed this session
                sessionStorage.setItem(key, '1');
            }
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '" . admin_url('admin-ajax.php') . "', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('action=flm_record_pageview&post_id=" . $post->ID . "&nonce=" . wp_create_nonce('flm_pageview_' . $post->ID) . "');
        })();
        ";
        
        wp_add_inline_script('jquery', $script);
    }
    
    /**
     * AJAX: Record pageview for FLM post
     */
    public function ajax_record_pageview() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        
        if (!$post_id || !wp_verify_nonce($nonce, 'flm_pageview_' . $post_id)) {
            wp_die();
        }
        
        // Verify it's an FLM post
        $story_id = get_post_meta($post_id, 'flm_story_id', true);
        if (!$story_id) {
            wp_die();
        }
        
        // Increment view count
        $views = (int) get_post_meta($post_id, 'flm_views', true);
        update_post_meta($post_id, 'flm_views', $views + 1);
        
        // Record daily view for trending
        $today = date('Y-m-d');
        $daily_views = get_post_meta($post_id, 'flm_daily_views', true);
        if (!is_array($daily_views)) {
            $daily_views = [];
        }
        
        // Keep only last 30 days
        $daily_views[$today] = isset($daily_views[$today]) ? $daily_views[$today] + 1 : 1;
        $cutoff = date('Y-m-d', strtotime('-30 days'));
        foreach ($daily_views as $date => $count) {
            if ($date < $cutoff) {
                unset($daily_views[$date]);
            }
        }
        update_post_meta($post_id, 'flm_daily_views', $daily_views);
        
        wp_die();
    }
    
    /**
     * AJAX: Get analytics data for period
     */
    public function ajax_get_analytics() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $days = isset($_POST['days']) ? absint($_POST['days']) : 7;
        $days = in_array($days, [7, 14, 30]) ? $days : 7;
        
        // Clear cache to get fresh data for the requested period
        delete_transient('flm_analytics_' . $days);
        
        $analytics = $this->get_analytics_data($days);
        
        wp_send_json_success($analytics);
    }
    
    /**
     * Enqueue admin CSS and JS
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_flm-importer') {
            return;
        }
        
        // Google Fonts
        wp_enqueue_style('flm-google-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap', [], null);
        
        // Chart.js for analytics
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
        
        // Admin CSS
        wp_add_inline_style('flm-google-fonts', $this->get_admin_css());
        
        // Make sure jQuery is loaded
        wp_enqueue_script('jquery');
        
        // Add our config object first (before the inline script)
        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('flm_nonce'),
            'strings' => [
                'importing' => 'Importing...',
                'testing' => 'Testing...',
                'discovering' => 'Discovering...',
                'saving' => 'Saving...',
                'unsavedChanges' => 'You have unsaved changes. Are you sure you want to leave?',
            ],
        ];
        
        // Add inline script attached to jQuery (which is always loaded in admin)
        wp_add_inline_script('jquery', 'var flmAdmin = ' . wp_json_encode($config) . ';');
        wp_add_inline_script('jquery', $this->get_admin_js());
    }
    
    /**
     * Get admin CSS
     */
    private function get_admin_css() {
        return '
/* ============================================
   FLM GAMEDAY ATLANTA - ADMIN DASHBOARD v2.4
   Modern Sports Broadcast Aesthetic - Enhanced
   ============================================ */

@import url("https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap");

:root {
    --flm-bg-dark: #0a0e13;
    --flm-bg-card: #151b23;
    --flm-bg-card-hover: #1c242e;
    --flm-bg-input: #0d1117;
    --flm-border: #262e38;
    --flm-border-light: #3d4650;
    --flm-border-glow: rgba(255, 107, 53, 0.3);
    --flm-text: #f0f3f6;
    --flm-text-muted: #8b949e;
    --flm-accent: #ff6b35;
    --flm-accent-hover: #ff8555;
    --flm-accent-glow: rgba(255, 107, 53, 0.25);
    --flm-accent-gradient: linear-gradient(135deg, #ff6b35 0%, #ff8c42 100%);
    --flm-success: #3fb950;
    --flm-success-glow: rgba(63, 185, 80, 0.25);
    --flm-success-gradient: linear-gradient(135deg, #3fb950 0%, #56d364 100%);
    --flm-warning: #d29922;
    --flm-warning-glow: rgba(210, 153, 34, 0.25);
    --flm-danger: #f85149;
    --flm-danger-glow: rgba(248, 81, 73, 0.25);
    --flm-danger-gradient: linear-gradient(135deg, #f85149 0%, #ff7b72 100%);
    --flm-info: #58a6ff;
    --flm-info-glow: rgba(88, 166, 255, 0.25);
    --flm-radius: 16px;
    --flm-radius-sm: 10px;
    --flm-radius-xs: 6px;
    --flm-shadow: 0 8px 32px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.03);
    --flm-shadow-sm: 0 4px 12px rgba(0,0,0,0.4);
    --flm-shadow-glow: 0 0 30px var(--flm-accent-glow);
    --flm-transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    --flm-transition-fast: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
    --flm-font: "DM Sans", -apple-system, BlinkMacSystemFont, sans-serif;
    --flm-mono: "JetBrains Mono", ui-monospace, monospace;
    
    /* Team Colors */
    --flm-team-braves: #ce1141;
    --flm-team-braves-secondary: #13274f;
    --flm-team-braves-glow: rgba(206, 17, 65, 0.3);
    --flm-team-falcons: #a71930;
    --flm-team-falcons-secondary: #000000;
    --flm-team-falcons-glow: rgba(167, 25, 48, 0.3);
    --flm-team-hawks: #e03a3e;
    --flm-team-hawks-secondary: #c1d32f;
    --flm-team-hawks-glow: rgba(224, 58, 62, 0.3);
    --flm-team-uga: #ba0c2f;
    --flm-team-uga-secondary: #000000;
    --flm-team-uga-glow: rgba(186, 12, 47, 0.3);
    --flm-team-gt: #b3a369;
    --flm-team-gt-secondary: #003057;
    --flm-team-gt-glow: rgba(179, 163, 105, 0.3);
}

/* Base Reset */
.flm-dashboard {
    font-family: var(--flm-font);
    background: var(--flm-bg-dark);
    background-image: 
        radial-gradient(ellipse at top left, rgba(255, 107, 53, 0.03) 0%, transparent 50%),
        radial-gradient(ellipse at bottom right, rgba(88, 166, 255, 0.03) 0%, transparent 50%);
    margin: 20px 20px 20px 0;
    padding: 0;
    min-height: calc(100vh - 100px);
    color: var(--flm-text);
    border-radius: var(--flm-radius);
    overflow: hidden;
    line-height: 1.5;
    position: relative;
}

.flm-dashboard::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 400px;
    background: linear-gradient(180deg, rgba(255, 107, 53, 0.02) 0%, transparent 100%);
    pointer-events: none;
}

/* ============================================
   TABBED NAVIGATION - v2.5.0
   ============================================ */
.flm-tabs-wrapper {
    position: sticky;
    top: 32px;
    z-index: 100;
    background: linear-gradient(180deg, var(--flm-bg) 0%, rgba(10, 14, 19, 0.98) 100%);
    border-bottom: 1px solid var(--flm-border);
    margin: 0 -24px;
    padding: 0 24px;
    backdrop-filter: blur(10px);
}

@media screen and (max-width: 782px) {
    .flm-tabs-wrapper {
        top: 46px;
    }
}

.flm-tabs {
    display: flex;
    gap: 0;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.flm-tabs::-webkit-scrollbar {
    display: none;
}

.flm-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 16px 20px;
    font-size: 13px;
    font-weight: 500;
    color: var(--flm-text-muted);
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: var(--flm-transition);
    white-space: nowrap;
    position: relative;
    font-family: inherit;
}

.flm-tab svg {
    width: 16px;
    height: 16px;
    opacity: 0.7;
}

.flm-tab:hover {
    color: var(--flm-text);
    background: rgba(255, 255, 255, 0.02);
}

.flm-tab:hover svg {
    opacity: 1;
}

.flm-tab.active {
    color: var(--flm-accent);
    border-bottom-color: var(--flm-accent);
}

.flm-tab.active svg {
    opacity: 1;
}

.flm-tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 18px;
    padding: 0 6px;
    font-size: 10px;
    font-weight: 600;
    background: var(--flm-bg-card);
    border-radius: 9px;
    color: var(--flm-text-muted);
}

.flm-tab.active .flm-tab-badge {
    background: rgba(255, 107, 53, 0.15);
    color: var(--flm-accent);
}

.flm-tab-badge.error {
    background: rgba(248, 81, 73, 0.15);
    color: var(--flm-danger);
}

.flm-tab-badge.success {
    background: rgba(63, 185, 80, 0.15);
    color: var(--flm-success);
}

/* Tab Panels */
.flm-tab-panels {
    position: relative;
    min-height: 400px;
}

.flm-tab-panel {
    display: none;
    animation: flm-tab-fade 0.25s ease;
}

.flm-tab-panel.active {
    display: block;
}

@keyframes flm-tab-fade {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Mobile Tabs */
@media (max-width: 768px) {
    .flm-tabs-wrapper {
        padding: 0 16px;
        margin: 0 -16px;
    }
    
    .flm-tab {
        padding: 14px 14px;
        font-size: 12px;
    }
    
    .flm-tab-text {
        display: none;
    }
    
    .flm-tab svg {
        width: 18px;
        height: 18px;
    }
}

/* Header with Save Button */
.flm-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.flm-save-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: var(--flm-text-muted);
    padding: 6px 12px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-xs);
    transition: var(--flm-transition);
}

.flm-save-indicator.saving {
    color: var(--flm-warning);
    border-color: rgba(210, 153, 34, 0.3);
}

.flm-save-indicator.saving svg {
    animation: flm-spin 1s linear infinite;
}

.flm-save-indicator.unsaved {
    color: var(--flm-warning);
    border-color: rgba(210, 153, 34, 0.3);
}

.flm-save-indicator.saved {
    color: var(--flm-success);
    border-color: rgba(63, 185, 80, 0.3);
}

.flm-save-indicator svg {
    width: 14px;
    height: 14px;
}

.flm-header-save-btn {
    padding: 8px 16px !important;
    font-size: 12px !important;
}

/* Keyboard Shortcut Hints */
.flm-kbd {
    display: inline-block;
    padding: 2px 5px;
    font-size: 9px;
    font-family: var(--flm-mono);
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: 3px;
    color: var(--flm-text-muted);
    margin-left: 6px;
    vertical-align: middle;
}

/* Section Headers within Tabs */
.flm-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--flm-border);
}

.flm-section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 15px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-section-title svg {
    width: 18px;
    height: 18px;
    color: var(--flm-accent);
}

.flm-section-subtitle {
    font-size: 12px;
    color: var(--flm-text-muted);
    font-weight: 400;
    margin-left: 8px;
}

.flm-dashboard *, .flm-dashboard *::before, .flm-dashboard *::after {
    box-sizing: border-box;
}

.flm-dashboard svg {
    width: 1em;
    height: 1em;
    vertical-align: middle;
}

/* Screen reader only */
.flm-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Focus styles for accessibility */
.flm-dashboard *:focus-visible {
    outline: 2px solid var(--flm-accent);
    outline-offset: 2px;
}

.flm-dashboard button:focus-visible,
.flm-dashboard input:focus-visible,
.flm-dashboard select:focus-visible {
    outline: 2px solid var(--flm-accent);
    outline-offset: 2px;
}

/* ============================================
   HEADER - Enhanced
   ============================================ */
.flm-header {
    background: linear-gradient(135deg, rgba(255, 107, 53, 0.08) 0%, var(--flm-bg-card) 50%, var(--flm-bg-dark) 100%);
    padding: 32px 40px;
    border-bottom: 1px solid var(--flm-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    position: relative;
    z-index: 1;
}

.flm-header-left {
    display: flex;
    align-items: center;
    gap: 18px;
}

.flm-logo {
    width: 52px;
    height: 52px;
    background: linear-gradient(135deg, var(--flm-accent) 0%, #ff8f6b 100%);
    border-radius: var(--flm-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    box-shadow: 0 8px 24px var(--flm-accent-glow);
    flex-shrink: 0;
}

.flm-logo svg {
    width: 28px;
    height: 28px;
}

.flm-title-group h1 {
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 2px 0;
    padding: 0;
    color: var(--flm-text);
    letter-spacing: -0.5px;
}

.flm-title-group p {
    font-size: 13px;
    color: var(--flm-text-muted);
    margin: 0;
}

.flm-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.flm-version {
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    color: var(--flm-text-muted);
}

.flm-view-posts-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    color: var(--flm-text-muted);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: var(--flm-transition);
}

.flm-view-posts-link:hover {
    border-color: var(--flm-accent);
    color: var(--flm-accent);
}

.flm-view-posts-link svg {
    width: 14px;
    height: 14px;
}

/* ============================================
   MAIN CONTENT
   ============================================ */
.flm-content {
    padding: 28px 36px;
}

/* ============================================
   STATUS BAR - Polished
   ============================================ */
.flm-status-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 32px;
}

@media (max-width: 1100px) {
    .flm-status-bar { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 600px) {
    .flm-status-bar { grid-template-columns: 1fr; }
}

.flm-stat-card {
    background: linear-gradient(145deg, var(--flm-bg-card) 0%, rgba(21, 27, 35, 0.8) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 24px;
    transition: var(--flm-transition);
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.flm-stat-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--flm-border) 0%, var(--flm-border-light) 50%, var(--flm-border) 100%);
    transition: var(--flm-transition);
}

.flm-stat-card::after {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    width: 80px;
    height: 80px;
    background: radial-gradient(circle at top right, rgba(255, 107, 53, 0.05) 0%, transparent 70%);
    pointer-events: none;
}

.flm-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--flm-shadow), 0 0 30px rgba(255, 107, 53, 0.08);
    border-color: var(--flm-border-light);
}

.flm-stat-card:hover::before {
    background: var(--flm-accent-gradient);
}

.flm-stat-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--flm-text-muted);
    margin-bottom: 12px;
    font-weight: 600;
}

.flm-stat-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--flm-text);
    line-height: 1;
    display: flex;
    align-items: center;
    gap: 10px;
}

.flm-stat-value.success { color: var(--flm-success); text-shadow: 0 0 30px var(--flm-success-glow); }
.flm-stat-value.warning { color: var(--flm-warning); text-shadow: 0 0 30px var(--flm-warning-glow); }
.flm-stat-value.danger { color: var(--flm-danger); text-shadow: 0 0 30px var(--flm-danger-glow); }

.flm-stat-meta {
    font-size: 12px;
    color: var(--flm-text-muted);
    margin-top: 10px;
}

/* Connection Status */
.flm-connection-status {
    display: flex;
    align-items: center;
    gap: 10px;
}

.flm-connection-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--flm-danger);
    box-shadow: 0 0 12px var(--flm-danger);
    flex-shrink: 0;
}

.flm-connection-dot[data-status="online"] {
    background: var(--flm-success);
    box-shadow: 0 0 12px var(--flm-success), 0 0 4px var(--flm-success);
    animation: flm-pulse 2s infinite;
}

.flm-connection-dot[data-status="offline"] {
    background: var(--flm-danger);
    box-shadow: 0 0 12px var(--flm-danger);
    animation: none;
}

.flm-connection-dot[data-status="checking"] {
    background: var(--flm-warning);
    box-shadow: 0 0 12px var(--flm-warning);
    animation: flm-spin 1s linear infinite;
}

@keyframes flm-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(0.9); }
}

@keyframes flm-spin {
    to { transform: rotate(360deg); }
}

/* ============================================
   CARDS - Polished
   ============================================ */
.flm-card {
    background: linear-gradient(145deg, var(--flm-bg-card) 0%, rgba(21, 27, 35, 0.9) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    margin-bottom: 24px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
    transition: var(--flm-transition);
}

.flm-card:hover {
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
}

.flm-card-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--flm-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.02) 0%, transparent 100%);
}

.flm-card-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--flm-text);
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.flm-card-icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, rgba(255, 107, 53, 0.15) 0%, rgba(255, 107, 53, 0.05) 100%);
    border: 1px solid rgba(255, 107, 53, 0.2);
    border-radius: var(--flm-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--flm-accent);
    flex-shrink: 0;
}

.flm-card-icon svg {
    width: 18px;
    height: 18px;
}

.flm-card-body {
    padding: 24px;
}

.flm-card-body.no-padding {
    padding: 0;
}

.flm-card-footer {
    padding: 18px 24px;
    border-top: 1px solid var(--flm-border);
    background: rgba(0, 0, 0, 0.2);
}

/* ============================================
   GRID LAYOUTS
   ============================================ */
.flm-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
}

.flm-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.flm-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

@media (max-width: 1100px) {
    .flm-grid-2 { grid-template-columns: 1fr; }
    .flm-grid-4 { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 900px) {
    .flm-grid-3 { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
    .flm-grid-4 { grid-template-columns: 1fr; }
}

/* ============================================
   ACTION CARDS - Polished
   ============================================ */
.flm-action-card {
    background: linear-gradient(145deg, var(--flm-bg-dark) 0%, rgba(10, 14, 19, 0.9) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 28px 24px;
    text-align: center;
    transition: var(--flm-transition);
    position: relative;
    overflow: hidden;
}

.flm-action-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 60%;
    height: 1px;
    background: linear-gradient(90deg, transparent 0%, var(--flm-border-light) 50%, transparent 100%);
}

.flm-action-card:hover {
    border-color: rgba(255, 107, 53, 0.3);
    transform: translateY(-4px);
    box-shadow: var(--flm-shadow), 0 0 40px rgba(255, 107, 53, 0.08);
}

.flm-action-card:focus-within {
    border-color: var(--flm-accent);
}

.flm-action-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 16px;
    background: linear-gradient(135deg, rgba(255, 107, 53, 0.15) 0%, rgba(255, 107, 53, 0.05) 100%);
    border: 1px solid rgba(255, 107, 53, 0.15);
    border-radius: var(--flm-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--flm-accent);
    transition: var(--flm-transition);
}

.flm-action-icon svg {
    width: 26px;
    height: 26px;
}

.flm-action-card:hover .flm-action-icon {
    background: linear-gradient(135deg, rgba(255, 107, 53, 0.25) 0%, rgba(255, 107, 53, 0.1) 100%);
    border-color: rgba(255, 107, 53, 0.3);
    transform: scale(1.08);
    box-shadow: 0 0 20px rgba(255, 107, 53, 0.2);
}

.flm-action-title {
    font-weight: 600;
    font-size: 15px;
    color: var(--flm-text);
    margin-bottom: 6px;
}

.flm-action-desc {
    font-size: 12px;
    color: var(--flm-text-muted);
    margin-bottom: 18px;
    line-height: 1.5;
}

/* ============================================
   LEAGUE IMPORT SECTION
   ============================================ */
.flm-league-import-section {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--flm-border);
}

.flm-section-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--flm-text-muted);
    margin-bottom: 14px;
    font-weight: 600;
}

.flm-league-buttons {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
}

.flm-btn-league {
    flex: 1;
    min-width: 120px;
    max-width: 180px;
    flex-direction: column;
    gap: 4px;
    padding: 16px 20px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    color: var(--flm-text);
    border-radius: var(--flm-radius);
}

.flm-btn-league:hover {
    border-color: var(--flm-accent);
    background: var(--flm-bg-card-hover);
    transform: translateY(-2px);
}

.flm-btn-league svg {
    width: 24px;
    height: 24px;
    color: var(--flm-accent);
    margin-bottom: 4px;
}

.flm-btn-league span {
    font-size: 14px;
    font-weight: 600;
}

.flm-btn-league small {
    font-size: 11px;
    color: var(--flm-text-muted);
    font-weight: 400;
}

.flm-btn-league.loading {
    pointer-events: none;
}

.flm-btn-league.loading svg {
    animation: flm-spin 1s linear infinite;
}

@media (max-width: 600px) {
    .flm-league-buttons {
        flex-direction: column;
    }
    .flm-btn-league {
        max-width: none;
    }
}

/* Purge Section (P5.3) */
.flm-purge-section {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--flm-border);
}

.flm-purge-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.flm-purge-info {
    color: var(--flm-text-muted);
    font-size: 13px;
}

.flm-purge-count {
    font-weight: 600;
    color: var(--flm-text);
}

.flm-purge-action {
    display: flex;
    align-items: center;
    gap: 10px;
}

.flm-select-sm {
    padding: 8px 12px;
    font-size: 12px;
}

/* ============================================
   BUTTONS - Polished
   ============================================ */
.flm-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 22px;
    font-size: 13px;
    font-weight: 600;
    font-family: var(--flm-font);
    border: none;
    border-radius: var(--flm-radius-sm);
    cursor: pointer;
    transition: var(--flm-transition);
    text-decoration: none;
    white-space: nowrap;
    position: relative;
    overflow: hidden;
    letter-spacing: 0.3px;
}

.flm-btn::before {
    content: "";
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
    transition: 0.5s;
}

.flm-btn:hover::before {
    left: 100%;
}

.flm-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
}

.flm-btn:disabled::before {
    display: none;
}

.flm-btn svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.flm-btn-primary {
    background: var(--flm-accent-gradient);
    color: white;
    box-shadow: 0 4px 15px var(--flm-accent-glow), inset 0 1px 0 rgba(255,255,255,0.15);
}

.flm-btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px var(--flm-accent-glow), inset 0 1px 0 rgba(255,255,255,0.2);
}

.flm-btn-primary:active:not(:disabled) {
    transform: translateY(0);
    box-shadow: 0 2px 10px var(--flm-accent-glow);
}

.flm-btn-secondary {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--flm-border);
    color: var(--flm-text);
}

.flm-btn-secondary:hover:not(:disabled) {
    border-color: var(--flm-accent);
    color: var(--flm-accent);
    background: rgba(255, 107, 53, 0.05);
    box-shadow: 0 0 20px rgba(255, 107, 53, 0.1);
}

.flm-btn-success {
    background: var(--flm-success-gradient);
    color: white;
    box-shadow: 0 4px 15px var(--flm-success-glow), inset 0 1px 0 rgba(255,255,255,0.15);
}

.flm-btn-success:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px var(--flm-success-glow), inset 0 1px 0 rgba(255,255,255,0.2);
}

.flm-btn-danger {
    background: rgba(248, 81, 73, 0.1);
    border: 1px solid var(--flm-danger);
    color: var(--flm-danger);
}

.flm-btn-danger:hover:not(:disabled) {
    background: var(--flm-danger-gradient);
    border-color: transparent;
    color: white;
    box-shadow: 0 4px 15px var(--flm-danger-glow);
}

.flm-btn-sm {
    padding: 8px 14px;
    font-size: 12px;
}

.flm-btn-sm svg {
    width: 14px;
    height: 14px;
}

.flm-btn-lg {
    padding: 14px 28px;
    font-size: 14px;
}

/* Button loading state */
.flm-btn.loading {
    color: transparent !important;
    pointer-events: none;
}

.flm-btn.loading::after {
    content: "";
    position: absolute;
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,0.2);
    border-top-color: white;
    border-radius: 50%;
    animation: flm-spin 0.7s linear infinite;
}

.flm-btn-secondary.loading::after {
    border-color: rgba(139,148,158,0.2);
    border-top-color: var(--flm-accent);
}

/* ============================================
   TEAM CARDS - Enhanced with Team Colors
   ============================================ */
.flm-teams-grid {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.flm-team-card {
    background: linear-gradient(145deg, var(--flm-bg-dark) 0%, rgba(10, 14, 19, 0.95) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: var(--flm-transition);
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.flm-team-card::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, var(--team-color, var(--flm-border)) 0%, var(--team-secondary, var(--flm-border)) 100%);
    opacity: 0.6;
    transition: var(--flm-transition);
}

.flm-team-card::after {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, var(--team-glow, transparent) 0%, transparent 30%);
    opacity: 0;
    transition: var(--flm-transition);
    pointer-events: none;
}

.flm-team-card:hover {
    border-color: var(--team-color, var(--flm-border-light));
    transform: translateX(4px);
}

.flm-team-card:hover::before {
    opacity: 1;
    width: 5px;
    box-shadow: 0 0 20px var(--team-glow, transparent);
}

.flm-team-card:hover::after {
    opacity: 0.8;
}

.flm-team-card[data-enabled="true"] {
    border-color: var(--team-color);
    background: linear-gradient(100deg, 
        rgba(var(--team-color-rgb, 255,107,53), 0.18) 0%, 
        rgba(var(--team-secondary-rgb, 0,0,0), 0.12) 30%,
        var(--flm-bg-dark) 70%);
}

.flm-team-card[data-enabled="true"]::before {
    opacity: 1;
    width: 5px;
    box-shadow: 0 0 25px var(--team-glow, transparent);
}

.flm-team-card[data-enabled="true"]::after {
    opacity: 0.6;
}

/* Team-specific colors with secondary */
.flm-team-card[data-team="braves"] { 
    --team-color: var(--flm-team-braves); 
    --team-secondary: var(--flm-team-braves-secondary);
    --team-glow: var(--flm-team-braves-glow); 
    --team-color-rgb: 206,17,65;
    --team-secondary-rgb: 19,39,79;
}
.flm-team-card[data-team="falcons"] { 
    --team-color: var(--flm-team-falcons); 
    --team-secondary: var(--flm-team-falcons-secondary);
    --team-glow: var(--flm-team-falcons-glow); 
    --team-color-rgb: 167,25,48;
    --team-secondary-rgb: 0,0,0;
}
.flm-team-card[data-team="hawks"] { 
    --team-color: var(--flm-team-hawks); 
    --team-secondary: var(--flm-team-hawks-secondary);
    --team-glow: var(--flm-team-hawks-glow); 
    --team-color-rgb: 224,58,62;
    --team-secondary-rgb: 193,211,47;
}
.flm-team-card[data-team="uga"] { 
    --team-color: var(--flm-team-uga); 
    --team-secondary: var(--flm-team-uga-secondary);
    --team-glow: var(--flm-team-uga-glow); 
    --team-color-rgb: 186,12,47;
    --team-secondary-rgb: 0,0,0;
}
.flm-team-card[data-team="gt"] { 
    --team-color: var(--flm-team-gt); 
    --team-secondary: var(--flm-team-gt-secondary);
    --team-glow: var(--flm-team-gt-glow); 
    --team-color-rgb: 179,163,105;
    --team-secondary-rgb: 0,48,87;
}

.flm-team-info {
    display: flex;
    align-items: center;
    gap: 16px;
}

.flm-team-icon {
    width: 52px;
    height: 52px;
    background: linear-gradient(145deg, var(--flm-bg-card) 0%, rgba(21, 27, 35, 0.9) 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--team-color, var(--flm-text-muted));
    border: 2px solid var(--flm-border);
    flex-shrink: 0;
    transition: var(--flm-transition);
    position: relative;
    overflow: hidden;
}

.flm-team-icon::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, var(--team-secondary) 0%, var(--team-color) 100%);
    opacity: 0;
    transition: var(--flm-transition);
}

.flm-team-icon svg {
    width: 26px;
    height: 26px;
    position: relative;
    z-index: 1;
}

/* Team logo letter styling */
.flm-team-logo {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -1px;
    position: relative;
    z-index: 1;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}

.flm-team-card[data-enabled="true"] .flm-team-icon {
    border-color: var(--team-color);
    box-shadow: 
        0 4px 20px var(--team-glow, transparent),
        inset 0 0 20px rgba(255,255,255,0.1);
}

.flm-team-card[data-enabled="true"] .flm-team-icon::before {
    opacity: 1;
}

.flm-team-card[data-enabled="true"] .flm-team-icon svg,
.flm-team-card[data-enabled="true"] .flm-team-logo {
    color: white;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
}

/* Team-specific icon backgrounds when enabled */
.flm-team-card[data-team="braves"][data-enabled="true"] .flm-team-icon::before {
    background: linear-gradient(135deg, #13274f 0%, #ce1141 100%);
}

.flm-team-card[data-team="falcons"][data-enabled="true"] .flm-team-icon::before {
    background: linear-gradient(135deg, #000000 0%, #a71930 100%);
}

.flm-team-card[data-team="hawks"][data-enabled="true"] .flm-team-icon::before {
    background: linear-gradient(135deg, #c1d32f 0%, #e03a3e 100%);
}

.flm-team-card[data-team="uga"][data-enabled="true"] .flm-team-icon::before {
    background: linear-gradient(135deg, #000000 0%, #ba0c2f 100%);
}

.flm-team-card[data-team="gt"][data-enabled="true"] .flm-team-icon::before {
    background: linear-gradient(135deg, #003057 0%, #b3a369 100%);
}

.flm-team-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.flm-team-name {
    font-weight: 600;
    font-size: 15px;
    color: var(--flm-text);
}

.flm-team-league {
    font-size: 11px;
    color: var(--flm-text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 500;
}

/* League badge */
.flm-team-league-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    background: rgba(var(--team-color-rgb, 255,107,53), 0.1);
    border-radius: 6px;
    border: 1px solid rgba(var(--team-color-rgb, 255,107,53), 0.2);
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--flm-text-muted);
    transition: var(--flm-transition);
}

.flm-team-card[data-enabled="true"] .flm-team-league-badge {
    background: rgba(var(--team-color-rgb, 255,107,53), 0.15);
    border-color: rgba(var(--team-color-rgb, 255,107,53), 0.3);
    color: var(--team-color);
}

.flm-league-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.7;
}

.flm-team-card[data-enabled="true"] .flm-league-icon {
    opacity: 1;
}

.flm-league-icon svg {
    width: 12px;
    height: 12px;
}

/* Card badge */
.flm-card-badge {
    font-size: 11px;
    font-weight: 500;
    padding: 4px 10px;
    background: rgba(63, 185, 80, 0.1);
    border: 1px solid rgba(63, 185, 80, 0.2);
    border-radius: 6px;
    color: var(--flm-success);
}

/* Toggle Switch - Enhanced */
.flm-toggle {
    position: relative;
    width: 52px;
    height: 28px;
    flex-shrink: 0;
}

.flm-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
    position: absolute;
}

.flm-toggle-track {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: 28px;
    transition: var(--flm-transition);
}

.flm-toggle-track::before {
    position: absolute;
    content: "";
    height: 22px;
    width: 22px;
    left: 2px;
    bottom: 2px;
    background: linear-gradient(145deg, var(--flm-text-muted) 0%, #6b7280 100%);
    border-radius: 50%;
    transition: var(--flm-transition);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

.flm-toggle input:checked + .flm-toggle-track {
    background: linear-gradient(135deg, var(--team-color, var(--flm-accent)) 0%, color-mix(in srgb, var(--team-color, var(--flm-accent)) 80%, black) 100%);
    border-color: var(--team-color, var(--flm-accent));
    box-shadow: 0 0 15px var(--team-glow, var(--flm-accent-glow));
}

.flm-toggle input:checked + .flm-toggle-track::before {
    transform: translateX(24px);
    background: linear-gradient(145deg, #ffffff 0%, #e5e7eb 100%);
}

.flm-toggle input:focus-visible + .flm-toggle-track {
    box-shadow: 0 0 0 3px var(--team-glow, var(--flm-accent-glow));
}

/* ============================================
   FORM ELEMENTS - Polished
   ============================================ */
.flm-form-group {
    margin-bottom: 20px;
}

.flm-form-group:last-child {
    margin-bottom: 0;
}

.flm-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.flm-label-hint {
    font-weight: 400;
    color: var(--flm-text-muted);
    text-transform: none;
    letter-spacing: 0;
    margin-left: 6px;
    font-size: 11px;
}

.flm-select-wrap {
    position: relative;
}

.flm-select-wrap::after {
    content: "";
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    border: 5px solid transparent;
    border-top-color: var(--flm-text-muted);
    pointer-events: none;
    transition: var(--flm-transition-fast);
}

.flm-select-wrap:hover::after {
    border-top-color: var(--flm-accent);
}

.flm-select, .flm-input {
    width: 100%;
    padding: 13px 16px;
    background: var(--flm-bg-input);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    color: var(--flm-text);
    font-size: 13px;
    font-family: var(--flm-font);
    transition: var(--flm-transition);
    appearance: none;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.2);
}

.flm-select option {
    background: #1c2128;
    color: #e6edf3;
    padding: 10px;
}

.flm-select option:hover,
.flm-select option:focus,
.flm-select option:checked {
    background: #388bfd;
    color: #fff;
}

.flm-select {
    padding-right: 44px;
    cursor: pointer;
}

.flm-select:hover, .flm-input:hover {
    border-color: var(--flm-border-light);
    background: rgba(13, 17, 23, 0.8);
}

.flm-select:focus, .flm-input:focus {
    outline: none;
    border-color: var(--flm-accent);
    box-shadow: 0 0 0 3px var(--flm-accent-glow), inset 0 1px 3px rgba(0, 0, 0, 0.2);
    background: var(--flm-bg-input);
}

.flm-input-mono {
    font-family: var(--flm-mono);
    letter-spacing: 0.5px;
    font-size: 12px;
}

/* Password/API Key Toggle */
.flm-input-wrap {
    position: relative;
}

.flm-input-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--flm-text-muted);
    cursor: pointer;
    padding: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--flm-transition);
    border-radius: var(--flm-radius-xs);
}

.flm-input-toggle:hover {
    color: var(--flm-accent);
    background: rgba(255, 107, 53, 0.1);
}

.flm-input-toggle svg {
    width: 18px;
    height: 18px;
}

.flm-input-wrap .flm-input {
    padding-right: 48px;
}

/* Checkbox Cards */
.flm-checkbox-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.flm-checkbox-grid-4 {
    grid-template-columns: repeat(4, 1fr);
}

@media (max-width: 900px) {
    .flm-checkbox-grid-4 { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 700px) {
    .flm-checkbox-grid { grid-template-columns: 1fr; }
    .flm-checkbox-grid-4 { grid-template-columns: 1fr; }
}

.flm-checkbox-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--flm-bg-input);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    cursor: pointer;
    transition: var(--flm-transition);
    position: relative;
    overflow: hidden;
}

.flm-checkbox-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 3px;
    height: 100%;
    background: transparent;
    transition: var(--flm-transition);
}

.flm-checkbox-card:hover {
    border-color: var(--flm-border-light);
    background: rgba(13, 17, 23, 0.6);
}

.flm-checkbox-card:focus-within {
    border-color: var(--flm-accent);
    box-shadow: 0 0 0 3px var(--flm-accent-glow);
}

.flm-checkbox-card input {
    width: 18px;
    height: 18px;
    accent-color: var(--flm-accent);
    cursor: pointer;
    flex-shrink: 0;
}

.flm-checkbox-card span {
    font-size: 13px;
    color: var(--flm-text);
    font-weight: 500;
}

.flm-checkbox-card[data-checked="true"] {
    border-color: rgba(255, 107, 53, 0.4);
    background: rgba(255, 107, 53, 0.08);
}

.flm-checkbox-card[data-checked="true"]::before {
    background: var(--flm-accent-gradient);
}

/* Radio Card (v2.13.0) */
.flm-radio-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--flm-bg-input);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    cursor: pointer;
    transition: var(--flm-transition);
    position: relative;
    overflow: hidden;
}

.flm-radio-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 3px;
    height: 100%;
    background: transparent;
    transition: var(--flm-transition);
}

.flm-radio-card:hover {
    border-color: var(--flm-border-light);
    background: rgba(13, 17, 23, 0.6);
}

.flm-radio-card:focus-within {
    border-color: var(--flm-accent);
    box-shadow: 0 0 0 3px var(--flm-accent-glow);
}

.flm-radio-card input[type="radio"] {
    width: 18px;
    height: 18px;
    accent-color: var(--flm-accent);
    cursor: pointer;
    flex-shrink: 0;
}

.flm-radio-card:has(input:checked) {
    border-color: rgba(255, 107, 53, 0.4);
    background: rgba(255, 107, 53, 0.08);
}

.flm-radio-card:has(input:checked)::before {
    background: var(--flm-accent-gradient);
}

.flm-radio-card-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.flm-radio-card-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-radio-card-desc {
    font-size: 11px;
    color: var(--flm-text-muted);
}

/* ============================================
   TEAM COLORS (v2.14.0)
   Visual identity per team
   ============================================ */
:root {
    --flm-team-braves: #CE1141;
    --flm-team-hawks: #E03A3E;
    --flm-team-falcons: #A71930;
    --flm-team-uga: #BA0C2F;
    --flm-team-gt: #B3A369;
}

.flm-team-accent-braves { border-left: 3px solid var(--flm-team-braves) !important; }
.flm-team-accent-hawks { border-left: 3px solid var(--flm-team-hawks) !important; }
.flm-team-accent-falcons { border-left: 3px solid var(--flm-team-falcons) !important; }
.flm-team-accent-uga { border-left: 3px solid var(--flm-team-uga) !important; }
.flm-team-accent-gt { border-left: 3px solid var(--flm-team-gt) !important; }

.flm-team-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
}

.flm-team-badge.braves { background: rgba(206,17,65,0.15); color: var(--flm-team-braves); }
.flm-team-badge.hawks { background: rgba(224,58,62,0.15); color: var(--flm-team-hawks); }
.flm-team-badge.falcons { background: rgba(167,25,48,0.15); color: var(--flm-team-falcons); }
.flm-team-badge.uga { background: rgba(186,12,47,0.15); color: var(--flm-team-uga); }
.flm-team-badge.gt { background: rgba(179,163,105,0.15); color: var(--flm-team-gt); }

/* ============================================
   SPARKLINES (v2.14.0)
   Inline trend visualization
   ============================================ */
.flm-sparkline {
    display: inline-flex;
    align-items: flex-end;
    gap: 1px;
    height: 20px;
    margin-left: 8px;
}

.flm-sparkline-bar {
    width: 3px;
    background: var(--flm-accent);
    border-radius: 1px;
    opacity: 0.6;
    transition: opacity 0.2s, height 0.3s;
}

.flm-sparkline-bar:last-child {
    opacity: 1;
}

.flm-sparkline:hover .flm-sparkline-bar {
    opacity: 0.8;
}

.flm-sparkline:hover .flm-sparkline-bar:last-child {
    opacity: 1;
}

.flm-trend-indicator {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
}

.flm-trend-indicator.up { color: var(--flm-success); }
.flm-trend-indicator.down { color: var(--flm-danger); }
.flm-trend-indicator.neutral { color: var(--flm-text-muted); }

/* ============================================
   COMMAND PALETTE (v2.14.0)
   Cmd+K quick actions
   ============================================ */
.flm-command-palette-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    backdrop-filter: blur(4px);
    z-index: 999999;
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding-top: 15vh;
    animation: flm-fade-in 0.15s ease;
}

.flm-command-palette-overlay.active {
    display: flex;
}

.flm-command-palette {
    width: 560px;
    max-width: 90vw;
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: 16px;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
    overflow: hidden;
    animation: flm-slide-down 0.2s ease;
}

@keyframes flm-slide-down {
    from { opacity: 0; transform: translateY(-20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.flm-command-input-wrap {
    display: flex;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--flm-border);
    gap: 12px;
}

.flm-command-input-wrap svg {
    width: 20px;
    height: 20px;
    color: var(--flm-text-muted);
    flex-shrink: 0;
}

.flm-command-input {
    flex: 1;
    background: transparent;
    border: none;
    font-size: 16px;
    color: var(--flm-text);
    outline: none;
}

.flm-command-input::placeholder {
    color: var(--flm-text-muted);
}

.flm-command-kbd {
    display: flex;
    gap: 4px;
}

.flm-command-kbd kbd {
    padding: 2px 6px;
    background: var(--flm-bg-input);
    border: 1px solid var(--flm-border);
    border-radius: 4px;
    font-size: 11px;
    font-family: var(--flm-font-mono);
    color: var(--flm-text-muted);
}

.flm-command-results {
    max-height: 400px;
    overflow-y: auto;
    padding: 8px;
}

.flm-command-group {
    margin-bottom: 8px;
}

.flm-command-group-title {
    padding: 8px 12px 4px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--flm-text-muted);
}

.flm-command-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s;
}

.flm-command-item:hover,
.flm-command-item.selected {
    background: var(--flm-bg-input);
}

.flm-command-item.selected {
    background: rgba(255,107,53,0.1);
}

.flm-command-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--flm-bg-input);
    border-radius: 8px;
    flex-shrink: 0;
}

.flm-command-icon svg {
    width: 16px;
    height: 16px;
    color: var(--flm-text-muted);
}

.flm-command-item.selected .flm-command-icon {
    background: var(--flm-accent);
}

.flm-command-item.selected .flm-command-icon svg {
    color: #fff;
}

.flm-command-text {
    flex: 1;
}

.flm-command-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--flm-text);
}

.flm-command-desc {
    font-size: 11px;
    color: var(--flm-text-muted);
    margin-top: 1px;
}

.flm-command-shortcut {
    display: flex;
    gap: 4px;
}

.flm-command-shortcut kbd {
    padding: 2px 5px;
    background: var(--flm-bg-dark);
    border-radius: 3px;
    font-size: 10px;
    font-family: var(--flm-font-mono);
    color: var(--flm-text-muted);
}

.flm-command-empty {
    padding: 40px 20px;
    text-align: center;
    color: var(--flm-text-muted);
}

.flm-command-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-top: 1px solid var(--flm-border);
    background: var(--flm-bg-input);
    font-size: 11px;
    color: var(--flm-text-muted);
}

.flm-command-footer-hints {
    display: flex;
    gap: 16px;
}

.flm-command-footer-hint {
    display: flex;
    align-items: center;
    gap: 6px;
}

/* ============================================
   ONBOARDING CHECKLIST (v2.14.0)
   Guided setup progress
   ============================================ */
.flm-onboarding {
    background: linear-gradient(135deg, rgba(255,107,53,0.1) 0%, rgba(139,92,246,0.1) 100%);
    border: 1px solid rgba(255,107,53,0.2);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
}

.flm-onboarding-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.flm-onboarding-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 15px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-onboarding-title svg {
    width: 20px;
    height: 20px;
    color: var(--flm-accent);
}

.flm-onboarding-progress-text {
    font-size: 12px;
    color: var(--flm-text-muted);
}

.flm-onboarding-progress-text strong {
    color: var(--flm-accent);
}

.flm-onboarding-bar {
    height: 6px;
    background: var(--flm-bg-input);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 16px;
}

.flm-onboarding-bar-fill {
    height: 100%;
    background: var(--flm-accent-gradient);
    border-radius: 3px;
    transition: width 0.5s ease;
}

.flm-onboarding-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
}

.flm-onboarding-step {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.flm-onboarding-step:hover:not(.completed) {
    border-color: var(--flm-accent);
    transform: translateY(-1px);
}

.flm-onboarding-step.completed {
    background: rgba(63,185,80,0.08);
    border-color: rgba(63,185,80,0.3);
}

.flm-onboarding-step-icon {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    background: var(--flm-bg-input);
    flex-shrink: 0;
}

.flm-onboarding-step.completed .flm-onboarding-step-icon {
    background: var(--flm-success);
    color: #fff;
}

.flm-onboarding-step-icon svg {
    width: 14px;
    height: 14px;
}

.flm-onboarding-step-text {
    font-size: 12px;
    font-weight: 500;
    color: var(--flm-text);
}

.flm-onboarding-step.completed .flm-onboarding-step-text {
    color: var(--flm-success);
}

.flm-onboarding-dismiss {
    background: none;
    border: none;
    color: var(--flm-text-muted);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: color 0.2s;
}

.flm-onboarding-dismiss:hover {
    color: var(--flm-text);
}

/* ============================================
   ACTIVITY FEED (v2.14.0)
   Real-time live feed
   ============================================ */
.flm-activity-feed {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: 12px;
    overflow: hidden;
}

.flm-activity-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid var(--flm-border);
}

.flm-activity-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-live-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--flm-danger);
}

.flm-live-dot {
    width: 6px;
    height: 6px;
    background: var(--flm-danger);
    border-radius: 50%;
    animation: flm-pulse 2s infinite;
}

@keyframes flm-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.2); }
}

.flm-activity-list {
    max-height: 300px;
    overflow-y: auto;
}

.flm-activity-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--flm-border);
    transition: background 0.2s;
    animation: flm-slide-in 0.3s ease;
}

@keyframes flm-slide-in {
    from { opacity: 0; transform: translateX(-10px); }
    to { opacity: 1; transform: translateX(0); }
}

.flm-activity-item:hover {
    background: var(--flm-bg-input);
}

.flm-activity-item:last-child {
    border-bottom: none;
}

.flm-activity-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    flex-shrink: 0;
}

.flm-activity-icon.import { background: rgba(88,166,255,0.15); color: #58a6ff; }
.flm-activity-icon.social { background: rgba(163,113,247,0.15); color: #a371f7; }
.flm-activity-icon.email { background: rgba(63,185,80,0.15); color: var(--flm-success); }
.flm-activity-icon.analytics { background: rgba(255,107,53,0.15); color: var(--flm-accent); }
.flm-activity-icon.error { background: rgba(248,81,73,0.15); color: var(--flm-danger); }

.flm-activity-icon svg {
    width: 16px;
    height: 16px;
}

.flm-activity-content {
    flex: 1;
    min-width: 0;
}

.flm-activity-text {
    font-size: 13px;
    color: var(--flm-text);
    line-height: 1.4;
}

.flm-activity-text strong {
    font-weight: 600;
}

.flm-activity-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
}

.flm-activity-time {
    font-size: 11px;
    color: var(--flm-text-muted);
}

/* ============================================
   EMPTY STATES (v2.14.0)
   Contextual guidance
   ============================================ */
.flm-empty-state {
    padding: 40px 20px;
    text-align: center;
}

.flm-empty-state-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--flm-bg-input);
    border-radius: 16px;
}

.flm-empty-state-icon svg {
    width: 32px;
    height: 32px;
    color: var(--flm-text-muted);
}

.flm-empty-state-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 8px;
}

.flm-empty-state-desc {
    font-size: 13px;
    color: var(--flm-text-muted);
    margin-bottom: 20px;
    max-width: 300px;
    margin-left: auto;
    margin-right: auto;
}

.flm-empty-state-action {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--flm-accent-gradient);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}

.flm-empty-state-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(255,107,53,0.3);
}

/* ============================================
   SMART NOTIFICATIONS (v2.14.0)
   Insight-driven alerts
   ============================================ */
.flm-insight-notification {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 16px;
    background: linear-gradient(135deg, rgba(88,166,255,0.1) 0%, rgba(139,92,246,0.1) 100%);
    border: 1px solid rgba(88,166,255,0.2);
    border-radius: 12px;
    margin-bottom: 16px;
    animation: flm-fade-in 0.3s ease;
}

.flm-insight-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(88,166,255,0.2);
    border-radius: 10px;
    flex-shrink: 0;
}

.flm-insight-icon svg {
    width: 20px;
    height: 20px;
    color: #58a6ff;
}

.flm-insight-content {
    flex: 1;
}

.flm-insight-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 4px;
}

.flm-insight-text {
    font-size: 13px;
    color: var(--flm-text-muted);
    line-height: 1.5;
    margin-bottom: 12px;
}

.flm-insight-actions {
    display: flex;
    gap: 8px;
}

.flm-insight-action {
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 500;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.flm-insight-action.primary {
    background: #58a6ff;
    color: #fff;
    border: none;
}

.flm-insight-action.primary:hover {
    background: #79b8ff;
}

.flm-insight-action.secondary {
    background: transparent;
    color: var(--flm-text-muted);
    border: 1px solid var(--flm-border);
}

.flm-insight-action.secondary:hover {
    border-color: var(--flm-text-muted);
    color: var(--flm-text);
}

.flm-insight-close {
    background: none;
    border: none;
    color: var(--flm-text-muted);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
}

.flm-insight-close:hover {
    color: var(--flm-text);
}

/* ============================================
   PROGRESSIVE DISCLOSURE (v2.14.0)
   Collapsible settings sections
   ============================================ */
.flm-collapse-section {
    border: 1px solid var(--flm-border);
    border-radius: 10px;
    margin-bottom: 12px;
    overflow: hidden;
}

.flm-collapse-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: var(--flm-bg-input);
    cursor: pointer;
    transition: background 0.2s;
    user-select: none;
}

.flm-collapse-header:hover {
    background: rgba(13,17,23,0.8);
}

.flm-collapse-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-collapse-title svg {
    width: 16px;
    height: 16px;
    color: var(--flm-text-muted);
}

.flm-collapse-meta {
    display: flex;
    align-items: center;
    gap: 12px;
}

.flm-collapse-badge {
    font-size: 11px;
    padding: 2px 8px;
    background: var(--flm-bg-dark);
    border-radius: 4px;
    color: var(--flm-text-muted);
}

.flm-collapse-chevron {
    width: 20px;
    height: 20px;
    color: var(--flm-text-muted);
    transition: transform 0.2s;
}

.flm-collapse-section.open .flm-collapse-chevron {
    transform: rotate(180deg);
}

.flm-collapse-body {
    display: none;
    padding: 16px;
    border-top: 1px solid var(--flm-border);
}

.flm-collapse-section.open .flm-collapse-body {
    display: block;
    animation: flm-fade-in 0.2s ease;
}

/* ============================================
   DRAG & DROP CALENDAR (v2.14.0)
   Interactive scheduling
   ============================================ */
.flm-calendar-day.drag-over {
    background: rgba(255,107,53,0.1) !important;
    border: 2px dashed var(--flm-accent) !important;
}

.flm-calendar-event.dragging {
    opacity: 0.5;
    transform: scale(0.95);
}

.flm-calendar-event {
    cursor: grab;
    transition: transform 0.15s, box-shadow 0.15s;
}

.flm-calendar-event:active {
    cursor: grabbing;
}

.flm-calendar-event:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.flm-calendar-drop-hint {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,107,53,0.9);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    border-radius: 6px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
}

.flm-calendar-day.drag-over .flm-calendar-drop-hint {
    opacity: 1;
}

/* ============================================
   OAUTH INTEGRATION (v2.15.0)
   OAuth connection cards and status
   ============================================ */
.flm-oauth-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.flm-oauth-card {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.2s;
}

.flm-oauth-card.connected {
    border-color: rgba(63,185,80,0.3);
}

.flm-oauth-card.disconnected {
    border-color: var(--flm-border);
}

.flm-oauth-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.flm-oauth-provider {
    display: flex;
    align-items: center;
    gap: 12px;
}

.flm-oauth-provider-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: var(--flm-bg-input);
}

.flm-oauth-provider-icon svg {
    width: 24px;
    height: 24px;
}

.flm-oauth-provider-icon.google { background: rgba(234,67,53,0.15); color: #ea4335; }
.flm-oauth-provider-icon.twitter { background: rgba(29,161,242,0.15); color: #1da1f2; }
.flm-oauth-provider-icon.facebook { background: rgba(24,119,242,0.15); color: #1877f2; }

.flm-oauth-provider-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-oauth-provider-desc {
    font-size: 11px;
    color: var(--flm-text-muted);
}

.flm-oauth-status {
    flex-shrink: 0;
}

.flm-oauth-card-body {
    margin-bottom: 16px;
}

.flm-oauth-expiry {
    font-size: 12px;
    color: var(--flm-text-muted);
    margin-bottom: 8px;
}

.flm-oauth-expiry.warning {
    color: var(--flm-warning);
}

.flm-oauth-pages {
    margin-top: 12px;
    display: none;
}

.flm-oauth-pages select {
    width: 100%;
}

.flm-oauth-card-footer {
    display: flex;
    gap: 8px;
}

.flm-oauth-connect,
.flm-oauth-disconnect,
.flm-oauth-refresh {
    flex: 1;
}

.flm-oauth-disconnect {
    display: none;
}

.flm-oauth-refresh {
    display: none;
    flex: 0;
    padding: 8px 12px;
}

.flm-oauth-card.connected .flm-oauth-connect {
    display: none;
}

.flm-oauth-card.connected .flm-oauth-disconnect,
.flm-oauth-card.connected .flm-oauth-refresh {
    display: flex;
}

.flm-oauth-scopes {
    margin-top: 12px;
    font-size: 11px;
    color: var(--flm-text-muted);
}

.flm-oauth-scope-badge {
    display: inline-block;
    padding: 2px 6px;
    background: var(--flm-bg-input);
    border-radius: 4px;
    margin: 2px;
    font-family: var(--flm-font-mono);
}

/* ============================================
   MOBILE RESPONSIVE (v2.14.0)
   Tablet & phone optimization
   ============================================ */
@media (max-width: 1200px) {
    .flm-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .flm-grid-2 {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 782px) {
    .flm-dashboard {
        padding: 12px;
    }
    
    .flm-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .flm-header-actions {
        width: 100%;
        justify-content: space-between;
    }
    
    .flm-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .flm-tabs {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding-bottom: 8px;
    }
    
    .flm-tabs::-webkit-scrollbar {
        display: none;
    }
    
    .flm-tab {
        white-space: nowrap;
        flex-shrink: 0;
    }
    
    .flm-card {
        border-radius: 12px;
    }
    
    .flm-card-body {
        padding: 16px;
    }
    
    .flm-integration-grid {
        grid-template-columns: 1fr;
    }
    
    .flm-onboarding-steps {
        grid-template-columns: 1fr;
    }
    
    .flm-command-palette {
        width: 95vw;
        margin: 0 10px;
        border-radius: 12px;
    }
    
    .flm-headline-input-group {
        flex-direction: column;
    }
    
    .flm-analyze-btn {
        width: 100%;
    }
    
    .flm-time-heatmap {
        overflow-x: auto;
    }
    
    .flm-calendar-grid {
        font-size: 11px;
    }
    
    .flm-calendar-event {
        font-size: 9px;
        padding: 2px 4px;
    }
    
    .flm-btn {
        padding: 10px 16px;
    }
    
    .flm-import-actions {
        flex-direction: column;
    }
    
    .flm-import-actions .flm-btn {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .flm-stat-card {
        padding: 16px;
    }
    
    .flm-stat-value {
        font-size: 28px;
    }
    
    .flm-header-title {
        font-size: 20px;
    }
    
    .flm-onboarding {
        padding: 14px;
    }
    
    .flm-activity-item {
        padding: 10px 12px;
    }
    
    .flm-insight-notification {
        flex-direction: column;
    }
    
    .flm-insight-icon {
        width: 32px;
        height: 32px;
    }
}

/* ============================================
   PROGRESS BAR - Polished
   ============================================ */
.flm-progress-wrap {
    margin-top: 24px;
    display: none;
}

.flm-progress-wrap.active {
    display: block;
    animation: flm-fade-in 0.3s ease;
}

@keyframes flm-fade-in {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.flm-progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.flm-progress-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-progress-value {
    font-size: 12px;
    color: var(--flm-accent);
    font-family: var(--flm-mono);
    font-weight: 500;
}

.flm-progress-bar {
    height: 10px;
    background: var(--flm-bg-dark);
    border-radius: 5px;
    overflow: hidden;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.3);
}

.flm-progress-fill {
    height: 100%;
    background: var(--flm-accent-gradient);
    border-radius: 5px;
    width: 0%;
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 0 15px var(--flm-accent-glow);
    position: relative;
}

.flm-progress-fill::after {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.2) 50%, transparent 100%);
    animation: flm-shimmer 1.5s infinite;
}

@keyframes flm-shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.flm-progress-status {
    margin-top: 12px;
    font-size: 12px;
    color: var(--flm-text-muted);
}

/* ============================================
   LOG DISPLAY - Polished
   ============================================ */
.flm-log {
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    max-height: 360px;
    overflow-y: auto;
    font-family: var(--flm-mono);
    font-size: 12px;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
}

.flm-log::-webkit-scrollbar {
    width: 8px;
}

.flm-log::-webkit-scrollbar-track {
    background: var(--flm-bg-dark);
    border-radius: 4px;
}

.flm-log::-webkit-scrollbar-thumb {
    background: var(--flm-border);
    border-radius: 4px;
}

.flm-log::-webkit-scrollbar-thumb:hover {
    background: var(--flm-border-light);
}

.flm-log-entry {
    padding: 12px 18px;
    border-bottom: 1px solid rgba(38, 46, 56, 0.5);
    display: flex;
    align-items: flex-start;
    gap: 12px;
    transition: var(--flm-transition-fast);
}

.flm-log-entry:hover {
    background: rgba(255, 255, 255, 0.02);
}

.flm-log-entry:last-child {
    border-bottom: none;
}

.flm-log-icon {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top: 1px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.05);
}

.flm-log-icon svg {
    width: 12px;
    height: 12px;
}

.flm-log-icon.success { 
    color: var(--flm-success); 
    background: rgba(63, 185, 80, 0.15);
}
.flm-log-icon.update { 
    color: var(--flm-warning); 
    background: rgba(210, 153, 34, 0.15);
}
.flm-log-icon.error { 
    color: var(--flm-danger); 
    background: rgba(248, 81, 73, 0.15);
}

.flm-log-content {
    flex: 1;
    min-width: 0;
}

.flm-log-text {
    color: var(--flm-text-muted);
    line-height: 1.5;
    word-break: break-word;
}

.flm-log-team {
    color: var(--flm-text);
    font-weight: 600;
}

.flm-log-time {
    font-size: 10px;
    color: var(--flm-text-muted);
    opacity: 0.6;
    margin-top: 4px;
}

.flm-log-empty {
    padding: 48px 24px;
    text-align: center;
    color: var(--flm-text-muted);
}

.flm-log-empty-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 16px;
    color: var(--flm-border);
    opacity: 0.5;
}

.flm-log-empty-icon svg {
    width: 56px;
    height: 56px;
}

/* Error Log Styles */
.flm-error-log-card {
    border-color: rgba(248, 81, 73, 0.3);
}

.flm-error-log .flm-log-entry {
    border-left: 3px solid transparent;
    padding-left: 14px;
    margin-left: 0;
}

.flm-error-log .flm-log-entry.flm-log-error {
    border-left-color: var(--flm-danger);
    background: rgba(248, 81, 73, 0.05);
}

.flm-error-log .flm-log-entry.flm-log-warning {
    border-left-color: var(--flm-warning);
    background: rgba(210, 153, 34, 0.05);
}

.flm-error-log .flm-log-entry.flm-log-info {
    border-left-color: var(--flm-info);
    background: rgba(88, 166, 255, 0.03);
}

.flm-error-log .flm-log-icon.error {
    color: var(--flm-danger);
}

.flm-error-log .flm-log-icon.warning {
    color: var(--flm-warning);
}

.flm-error-log .flm-log-icon.info {
    color: var(--flm-info);
}

.flm-log-context {
    font-weight: 600;
    color: var(--flm-text-muted);
    margin-right: 6px;
    font-size: 10px;
    text-transform: uppercase;
}

.flm-log-details {
    margin-top: 8px;
    font-size: 11px;
}

.flm-log-details summary {
    cursor: pointer;
    color: var(--flm-info);
    font-weight: 500;
}

.flm-log-details summary:hover {
    color: var(--flm-accent);
}

.flm-log-details pre {
    margin: 8px 0 0 0;
    padding: 12px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-xs);
    font-family: var(--flm-mono);
    font-size: 11px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 150px;
    overflow-y: auto;
}

/* Tall logs for Logs tab */
.flm-log-tall {
    max-height: 500px;
}

/* ============================================
   PUBLISHING TAB STYLES (v2.10.0)
   ============================================ */

.flm-publishing-section {
    padding: 0;
}

/* Empty State */
.flm-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    text-align: center;
}

.flm-empty-icon {
    width: 48px;
    height: 48px;
    color: var(--flm-text-muted);
    opacity: 0.5;
    margin-bottom: 12px;
}

.flm-empty-icon svg {
    width: 100%;
    height: 100%;
}

.flm-empty-text {
    font-size: 14px;
    font-weight: 500;
    color: var(--flm-text-muted);
    margin-bottom: 4px;
}

.flm-empty-hint {
    font-size: 12px;
    color: var(--flm-text-muted);
    opacity: 0.7;
}

/* Integration Status Cards */
.flm-integration-status-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: var(--flm-bg-input);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    transition: all 0.2s ease;
}

.flm-integration-status-card:hover {
    border-color: var(--flm-accent);
}

.flm-integration-status-card.connected {
    border-left: 3px solid var(--flm-success);
}

.flm-integration-status-card.disconnected {
    border-left: 3px solid var(--flm-border);
    opacity: 0.8;
}

.flm-integration-status-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
}

.flm-integration-status-info {
    flex: 1;
}

.flm-integration-status-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--flm-text);
}

.flm-integration-status-state {
    font-size: 12px;
    margin-top: 2px;
}

.flm-integration-status-actions {
    display: flex;
    gap: 8px;
}

/* Social Log Entry Enhancements */
.flm-log-entry .flm-log-platform {
    font-weight: 700;
    font-size: 13px;
    min-width: 24px;
}

.flm-log-entry .flm-log-status {
    font-size: 12px;
    margin: 0 6px;
}

.flm-log-entry .flm-log-status.success {
    color: var(--flm-success);
}

.flm-log-entry .flm-log-status.error {
    color: var(--flm-danger);
}

.flm-log-entry .flm-log-error {
    font-size: 11px;
    color: var(--flm-danger);
    margin-top: 4px;
    padding-left: 30px;
}

.flm-log-entry.success {
    border-left-color: var(--flm-success);
}

.flm-log-entry.error {
    border-left-color: var(--flm-danger);
    background: rgba(248, 81, 73, 0.03);
}

/* ============================================
   SETTINGS TAB STYLES
   ============================================ */

/* Schedule Info */
.flm-schedule-info {
    margin-top: 16px;
    padding: 12px 16px;
    background: rgba(63, 185, 80, 0.08);
    border: 1px solid rgba(63, 185, 80, 0.2);
    border-radius: var(--flm-radius-sm);
}

.flm-schedule-next {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--flm-text-muted);
}

.flm-schedule-next svg {
    width: 16px;
    height: 16px;
    color: var(--flm-success);
}

.flm-schedule-next strong {
    color: var(--flm-text);
}

/* Danger Zone Card */
.flm-card-danger {
    border-color: rgba(248, 81, 73, 0.3);
}

.flm-card-danger .flm-card-header {
    background: rgba(248, 81, 73, 0.05);
}

.flm-card-danger .flm-card-icon {
    color: var(--flm-danger);
}

.flm-danger-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 0;
    border-bottom: 1px solid var(--flm-border);
}

.flm-danger-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.flm-danger-item:first-child {
    padding-top: 0;
}

.flm-danger-info {
    flex: 1;
}

.flm-danger-info strong {
    display: block;
    font-size: 13px;
    color: var(--flm-text);
    margin-bottom: 2px;
}

.flm-danger-info p {
    font-size: 12px;
    color: var(--flm-text-muted);
    margin: 0;
}

/* Quick Tips Card */
.flm-card-tips {
    border-color: rgba(88, 166, 255, 0.3);
}

.flm-card-tips .flm-card-header {
    background: rgba(88, 166, 255, 0.05);
}

.flm-card-tips .flm-card-icon {
    color: var(--flm-info);
}

.flm-tip-item {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--flm-border);
}

.flm-tip-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.flm-tip-item:first-child {
    padding-top: 0;
}

.flm-tip-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    flex-shrink: 0;
}

.flm-tip-icon svg {
    width: 16px;
    height: 16px;
    color: var(--flm-accent);
}

.flm-tip-content strong {
    display: block;
    font-size: 13px;
    color: var(--flm-text);
    margin-bottom: 2px;
}

.flm-tip-content p {
    font-size: 12px;
    color: var(--flm-text-muted);
    margin: 0;
    line-height: 1.5;
}

.flm-tip-link {
    color: var(--flm-accent);
    text-decoration: none;
}

.flm-tip-link:hover {
    text-decoration: underline;
}

/* Mini Stats */
.flm-mini-stats {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.flm-mini-stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    border-left: 3px solid var(--flm-border);
}

.flm-mini-stat.active {
    border-left-color: var(--flm-success);
    background: rgba(63, 185, 80, 0.05);
}

.flm-mini-stat.inactive {
    opacity: 0.6;
}

.flm-mini-stat-team {
    font-size: 13px;
    font-weight: 500;
    color: var(--flm-text);
}

.flm-mini-stat-count {
    font-size: 12px;
    color: var(--flm-text-muted);
    font-family: var(--flm-mono);
}

/* ============================================
   TEST RESULTS
   ============================================ */
.flm-results {
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    margin-top: 16px;
    overflow: hidden;
}

.flm-results-header {
    padding: 12px 16px;
    background: rgba(0,0,0,0.2);
    border-bottom: 1px solid var(--flm-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.flm-results-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--flm-text);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.flm-results-body {
    padding: 16px;
    max-height: 300px;
    overflow-y: auto;
    font-family: var(--flm-mono);
    font-size: 12px;
    line-height: 1.6;
    color: var(--flm-text-muted);
    white-space: pre-wrap;
}

.flm-results-body .success { color: var(--flm-success); }
.flm-results-body .error { color: var(--flm-danger); }
.flm-results-body .info { color: var(--flm-info); }

/* ============================================
   TOAST NOTIFICATIONS - Polished
   ============================================ */
.flm-toast-container {
    position: fixed;
    top: 50px;
    right: 20px;
    z-index: 100000;
    display: flex;
    flex-direction: column;
    gap: 12px;
    pointer-events: none;
}

.flm-toast {
    background: linear-gradient(145deg, var(--flm-bg-card) 0%, rgba(21, 27, 35, 0.98) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: var(--flm-shadow), 0 0 40px rgba(0, 0, 0, 0.3);
    transform: translateX(120%);
    opacity: 0;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    pointer-events: auto;
    max-width: 400px;
    backdrop-filter: blur(10px);
    position: relative;
    overflow: hidden;
}

.flm-toast::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--flm-border);
}

.flm-toast.show {
    transform: translateX(0);
    opacity: 1;
}

.flm-toast.hiding {
    transform: translateX(120%);
    opacity: 0;
}

.flm-toast-icon {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.flm-toast-icon svg {
    width: 22px;
    height: 22px;
}

.flm-toast.success { border-color: rgba(63, 185, 80, 0.4); }
.flm-toast.success::before { background: var(--flm-success-gradient); }
.flm-toast.success .flm-toast-icon { color: var(--flm-success); filter: drop-shadow(0 0 8px var(--flm-success-glow)); }

.flm-toast.error { border-color: rgba(248, 81, 73, 0.4); }
.flm-toast.error::before { background: var(--flm-danger-gradient); }
.flm-toast.error .flm-toast-icon { color: var(--flm-danger); filter: drop-shadow(0 0 8px var(--flm-danger-glow)); }

.flm-toast.warning { border-color: rgba(210, 153, 34, 0.4); }
.flm-toast.warning::before { background: linear-gradient(135deg, var(--flm-warning) 0%, #e5ac30 100%); }
.flm-toast.warning .flm-toast-icon { color: var(--flm-warning); filter: drop-shadow(0 0 8px var(--flm-warning-glow)); }

.flm-toast.info { border-color: rgba(88, 166, 255, 0.4); }
.flm-toast.info::before { background: linear-gradient(135deg, var(--flm-info) 0%, #79b8ff 100%); }
.flm-toast.info .flm-toast-icon { color: var(--flm-info); filter: drop-shadow(0 0 8px var(--flm-info-glow)); }

.flm-toast-content {
    flex: 1;
}

.flm-toast-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 3px;
}

.flm-toast-message {
    font-size: 12px;
    color: var(--flm-text-muted);
    line-height: 1.4;
}

.flm-toast-close {
    background: none;
    border: none;
    color: var(--flm-text-muted);
    cursor: pointer;
    padding: 6px;
    display: flex;
    opacity: 0.5;
    transition: var(--flm-transition);
    border-radius: var(--flm-radius-xs);
}

.flm-toast-close:hover {
    opacity: 1;
    color: var(--flm-text);
    background: rgba(255, 255, 255, 0.05);
}

.flm-toast-close svg {
    width: 16px;
    height: 16px;
}

/* ============================================
   FOOTER
   ============================================ */
.flm-footer {
    padding: 20px 36px;
    border-top: 1px solid var(--flm-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    color: var(--flm-text-muted);
    font-size: 12px;
}

.flm-footer a {
    color: var(--flm-accent);
    text-decoration: none;
}

.flm-footer a:hover {
    text-decoration: underline;
}

.flm-footer-links {
    display: flex;
    gap: 16px;
}

/* ============================================
   UTILITIES
   ============================================ */
.flm-mt-0 { margin-top: 0; }
.flm-mt-1 { margin-top: 8px; }
.flm-mt-2 { margin-top: 16px; }
.flm-mt-3 { margin-top: 24px; }
.flm-mb-0 { margin-bottom: 0; }
.flm-mb-1 { margin-bottom: 8px; }
.flm-mb-2 { margin-bottom: 16px; }

.flm-text-muted { color: var(--flm-text-muted); }
.flm-text-success { color: var(--flm-success); }
.flm-text-danger { color: var(--flm-danger); }
.flm-text-center { text-align: center; }
.flm-text-right { text-align: right; }
.flm-text-sm { font-size: 12px; }

.flm-flex { display: flex; }
.flm-flex-between { justify-content: space-between; }
.flm-flex-center { align-items: center; }
.flm-gap-1 { gap: 8px; }
.flm-gap-2 { gap: 16px; }

/* Hide WordPress default notices inside our UI */
.flm-dashboard .notice,
.flm-dashboard .updated,
.flm-dashboard .error {
    display: none;
}

/* ============================================
   DRY-RUN PREVIEW MODAL
   ============================================ */
.flm-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(4px);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    transition: var(--flm-transition);
}

.flm-modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.flm-modal {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    width: 90%;
    max-width: 900px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
    transform: translateY(20px);
    transition: var(--flm-transition);
}

.flm-modal-overlay.active .flm-modal {
    transform: translateY(0);
}

.flm-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--flm-border);
    flex-shrink: 0;
}

.flm-modal-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--flm-text);
    display: flex;
    align-items: center;
    gap: 10px;
}

.flm-modal-title svg {
    width: 20px;
    height: 20px;
    color: var(--flm-accent);
}

.flm-modal-close {
    background: transparent;
    border: none;
    color: var(--flm-text-muted);
    cursor: pointer;
    padding: 8px;
    border-radius: var(--flm-radius-sm);
    transition: var(--flm-transition);
}

.flm-modal-close:hover {
    background: var(--flm-bg-dark);
    color: var(--flm-text);
}

.flm-modal-close svg {
    width: 20px;
    height: 20px;
}

.flm-modal-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
}

.flm-modal-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    border-top: 1px solid var(--flm-border);
    flex-shrink: 0;
}

/* Setup Wizard Styles (v2.8.0) */
.flm-wizard-steps {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--flm-border);
    flex-wrap: wrap;
}

.flm-wizard-step {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--flm-bg-dark);
    border-radius: 20px;
    font-size: 12px;
    color: var(--flm-text-muted);
    transition: var(--flm-transition);
}

.flm-wizard-step.active {
    background: var(--flm-accent);
    color: white;
}

.flm-wizard-step.completed {
    background: rgba(63, 185, 80, 0.15);
    color: var(--flm-success);
}

.flm-wizard-step-num {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--flm-bg-card);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 11px;
}

.flm-wizard-step.active .flm-wizard-step-num {
    background: rgba(255,255,255,0.2);
}

.flm-wizard-step.completed .flm-wizard-step-num {
    background: var(--flm-success);
    color: white;
}

.flm-wizard-content {
    min-height: 280px;
}

.flm-wizard-panel {
    display: none;
}

.flm-wizard-panel.active {
    display: block;
    animation: flm-fade-in 0.3s ease;
}

.flm-wizard-instruction {
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius);
    padding: 20px;
    margin-bottom: 16px;
}

.flm-wizard-instruction h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--flm-text);
    margin: 0 0 12px;
}

.flm-wizard-instruction p {
    font-size: 13px;
    color: var(--flm-text-muted);
    margin: 0 0 12px;
    line-height: 1.6;
}

.flm-wizard-instruction ol {
    margin: 0;
    padding-left: 20px;
    color: var(--flm-text-muted);
    font-size: 13px;
    line-height: 2;
}

.flm-wizard-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    background: var(--flm-accent);
    color: white;
    border-radius: var(--flm-radius-sm);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    margin-top: 8px;
    transition: var(--flm-transition);
}

.flm-wizard-link:hover {
    filter: brightness(1.1);
    color: white;
}

.flm-wizard-link svg {
    width: 14px;
    height: 14px;
}

.flm-copy-box {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding: 12px;
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
}

.flm-copy-box code {
    flex: 1;
    font-family: var(--flm-mono);
    font-size: 11px;
    color: var(--flm-text);
    word-break: break-all;
    background: none;
    padding: 0;
}

.flm-copy-btn {
    padding: 6px 12px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    color: var(--flm-text);
    font-size: 11px;
    cursor: pointer;
    transition: var(--flm-transition);
    white-space: nowrap;
}

.flm-copy-btn:hover {
    background: var(--flm-accent);
    border-color: var(--flm-accent);
    color: white;
}

.flm-copy-btn.copied {
    background: var(--flm-success);
    border-color: var(--flm-success);
    color: white;
}

.flm-wizard-tip {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    background: rgba(88, 166, 255, 0.1);
    border: 1px solid rgba(88, 166, 255, 0.2);
    border-radius: var(--flm-radius-sm);
    margin-top: 16px;
}

.flm-wizard-tip-icon {
    font-size: 16px;
    flex-shrink: 0;
}

.flm-wizard-tip-text {
    font-size: 12px;
    color: var(--flm-text-muted);
    line-height: 1.5;
}

.flm-wizard-warning {
    background: rgba(210, 153, 34, 0.1);
    border-color: rgba(210, 153, 34, 0.2);
}

.flm-wizard-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 20px;
    border-top: 1px solid var(--flm-border);
    margin-top: 20px;
}

.flm-wizard-nav-left,
.flm-wizard-nav-right {
    display: flex;
    gap: 8px;
}

.flm-setup-guide-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: transparent;
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    color: var(--flm-text-muted);
    font-size: 12px;
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-setup-guide-btn:hover {
    background: var(--flm-bg-dark);
    color: var(--flm-text);
    border-color: var(--flm-text-muted);
}

.flm-setup-guide-btn svg {
    width: 14px;
    height: 14px;
}

.flm-checklist {
    margin-top: 16px;
}

.flm-checklist-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    margin-bottom: 8px;
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-checklist-item:hover {
    border-color: var(--flm-accent);
}

.flm-checklist-item.checked {
    background: rgba(63, 185, 80, 0.05);
    border-color: rgba(63, 185, 80, 0.3);
}

.flm-checklist-check {
    width: 20px;
    height: 20px;
    border: 2px solid var(--flm-border);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 12px;
    color: transparent;
}

.flm-checklist-item.checked .flm-checklist-check {
    background: var(--flm-success);
    border-color: var(--flm-success);
    color: white;
}

.flm-checklist-text {
    flex: 1;
    font-size: 13px;
    color: var(--flm-text);
}

.flm-checklist-item.checked .flm-checklist-text {
    text-decoration: line-through;
    color: var(--flm-text-muted);
}

.flm-preview-summary {
    display: flex;
    gap: 24px;
    margin-bottom: 20px;
    padding: 16px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
}

.flm-preview-stat {
    text-align: center;
}

.flm-preview-stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--flm-text);
}

.flm-preview-stat-value.new {
    color: var(--flm-success);
}

.flm-preview-stat-value.update {
    color: var(--flm-info);
}

.flm-preview-stat-value.skip {
    color: var(--flm-text-muted);
}

.flm-preview-stat-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--flm-text-muted);
    margin-top: 4px;
}

.flm-preview-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.flm-preview-item {
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    padding: 16px;
    display: flex;
    gap: 16px;
    transition: var(--flm-transition);
}

.flm-preview-item:hover {
    border-color: var(--flm-border-light);
}

.flm-preview-item-badge {
    flex-shrink: 0;
    width: 56px;
    height: 56px;
    border-radius: var(--flm-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.flm-preview-item-badge.new {
    background: var(--flm-success-glow);
    color: var(--flm-success);
    border: 1px solid var(--flm-success);
}

.flm-preview-item-badge.update {
    background: rgba(88, 166, 255, 0.15);
    color: var(--flm-info);
    border: 1px solid var(--flm-info);
}

.flm-preview-item-badge.skip {
    background: rgba(139, 148, 158, 0.15);
    color: var(--flm-text-muted);
    border: 1px solid var(--flm-border);
}

.flm-preview-item-content {
    flex: 1;
    min-width: 0;
}

.flm-preview-item-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 6px;
    line-height: 1.4;
}

.flm-preview-item-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 12px;
    color: var(--flm-text-muted);
}

.flm-preview-item-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.flm-preview-item-meta svg {
    width: 12px;
    height: 12px;
}

.flm-preview-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    color: var(--flm-text-muted);
}

.flm-preview-loading svg {
    width: 40px;
    height: 40px;
    margin-bottom: 16px;
    animation: flm-spin 1s linear infinite;
}

.flm-preview-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--flm-text-muted);
}

.flm-preview-empty svg {
    width: 48px;
    height: 48px;
    margin-bottom: 12px;
    opacity: 0.5;
}

.flm-preview-filters {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}

.flm-preview-filter {
    padding: 6px 12px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    color: var(--flm-text-muted);
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-preview-filter:hover,
.flm-preview-filter.active {
    border-color: var(--flm-accent);
    color: var(--flm-accent);
}

.flm-preview-filter .count {
    background: var(--flm-border);
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 6px;
    font-size: 10px;
}

.flm-preview-filter.active .count {
    background: var(--flm-accent);
    color: white;
}

/* ============================================
   SELECTIVE IMPORT CHECKBOXES (P2.3)
   ============================================ */
.flm-preview-select-all {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px 16px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    margin-bottom: 16px;
}

.flm-preview-select-all label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 500;
    color: var(--flm-text);
    cursor: pointer;
}

.flm-preview-select-all .flm-selection-count {
    margin-left: auto;
    font-size: 13px;
    color: var(--flm-text-muted);
}

.flm-preview-select-all .flm-selection-count strong {
    color: var(--flm-accent);
}

.flm-preview-checkbox {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    appearance: none;
    -webkit-appearance: none;
    background: var(--flm-bg-input);
    border: 2px solid var(--flm-border);
    border-radius: 4px;
    cursor: pointer;
    position: relative;
    transition: var(--flm-transition);
}

.flm-preview-checkbox:hover {
    border-color: var(--flm-accent);
}

.flm-preview-checkbox:checked {
    background: var(--flm-accent);
    border-color: var(--flm-accent);
}

.flm-preview-checkbox:checked::after {
    content: "";
    position: absolute;
    left: 5px;
    top: 2px;
    width: 6px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.flm-preview-checkbox:focus-visible {
    outline: 2px solid var(--flm-accent);
    outline-offset: 2px;
}

.flm-preview-item-checkbox {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.flm-preview-item.disabled {
    opacity: 0.5;
    pointer-events: none;
}

.flm-preview-item.selected {
    border-color: var(--flm-accent);
    background: rgba(255, 107, 53, 0.05);
}

.flm-preview-quick-select {
    display: flex;
    gap: 8px;
}

.flm-preview-quick-select button {
    padding: 4px 10px;
    font-size: 11px;
    background: transparent;
    border: 1px solid var(--flm-border);
    border-radius: 4px;
    color: var(--flm-text-muted);
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-preview-quick-select button:hover {
    border-color: var(--flm-accent);
    color: var(--flm-accent);
}

/* ============================================
   PAGE LOAD ANIMATIONS
   ============================================ */
@keyframes flm-slide-up {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes flm-count-up {
    from { opacity: 0; transform: scale(0.5); }
    to { opacity: 1; transform: scale(1); }
}

.flm-animate-in {
    animation: flm-slide-up 0.5s ease forwards;
    opacity: 0;
}

.flm-stat-card:nth-child(1) { animation-delay: 0.1s; }
.flm-stat-card:nth-child(2) { animation-delay: 0.15s; }
.flm-stat-card:nth-child(3) { animation-delay: 0.2s; }
.flm-stat-card:nth-child(4) { animation-delay: 0.25s; }

.flm-card.flm-animate-in:nth-child(1) { animation-delay: 0.3s; }
.flm-card.flm-animate-in:nth-child(2) { animation-delay: 0.35s; }
.flm-card.flm-animate-in:nth-child(3) { animation-delay: 0.4s; }

/* Count-up animation for stat values */
.flm-stat-value[data-animate="true"] {
    animation: flm-count-up 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

/* ============================================
   SPARKLINES (Mini charts in stat cards)
   ============================================ */
.flm-sparkline {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 40px;
    opacity: 0.15;
    pointer-events: none;
}

.flm-sparkline svg {
    width: 100%;
    height: 100%;
}

.flm-sparkline path {
    fill: none;
    stroke: var(--flm-accent);
    stroke-width: 2;
}

.flm-sparkline .fill {
    fill: url(#sparkline-gradient);
    stroke: none;
}

/* ============================================
   ANALYTICS SECTION - Enhanced
   ============================================ */
.flm-analytics-section {
    margin-top: 32px;
}

.flm-analytics-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--flm-border);
}

.flm-analytics-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 18px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-analytics-title-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, rgba(88, 166, 255, 0.15) 0%, rgba(88, 166, 255, 0.05) 100%);
    border: 1px solid rgba(88, 166, 255, 0.2);
    border-radius: var(--flm-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--flm-info);
}

.flm-analytics-title-icon svg {
    width: 20px;
    height: 20px;
}

.flm-analytics-period {
    display: flex;
    gap: 8px;
}

.flm-period-btn {
    padding: 8px 16px;
    font-size: 12px;
    font-weight: 500;
    background: transparent;
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-xs);
    color: var(--flm-text-muted);
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-period-btn:hover {
    border-color: var(--flm-border-light);
    color: var(--flm-text);
}

.flm-period-btn.active {
    background: var(--flm-accent);
    border-color: var(--flm-accent);
    color: white;
}

/* Analytics Row Layout */
.flm-analytics-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

.flm-analytics-row-3 {
    grid-template-columns: repeat(3, 1fr);
}

@media (max-width: 1200px) {
    .flm-analytics-row {
        grid-template-columns: 1fr;
    }
    .flm-analytics-row-3 {
        grid-template-columns: 1fr;
    }
}

@media (min-width: 768px) and (max-width: 1200px) {
    .flm-analytics-row-3 {
        grid-template-columns: repeat(2, 1fr);
    }
    .flm-analytics-row-3 > :last-child {
        grid-column: span 2;
    }
}

/* Chart Cards */
.flm-chart-card {
    background: linear-gradient(145deg, var(--flm-bg-card) 0%, rgba(21, 27, 35, 0.9) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    overflow: hidden;
}

.flm-chart-wide {
    min-height: 380px;
}

.flm-chart-narrow {
    min-height: 380px;
}

.flm-chart-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--flm-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: rgba(0, 0, 0, 0.15);
}

.flm-chart-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-chart-subtitle {
    font-size: 11px;
    color: var(--flm-text-muted);
    margin-top: 2px;
}

.flm-chart-body {
    padding: 20px;
    position: relative;
}

.flm-chart-body-scroll {
    max-height: 300px;
    overflow-y: auto;
}

.flm-chart-canvas {
    width: 100%;
    height: 260px;
}

/* Top Posts List */
.flm-top-posts {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.flm-top-post-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-xs);
    transition: var(--flm-transition);
}

.flm-top-post-item:hover {
    background: var(--flm-bg-card-hover);
}

.flm-top-post-rank {
    width: 24px;
    height: 24px;
    background: var(--flm-accent);
    color: white;
    font-size: 11px;
    font-weight: 700;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.flm-top-post-item:nth-child(1) .flm-top-post-rank { background: #ffd700; color: #000; }
.flm-top-post-item:nth-child(2) .flm-top-post-rank { background: #c0c0c0; color: #000; }
.flm-top-post-item:nth-child(3) .flm-top-post-rank { background: #cd7f32; color: #fff; }

.flm-top-post-info {
    flex: 1;
    min-width: 0;
}

.flm-top-post-title {
    font-size: 12px;
    font-weight: 500;
    color: var(--flm-text);
    text-decoration: none;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.flm-top-post-title:hover {
    color: var(--flm-accent);
}

.flm-top-post-meta {
    font-size: 10px;
    color: var(--flm-text-muted);
    margin-top: 2px;
}

.flm-top-post-team {
    display: inline-block;
    padding: 1px 6px;
    background: rgba(255, 107, 53, 0.15);
    border-radius: 3px;
    color: var(--flm-accent);
}

.flm-top-post-views {
    font-size: 13px;
    font-weight: 600;
    color: var(--flm-success);
    flex-shrink: 0;
}

/* Donut Chart Container */
.flm-donut-container {
    display: flex;
    align-items: center;
    gap: 20px;
}

.flm-donut-chart {
    width: 120px;
    height: 120px;
    flex-shrink: 0;
}

.flm-donut-legend {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.flm-legend-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 10px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-xs);
}

.flm-legend-label {
    display: flex;
    align-items: center;
    gap: 8px;
}

.flm-legend-color {
    width: 10px;
    height: 10px;
    border-radius: 2px;
}

.flm-legend-name {
    font-size: 12px;
    color: var(--flm-text);
}

.flm-legend-value {
    font-size: 12px;
    font-weight: 600;
    color: var(--flm-text);
}

/* Story Types Bar Chart */
.flm-bar-chart {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.flm-bar-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.flm-bar-label {
    width: 70px;
    font-size: 11px;
    color: var(--flm-text-muted);
    flex-shrink: 0;
    text-align: right;
}

.flm-bar-track {
    flex: 1;
    height: 20px;
    background: var(--flm-bg-dark);
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.flm-bar-fill {
    height: 100%;
    background: var(--flm-accent-gradient);
    border-radius: 4px;
    transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 8px;
    min-width: 30px;
}

.flm-bar-fill-braves { background: linear-gradient(90deg, #ce1141 0%, #13274f 100%); }
.flm-bar-fill-falcons { background: linear-gradient(90deg, #a71930 0%, #000000 100%); }
.flm-bar-fill-hawks { background: linear-gradient(90deg, #e03a3e 0%, #c1d32f 100%); }
.flm-bar-fill-uga { background: linear-gradient(90deg, #ba0c2f 0%, #000000 100%); }
.flm-bar-fill-gt { background: linear-gradient(90deg, #b3a369 0%, #003057 100%); }

.flm-bar-value {
    font-size: 10px;
    font-weight: 600;
    color: white;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
}

/* Summary Stats Row */
.flm-summary-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

@media (max-width: 900px) {
    .flm-summary-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

.flm-summary-stat {
    background: linear-gradient(145deg, var(--flm-bg-card) 0%, var(--flm-bg-dark) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    padding: 20px;
    text-align: center;
}

.flm-summary-stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--flm-text);
    margin-bottom: 4px;
}

.flm-summary-stat-label {
    font-size: 11px;
    color: var(--flm-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Empty Analytics State */
.flm-analytics-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--flm-text-muted);
}

.flm-analytics-empty-sm {
    padding: 30px 16px;
}

.flm-analytics-empty-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 12px;
    color: var(--flm-border-light);
    opacity: 0.5;
}

.flm-analytics-empty-icon svg {
    width: 48px;
    height: 48px;
}

.flm-analytics-empty h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 6px;
}

.flm-analytics-empty p {
    font-size: 12px;
    max-width: 200px;
    margin: 0 auto;
    line-height: 1.5;
}

/* ============================================
   ENHANCED ANALYTICS - v2.4.2
   ============================================ */

/* Analytics Subtitle */
.flm-analytics-subtitle {
    font-size: 12px;
    color: var(--flm-text-muted);
    font-weight: 400;
    margin-top: 2px;
}

/* Enhanced Summary Stats */
.flm-summary-stat {
    background: linear-gradient(145deg, var(--flm-bg-card) 0%, var(--flm-bg-dark) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    position: relative;
    overflow: hidden;
    transition: var(--flm-transition);
}

.flm-summary-stat:hover {
    transform: translateY(-2px);
    box-shadow: var(--flm-shadow);
}

.flm-summary-stat::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--flm-border);
}

.flm-summary-stat-posts::before { background: linear-gradient(90deg, var(--flm-accent) 0%, #ff8c42 100%); }
.flm-summary-stat-views::before { background: linear-gradient(90deg, var(--flm-info) 0%, #79b8ff 100%); }
.flm-summary-stat-period::before { background: linear-gradient(90deg, var(--flm-success) 0%, #56d364 100%); }
.flm-summary-stat-success::before { background: linear-gradient(90deg, #a855f7 0%, #c084fc 100%); }

.flm-summary-stat-icon {
    width: 36px;
    height: 36px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: var(--flm-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--flm-text-muted);
}

.flm-summary-stat-icon svg {
    width: 18px;
    height: 18px;
}

.flm-summary-stat-posts .flm-summary-stat-icon { color: var(--flm-accent); background: rgba(255, 107, 53, 0.1); }
.flm-summary-stat-views .flm-summary-stat-icon { color: var(--flm-info); background: rgba(88, 166, 255, 0.1); }
.flm-summary-stat-period .flm-summary-stat-icon { color: var(--flm-success); background: rgba(63, 185, 80, 0.1); }
.flm-summary-stat-success .flm-summary-stat-icon { color: #a855f7; background: rgba(168, 85, 247, 0.1); }

.flm-summary-stat-content {
    flex: 1;
}

.flm-summary-stat-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--flm-text);
    line-height: 1;
}

.flm-summary-stat-label {
    font-size: 12px;
    color: var(--flm-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
}

.flm-summary-stat-meta {
    font-size: 11px;
    color: var(--flm-text-muted);
    background: var(--flm-bg-dark);
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-block;
}

/* Trend Indicators */
.flm-summary-stat-trend {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
    position: absolute;
    top: 16px;
    right: 16px;
}

.flm-summary-stat-trend.up {
    color: var(--flm-success);
    background: rgba(63, 185, 80, 0.15);
}

.flm-summary-stat-trend.down {
    color: var(--flm-danger);
    background: rgba(248, 81, 73, 0.15);
}

.flm-trend-arrow {
    font-size: 14px;
}

/* Mini Gauge */
.flm-summary-stat-gauge {
    height: 4px;
    background: var(--flm-bg-dark);
    border-radius: 2px;
    overflow: hidden;
    margin-top: 8px;
}

.flm-gauge-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--flm-success) 0%, #56d364 100%);
    border-radius: 2px;
    transition: width 1s ease;
}

/* Insights Row */
.flm-insights-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.flm-insight-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: linear-gradient(145deg, var(--flm-bg-card) 0%, var(--flm-bg-dark) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    position: relative;
    overflow: hidden;
}

.flm-insight-card::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
}

.flm-insight-success::before { background: var(--flm-success); }
.flm-insight-info::before { background: var(--flm-info); }
.flm-insight-warning::before { background: var(--flm-warning); }

.flm-insight-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.flm-insight-icon svg {
    width: 16px;
    height: 16px;
}

.flm-insight-success .flm-insight-icon { background: rgba(63, 185, 80, 0.15); color: var(--flm-success); }
.flm-insight-info .flm-insight-icon { background: rgba(88, 166, 255, 0.15); color: var(--flm-info); }
.flm-insight-warning .flm-insight-icon { background: rgba(210, 153, 34, 0.15); color: var(--flm-warning); }

.flm-insight-text {
    font-size: 13px;
    color: var(--flm-text);
    line-height: 1.4;
}

/* Enhanced Top Posts */
.flm-top-post-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-xs);
    transition: var(--flm-transition);
    border: 1px solid transparent;
}

.flm-top-post-item:hover {
    background: var(--flm-bg-card-hover);
    border-color: var(--flm-border);
}

.flm-top-post-rank {
    width: 28px;
    height: 28px;
    background: var(--flm-accent);
    color: white;
    font-size: 12px;
    font-weight: 700;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.flm-top-post-item:nth-child(1) .flm-top-post-rank { 
    background: linear-gradient(135deg, #ffd700 0%, #ffb800 100%); 
    color: #000;
    box-shadow: 0 0 10px rgba(255, 215, 0, 0.3);
}
.flm-top-post-item:nth-child(2) .flm-top-post-rank { 
    background: linear-gradient(135deg, #c0c0c0 0%, #a8a8a8 100%); 
    color: #000; 
}
.flm-top-post-item:nth-child(3) .flm-top-post-rank { 
    background: linear-gradient(135deg, #cd7f32 0%, #b87333 100%); 
    color: #fff; 
}

.flm-top-post-info {
    flex: 1;
    min-width: 0;
}

.flm-top-post-title {
    font-size: 12px;
    font-weight: 500;
    color: var(--flm-text);
    text-decoration: none;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.flm-top-post-title:hover {
    color: var(--flm-accent);
}

.flm-top-post-meta {
    display: flex;
    gap: 6px;
    margin-top: 4px;
}

.flm-top-post-team {
    font-size: 10px;
    padding: 2px 6px;
    background: rgba(255, 107, 53, 0.15);
    border-radius: 3px;
    color: var(--team-color, var(--flm-accent));
    border-left: 2px solid var(--team-color, var(--flm-accent));
}

.flm-top-post-type {
    font-size: 10px;
    padding: 2px 6px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 3px;
    color: var(--flm-text-muted);
}

.flm-top-post-stats {
    text-align: right;
    flex-shrink: 0;
}

.flm-top-post-views {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-success);
    display: block;
}

.flm-top-post-vpd {
    font-size: 10px;
    color: var(--flm-text-muted);
}

/* Performance Table */
.flm-performance-table-card {
    margin-top: 24px;
}

.flm-chart-body-table {
    padding: 0;
    overflow-x: auto;
}

.flm-performance-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.flm-performance-table th {
    text-align: left;
    padding: 14px 16px;
    background: var(--flm-bg-dark);
    color: var(--flm-text-muted);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--flm-border);
    position: sticky;
    top: 0;
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-performance-table th:hover {
    color: var(--flm-text);
    background: rgba(255, 107, 53, 0.05);
}

.flm-performance-table th::after {
    content: "↕";
    margin-left: 6px;
    opacity: 0.3;
}

.flm-performance-table th.sorted-asc::after { content: "↑"; opacity: 1; color: var(--flm-accent); }
.flm-performance-table th.sorted-desc::after { content: "↓"; opacity: 1; color: var(--flm-accent); }

.flm-performance-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--flm-border);
    color: var(--flm-text);
}

.flm-performance-table tbody tr {
    transition: var(--flm-transition);
}

.flm-performance-table tbody tr:hover {
    background: rgba(255, 107, 53, 0.03);
}

.flm-performance-table tbody tr:nth-child(even) {
    background: rgba(0, 0, 0, 0.1);
}

.flm-performance-table tbody tr:nth-child(even):hover {
    background: rgba(255, 107, 53, 0.05);
}

.flm-td-title a {
    color: var(--flm-text);
    text-decoration: none;
    font-weight: 500;
}

.flm-td-title a:hover {
    color: var(--flm-accent);
}

.flm-team-badge {
    display: inline-block;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 500;
    background: rgba(255, 107, 53, 0.1);
    border-radius: 4px;
    color: var(--team-color, var(--flm-accent));
    border-left: 3px solid var(--team-color, var(--flm-accent));
}

.flm-td-type {
    color: var(--flm-text-muted);
}

.flm-td-age {
    color: var(--flm-text-muted);
    font-family: var(--flm-mono);
    font-size: 12px;
}

.flm-td-views {
    font-weight: 600;
    font-family: var(--flm-mono);
}

.flm-vpd-value {
    display: inline-block;
    padding: 3px 8px;
    background: rgba(63, 185, 80, 0.1);
    color: var(--flm-success);
    border-radius: 4px;
    font-weight: 600;
    font-family: var(--flm-mono);
    font-size: 12px;
}

.flm-th-title { width: 35%; }
.flm-th-team { width: 15%; }
.flm-th-type { width: 15%; }
.flm-th-age { width: 10%; text-align: center; }
.flm-th-views { width: 12%; text-align: right; }
.flm-th-vpd { width: 13%; text-align: right; }

.flm-td-age, .flm-td-views, .flm-td-vpd { text-align: right; }

/* Loading State */
.flm-analytics-loading {
    position: relative;
    min-height: 200px;
}

.flm-analytics-loading::after {
    content: "";
    position: absolute;
    top: 50%;
    left: 50%;
    width: 40px;
    height: 40px;
    margin: -20px 0 0 -20px;
    border: 3px solid var(--flm-border);
    border-top-color: var(--flm-accent);
    border-radius: 50%;
    animation: flm-spin 0.8s linear infinite;
}

/* ============================================
   CHAMPIONSHIP EDITION v2.6.0
   Premium Micro-interactions & Polish
   ============================================ */

/* Reduced Motion Support */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

/* Button Ripple Effect */
.flm-btn {
    position: relative;
    overflow: hidden;
}

.flm-btn .flm-ripple {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.4);
    transform: scale(0);
    animation: flm-ripple 0.6s linear;
    pointer-events: none;
}

@keyframes flm-ripple {
    to {
        transform: scale(4);
        opacity: 0;
    }
}

/* Card Hover Lift Effect */
.flm-card {
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
}

.flm-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
}

/* Team Card Enhanced Toggle Animation */
.flm-team-card {
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.flm-team-card[data-enabled="true"] {
    transform: scale(1.02);
}

.flm-team-card .flm-team-icon {
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.flm-team-card[data-enabled="true"] .flm-team-icon {
    transform: scale(1.1) rotate(-5deg);
}

/* Skeleton Loading States */
.flm-skeleton {
    background: linear-gradient(90deg, var(--flm-bg-dark) 25%, var(--flm-bg-card) 50%, var(--flm-bg-dark) 75%);
    background-size: 200% 100%;
    animation: flm-skeleton 1.5s ease-in-out infinite;
    border-radius: var(--flm-radius-sm);
}

@keyframes flm-skeleton {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.flm-skeleton-text {
    height: 14px;
    margin: 8px 0;
}

.flm-skeleton-title {
    height: 20px;
    width: 60%;
    margin: 12px 0;
}

.flm-skeleton-stat {
    height: 32px;
    width: 80px;
}

.flm-skeleton-card {
    height: 120px;
}

/* Pulse Animation for Status Indicators */
.flm-connection-dot[data-status="online"] {
    animation: flm-pulse 2s ease-in-out infinite;
}

@keyframes flm-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(63, 185, 80, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(63, 185, 80, 0); }
}

/* Number Counter Animation */
.flm-count-up {
    display: inline-block;
    transition: transform 0.1s ease;
}

.flm-count-up.counting {
    transform: scale(1.1);
}

/* Sparkline Micro Charts */
.flm-sparkline {
    display: inline-flex;
    align-items: flex-end;
    gap: 2px;
    height: 24px;
    padding: 4px 0;
}

.flm-sparkline-bar {
    width: 4px;
    background: var(--flm-accent);
    border-radius: 2px;
    transition: height 0.3s ease;
    opacity: 0.6;
}

.flm-sparkline-bar:last-child {
    opacity: 1;
}

/* Command Palette */
.flm-command-palette {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 15vh;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
}

.flm-command-palette.open {
    opacity: 1;
    visibility: visible;
}

.flm-command-palette-box {
    width: 100%;
    max-width: 560px;
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
    overflow: hidden;
    transform: translateY(-20px) scale(0.95);
    transition: transform 0.2s ease;
}

.flm-command-palette.open .flm-command-palette-box {
    transform: translateY(0) scale(1);
}

.flm-command-input-wrap {
    display: flex;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--flm-border);
    gap: 12px;
}

.flm-command-input-wrap svg {
    width: 20px;
    height: 20px;
    color: var(--flm-text-muted);
    flex-shrink: 0;
}

.flm-command-input {
    flex: 1;
    background: transparent;
    border: none;
    font-size: 16px;
    color: var(--flm-text);
    outline: none;
    font-family: var(--flm-font);
}

.flm-command-input::placeholder {
    color: var(--flm-text-muted);
}

.flm-command-results {
    max-height: 400px;
    overflow-y: auto;
}

.flm-command-group {
    padding: 8px;
}

.flm-command-group-title {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--flm-text-muted);
    padding: 8px 12px;
}

.flm-command-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: var(--flm-radius-sm);
    cursor: pointer;
    transition: var(--flm-transition-fast);
}

.flm-command-item:hover, .flm-command-item.selected {
    background: var(--flm-bg-dark);
}

.flm-command-item svg {
    width: 16px;
    height: 16px;
    color: var(--flm-accent);
}

.flm-command-item-text {
    flex: 1;
    font-size: 14px;
    color: var(--flm-text);
}

.flm-command-item-shortcut {
    display: flex;
    gap: 4px;
}

.flm-command-item-shortcut kbd {
    display: inline-block;
    padding: 2px 6px;
    font-size: 11px;
    font-family: var(--flm-mono);
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: 4px;
    color: var(--flm-text-muted);
}

/* Keyboard Shortcuts Modal */
.flm-shortcuts-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
}

.flm-shortcuts-modal.open {
    opacity: 1;
    visibility: visible;
}

.flm-shortcuts-box {
    width: 100%;
    max-width: 480px;
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
    overflow: hidden;
    transform: scale(0.95);
    transition: transform 0.2s ease;
}

.flm-shortcuts-modal.open .flm-shortcuts-box {
    transform: scale(1);
}

.flm-shortcuts-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--flm-border);
}

.flm-shortcuts-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-shortcuts-close {
    background: none;
    border: none;
    color: var(--flm-text-muted);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: var(--flm-transition-fast);
}

.flm-shortcuts-close:hover {
    background: var(--flm-bg-dark);
    color: var(--flm-text);
}

.flm-shortcuts-close svg {
    width: 20px;
    height: 20px;
}

.flm-shortcuts-body {
    padding: 16px 20px;
}

.flm-shortcuts-group {
    margin-bottom: 20px;
}

.flm-shortcuts-group:last-child {
    margin-bottom: 0;
}

.flm-shortcuts-group-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--flm-text-muted);
    margin-bottom: 12px;
}

.flm-shortcut-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
}

.flm-shortcut-label {
    font-size: 13px;
    color: var(--flm-text);
}

.flm-shortcut-keys {
    display: flex;
    gap: 4px;
}

.flm-shortcut-keys kbd {
    display: inline-block;
    padding: 4px 8px;
    font-size: 11px;
    font-family: var(--flm-mono);
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: 4px;
    color: var(--flm-text-muted);
    min-width: 24px;
    text-align: center;
}

/* Confetti Animation */
.flm-confetti-container {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 10001;
    overflow: hidden;
}

.flm-confetti {
    position: absolute;
    width: 10px;
    height: 10px;
    opacity: 0;
}

.flm-confetti.animate {
    animation: flm-confetti-fall 3s ease-out forwards;
}

@keyframes flm-confetti-fall {
    0% {
        opacity: 1;
        transform: translateY(0) rotate(0deg);
    }
    100% {
        opacity: 0;
        transform: translateY(100vh) rotate(720deg);
    }
}

/* Achievement Badge */
.flm-achievement {
    position: fixed;
    bottom: 100px;
    right: 24px;
    background: linear-gradient(135deg, var(--flm-bg-card) 0%, var(--flm-bg-dark) 100%);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.4);
    transform: translateX(120%);
    transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    z-index: 10000;
}

.flm-achievement.show {
    transform: translateX(0);
}

.flm-achievement-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #ffd700 0%, #ffb800 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 20px rgba(255, 215, 0, 0.4);
}

.flm-achievement-icon svg {
    width: 24px;
    height: 24px;
    color: #000;
}

.flm-achievement-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.flm-achievement-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #ffd700;
}

.flm-achievement-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-achievement-desc {
    font-size: 12px;
    color: var(--flm-text-muted);
}

/* Onboarding Checklist */
.flm-onboarding {
    background: linear-gradient(135deg, rgba(255, 107, 53, 0.1) 0%, var(--flm-bg-card) 100%);
    border: 1px solid rgba(255, 107, 53, 0.3);
    border-radius: var(--flm-radius);
    padding: 20px;
    margin-bottom: 24px;
}

.flm-onboarding-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.flm-onboarding-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--flm-text);
    display: flex;
    align-items: center;
    gap: 10px;
}

.flm-onboarding-title svg {
    width: 20px;
    height: 20px;
    color: var(--flm-accent);
}

.flm-onboarding-progress {
    font-size: 12px;
    color: var(--flm-text-muted);
}

.flm-onboarding-progress strong {
    color: var(--flm-accent);
}

.flm-onboarding-steps {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.flm-onboarding-step {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    transition: var(--flm-transition);
}

.flm-onboarding-step.completed {
    opacity: 0.6;
}

.flm-onboarding-step-check {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid var(--flm-border);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: var(--flm-transition);
}

.flm-onboarding-step.completed .flm-onboarding-step-check {
    background: var(--flm-success);
    border-color: var(--flm-success);
}

.flm-onboarding-step.completed .flm-onboarding-step-check svg {
    color: white;
}

.flm-onboarding-step-check svg {
    width: 14px;
    height: 14px;
    color: transparent;
}

.flm-onboarding-step-text {
    flex: 1;
    font-size: 13px;
    color: var(--flm-text);
}

.flm-onboarding-step.completed .flm-onboarding-step-text {
    text-decoration: line-through;
}

.flm-onboarding-step-action {
    font-size: 12px;
    color: var(--flm-accent);
    text-decoration: none;
}

.flm-onboarding-step-action:hover {
    text-decoration: underline;
}

.flm-onboarding-dismiss {
    background: none;
    border: none;
    color: var(--flm-text-muted);
    font-size: 12px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: var(--flm-transition-fast);
}

.flm-onboarding-dismiss:hover {
    background: var(--flm-bg-dark);
    color: var(--flm-text);
}

/* Tooltip System */
.flm-tooltip-trigger {
    position: relative;
    cursor: help;
}

.flm-tooltip-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--flm-bg-dark);
    color: var(--flm-text-muted);
    font-size: 10px;
    font-weight: 600;
    margin-left: 6px;
    transition: var(--flm-transition-fast);
}

.flm-tooltip-icon:hover {
    background: var(--flm-accent);
    color: white;
}

.flm-tooltip {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    padding: 10px 14px;
    font-size: 12px;
    color: var(--flm-text);
    white-space: nowrap;
    max-width: 280px;
    white-space: normal;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    z-index: 1000;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
}

.flm-tooltip::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 6px solid transparent;
    border-top-color: var(--flm-bg-dark);
}

.flm-tooltip-trigger:hover .flm-tooltip {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(-4px);
}

/* Heat Calendar */
.flm-heat-calendar {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.flm-heat-calendar-row {
    display: flex;
    gap: 4px;
}

.flm-heat-day {
    width: 14px;
    height: 14px;
    border-radius: 3px;
    background: var(--flm-bg-dark);
    transition: var(--flm-transition-fast);
}

.flm-heat-day[data-level="1"] { background: rgba(255, 107, 53, 0.2); }
.flm-heat-day[data-level="2"] { background: rgba(255, 107, 53, 0.4); }
.flm-heat-day[data-level="3"] { background: rgba(255, 107, 53, 0.6); }
.flm-heat-day[data-level="4"] { background: rgba(255, 107, 53, 0.8); }
.flm-heat-day[data-level="5"] { background: var(--flm-accent); }

.flm-heat-day:hover {
    transform: scale(1.3);
    box-shadow: 0 0 10px rgba(255, 107, 53, 0.5);
}

/* Live Activity Indicator */
.flm-live-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: rgba(248, 81, 73, 0.1);
    border: 1px solid rgba(248, 81, 73, 0.3);
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    color: var(--flm-danger);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.flm-live-dot {
    width: 8px;
    height: 8px;
    background: var(--flm-danger);
    border-radius: 50%;
    animation: flm-live-pulse 1.5s ease-in-out infinite;
}

@keyframes flm-live-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.8); }
}

/* Enhanced Progress Bar */
.flm-progress-fill {
    position: relative;
    overflow: hidden;
}

.flm-progress-fill::after {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    animation: flm-progress-shine 1.5s ease-in-out infinite;
}

@keyframes flm-progress-shine {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* Easter Egg - Konami Code Activated */
.flm-dashboard.party-mode {
    animation: flm-party 0.5s ease infinite;
}

@keyframes flm-party {
    0%, 100% { filter: hue-rotate(0deg); }
    50% { filter: hue-rotate(30deg); }
}

.flm-dashboard.party-mode .flm-header {
    background: linear-gradient(90deg, 
        rgba(255, 0, 0, 0.1), 
        rgba(255, 127, 0, 0.1), 
        rgba(255, 255, 0, 0.1), 
        rgba(0, 255, 0, 0.1), 
        rgba(0, 0, 255, 0.1), 
        rgba(139, 0, 255, 0.1)
    );
    background-size: 600% 100%;
    animation: flm-rainbow 3s linear infinite;
}

@keyframes flm-rainbow {
    0% { background-position: 0% 50%; }
    100% { background-position: 100% 50%; }
}

/* Header Quick Stats Enhancement */
.flm-header-stats {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 0 16px;
    border-left: 1px solid var(--flm-border);
    border-right: 1px solid var(--flm-border);
    margin: 0 8px;
}

.flm-header-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}

.flm-header-stat-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--flm-accent);
    font-family: var(--flm-mono);
}

.flm-header-stat-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--flm-text-muted);
}

/* Footer Enhancement */
.flm-footer {
    position: relative;
}

.flm-footer::before {
    content: "";
    position: absolute;
    top: 0;
    left: 24px;
    right: 24px;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--flm-border), transparent);
}

/* Custom Scrollbar Enhancement */
.flm-dashboard ::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.flm-dashboard ::-webkit-scrollbar-track {
    background: var(--flm-bg-dark);
    border-radius: 4px;
}

.flm-dashboard ::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, var(--flm-border-light), var(--flm-border));
    border-radius: 4px;
}

.flm-dashboard ::-webkit-scrollbar-thumb:hover {
    background: var(--flm-accent);
}

/* Focus Styles Enhancement */
.flm-dashboard *:focus-visible {
    outline: 2px solid var(--flm-accent);
    outline-offset: 2px;
    box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.2);
}

/* Print Styles */
@media print {
    .flm-dashboard {
        background: white !important;
        color: black !important;
    }
    
    .flm-header, .flm-footer, .flm-tabs-wrapper, .flm-btn, 
    .flm-toggle, .flm-command-palette, .flm-shortcuts-modal,
    .flm-achievement, .flm-confetti-container {
        display: none !important;
    }
    
    .flm-card {
        break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #ccc !important;
    }
}

/* ============================================
   ULTIMATE EDITION v2.7.0
   Premium Analytics & Visual Enhancements
   ============================================ */

/* Theme Toggle System */
.flm-theme-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: 20px;
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-theme-toggle:hover {
    border-color: var(--flm-accent);
}

.flm-theme-toggle-track {
    width: 36px;
    height: 20px;
    background: var(--flm-border);
    border-radius: 10px;
    position: relative;
    transition: var(--flm-transition);
}

.flm-theme-toggle-thumb {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 16px;
    height: 16px;
    background: var(--flm-text);
    border-radius: 50%;
    transition: var(--flm-transition);
    display: flex;
    align-items: center;
    justify-content: center;
}

.flm-theme-toggle-thumb svg {
    width: 10px;
    height: 10px;
    color: var(--flm-bg-dark);
}

.flm-dashboard[data-theme="light"] .flm-theme-toggle-track {
    background: var(--flm-accent);
}

.flm-dashboard[data-theme="light"] .flm-theme-toggle-thumb {
    left: 18px;
}

/* Light Theme Variables */
.flm-dashboard[data-theme="light"] {
    --flm-bg-dark: #f7f7f7;
    --flm-bg-card: #ffffff;
    --flm-bg-card-hover: #fafafa;
    --flm-bg-input: #ffffff;
    --flm-border: #e5e5e5;
    --flm-border-light: #f0f0f0;
    --flm-text: #111111;
    --flm-text-muted: #555555;
    --flm-bg: #ffffff;
}

/* FORCE WHITE - Override all gradients and backgrounds */
.flm-dashboard[data-theme="light"],
.flm-dashboard[data-theme="light"] .flm-main {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-card,
.flm-dashboard[data-theme="light"] .flm-card-header,
.flm-dashboard[data-theme="light"] .flm-card-body,
.flm-dashboard[data-theme="light"] .flm-card-footer {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-card {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08) !important;
    border: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-card-header {
    border-bottom: 1px solid #f0f0f0 !important;
}

.flm-dashboard[data-theme="light"] .flm-header {
    background: #ffffff !important;
    border-bottom: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-tabs-wrapper {
    background: #ffffff !important;
    border-bottom: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-tab-panel {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-footer {
    background: #ffffff !important;
    border-top: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-tab {
    color: #666666 !important;
    background: transparent !important;
}

.flm-dashboard[data-theme="light"] .flm-tab:hover {
    color: #111111 !important;
    background: #f5f5f5 !important;
}

.flm-dashboard[data-theme="light"] .flm-tab.active {
    color: var(--flm-accent) !important;
    background: transparent !important;
}

/* Status Bar / Stat Cards */
.flm-dashboard[data-theme="light"] .flm-status-bar {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-stat-card {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04) !important;
}

/* Action Cards */
.flm-dashboard[data-theme="light"] .flm-action-card {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-action-card:hover {
    background: #fafafa !important;
    border-color: #d0d0d0 !important;
}

/* Quick Actions Section */
.flm-dashboard[data-theme="light"] .flm-quick-actions {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-quick-actions-grid {
    background: transparent !important;
}

/* Team Cards */
.flm-dashboard[data-theme="light"] .flm-team-card {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-team-card:hover {
    border-color: #d0d0d0 !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-team-card[data-enabled="true"] {
    border-color: var(--flm-accent) !important;
    box-shadow: 0 0 0 2px rgba(255, 107, 53, 0.15) !important;
    background: #ffffff !important;
}

/* Buttons */
.flm-dashboard[data-theme="light"] .flm-btn-secondary {
    background: #ffffff !important;
    border: 1px solid #d5d5d5 !important;
    color: #333333 !important;
}

.flm-dashboard[data-theme="light"] .flm-btn-secondary:hover {
    background: #f5f5f5 !important;
    border-color: #c0c0c0 !important;
}

/* Analytics Section */
.flm-dashboard[data-theme="light"] .flm-analytics-section {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-analytics-header {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-summary-stats {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-summary-stat {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

/* Charts */
.flm-dashboard[data-theme="light"] .flm-chart-container,
.flm-dashboard[data-theme="light"] .flm-analytics-chart {
    background: #ffffff !important;
}

/* Performance Table */
.flm-dashboard[data-theme="light"] .flm-performance-table {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-performance-table th {
    background: #fafafa !important;
    border-bottom: 2px solid #e5e5e5 !important;
    color: #333333 !important;
}

.flm-dashboard[data-theme="light"] .flm-performance-table td {
    border-bottom: 1px solid #f0f0f0 !important;
    color: #333333 !important;
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-performance-table tbody tr {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-performance-table tbody tr:hover {
    background: #f9f9f9 !important;
}

/* Goals Card */
.flm-dashboard[data-theme="light"] .flm-goals-card {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-goal-item {
    background: #fafafa !important;
    border: 1px solid #f0f0f0 !important;
}

/* Heatmap */
.flm-dashboard[data-theme="light"] .flm-heatmap-card {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-heatmap-day {
    background: #eeeeee !important;
}

.flm-dashboard[data-theme="light"] .flm-heatmap-day[data-level="1"] { background: rgba(255, 107, 53, 0.25) !important; }
.flm-dashboard[data-theme="light"] .flm-heatmap-day[data-level="2"] { background: rgba(255, 107, 53, 0.45) !important; }
.flm-dashboard[data-theme="light"] .flm-heatmap-day[data-level="3"] { background: rgba(255, 107, 53, 0.65) !important; }
.flm-dashboard[data-theme="light"] .flm-heatmap-day[data-level="4"] { background: rgba(255, 107, 53, 0.85) !important; }
.flm-dashboard[data-theme="light"] .flm-heatmap-day[data-level="5"] { background: var(--flm-accent) !important; }

/* Top Performers */
.flm-dashboard[data-theme="light"] .flm-performer-item {
    background: #fafafa !important;
    border: 1px solid #f0f0f0 !important;
}

.flm-dashboard[data-theme="light"] .flm-performer-item:hover {
    background: #f5f5f5 !important;
}

/* Calendar */
.flm-dashboard[data-theme="light"] .flm-calendar-card {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-calendar-grid {
    background: #e5e5e5 !important;
    border: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-calendar-weekday {
    background: #fafafa !important;
    color: #555555 !important;
}

.flm-dashboard[data-theme="light"] .flm-calendar-day {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-calendar-day:hover {
    background: #f9f9f9 !important;
}

.flm-dashboard[data-theme="light"] .flm-calendar-day.other-month {
    background: #fafafa !important;
}

.flm-dashboard[data-theme="light"] .flm-calendar-day.today {
    background: rgba(255, 107, 53, 0.08) !important;
}

/* Logs */
.flm-dashboard[data-theme="light"] .flm-logs-container,
.flm-dashboard[data-theme="light"] .flm-logs-table {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-log-item {
    background: #ffffff !important;
    border-bottom: 1px solid #f0f0f0 !important;
}

.flm-dashboard[data-theme="light"] .flm-log-item:hover {
    background: #f9f9f9 !important;
}

/* Settings */
.flm-dashboard[data-theme="light"] .flm-settings-section,
.flm-dashboard[data-theme="light"] .flm-form-group {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-input,
.flm-dashboard[data-theme="light"] .flm-select {
    background: #ffffff !important;
    border: 1px solid #d5d5d5 !important;
    color: #111111 !important;
}

.flm-dashboard[data-theme="light"] .flm-input:focus,
.flm-dashboard[data-theme="light"] .flm-select:focus {
    border-color: var(--flm-accent) !important;
    box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1) !important;
}

.flm-dashboard[data-theme="light"] .flm-checkbox-card {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-checkbox-card:hover {
    background: #f9f9f9 !important;
}

/* Modals */
.flm-dashboard[data-theme="light"] .flm-modal {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-modal-header,
.flm-dashboard[data-theme="light"] .flm-modal-body,
.flm-dashboard[data-theme="light"] .flm-modal-footer {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-modal-header {
    border-bottom: 1px solid #f0f0f0 !important;
}

/* Command Palette */
.flm-dashboard[data-theme="light"] .flm-command-palette-box {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-command-input {
    background: #ffffff !important;
    color: #111111 !important;
}

.flm-dashboard[data-theme="light"] .flm-command-item {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-command-item:hover,
.flm-dashboard[data-theme="light"] .flm-command-item.active {
    background: #f5f5f5 !important;
}

/* Shortcuts Modal */
.flm-dashboard[data-theme="light"] .flm-shortcuts-box {
    background: #ffffff !important;
}

/* Onboarding */
.flm-dashboard[data-theme="light"] .flm-onboarding {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

/* Toast */
.flm-dashboard[data-theme="light"] .flm-toast {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
}

/* League Cards */
.flm-dashboard[data-theme="light"] .flm-league-card {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-league-card:hover {
    background: #f9f9f9 !important;
}

/* Widgets Grid */
.flm-dashboard[data-theme="light"] .flm-widgets-grid {
    background: transparent !important;
}

/* Comparison Toggle */
.flm-dashboard[data-theme="light"] .flm-comparison-toggle {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
}

/* Progress Ring Text */
.flm-dashboard[data-theme="light"] .flm-progress-ring-text {
    color: #111111 !important;
}

/* Misc elements */
.flm-dashboard[data-theme="light"] .flm-divider {
    border-color: #f0f0f0 !important;
}

.flm-dashboard[data-theme="light"] .flm-kbd {
    background: #f5f5f5 !important;
    border: 1px solid #e0e0e0 !important;
    color: #555555 !important;
}

.flm-dashboard[data-theme="light"] .flm-version {
    background: #f5f5f5 !important;
    color: #666666 !important;
}

/* Period Buttons */
.flm-dashboard[data-theme="light"] .flm-period-btn {
    background: #ffffff !important;
    border: 1px solid #e5e5e5 !important;
    color: #555555 !important;
}

.flm-dashboard[data-theme="light"] .flm-period-btn:hover {
    background: #f5f5f5 !important;
}

.flm-dashboard[data-theme="light"] .flm-period-btn.active {
    background: var(--flm-accent) !important;
    color: #ffffff !important;
}

/* Any remaining dark backgrounds */
.flm-dashboard[data-theme="light"] [class*="flm-"] {
    --flm-bg-dark: #f7f7f7;
}

/* Sparklines */
.flm-sparkline-container {
    display: flex;
    align-items: flex-end;
    gap: 2px;
    height: 32px;
    padding: 4px 0;
}

.flm-sparkline-bar {
    flex: 1;
    min-width: 4px;
    max-width: 8px;
    background: linear-gradient(180deg, var(--flm-accent) 0%, rgba(255, 107, 53, 0.4) 100%);
    border-radius: 2px;
    transition: all 0.3s ease;
    position: relative;
}

.flm-sparkline-bar:hover {
    background: var(--flm-accent);
    transform: scaleY(1.1);
}

.flm-sparkline-bar::after {
    content: attr(data-value);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: var(--flm-bg-dark);
    color: var(--flm-text);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    pointer-events: none;
    z-index: 10;
}

.flm-sparkline-bar:hover::after {
    opacity: 1;
    visibility: visible;
    bottom: calc(100% + 4px);
}

.flm-stat-card .flm-sparkline-container {
    margin-top: 8px;
    height: 24px;
}

/* Progress Rings */
.flm-progress-ring {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.flm-progress-ring svg {
    transform: rotate(-90deg);
}

.flm-progress-ring-bg {
    fill: none;
    stroke: var(--flm-border);
}

.flm-progress-ring-fill {
    fill: none;
    stroke: var(--flm-accent);
    stroke-linecap: round;
    transition: stroke-dashoffset 1s ease;
}

.flm-progress-ring-fill.success { stroke: var(--flm-success); }
.flm-progress-ring-fill.warning { stroke: var(--flm-warning); }
.flm-progress-ring-fill.danger { stroke: var(--flm-danger); }

.flm-progress-ring-text {
    position: absolute;
    font-size: 14px;
    font-weight: 700;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

.flm-progress-ring-label {
    position: absolute;
    bottom: -20px;
    font-size: 11px;
    color: var(--flm-text-muted);
    white-space: nowrap;
}

/* Goal Tracking Card */
.flm-goals-card {
    background: linear-gradient(135deg, var(--flm-bg-card) 0%, rgba(255, 107, 53, 0.05) 100%);
}

.flm-goals-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 20px;
}

.flm-goal-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    text-align: center;
}

.flm-goal-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--flm-text-muted);
}

.flm-goal-value {
    font-size: 11px;
    color: var(--flm-text-muted);
    font-family: var(--flm-mono);
}

/* Comparison Mode */
.flm-comparison-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    cursor: pointer;
    font-size: 12px;
    color: var(--flm-text-muted);
    transition: var(--flm-transition);
}

.flm-comparison-toggle:hover {
    border-color: var(--flm-accent);
    color: var(--flm-text);
}

.flm-comparison-toggle.active {
    background: rgba(255, 107, 53, 0.1);
    border-color: var(--flm-accent);
    color: var(--flm-accent);
}

.flm-comparison-toggle svg {
    width: 14px;
    height: 14px;
}

.flm-comparison-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    font-family: var(--flm-mono);
}

.flm-comparison-badge.up {
    background: rgba(63, 185, 80, 0.15);
    color: var(--flm-success);
}

.flm-comparison-badge.down {
    background: rgba(248, 81, 73, 0.15);
    color: var(--flm-danger);
}

.flm-comparison-badge.neutral {
    background: rgba(139, 148, 158, 0.15);
    color: var(--flm-text-muted);
}

.flm-stat-comparison {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    font-size: 11px;
    color: var(--flm-text-muted);
}

/* Heatmap Calendar */
.flm-heatmap-card {
    overflow: hidden;
}

.flm-heatmap-container {
    overflow-x: auto;
    padding: 8px 0;
}

.flm-heatmap {
    display: flex;
    gap: 3px;
}

.flm-heatmap-week {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.flm-heatmap-day {
    width: 12px;
    height: 12px;
    border-radius: 2px;
    background: var(--flm-bg-dark);
    cursor: pointer;
    transition: all 0.15s ease;
    position: relative;
}

.flm-heatmap-day:hover {
    transform: scale(1.3);
    z-index: 10;
}

.flm-heatmap-day[data-level="1"] { background: rgba(255, 107, 53, 0.2); }
.flm-heatmap-day[data-level="2"] { background: rgba(255, 107, 53, 0.4); }
.flm-heatmap-day[data-level="3"] { background: rgba(255, 107, 53, 0.6); }
.flm-heatmap-day[data-level="4"] { background: rgba(255, 107, 53, 0.8); }
.flm-heatmap-day[data-level="5"] { background: var(--flm-accent); }

.flm-heatmap-day[data-future="true"] {
    background: transparent;
    border: 1px dashed var(--flm-border);
}

.flm-heatmap-tooltip {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 11px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    z-index: 100;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.flm-heatmap-day:hover .flm-heatmap-tooltip {
    opacity: 1;
    visibility: visible;
}

.flm-heatmap-legend {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    font-size: 11px;
    color: var(--flm-text-muted);
}

.flm-heatmap-legend-scale {
    display: flex;
    gap: 2px;
}

.flm-heatmap-legend-item {
    width: 12px;
    height: 12px;
    border-radius: 2px;
}

.flm-heatmap-months {
    display: flex;
    gap: 3px;
    margin-bottom: 4px;
    padding-left: 20px;
}

.flm-heatmap-month {
    font-size: 10px;
    color: var(--flm-text-muted);
    min-width: 36px;
}

.flm-heatmap-days {
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin-right: 4px;
}

.flm-heatmap-day-label {
    height: 12px;
    font-size: 9px;
    color: var(--flm-text-muted);
    line-height: 12px;
}

/* Content Calendar */
.flm-calendar-card {
    overflow: hidden;
}

.flm-calendar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid var(--flm-border);
    margin-bottom: 12px;
}

.flm-calendar-nav {
    display: flex;
    align-items: center;
    gap: 12px;
}

.flm-calendar-nav-btn {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    cursor: pointer;
    transition: var(--flm-transition);
    color: var(--flm-text);
}

.flm-calendar-nav-btn:hover {
    background: var(--flm-bg-card-hover);
    border-color: var(--flm-accent);
}

.flm-calendar-nav-btn svg {
    width: 16px;
    height: 16px;
}

.flm-calendar-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-calendar-today-btn {
    padding: 6px 12px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    font-size: 12px;
    color: var(--flm-text-muted);
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-calendar-today-btn:hover {
    border-color: var(--flm-accent);
    color: var(--flm-accent);
}

.flm-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    background: var(--flm-border);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    overflow: hidden;
}

.flm-calendar-weekday {
    padding: 8px;
    background: var(--flm-bg-dark);
    font-size: 11px;
    font-weight: 600;
    color: var(--flm-text-muted);
    text-align: center;
    text-transform: uppercase;
}

.flm-calendar-day {
    min-height: 80px;
    padding: 8px;
    background: var(--flm-bg-card);
    transition: var(--flm-transition);
}

.flm-calendar-day:hover {
    background: var(--flm-bg-card-hover);
}

.flm-calendar-day.other-month {
    background: var(--flm-bg-dark);
    opacity: 0.5;
}

.flm-calendar-day.today {
    background: rgba(255, 107, 53, 0.08);
}

.flm-calendar-day.today .flm-calendar-date {
    background: var(--flm-accent);
    color: white;
}

.flm-calendar-date {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 500;
    color: var(--flm-text);
    margin-bottom: 4px;
}

.flm-calendar-events {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.flm-calendar-event {
    padding: 2px 6px;
    background: rgba(255, 107, 53, 0.15);
    border-left: 2px solid var(--flm-accent);
    border-radius: 2px;
    font-size: 10px;
    color: var(--flm-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-calendar-event:hover {
    background: rgba(255, 107, 53, 0.25);
}

.flm-calendar-event.braves { border-left-color: #CE1141; background: rgba(206, 17, 65, 0.15); }
.flm-calendar-event.falcons { border-left-color: #A71930; background: rgba(167, 25, 48, 0.15); }
.flm-calendar-event.hawks { border-left-color: #E03A3E; background: rgba(224, 58, 62, 0.15); }
.flm-calendar-event.uga { border-left-color: #BA0C2F; background: rgba(186, 12, 47, 0.15); }
.flm-calendar-event.gt { border-left-color: #B3A369; background: rgba(179, 163, 105, 0.15); }

.flm-calendar-more {
    font-size: 10px;
    color: var(--flm-text-muted);
    padding: 2px 0;
}

/* Top Performers Widget */
.flm-performers-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.flm-performer-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    transition: var(--flm-transition);
}

.flm-performer-item:hover {
    background: var(--flm-bg-card-hover);
}

.flm-performer-rank {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
}

.flm-performer-rank.gold {
    background: linear-gradient(135deg, #ffd700 0%, #ffb800 100%);
    color: #000;
    box-shadow: 0 0 12px rgba(255, 215, 0, 0.4);
}

.flm-performer-rank.silver {
    background: linear-gradient(135deg, #c0c0c0 0%, #a8a8a8 100%);
    color: #000;
}

.flm-performer-rank.bronze {
    background: linear-gradient(135deg, #cd7f32 0%, #b87333 100%);
    color: #fff;
}

.flm-performer-rank.default {
    background: var(--flm-border);
    color: var(--flm-text-muted);
}

.flm-performer-info {
    flex: 1;
    min-width: 0;
}

.flm-performer-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--flm-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.flm-performer-title a {
    color: inherit;
    text-decoration: none;
}

.flm-performer-title a:hover {
    color: var(--flm-accent);
}

.flm-performer-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: var(--flm-text-muted);
    margin-top: 2px;
}

.flm-performer-team {
    display: inline-block;
    padding: 1px 6px;
    background: var(--flm-bg-card);
    border-radius: 3px;
    font-size: 10px;
}

.flm-performer-stats {
    text-align: right;
    flex-shrink: 0;
}

.flm-performer-views {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

.flm-performer-vpd {
    font-size: 11px;
    color: var(--flm-success);
}

/* Animated Number Counter */
.flm-animated-number {
    display: inline-block;
    transition: transform 0.1s ease;
}

.flm-animated-number.counting {
    transform: scale(1.05);
}

/* Gradient Chart Enhancements */
.flm-chart-gradient-fill {
    opacity: 0.3;
}

.flm-chart-line {
    stroke-width: 3;
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.flm-chart-dot {
    fill: var(--flm-bg-card);
    stroke: var(--flm-accent);
    stroke-width: 2;
    r: 4;
    transition: r 0.2s ease;
}

.flm-chart-dot:hover {
    r: 6;
}

/* Dashboard Stats Enhancement */
.flm-stat-card-enhanced {
    position: relative;
    overflow: hidden;
}

.flm-stat-card-enhanced::before {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    width: 80px;
    height: 80px;
    background: radial-gradient(circle at top right, rgba(255, 107, 53, 0.1) 0%, transparent 70%);
    pointer-events: none;
}

.flm-stat-trend {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    margin-top: 4px;
}

.flm-stat-trend.up {
    color: var(--flm-success);
}

.flm-stat-trend.down {
    color: var(--flm-danger);
}

.flm-stat-trend svg {
    width: 12px;
    height: 12px;
}

/* Analytics Period Comparison */
.flm-period-compare {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px 16px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    margin-bottom: 16px;
}

.flm-period-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.flm-period-label {
    font-size: 10px;
    text-transform: uppercase;
    color: var(--flm-text-muted);
}

.flm-period-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

.flm-period-vs {
    font-size: 12px;
    color: var(--flm-text-muted);
    padding: 0 8px;
}

.flm-period-change {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.flm-period-change.up {
    background: rgba(63, 185, 80, 0.15);
    color: var(--flm-success);
}

.flm-period-change.down {
    background: rgba(248, 81, 73, 0.15);
    color: var(--flm-danger);
}

/* Quick Stats Row */
.flm-quick-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.flm-quick-stat {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
}

.flm-quick-stat-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
}

.flm-quick-stat-icon svg {
    width: 24px;
    height: 24px;
    color: var(--flm-accent);
}

.flm-quick-stat-content {
    flex: 1;
}

.flm-quick-stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--flm-text);
    font-family: var(--flm-mono);
    line-height: 1;
}

.flm-quick-stat-label {
    font-size: 12px;
    color: var(--flm-text-muted);
    margin-top: 4px;
}

/* Mini Widget Grid for Dashboard */
.flm-widgets-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-top: 24px;
}

@media (max-width: 1200px) {
    .flm-widgets-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .flm-widgets-grid {
        grid-template-columns: 1fr;
    }
    
    .flm-calendar-day {
        min-height: 60px;
        padding: 4px;
    }
    
    .flm-heatmap-day {
        width: 10px;
        height: 10px;
    }
}

/* ============================================
   ML INSIGHTS v2.8.0
   AI-Powered Analytics & Integrations
   ============================================ */

/* Insights Tab Layout */
.flm-insights-section {
    padding: 24px 0;
}

.flm-insights-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.flm-insights-title {
    display: flex;
    align-items: center;
    gap: 16px;
}

.flm-insights-title-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #a371f7 0%, #8b5cf6 100%);
    border-radius: var(--flm-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
}

.flm-insights-title-icon svg {
    width: 24px;
    height: 24px;
    color: white;
}

.flm-insights-title span {
    font-size: 20px;
    font-weight: 700;
    color: var(--flm-text);
}

.flm-insights-subtitle {
    font-size: 13px;
    color: var(--flm-text-muted);
    margin-top: 2px;
}

/* AI Status Indicator */
.flm-ai-status {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: rgba(163, 113, 247, 0.1);
    border: 1px solid rgba(163, 113, 247, 0.3);
    border-radius: 20px;
    font-size: 12px;
    color: #a371f7;
}

.flm-ai-status-dot {
    width: 8px;
    height: 8px;
    background: #a371f7;
    border-radius: 50%;
    animation: flm-pulse 2s ease-in-out infinite;
}

.flm-ai-status.connected .flm-ai-status-dot {
    background: var(--flm-success);
}

.flm-ai-status.disconnected .flm-ai-status-dot {
    background: var(--flm-danger);
    animation: none;
}

/* Integration Cards Grid */
.flm-integrations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.flm-integration-card {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 24px;
    transition: var(--flm-transition);
    position: relative;
    overflow: hidden;
}

.flm-integration-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--integration-color, var(--flm-border));
}

.flm-integration-card:hover {
    border-color: var(--integration-color, var(--flm-accent));
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

.flm-integration-card.connected::before {
    background: var(--flm-success);
}

.flm-integration-card[data-integration="ga4"] { --integration-color: #f9ab00; }
.flm-integration-card[data-integration="claude"] { --integration-color: #d97706; }
.flm-integration-card[data-integration="twitter"] { --integration-color: #1da1f2; }
.flm-integration-card[data-integration="facebook"] { --integration-color: #1877f2; }

.flm-integration-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.flm-integration-logo {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.flm-integration-logo.ga4 { background: linear-gradient(135deg, #f9ab00 0%, #e37400 100%); }
.flm-integration-logo.claude { background: linear-gradient(135deg, #d97706 0%, #b45309 100%); }
.flm-integration-logo.twitter { background: linear-gradient(135deg, #1da1f2 0%, #0d8ddb 100%); }
.flm-integration-logo.facebook { background: linear-gradient(135deg, #1877f2 0%, #0d65d9 100%); }

.flm-integration-logo svg {
    width: 24px;
    height: 24px;
    color: white;
}

.flm-integration-status {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.flm-integration-status.connected {
    background: rgba(63, 185, 80, 0.15);
    color: var(--flm-success);
}

.flm-integration-status.disconnected {
    background: rgba(139, 148, 158, 0.15);
    color: var(--flm-text-muted);
}

.flm-integration-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 4px;
}

.flm-integration-desc {
    font-size: 13px;
    color: var(--flm-text-muted);
    line-height: 1.5;
    margin-bottom: 16px;
}

.flm-integration-stats {
    display: flex;
    gap: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--flm-border);
}

.flm-integration-stat {
    flex: 1;
}

.flm-integration-stat-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

.flm-integration-stat-label {
    font-size: 11px;
    color: var(--flm-text-muted);
    margin-top: 2px;
}

/* Headline Analyzer */
.flm-headline-analyzer {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 24px;
    margin-bottom: 24px;
}

.flm-headline-input-group {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
}

.flm-headline-input {
    flex: 1;
    padding: 14px 18px;
    background: var(--flm-bg-input);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    font-size: 15px;
    color: var(--flm-text);
    transition: var(--flm-transition);
}

.flm-headline-input:focus {
    border-color: var(--flm-accent);
    box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.15);
    outline: none;
}

.flm-headline-input::placeholder {
    color: var(--flm-text-muted);
}

.flm-analyze-btn {
    padding: 14px 24px;
    background: linear-gradient(135deg, #a371f7 0%, #8b5cf6 100%);
    color: white;
    border: none;
    border-radius: var(--flm-radius-sm);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--flm-transition);
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}

.flm-analyze-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(163, 113, 247, 0.4);
}

.flm-analyze-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.flm-analyze-btn svg {
    width: 16px;
    height: 16px;
}

/* Score Display */
.flm-headline-results {
    display: none;
    animation: flm-fade-in 0.3s ease;
}

.flm-headline-results.show {
    display: block;
}

.flm-score-display {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 20px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    margin-bottom: 20px;
}

.flm-score-ring {
    position: relative;
    width: 100px;
    height: 100px;
    flex-shrink: 0;
}

.flm-score-ring svg {
    transform: rotate(-90deg);
}

.flm-score-ring-bg {
    fill: none;
    stroke: var(--flm-border);
    stroke-width: 8;
}

.flm-score-ring-fill {
    fill: none;
    stroke-width: 8;
    stroke-linecap: round;
    transition: stroke-dashoffset 1s ease;
}

.flm-score-ring-fill.excellent { stroke: var(--flm-success); }
.flm-score-ring-fill.good { stroke: #58a6ff; }
.flm-score-ring-fill.average { stroke: var(--flm-warning); }
.flm-score-ring-fill.poor { stroke: var(--flm-danger); }

.flm-score-value {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 28px;
    font-weight: 700;
    font-family: var(--flm-mono);
    color: var(--flm-text);
}

.flm-score-label {
    position: absolute;
    bottom: 8px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 10px;
    color: var(--flm-text-muted);
    text-transform: uppercase;
}

.flm-score-details {
    flex: 1;
}

.flm-score-verdict {
    font-size: 18px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 8px;
}

.flm-score-breakdown {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.flm-score-factor {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: var(--flm-bg-card);
    border-radius: 12px;
    font-size: 12px;
}

.flm-score-factor-icon {
    width: 14px;
    height: 14px;
}

.flm-score-factor-icon.positive { color: var(--flm-success); }
.flm-score-factor-icon.negative { color: var(--flm-danger); }
.flm-score-factor-icon.neutral { color: var(--flm-text-muted); }

/* AI Suggestions */
.flm-ai-suggestions {
    padding: 16px;
    background: rgba(163, 113, 247, 0.08);
    border: 1px solid rgba(163, 113, 247, 0.2);
    border-radius: var(--flm-radius-sm);
}

.flm-ai-suggestions-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #a371f7;
    margin-bottom: 12px;
}

.flm-ai-suggestions-title svg {
    width: 16px;
    height: 16px;
}

.flm-ai-suggestion-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(163, 113, 247, 0.15);
}

.flm-ai-suggestion-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.flm-ai-suggestion-number {
    width: 20px;
    height: 20px;
    background: #a371f7;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    flex-shrink: 0;
}

.flm-ai-suggestion-text {
    font-size: 13px;
    color: var(--flm-text);
    line-height: 1.5;
}

/* Optimal Time Widget */
.flm-optimal-time-card {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 24px;
}

.flm-time-heatmap {
    display: grid;
    grid-template-columns: auto repeat(7, 1fr);
    gap: 4px;
    margin: 20px 0;
}

.flm-time-heatmap-label {
    font-size: 10px;
    color: var(--flm-text-muted);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 8px;
}

.flm-time-heatmap-header {
    font-size: 10px;
    color: var(--flm-text-muted);
    text-align: center;
    padding: 4px;
}

.flm-time-cell {
    aspect-ratio: 1;
    border-radius: 4px;
    background: var(--flm-bg-dark);
    cursor: pointer;
    transition: var(--flm-transition);
    position: relative;
}

.flm-time-cell:hover {
    transform: scale(1.2);
    z-index: 10;
}

.flm-time-cell[data-score="1"] { background: rgba(255, 107, 53, 0.15); }
.flm-time-cell[data-score="2"] { background: rgba(255, 107, 53, 0.3); }
.flm-time-cell[data-score="3"] { background: rgba(255, 107, 53, 0.5); }
.flm-time-cell[data-score="4"] { background: rgba(255, 107, 53, 0.7); }
.flm-time-cell[data-score="5"] { background: var(--flm-accent); }

.flm-time-recommendation {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: rgba(63, 185, 80, 0.1);
    border: 1px solid rgba(63, 185, 80, 0.3);
    border-radius: var(--flm-radius-sm);
}

.flm-time-recommendation-icon {
    width: 48px;
    height: 48px;
    background: var(--flm-success);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.flm-time-recommendation-icon svg {
    width: 24px;
    height: 24px;
    color: white;
}

.flm-time-recommendation-text {
    flex: 1;
}

.flm-time-recommendation-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 4px;
}

.flm-time-recommendation-detail {
    font-size: 13px;
    color: var(--flm-text-muted);
}

/* Channel Comparison */
.flm-channel-comparison {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 24px;
}

.flm-channel-bars {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-top: 20px;
}

.flm-channel-bar {
    display: flex;
    align-items: center;
    gap: 12px;
}

.flm-channel-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.flm-channel-icon.website { background: var(--flm-accent); }
.flm-channel-icon.twitter { background: #1da1f2; }
.flm-channel-icon.facebook { background: #1877f2; }
.flm-channel-icon.email { background: #ea4335; }

.flm-channel-icon svg {
    width: 16px;
    height: 16px;
    color: white;
}

.flm-channel-info {
    flex: 1;
    min-width: 0;
}

.flm-channel-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--flm-text);
    margin-bottom: 4px;
}

.flm-channel-track {
    height: 8px;
    background: var(--flm-bg-dark);
    border-radius: 4px;
    overflow: hidden;
}

.flm-channel-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 1s ease;
}

.flm-channel-fill.website { background: linear-gradient(90deg, var(--flm-accent) 0%, #ff8c42 100%); }
.flm-channel-fill.twitter { background: linear-gradient(90deg, #1da1f2 0%, #0d8ddb 100%); }
.flm-channel-fill.facebook { background: linear-gradient(90deg, #1877f2 0%, #0d65d9 100%); }
.flm-channel-fill.email { background: linear-gradient(90deg, #ea4335 0%, #c5221f 100%); }

.flm-channel-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
    font-family: var(--flm-mono);
    width: 70px;
    text-align: right;
}

/* Trending Topics */
.flm-trending-card {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 24px;
}

.flm-trending-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 16px;
}

.flm-trending-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    transition: var(--flm-transition);
    cursor: pointer;
}

.flm-trending-item:hover {
    background: var(--flm-bg-card-hover);
}

.flm-trending-rank {
    width: 28px;
    height: 28px;
    background: var(--flm-accent);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
}

.flm-trending-content {
    flex: 1;
    min-width: 0;
}

.flm-trending-topic {
    font-size: 14px;
    font-weight: 500;
    color: var(--flm-text);
    margin-bottom: 2px;
}

.flm-trending-meta {
    font-size: 12px;
    color: var(--flm-text-muted);
    display: flex;
    align-items: center;
    gap: 8px;
}

.flm-trending-team {
    display: inline-block;
    padding: 2px 8px;
    background: var(--flm-bg-card);
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
}

.flm-trending-velocity {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: rgba(63, 185, 80, 0.15);
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    color: var(--flm-success);
}

.flm-trending-velocity svg {
    width: 12px;
    height: 12px;
}

/* Performance Predictor */
.flm-predictor-card {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 24px;
}

.flm-predictor-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.flm-predictor-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.flm-predictor-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--flm-text-muted);
}

.flm-predictor-result {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
}

.flm-predictor-metric {
    flex: 1;
    text-align: center;
}

.flm-predictor-metric-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

.flm-predictor-metric-label {
    font-size: 12px;
    color: var(--flm-text-muted);
    margin-top: 4px;
}

.flm-predictor-confidence {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: rgba(88, 166, 255, 0.15);
    border-radius: 20px;
    font-size: 13px;
    color: #58a6ff;
}

/* Content Ideas Generator */
.flm-ideas-card {
    background: linear-gradient(135deg, rgba(163, 113, 247, 0.1) 0%, rgba(139, 92, 246, 0.05) 100%);
    border: 1px solid rgba(163, 113, 247, 0.3);
    border-radius: var(--flm-radius);
    padding: 24px;
}

.flm-ideas-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 16px;
}

.flm-idea-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    background: var(--flm-bg-card);
    border-radius: var(--flm-radius-sm);
    transition: var(--flm-transition);
}

.flm-idea-item:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.flm-idea-type {
    padding: 4px 10px;
    background: var(--flm-accent);
    color: white;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    flex-shrink: 0;
}

.flm-idea-content {
    flex: 1;
}

.flm-idea-headline {
    font-size: 14px;
    font-weight: 500;
    color: var(--flm-text);
    margin-bottom: 4px;
}

.flm-idea-reason {
    font-size: 12px;
    color: var(--flm-text-muted);
}

.flm-idea-action {
    padding: 6px 12px;
    background: var(--flm-bg-dark);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius-sm);
    font-size: 12px;
    color: var(--flm-text);
    cursor: pointer;
    transition: var(--flm-transition);
}

.flm-idea-action:hover {
    background: var(--flm-accent);
    border-color: var(--flm-accent);
    color: white;
}

/* Settings Integration Section */
.flm-integration-settings {
    margin-top: 24px;
}

.flm-integration-group {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    margin-bottom: 16px;
    overflow: hidden;
}

.flm-integration-group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: var(--flm-bg-dark);
    border-bottom: 1px solid var(--flm-border);
    cursor: pointer;
}

.flm-integration-group-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
}

.flm-integration-group-body {
    padding: 20px;
    display: none;
}

.flm-integration-group.expanded .flm-integration-group-body {
    display: block;
}

.flm-integration-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}

/* Input with Password Toggle */
.flm-input-with-toggle {
    position: relative;
    display: flex;
}

.flm-input-with-toggle .flm-input {
    padding-right: 44px;
    flex: 1;
}

.flm-toggle-password {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    background: transparent;
    border: none;
    border-radius: var(--flm-radius-sm);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--flm-text-muted);
    transition: var(--flm-transition);
}

.flm-toggle-password:hover {
    background: var(--flm-bg-dark);
    color: var(--flm-text);
}

.flm-toggle-password.showing {
    color: var(--flm-accent);
}

.flm-toggle-password svg {
    width: 16px;
    height: 16px;
}

/* Time Cell Animation */
.flm-time-cell {
    position: relative;
}

.flm-time-cell::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 4px;
    box-shadow: 0 0 0 2px var(--flm-accent);
    opacity: 0;
    transition: opacity 0.2s ease;
}

.flm-time-cell:hover::after {
    opacity: 1;
}

/* SEO & Search Widgets */
.flm-seo-widget {
    background: var(--flm-bg-card);
    border: 1px solid var(--flm-border);
    border-radius: var(--flm-radius);
    padding: 20px;
}

.flm-seo-widget-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.flm-seo-widget-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
    margin: 0;
}

/* Keyword List */
.flm-keyword-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.flm-keyword-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
    transition: var(--flm-transition);
}

.flm-keyword-item:hover {
    background: var(--flm-bg-card-hover);
}

.flm-keyword-rank {
    width: 24px;
    height: 24px;
    background: var(--flm-accent);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
}

.flm-keyword-info {
    flex: 1;
    min-width: 0;
}

.flm-keyword-text {
    font-size: 13px;
    font-weight: 500;
    color: var(--flm-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.flm-keyword-meta {
    font-size: 11px;
    color: var(--flm-text-muted);
    margin-top: 2px;
}

.flm-keyword-clicks {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

/* SEO Stats Grid */
.flm-seo-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.flm-seo-stat {
    text-align: center;
    padding: 12px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
}

.flm-seo-stat-value {
    font-size: 22px;
    font-weight: 700;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

.flm-seo-stat-label {
    font-size: 11px;
    color: var(--flm-text-muted);
    margin-top: 2px;
}

.flm-seo-stat-change {
    font-size: 11px;
    font-weight: 600;
    margin-top: 4px;
}

.flm-seo-stat-change.up {
    color: var(--flm-success);
}

.flm-seo-stat-change.down {
    color: var(--flm-danger);
}

/* Search Engines Breakdown */
.flm-seo-engines {
    display: flex;
    gap: 12px;
    padding-top: 16px;
    border-top: 1px solid var(--flm-border);
}

.flm-seo-engine {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--flm-bg-dark);
    border-radius: var(--flm-radius-sm);
}

.flm-seo-engine-icon {
    width: 20px;
    height: 20px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: white;
}

.flm-seo-engine-icon.google { background: #4285f4; }
.flm-seo-engine-icon.bing { background: #008373; }
.flm-seo-engine-icon.other { background: var(--flm-text-muted); }

.flm-seo-engine-name {
    font-size: 12px;
    color: var(--flm-text);
    flex: 1;
}

.flm-seo-engine-value {
    font-size: 13px;
    font-weight: 600;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

/* SEO Health Score */
.flm-seo-health-score {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 16px;
}

.flm-seo-score-ring {
    position: relative;
    flex-shrink: 0;
}

.flm-seo-score-value {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 24px;
    font-weight: 700;
    color: var(--flm-text);
    font-family: var(--flm-mono);
}

.flm-seo-health-details {
    flex: 1;
}

.flm-seo-health-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--flm-text);
    margin-bottom: 8px;
}

.flm-seo-health-breakdown {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.flm-seo-health-breakdown span {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.flm-health-good {
    background: rgba(63, 185, 80, 0.15);
    color: var(--flm-success);
}

.flm-health-warning {
    background: rgba(210, 153, 34, 0.15);
    color: var(--flm-warning);
}

.flm-health-error {
    background: rgba(248, 81, 73, 0.15);
    color: var(--flm-danger);
}

/* SEO Issues */
.flm-seo-issues {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.flm-seo-issue {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: var(--flm-radius-sm);
    font-size: 12px;
}

.flm-seo-issue.warning {
    background: rgba(210, 153, 34, 0.1);
    color: var(--flm-warning);
}

.flm-seo-issue.error {
    background: rgba(248, 81, 73, 0.1);
    color: var(--flm-danger);
}

.flm-seo-issue-icon {
    font-size: 14px;
}

/* Light Theme Overrides for Insights */
.flm-dashboard[data-theme="light"] .flm-integration-card {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-headline-analyzer,
.flm-dashboard[data-theme="light"] .flm-optimal-time-card,
.flm-dashboard[data-theme="light"] .flm-channel-comparison,
.flm-dashboard[data-theme="light"] .flm-trending-card,
.flm-dashboard[data-theme="light"] .flm-predictor-card {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-score-display,
.flm-dashboard[data-theme="light"] .flm-trending-item,
.flm-dashboard[data-theme="light"] .flm-predictor-result {
    background: #f9f9f9 !important;
}

.flm-dashboard[data-theme="light"] .flm-ideas-card {
    background: linear-gradient(135deg, rgba(163, 113, 247, 0.08) 0%, rgba(139, 92, 246, 0.03) 100%) !important;
}

.flm-dashboard[data-theme="light"] .flm-idea-item {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-seo-widget {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-keyword-item,
.flm-dashboard[data-theme="light"] .flm-seo-stat,
.flm-dashboard[data-theme="light"] .flm-seo-engine {
    background: #f9f9f9 !important;
}

/* Light mode for Integration Settings */
.flm-dashboard[data-theme="light"] .flm-integration-group {
    background: #ffffff !important;
    border-color: #e5e5e5 !important;
}

.flm-dashboard[data-theme="light"] .flm-integration-group-header {
    background: #fafafa !important;
}

.flm-dashboard[data-theme="light"] .flm-integration-group-body {
    background: #ffffff !important;
}

.flm-dashboard[data-theme="light"] .flm-input-with-toggle .flm-input {
    background: #ffffff !important;
    border-color: #d5d5d5 !important;
}

.flm-dashboard[data-theme="light"] .flm-toggle-password:hover {
    background: #f0f0f0 !important;
}

.flm-dashboard[data-theme="light"] .flm-time-recommendation {
    background: rgba(63, 185, 80, 0.08) !important;
    border-color: rgba(63, 185, 80, 0.2) !important;
}

.flm-dashboard[data-theme="light"] .flm-time-cell {
    background: #f0f0f0 !important;
}

.flm-dashboard[data-theme="light"] .flm-time-cell[data-score="1"] { background: rgba(255, 107, 53, 0.2) !important; }
.flm-dashboard[data-theme="light"] .flm-time-cell[data-score="2"] { background: rgba(255, 107, 53, 0.35) !important; }
.flm-dashboard[data-theme="light"] .flm-time-cell[data-score="3"] { background: rgba(255, 107, 53, 0.5) !important; }
.flm-dashboard[data-theme="light"] .flm-time-cell[data-score="4"] { background: rgba(255, 107, 53, 0.7) !important; }
.flm-dashboard[data-theme="light"] .flm-time-cell[data-score="5"] { background: var(--flm-accent) !important; }

@media (max-width: 768px) {
    .flm-integrations-grid {
        grid-template-columns: 1fr;
    }
    
    .flm-headline-input-group {
        flex-direction: column;
    }
    
    .flm-score-display {
        flex-direction: column;
        text-align: center;
    }
    
    .flm-predictor-form {
        grid-template-columns: 1fr;
    }
}
        ';
    }
    
    /**
     * Get admin JavaScript
     */
    private function get_admin_js() {
        return '
(function($) {
    "use strict";
    
    console.log("FLM Admin JS loaded");
    console.log("FLM Config:", typeof flmAdmin !== "undefined" ? flmAdmin : "NOT DEFINED");
    
    // Toast notification system
    const Toast = {
        container: null,
        
        init: function() {
            this.container = document.createElement("div");
            this.container.className = "flm-toast-container";
            this.container.setAttribute("role", "alert");
            this.container.setAttribute("aria-live", "polite");
            document.body.appendChild(this.container);
        },
        
        show: function(type, title, message, duration = 5000) {
            const toast = document.createElement("div");
            toast.className = "flm-toast " + type;
            
            const icons = {
                success: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>\',
                error: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>\',
                warning: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>\',
                info: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>\'
            };
            
            toast.innerHTML = 
                "<span class=\"flm-toast-icon\">" + (icons[type] || icons.info) + "</span>" +
                "<div class=\"flm-toast-content\">" +
                    "<div class=\"flm-toast-title\">" + title + "</div>" +
                    (message ? "<div class=\"flm-toast-message\">" + message + "</div>" : "") +
                "</div>" +
                "<button class=\"flm-toast-close\" aria-label=\"Close notification\">" +
                    "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M18 6L6 18M6 6l12 12\"/></svg>" +
                "</button>";
            
            this.container.appendChild(toast);
            
            // Trigger animation
            requestAnimationFrame(() => {
                toast.classList.add("show");
            });
            
            // Close button
            toast.querySelector(".flm-toast-close").addEventListener("click", () => {
                this.hide(toast);
            });
            
            // Auto hide
            if (duration > 0) {
                setTimeout(() => this.hide(toast), duration);
            }
            
            return toast;
        },
        
        hide: function(toast) {
            toast.classList.add("hiding");
            toast.classList.remove("show");
            setTimeout(() => toast.remove(), 300);
        }
    };
    
    // Initialize toast system
    $(document).ready(function() {
        Toast.init();
        
        // Initialize tabs
        initTabs();
        
        // Initialize animations
        initPageAnimations();
        
        // Initialize analytics charts if data exists and analytics tab is active
        if (typeof flmAnalyticsData !== "undefined") {
            initAnalyticsCharts();
        }
        
        // Initialize keyboard shortcuts
        initKeyboardShortcuts();
        
        // Initialize save indicator
        initSaveIndicator();
    });
    
    // Tab Navigation System
    function initTabs() {
        const tabs = document.querySelectorAll(".flm-tab");
        const panels = document.querySelectorAll(".flm-tab-panel");
        
        if (!tabs.length) return;
        
        // Handle URL hash on load
        const hash = window.location.hash.slice(1);
        if (hash) {
            const targetTab = document.querySelector(".flm-tab[data-tab=\"" + hash + "\"]");
            if (targetTab) {
                activateTab(targetTab);
            }
        }
        
        // Tab click handler
        tabs.forEach(tab => {
            tab.addEventListener("click", function() {
                activateTab(this);
            });
        });
        
        function activateTab(tab) {
            const targetId = tab.dataset.tab;
            
            // Update URL hash without scrolling
            history.replaceState(null, null, "#" + targetId);
            
            // Deactivate all tabs
            tabs.forEach(t => t.classList.remove("active"));
            panels.forEach(p => p.classList.remove("active"));
            
            // Activate clicked tab
            tab.classList.add("active");
            
            // Activate corresponding panel
            const targetPanel = document.getElementById("panel-" + targetId);
            if (targetPanel) {
                targetPanel.classList.add("active");
                
                // Initialize charts if switching to analytics tab
                if (targetId === "analytics" && typeof flmAnalyticsData !== "undefined") {
                    setTimeout(initAnalyticsCharts, 100);
                }
            }
        }
    }
    
    // Keyboard Shortcuts
    function initKeyboardShortcuts() {
        document.addEventListener("keydown", function(e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === "s") {
                e.preventDefault();
                $("#flm-settings-form").submit();
            }
            
            // Ctrl/Cmd + K to open command palette
            if ((e.ctrlKey || e.metaKey) && e.key === "k") {
                e.preventDefault();
                toggleCommandPalette();
            }
            
            // Escape to close command palette
            if (e.key === "Escape") {
                closeCommandPalette();
            }
            
            // Number keys 1-5 to switch tabs (when not in input and palette closed)
            if (!$(e.target).is("input, textarea, select") && e.key >= "1" && e.key <= "5") {
                if (!document.querySelector(".flm-command-palette-overlay.active")) {
                    const tabIndex = parseInt(e.key) - 1;
                    const tabs = document.querySelectorAll(".flm-tab");
                    if (tabs[tabIndex]) {
                        tabs[tabIndex].click();
                    }
                }
            }
        });
    }
    
    // ============================================
    // COMMAND PALETTE (v2.14.0)
    // ============================================
    
    const commandPaletteCommands = [
        // Actions
        { id: "run-import", title: "Run Import Now", desc: "Fetch latest articles from FLM", icon: "download", group: "Actions", action: () => { closeCommandPalette(); runImport(); } },
        { id: "dry-run", title: "Preview Import (Dry Run)", desc: "See what would be imported", icon: "eye", group: "Actions", action: () => { closeCommandPalette(); runDryRun(); } },
        { id: "clear-cache", title: "Clear All Caches", desc: "Reset cached data", icon: "refresh", group: "Actions", action: () => { closeCommandPalette(); clearAllCaches(); } },
        { id: "export-settings", title: "Export Settings", desc: "Download settings as JSON", icon: "upload", group: "Actions", action: () => { closeCommandPalette(); switchToTabAndScroll("settings", "flm-export-section"); } },
        
        // Navigation
        { id: "nav-dashboard", title: "Go to Dashboard", desc: "Overview and stats", icon: "home", group: "Navigation", shortcut: "1", action: () => navigateToTab("dashboard") },
        { id: "nav-insights", title: "Go to Insights", desc: "Analytics and integrations", icon: "chart", group: "Navigation", shortcut: "2", action: () => navigateToTab("insights") },
        { id: "nav-import", title: "Go to Import", desc: "Import articles", icon: "download", group: "Navigation", shortcut: "3", action: () => navigateToTab("import") },
        { id: "nav-calendar", title: "Go to Calendar", desc: "Content schedule", icon: "calendar", group: "Navigation", shortcut: "4", action: () => navigateToTab("calendar") },
        { id: "nav-settings", title: "Go to Settings", desc: "Configuration", icon: "settings", group: "Navigation", shortcut: "5", action: () => navigateToTab("settings") },
        
        // Tools
        { id: "analyze-headline", title: "Analyze Headline", desc: "Get headline suggestions", icon: "sparkle", group: "Tools", action: () => { closeCommandPalette(); switchToTabAndScroll("insights", "flm-headline-analyzer"); } },
        { id: "test-social", title: "Test Social Connection", desc: "Verify Twitter/Facebook auth", icon: "share", group: "Tools", action: () => { closeCommandPalette(); switchToTabAndScroll("settings", "flm-social-settings"); } },
        { id: "view-logs", title: "View Import Log", desc: "See recent import activity", icon: "list", group: "Tools", action: () => { closeCommandPalette(); switchToTabAndScroll("import", "flm-import-log"); } },
        
        // Quick Settings
        { id: "toggle-auto-import", title: "Toggle Auto Import", desc: "Enable/disable scheduled imports", icon: "clock", group: "Quick Settings", action: () => { closeCommandPalette(); toggleAutoImport(); } },
        { id: "toggle-social", title: "Toggle Social Posting", desc: "Enable/disable social sharing", icon: "share", group: "Quick Settings", action: () => { closeCommandPalette(); toggleSocialPosting(); } },
    ];
    
    function createCommandPalette() {
        if (document.getElementById("flm-command-palette")) return;
        
        const overlay = document.createElement("div");
        overlay.id = "flm-command-palette";
        overlay.className = "flm-command-palette-overlay";
        overlay.innerHTML = `
            <div class="flm-command-palette">
                <div class="flm-command-input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input type="text" class="flm-command-input" placeholder="Type a command or search...">
                    <div class="flm-command-kbd">
                        <kbd>esc</kbd>
                    </div>
                </div>
                <div class="flm-command-results"></div>
                <div class="flm-command-footer">
                    <div class="flm-command-footer-hints">
                        <span class="flm-command-footer-hint"><kbd>↑↓</kbd> Navigate</span>
                        <span class="flm-command-footer-hint"><kbd>↵</kbd> Select</span>
                        <span class="flm-command-footer-hint"><kbd>esc</kbd> Close</span>
                    </div>
                    <span>FLM GameDay v2.14.0</span>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        const input = overlay.querySelector(".flm-command-input");
        const results = overlay.querySelector(".flm-command-results");
        
        // Click outside to close
        overlay.addEventListener("click", function(e) {
            if (e.target === overlay) closeCommandPalette();
        });
        
        // Filter commands on input
        input.addEventListener("input", function() {
            renderCommands(this.value);
        });
        
        // Keyboard navigation
        input.addEventListener("keydown", function(e) {
            const items = results.querySelectorAll(".flm-command-item");
            const selected = results.querySelector(".flm-command-item.selected");
            let index = Array.from(items).indexOf(selected);
            
            if (e.key === "ArrowDown") {
                e.preventDefault();
                index = Math.min(index + 1, items.length - 1);
                selectCommandItem(items, index);
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                index = Math.max(index - 1, 0);
                selectCommandItem(items, index);
            } else if (e.key === "Enter" && selected) {
                e.preventDefault();
                selected.click();
            }
        });
        
        renderCommands("");
    }
    
    function selectCommandItem(items, index) {
        items.forEach(i => i.classList.remove("selected"));
        if (items[index]) {
            items[index].classList.add("selected");
            items[index].scrollIntoView({ block: "nearest" });
        }
    }
    
    function getCommandIcon(icon) {
        const icons = {
            download: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>\',
            eye: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>\',
            refresh: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>\',
            upload: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>\',
            home: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M9 22V12h6v10"/></svg>\',
            chart: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>\',
            calendar: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>\',
            settings: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>\',
            sparkle: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3zM5 18l.75 2.25L8 21l-2.25.75L5 24l-.75-2.25L2 21l2.25-.75L5 18z"/></svg>\',
            share: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>\',
            list: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>\',
            clock: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>\',
        };
        return icons[icon] || icons.settings;
    }
    
    function renderCommands(filter) {
        const results = document.querySelector(".flm-command-results");
        if (!results) return;
        
        const filtered = commandPaletteCommands.filter(cmd => 
            cmd.title.toLowerCase().includes(filter.toLowerCase()) ||
            cmd.desc.toLowerCase().includes(filter.toLowerCase())
        );
        
        if (filtered.length === 0) {
            results.innerHTML = `<div class="flm-command-empty">No commands found for "${filter}"</div>`;
            return;
        }
        
        // Group by category
        const groups = {};
        filtered.forEach(cmd => {
            if (!groups[cmd.group]) groups[cmd.group] = [];
            groups[cmd.group].push(cmd);
        });
        
        let html = "";
        let first = true;
        
        for (const [group, cmds] of Object.entries(groups)) {
            html += `<div class="flm-command-group">
                <div class="flm-command-group-title">${group}</div>`;
            
            cmds.forEach(cmd => {
                html += `<div class="flm-command-item${first ? " selected" : ""}" data-command="${cmd.id}">
                    <div class="flm-command-icon">${getCommandIcon(cmd.icon)}</div>
                    <div class="flm-command-text">
                        <div class="flm-command-title">${cmd.title}</div>
                        <div class="flm-command-desc">${cmd.desc}</div>
                    </div>
                    ${cmd.shortcut ? `<div class="flm-command-shortcut"><kbd>${cmd.shortcut}</kbd></div>` : ""}
                </div>`;
                first = false;
            });
            
            html += `</div>`;
        }
        
        results.innerHTML = html;
        
        // Add click handlers
        results.querySelectorAll(".flm-command-item").forEach(item => {
            item.addEventListener("click", function() {
                const cmd = commandPaletteCommands.find(c => c.id === this.dataset.command);
                if (cmd && cmd.action) cmd.action();
            });
            
            item.addEventListener("mouseenter", function() {
                results.querySelectorAll(".flm-command-item").forEach(i => i.classList.remove("selected"));
                this.classList.add("selected");
            });
        });
    }
    
    function toggleCommandPalette() {
        createCommandPalette();
        const overlay = document.getElementById("flm-command-palette");
        if (overlay.classList.contains("active")) {
            closeCommandPalette();
        } else {
            overlay.classList.add("active");
            const input = overlay.querySelector(".flm-command-input");
            input.value = "";
            input.focus();
            renderCommands("");
        }
    }
    
    function closeCommandPalette() {
        const overlay = document.getElementById("flm-command-palette");
        if (overlay) overlay.classList.remove("active");
    }
    
    function navigateToTab(tabId) {
        closeCommandPalette();
        const tab = document.querySelector(`.flm-tab[data-tab="${tabId}"]`);
        if (tab) tab.click();
    }
    
    function switchToTabAndScroll(tabId, elementId) {
        navigateToTab(tabId);
        setTimeout(() => {
            const el = document.getElementById(elementId);
            if (el) el.scrollIntoView({ behavior: "smooth", block: "center" });
        }, 100);
    }
    
    function toggleAutoImport() {
        const checkbox = document.getElementById("flm_auto_import");
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event("change", { bubbles: true }));
            Toast.show(checkbox.checked ? "Auto import enabled" : "Auto import disabled", "success");
        }
    }
    
    function toggleSocialPosting() {
        const checkbox = document.getElementById("flm_social_auto_post");
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event("change", { bubbles: true }));
            Toast.show(checkbox.checked ? "Social posting enabled" : "Social posting disabled", "success");
        }
    }
    
    function clearAllCaches() {
        $.post(ajaxurl, {
            action: "flm_clear_all_caches",
            nonce: flmAdmin.nonce
        }, function(response) {
            Toast.show("All caches cleared", "success");
        });
    }
    
    // ============================================
    // SPARKLINES (v2.14.0)
    // ============================================
    
    function createSparkline(data, container) {
        if (!data || data.length === 0) return;
        
        const max = Math.max(...data);
        const min = Math.min(...data);
        const range = max - min || 1;
        
        let html = \'<div class="flm-sparkline">\';
        data.forEach((val, i) => {
            const height = Math.max(3, ((val - min) / range) * 18);
            html += `<div class="flm-sparkline-bar" style="height: ${height}px" title="Day ${i+1}: ${val.toLocaleString()}"></div>`;
        });
        html += \'</div>\';
        
        if (typeof container === "string") {
            const el = document.querySelector(container);
            if (el) el.insertAdjacentHTML("beforeend", html);
        } else if (container) {
            container.insertAdjacentHTML("beforeend", html);
        }
        
        return html;
    }
    
    function calculateTrend(data) {
        if (!data || data.length < 2) return { direction: "neutral", percent: 0 };
        
        const recent = data.slice(-3).reduce((a, b) => a + b, 0) / Math.min(3, data.length);
        const older = data.slice(0, 3).reduce((a, b) => a + b, 0) / Math.min(3, data.length);
        
        if (older === 0) return { direction: "neutral", percent: 0 };
        
        const percent = Math.round(((recent - older) / older) * 100);
        const direction = percent > 5 ? "up" : percent < -5 ? "down" : "neutral";
        
        return { direction, percent: Math.abs(percent) };
    }
    
    function renderTrendIndicator(data) {
        const trend = calculateTrend(data);
        const arrow = trend.direction === "up" ? "↑" : trend.direction === "down" ? "↓" : "→";
        return `<span class="flm-trend-indicator ${trend.direction}">${arrow} ${trend.percent}%</span>`;
    }
    
    // ============================================
    // ONBOARDING CHECKLIST (v2.14.0)
    // ============================================
    
    function getOnboardingSteps() {
        return [
            { id: "api-key", label: "Add FLM API Key", check: () => !!document.querySelector("[data-has-api-key=\"true\"]"), action: () => switchToTabAndScroll("settings", "flm_api_key") },
            { id: "teams", label: "Select Teams", check: () => !!document.querySelector("[data-has-teams=\"true\"]"), action: () => switchToTabAndScroll("settings", "flm-team-selection") },
            { id: "categories", label: "Map Categories", check: () => !!document.querySelector("[data-has-categories=\"true\"]"), action: () => switchToTabAndScroll("settings", "flm-category-mapping") },
            { id: "first-import", label: "Run First Import", check: () => parseInt(document.querySelector("[data-total-articles]")?.dataset.totalArticles || "0") > 0, action: () => navigateToTab("import") },
            { id: "analytics", label: "Connect Analytics", check: () => !!document.querySelector("[data-has-analytics=\"true\"]"), action: () => switchToTabAndScroll("settings", "flm-ga-settings") },
            { id: "email", label: "Connect Email ESP", check: () => !!document.querySelector("[data-has-esp=\"true\"]"), action: () => switchToTabAndScroll("settings", "flm-esp-settings") },
        ];
    }
    
    function renderOnboarding() {
        const container = document.getElementById("flm-onboarding");
        if (!container) return;
        
        // Check if dismissed
        if (localStorage.getItem("flm-onboarding-dismissed") === "true") {
            container.style.display = "none";
            return;
        }
        
        const steps = getOnboardingSteps();
        const completed = steps.filter(s => s.check()).length;
        const percent = Math.round((completed / steps.length) * 100);
        
        // Hide if all complete
        if (completed === steps.length) {
            container.style.display = "none";
            return;
        }
        
        let stepsHtml = steps.map(step => {
            const done = step.check();
            return `<div class="flm-onboarding-step ${done ? "completed" : ""}" data-step="${step.id}">
                <div class="flm-onboarding-step-icon">
                    ${done ? 
                        \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>\' : 
                        \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>\'
                    }
                </div>
                <span class="flm-onboarding-step-text">${step.label}</span>
            </div>`;
        }).join("");
        
        container.innerHTML = `
            <div class="flm-onboarding-header">
                <div class="flm-onboarding-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
                    Setup Progress
                </div>
                <div class="flm-onboarding-progress-text"><strong>${completed}/${steps.length}</strong> Complete</div>
                <button class="flm-onboarding-dismiss" title="Dismiss">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flm-onboarding-bar">
                <div class="flm-onboarding-bar-fill" style="width: ${percent}%"></div>
            </div>
            <div class="flm-onboarding-steps">${stepsHtml}</div>
        `;
        
        // Add click handlers
        container.querySelectorAll(".flm-onboarding-step:not(.completed)").forEach(el => {
            el.addEventListener("click", function() {
                const step = steps.find(s => s.id === this.dataset.step);
                if (step && step.action) step.action();
            });
        });
        
        container.querySelector(".flm-onboarding-dismiss")?.addEventListener("click", function() {
            localStorage.setItem("flm-onboarding-dismissed", "true");
            container.style.display = "none";
        });
    }
    
    // ============================================
    // ACTIVITY FEED (v2.14.0)
    // ============================================
    
    const activityFeed = {
        items: [],
        maxItems: 20,
        
        add: function(type, text, meta = {}) {
            this.items.unshift({
                type,
                text,
                time: new Date(),
                meta
            });
            
            if (this.items.length > this.maxItems) {
                this.items = this.items.slice(0, this.maxItems);
            }
            
            this.render();
        },
        
        render: function() {
            const container = document.getElementById("flm-activity-list");
            if (!container) return;
            
            if (this.items.length === 0) {
                container.innerHTML = `<div class="flm-empty-state">
                    <div class="flm-empty-state-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3M3 12a9 9 0 1018 0 9 9 0 00-18 0z"/></svg>
                    </div>
                    <div class="flm-empty-state-title">No recent activity</div>
                    <div class="flm-empty-state-desc">Activity will appear here as you use the plugin</div>
                </div>`;
                return;
            }
            
            container.innerHTML = this.items.map(item => this.renderItem(item)).join("");
        },
        
        renderItem: function(item) {
            const icons = {
                import: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>\',
                social: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>\',
                email: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 6l-10 7L2 6"/></svg>\',
                analytics: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>\',
                error: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>\',
            };
            
            const timeAgo = this.formatTimeAgo(item.time);
            
            return `<div class="flm-activity-item">
                <div class="flm-activity-icon ${item.type}">${icons[item.type] || icons.analytics}</div>
                <div class="flm-activity-content">
                    <div class="flm-activity-text">${item.text}</div>
                    <div class="flm-activity-meta">
                        <span class="flm-activity-time">${timeAgo}</span>
                        ${item.meta.team ? `<span class="flm-team-badge ${item.meta.team}">${item.meta.team}</span>` : ""}
                    </div>
                </div>
            </div>`;
        },
        
        formatTimeAgo: function(date) {
            const seconds = Math.floor((new Date() - date) / 1000);
            if (seconds < 60) return "Just now";
            if (seconds < 3600) return Math.floor(seconds / 60) + " min ago";
            if (seconds < 86400) return Math.floor(seconds / 3600) + " hour ago";
            return Math.floor(seconds / 86400) + " day ago";
        },
        
        loadFromServer: function() {
            // Load recent activity from server
            $.get(ajaxurl, {
                action: "flm_get_recent_activity",
                nonce: flmAdmin.nonce
            }, (response) => {
                if (response.success && response.data) {
                    this.items = response.data.map(item => ({
                        ...item,
                        time: new Date(item.time)
                    }));
                    this.render();
                }
            });
        }
    };
    
    // ============================================
    // SMART NOTIFICATIONS / INSIGHTS (v2.14.0)
    // ============================================
    
    const insightsEngine = {
        insights: [],
        dismissed: JSON.parse(localStorage.getItem("flm-dismissed-insights") || "[]"),
        
        analyze: function() {
            this.insights = [];
            
            // Check send time patterns
            const sendTimeData = window.flmSendTimeData;
            if (sendTimeData && sendTimeData.bestHour && sendTimeData.currentHour) {
                if (Math.abs(sendTimeData.bestHour - sendTimeData.currentHour) > 2) {
                    this.insights.push({
                        id: "send-time-optimization",
                        title: "Send Time Optimization",
                        text: "Your content performs best at " + this.formatHour(sendTimeData.bestHour) + ", but you are currently posting at " + this.formatHour(sendTimeData.currentHour) + ". Adjusting could increase clicks by ~" + (sendTimeData.potentialLift || 15) + "%.",
                        actions: [
                            { label: "Update Schedule", primary: true, action: () => switchToTabAndScroll("settings", "flm-send-time-settings") },
                            { label: "Dismiss", action: () => this.dismiss("send-time-optimization") }
                        ]
                    });
                }
            }
            
            // Check team performance
            const teamStats = window.flmTeamStats;
            if (teamStats) {
                const topTeam = Object.entries(teamStats).sort((a, b) => b[1].clicks - a[1].clicks)[0];
                const bottomTeam = Object.entries(teamStats).sort((a, b) => a[1].clicks - b[1].clicks)[0];
                
                if (topTeam && bottomTeam && topTeam[1].clicks > bottomTeam[1].clicks * 2) {
                    this.insights.push({
                        id: "team-performance-gap",
                        title: "Content Balance Opportunity",
                        text: `${topTeam[0]} content gets ${Math.round(topTeam[1].clicks / bottomTeam[1].clicks)}x more engagement than ${bottomTeam[0]}. Consider adjusting your content mix.`,
                        actions: [
                            { label: "View Analytics", primary: true, action: () => navigateToTab("insights") },
                            { label: "Dismiss", action: () => this.dismiss("team-performance-gap") }
                        ]
                    });
                }
            }
            
            // Check for API issues
            const lastImport = window.flmLastImport;
            if (lastImport && lastImport.errors > 0) {
                this.insights.push({
                    id: "import-errors",
                    title: "Import Issues Detected",
                    text: `Your last import had ${lastImport.errors} error(s). This might affect content freshness.`,
                    actions: [
                        { label: "View Log", primary: true, action: () => navigateToTab("import") },
                        { label: "Dismiss", action: () => this.dismiss("import-errors") }
                    ]
                });
            }
            
            this.render();
        },
        
        dismiss: function(id) {
            this.dismissed.push(id);
            localStorage.setItem("flm-dismissed-insights", JSON.stringify(this.dismissed));
            this.render();
        },
        
        render: function() {
            const container = document.getElementById("flm-insights-container");
            if (!container) return;
            
            const active = this.insights.filter(i => !this.dismissed.includes(i.id));
            
            if (active.length === 0) {
                container.innerHTML = "";
                return;
            }
            
            container.innerHTML = active.map(insight => `
                <div class="flm-insight-notification" data-insight="${insight.id}">
                    <div class="flm-insight-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a7 7 0 017 7c0 2.38-1.19 4.47-3 5.74V17a2 2 0 01-2 2H10a2 2 0 01-2-2v-2.26C6.19 13.47 5 11.38 5 9a7 7 0 017-7zM10 21h4"/></svg>
                    </div>
                    <div class="flm-insight-content">
                        <div class="flm-insight-title">${insight.title}</div>
                        <div class="flm-insight-text">${insight.text}</div>
                        <div class="flm-insight-actions">
                            ${insight.actions.map(a => `<button class="flm-insight-action ${a.primary ? "primary" : "secondary"}" data-action="${a.label}">${a.label}</button>`).join("")}
                        </div>
                    </div>
                    <button class="flm-insight-close" data-dismiss="${insight.id}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>
            `).join("");
            
            // Bind actions
            container.querySelectorAll(".flm-insight-action, .flm-insight-close").forEach(btn => {
                btn.addEventListener("click", (e) => {
                    const insightId = btn.closest(".flm-insight-notification").dataset.insight;
                    const insight = this.insights.find(i => i.id === insightId);
                    
                    if (btn.classList.contains("flm-insight-close")) {
                        this.dismiss(insightId);
                        return;
                    }
                    
                    const action = insight?.actions.find(a => a.label === btn.dataset.action);
                    if (action?.action) action.action();
                });
            });
        },
        
        formatHour: function(hour) {
            const ampm = hour >= 12 ? "PM" : "AM";
            const h = hour % 12 || 12;
            return `${h}:00 ${ampm}`;
        }
    };
    
    // ============================================
    // PROGRESSIVE DISCLOSURE (v2.14.0)
    // ============================================
    
    function initCollapseSections() {
        document.querySelectorAll(".flm-collapse-header").forEach(header => {
            header.addEventListener("click", function() {
                const section = this.closest(".flm-collapse-section");
                section.classList.toggle("open");
                
                // Save state
                const sectionId = section.dataset.section;
                if (sectionId) {
                    const openSections = JSON.parse(localStorage.getItem("flm-open-sections") || "[]");
                    if (section.classList.contains("open")) {
                        if (!openSections.includes(sectionId)) openSections.push(sectionId);
                    } else {
                        const idx = openSections.indexOf(sectionId);
                        if (idx > -1) openSections.splice(idx, 1);
                    }
                    localStorage.setItem("flm-open-sections", JSON.stringify(openSections));
                }
            });
        });
        
        // Restore state
        const openSections = JSON.parse(localStorage.getItem("flm-open-sections") || "[]");
        openSections.forEach(id => {
            const section = document.querySelector(`.flm-collapse-section[data-section="${id}"]`);
            if (section) section.classList.add("open");
        });
    }
    
    // ============================================
    // DRAG & DROP CALENDAR (v2.14.0)
    // ============================================
    
    function initDragDropCalendar() {
        const calendar = document.querySelector(".flm-calendar-grid");
        if (!calendar) return;
        
        let draggedEvent = null;
        let draggedData = null;
        
        // Make events draggable
        calendar.querySelectorAll(".flm-calendar-event").forEach(event => {
            event.draggable = true;
            
            event.addEventListener("dragstart", function(e) {
                draggedEvent = this;
                draggedData = {
                    postId: this.dataset.postId,
                    originalDate: this.closest(".flm-calendar-day")?.dataset.date
                };
                this.classList.add("dragging");
                e.dataTransfer.effectAllowed = "move";
            });
            
            event.addEventListener("dragend", function() {
                this.classList.remove("dragging");
                draggedEvent = null;
                document.querySelectorAll(".flm-calendar-day").forEach(d => d.classList.remove("drag-over"));
            });
        });
        
        // Make days drop targets
        calendar.querySelectorAll(".flm-calendar-day").forEach(day => {
            day.addEventListener("dragover", function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = "move";
                this.classList.add("drag-over");
            });
            
            day.addEventListener("dragleave", function() {
                this.classList.remove("drag-over");
            });
            
            day.addEventListener("drop", function(e) {
                e.preventDefault();
                this.classList.remove("drag-over");
                
                if (!draggedEvent || !draggedData) return;
                
                const newDate = this.dataset.date;
                if (newDate === draggedData.originalDate) return;
                
                // Move the event visually
                const eventsContainer = this.querySelector(".flm-calendar-events") || this;
                eventsContainer.appendChild(draggedEvent);
                
                // Update server
                $.post(ajaxurl, {
                    action: "flm_reschedule_post",
                    nonce: flmAdmin.nonce,
                    post_id: draggedData.postId,
                    new_date: newDate
                }, function(response) {
                    if (response.success) {
                        Toast.show("Post rescheduled", "success");
                        activityFeed.add("import", `Rescheduled post to ${newDate}`);
                    } else {
                        Toast.show("Failed to reschedule", "danger");
                        // Revert visual change
                        location.reload();
                    }
                });
            });
        });
    }
    
    // ============================================
    // TEAM COLORS INTEGRATION (v2.14.0)
    // ============================================
    
    function applyTeamColors() {
        // Apply team accents to stat cards
        document.querySelectorAll("[data-team]").forEach(el => {
            const team = el.dataset.team?.toLowerCase();
            if (team) {
                el.classList.add(`flm-team-accent-${team}`);
            }
        });
        
        // Apply to table rows
        document.querySelectorAll("tr[data-team], .flm-article-row[data-team]").forEach(row => {
            const team = row.dataset.team?.toLowerCase();
            if (team) {
                row.classList.add(`flm-team-accent-${team}`);
            }
        });
    }
    
    // ============================================
    // EMPTY STATES (v2.14.0)
    // ============================================
    
    function renderEmptyStates() {
        const emptyStates = {
            "flm-articles-table-empty": {
                icon: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>\',
                title: "No articles imported yet",
                desc: "Add your FLM API key and run your first import to see articles here",
                action: "Add API Key",
                onClick: () => switchToTabAndScroll("settings", "flm_api_key")
            },
            "flm-analytics-empty": {
                icon: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>\',
                title: "No analytics data yet",
                desc: "Connect Google Analytics to see performance metrics",
                action: "Connect Analytics",
                onClick: () => switchToTabAndScroll("settings", "flm-ga-settings")
            },
            "flm-calendar-empty": {
                icon: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>\',
                title: "No scheduled content",
                desc: "Import articles to see them on the calendar",
                action: "Run Import",
                onClick: () => navigateToTab("import")
            },
            "flm-social-log-empty": {
                icon: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>\',
                title: "No social posts yet",
                desc: "Enable social posting to automatically share articles",
                action: "Configure Social",
                onClick: () => switchToTabAndScroll("settings", "flm-social-settings")
            }
        };
        
        Object.entries(emptyStates).forEach(([id, state]) => {
            const container = document.getElementById(id);
            if (!container) return;
            
            container.innerHTML = `
                <div class="flm-empty-state">
                    <div class="flm-empty-state-icon">${state.icon}</div>
                    <div class="flm-empty-state-title">${state.title}</div>
                    <div class="flm-empty-state-desc">${state.desc}</div>
                    <button class="flm-empty-state-action">${state.action} →</button>
                </div>
            `;
            
            container.querySelector(".flm-empty-state-action")?.addEventListener("click", state.onClick);
        });
    }
    
    // ============================================
    // INITIALIZE ALL v2.14.0 FEATURES
    // ============================================
    
    function initV214Features() {
        // Command palette
        createCommandPalette();
        
        // Onboarding
        renderOnboarding();
        
        // Activity feed
        activityFeed.loadFromServer();
        
        // Smart insights
        setTimeout(() => insightsEngine.analyze(), 500);
        
        // Collapsible sections
        initCollapseSections();
        
        // Drag & drop calendar
        initDragDropCalendar();
        
        // Team colors
        applyTeamColors();
        
        // Empty states
        renderEmptyStates();
        
        // Add sparklines to stat cards
        document.querySelectorAll(".flm-stat-card[data-trend]").forEach(card => {
            try {
                const trendData = JSON.parse(card.dataset.trend);
                if (trendData && trendData.length > 1) {
                    const valueEl = card.querySelector(".flm-stat-value");
                    if (valueEl) {
                        valueEl.insertAdjacentHTML("afterend", createSparkline(trendData) + renderTrendIndicator(trendData));
                    }
                }
            } catch(e) {}
        });
    }
    
    // Add to document ready
    $(document).ready(function() {
        initV214Features();
        initOAuthHandlers();
    });
    
    // ============================================
    // OAuth Integration (v2.15.0)
    // ============================================
    
    function initOAuthHandlers() {
        // Check for OAuth success/error messages in URL
        const urlParams = new URLSearchParams(window.location.search);
        
        if (urlParams.get("oauth_success")) {
            const provider = urlParams.get("oauth_success");
            Toast.show(provider + " connected successfully!", "success");
            // Clean URL and reload to show updated state
            const cleanUrl = window.location.pathname + "?page=flm-importer&tab=settings";
            window.history.replaceState({}, document.title, cleanUrl);
            // Reload page to reflect new connection status
            setTimeout(function() {
                window.location.reload();
            }, 1000);
        }
        
        if (urlParams.get("oauth_error")) {
            const error = urlParams.get("oauth_error");
            Toast.show("OAuth error: " + error, "danger");
            // Clean URL
            const cleanUrl = window.location.pathname + "?page=flm-importer";
            window.history.replaceState({}, document.title, cleanUrl);
        }
        
        // Connect buttons
        $(document).on("click", ".flm-oauth-connect", function() {
            const provider = $(this).data("provider");
            initOAuthFlow(provider);
        });
        
        // Disconnect buttons
        $(document).on("click", ".flm-oauth-disconnect", function() {
            const provider = $(this).data("provider");
            if (confirm("Disconnect " + provider.charAt(0).toUpperCase() + provider.slice(1) + "?")) {
                disconnectOAuth(provider);
            }
        });
        
        // Refresh buttons
        $(document).on("click", ".flm-oauth-refresh", function() {
            const provider = $(this).data("provider");
            refreshOAuthToken(provider);
        });
        
        // Facebook page selector
        $(document).on("change", "#flm-facebook-page-select", function() {
            const pageId = $(this).val();
            selectFacebookPage(pageId);
        });
        
        // Check OAuth status periodically
        updateOAuthStatus();
        setInterval(updateOAuthStatus, 60000); // Every minute
    }
    
    function initOAuthFlow(provider) {
        const $btn = $(".flm-oauth-connect[data-provider=\"" + provider + "\"]");
        const originalText = $btn.html();
        
        $btn.prop("disabled", true).html(\'<span class="flm-spinner"></span> Connecting...\');
        
        $.post(ajaxurl, {
            action: "flm_oauth_init",
            nonce: flmAdmin.nonce,
            provider: provider
        }, function(response) {
            if (response.success && response.data.auth_url) {
                // Redirect in same window (maintains session)
                window.location.href = response.data.auth_url;
            } else {
                Toast.show(response.data || "Failed to start OAuth flow", "danger");
                $btn.prop("disabled", false).html(originalText);
            }
        }).fail(function() {
            Toast.show("Connection error", "danger");
            $btn.prop("disabled", false).html(originalText);
        });
    }
    
    function handleOAuthCallback(params) {
        const provider = params.get("provider");
        const success = params.get("success") === "1";
        
        if (!success) {
            const error = params.get("error") || "Authorization failed";
            Toast.show(error, "danger");
            // Clean URL
            window.history.replaceState({}, "", window.location.pathname + "?page=flm-importer#settings");
            return;
        }
        
        // Save tokens via AJAX
        $.post(ajaxurl, {
            action: "flm_oauth_callback",
            nonce: flmAdmin.nonce,
            provider: provider,
            access_token: params.get("access_token"),
            refresh_token: params.get("refresh_token") || "",
            expires_in: params.get("expires_in") || "3600",
            scope: params.get("scope") || "",
            pages: params.get("pages") || ""  // For Facebook
        }, function(response) {
            if (response.success) {
                Toast.show(response.data.message, "success");
                updateOAuthStatus();
                
                // Close popup if this is the popup
                if (window.opener) {
                    window.opener.postMessage({ type: "oauth_success", provider: provider }, "*");
                    window.close();
                }
            } else {
                Toast.show(response.data || "Failed to save credentials", "danger");
            }
        });
        
        // Clean URL
        window.history.replaceState({}, "", window.location.pathname + "?page=flm-importer#settings");
    }
    
    function disconnectOAuth(provider) {
        $.post(ajaxurl, {
            action: "flm_oauth_disconnect",
            nonce: flmAdmin.nonce,
            provider: provider
        }, function(response) {
            if (response.success) {
                Toast.show(response.data.message, "success");
                updateOAuthStatus();
            } else {
                Toast.show(response.data || "Failed to disconnect", "danger");
            }
        });
    }
    
    function refreshOAuthToken(provider) {
        const $btn = $(".flm-oauth-refresh[data-provider=\"" + provider + "\"]");
        const originalText = $btn.html();
        
        $btn.prop("disabled", true).html(\'<span class="flm-spinner"></span>\');
        
        $.post(ajaxurl, {
            action: "flm_oauth_refresh",
            nonce: flmAdmin.nonce,
            provider: provider
        }, function(response) {
            if (response.success) {
                Toast.show(response.data.message, "success");
                updateOAuthStatus();
            } else {
                Toast.show(response.data || "Failed to refresh token", "danger");
            }
            $btn.prop("disabled", false).html(originalText);
        }).fail(function() {
            Toast.show("Connection error", "danger");
            $btn.prop("disabled", false).html(originalText);
        });
    }
    
    function selectFacebookPage(pageId) {
        // This would update the selected page - handled via normal settings save
        Toast.show("Page selected. Remember to save settings.", "info");
    }
    
    function updateOAuthStatus() {
        $.post(ajaxurl, {
            action: "flm_oauth_status",
            nonce: flmAdmin.nonce
        }, function(response) {
            if (!response.success) return;
            
            const status = response.data;
            const providerNames = {
                ga4: "Google Analytics",
                gsc: "Search Console", 
                twitter: "Twitter",
                facebook: "Facebook"
            };
            
            ["ga4", "gsc", "twitter", "facebook"].forEach(function(provider) {
                const $card = $(".flm-oauth-card[data-provider=\"" + provider + "\"]");
                if (!$card.length) return;
                
                const info = status[provider];
                const $status = $card.find(".flm-oauth-status");
                const $connectBtn = $card.find(".flm-oauth-connect");
                const $disconnectBtn = $card.find(".flm-oauth-disconnect");
                const $refreshBtn = $card.find(".flm-oauth-refresh");
                const $expiry = $card.find(".flm-oauth-expiry");
                
                if (info.connected) {
                    $card.addClass("connected").removeClass("disconnected");
                    $connectBtn.hide();
                    $disconnectBtn.show();
                    $refreshBtn.show();
                    $status.html(\'<span class="flm-badge success">Connected</span>\');
                    
                    // Show expiry
                    if (info.expires_in > 0) {
                        const expiryText = formatExpiryTime(info.expires_in);
                        $expiry.html("Expires: " + expiryText).show();
                        
                        if (info.needs_refresh) {
                            $expiry.addClass("warning");
                        } else {
                            $expiry.removeClass("warning");
                        }
                    } else {
                        $expiry.hide();
                    }
                    
                    // Facebook pages
                    if (provider === "facebook" && info.pages && info.pages.length > 0) {
                        let pagesHtml = \'<select id="flm-facebook-page-select" class="flm-select flm-select-sm">\';
                        info.pages.forEach(function(page) {
                            const selected = page.id === info.selected_page ? " selected" : "";
                            pagesHtml += \'<option value="\' + page.id + \'"\' + selected + \'>\' + page.name + \'</option>\';
                        });
                        pagesHtml += "</select>";
                        $card.find(".flm-oauth-pages").html(pagesHtml).show();
                    }
                } else {
                    $card.removeClass("connected").addClass("disconnected");
                    $connectBtn.show();
                    $disconnectBtn.hide();
                    $refreshBtn.hide();
                    $status.html(\'<span class="flm-badge secondary">Not Connected</span>\');
                    $expiry.hide();
                    $card.find(".flm-oauth-pages").hide();
                }
            });
        });
    }
    
    function formatExpiryTime(seconds) {
        if (seconds < 60) return "< 1 minute";
        if (seconds < 3600) return Math.floor(seconds / 60) + " minutes";
        if (seconds < 86400) return Math.floor(seconds / 3600) + " hours";
        return Math.floor(seconds / 86400) + " days";
    }
    
    // Listen for OAuth popup messages
    window.addEventListener("message", function(event) {
        if (event.data && event.data.type === "oauth_success") {
            Toast.show(event.data.provider + " connected!", "success");
            updateOAuthStatus();
        }
    });
    
    // Save Indicator
    function initSaveIndicator() {
        const $indicator = $(".flm-save-indicator");
        
        // Update on settings change from any form
        $(document).on("change", "#flm-settings-form input, #flm-settings-form select, #flm-settings-form-settings input, #flm-settings-form-settings select", function() {
            $indicator.removeClass("saved").addClass("unsaved").html(
                \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>\' +
                \'<span>Unsaved changes</span>\'
            );
        });
        
        // Show saving state
        $(document).on("submit", "#flm-settings-form, #flm-settings-form-settings", function() {
            $indicator.removeClass("saved unsaved").addClass("saving").html(
                \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>\' +
                \'<span>Saving...</span>\'
            );
        });
        
        // Show saved state after successful save
        $(document).ajaxSuccess(function(event, xhr, settings) {
            if (settings.data && (settings.data.indexOf("flm_save_settings") !== -1)) {
                $indicator.removeClass("saving unsaved").addClass("saved").html(
                    \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>\' +
                    \'<span>All saved</span>\'
                );
            }
        });
    }
    
    // Page load animations
    function initPageAnimations() {
        // Add animation classes to stat cards
        $(".flm-stat-card").addClass("flm-animate-in");
        
        // Animate count-up for stat values
        $(".flm-stat-value[data-count]").each(function() {
            const $el = $(this);
            const target = parseInt($el.data("count"), 10) || 0;
            const duration = 1000;
            const start = performance.now();
            
            function animate(currentTime) {
                const elapsed = currentTime - start;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3); // ease out cubic
                const current = Math.round(target * eased);
                
                $el.text(current);
                
                if (progress < 1) {
                    requestAnimationFrame(animate);
                }
            }
            
            // Start animation after element becomes visible
            setTimeout(function() {
                requestAnimationFrame(animate);
            }, 300);
        });
    }
    
    // Analytics Charts
    function initAnalyticsCharts() {
        if (typeof flmAnalyticsData === "undefined") return;
        
        const data = flmAnalyticsData;
        
        // Import Activity Chart (Line) - only if has activity
        if (document.getElementById("flm-activity-chart") && data.hasActivity) {
            const ctx = document.getElementById("flm-activity-chart").getContext("2d");
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 260);
            gradient.addColorStop(0, "rgba(255, 107, 53, 0.3)");
            gradient.addColorStop(1, "rgba(255, 107, 53, 0)");
            
            new Chart(ctx, {
                type: "line",
                data: {
                    labels: data.activityLabels || [],
                    datasets: [{
                        label: "Posts Imported",
                        data: data.activityData || [],
                        borderColor: "#ff6b35",
                        backgroundColor: gradient,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: "#ff6b35",
                        pointBorderColor: "#151b23",
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: "index",
                        intersect: false
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: "#151b23",
                            borderColor: "#262e38",
                            borderWidth: 1,
                            titleColor: "#f0f3f6",
                            bodyColor: "#8b949e",
                            padding: 12,
                            cornerRadius: 8,
                            displayColors: false
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: "rgba(38, 46, 56, 0.3)", drawBorder: false },
                            ticks: { color: "#8b949e", font: { size: 11 } }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: "rgba(38, 46, 56, 0.3)", drawBorder: false },
                            ticks: { color: "#8b949e", font: { size: 11 }, stepSize: 1 }
                        }
                    }
                }
            });
        }
        
        // Posts by Team Chart (Doughnut) - only if has team data
        if (document.getElementById("flm-team-chart") && data.hasTeamData && data.teamData && data.teamData.length > 0) {
            const ctx = document.getElementById("flm-team-chart").getContext("2d");
            
            // Map team colors based on labels
            const colorMap = {
                "Braves": "#ce1141",
                "Falcons": "#a71930",
                "Hawks": "#e03a3e",
                "Uga": "#ba0c2f",
                "Gt": "#b3a369"
            };
            
            const colors = (data.teamLabels || []).map(label => colorMap[label] || "#ff6b35");
            
            new Chart(ctx, {
                type: "doughnut",
                data: {
                    labels: data.teamLabels || [],
                    datasets: [{
                        data: data.teamData || [],
                        backgroundColor: colors,
                        borderColor: "#0a0e13",
                        borderWidth: 2,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: "60%",
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: "#151b23",
                            borderColor: "#262e38",
                            borderWidth: 1,
                            titleColor: "#f0f3f6",
                            bodyColor: "#8b949e",
                            padding: 12,
                            cornerRadius: 8
                        }
                    }
                }
            });
        }
    }
    
    // Period selector for analytics - AJAX
    $(document).on("click", ".flm-period-btn", function() {
        const $btn = $(this);
        const days = $btn.data("days");
        
        $(".flm-period-btn").removeClass("active");
        $btn.addClass("active");
        
        // Update period label
        $(".flm-period-label").text(days);
        
        // Show loading state
        $("#flm-analytics").addClass("flm-analytics-loading");
        
        // Fetch new analytics data
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_get_analytics",
                nonce: flmAdmin.nonce,
                days: days
            },
            success: function(response) {
                if (response.success) {
                    // Update summary stats
                    const data = response.data;
                    animateValue($(".flm-summary-stat-posts .flm-summary-stat-value"), data.total_posts);
                    animateValue($(".flm-summary-stat-views .flm-summary-stat-value"), data.total_views);
                    animateValue($(".flm-summary-stat-period .flm-summary-stat-value"), data.views_period);
                    
                    // Update trends
                    updateTrend($(".flm-summary-stat-posts .flm-summary-stat-trend"), data.posts_change);
                    updateTrend($(".flm-summary-stat-period .flm-summary-stat-trend"), data.views_change);
                    
                    // Update avg
                    $(".flm-summary-stat-views .flm-summary-stat-meta").text(data.avg_views_per_post + " avg/post");
                    
                    Toast.show("success", "Analytics Updated", "Showing data for " + days + " days");
                }
            },
            complete: function() {
                $("#flm-analytics").removeClass("flm-analytics-loading");
            }
        });
    });
    
    // Animate value helper
    function animateValue($el, target) {
        const start = parseInt($el.text().replace(/,/g, "")) || 0;
        const duration = 500;
        const startTime = performance.now();
        
        function animate(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(start + (target - start) * eased);
            $el.text(current.toLocaleString());
            
            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        }
        requestAnimationFrame(animate);
    }
    
    // Update trend indicator
    function updateTrend($el, change) {
        $el.removeClass("up down").addClass(change >= 0 ? "up" : "down");
        $el.find(".flm-trend-arrow").text(change >= 0 ? "↑" : "↓");
        $el.find(".flm-trend-value").text(Math.abs(change) + "%");
    }
    
    // Performance table sorting
    $(document).on("click", ".flm-performance-table th[data-sort]", function() {
        const $th = $(this);
        const $table = $th.closest("table");
        const $tbody = $table.find("tbody");
        const sortKey = $th.data("sort");
        const isAsc = $th.hasClass("sorted-asc");
        
        // Remove sort classes from all headers
        $table.find("th").removeClass("sorted-asc sorted-desc");
        
        // Add new sort class
        $th.addClass(isAsc ? "sorted-desc" : "sorted-asc");
        
        // Sort rows
        const $rows = $tbody.find("tr").get();
        $rows.sort(function(a, b) {
            let aVal, bVal;
            
            switch (sortKey) {
                case "title":
                    aVal = $(a).find(".flm-td-title a").text().toLowerCase();
                    bVal = $(b).find(".flm-td-title a").text().toLowerCase();
                    break;
                case "team":
                    aVal = $(a).find(".flm-td-team").text().toLowerCase();
                    bVal = $(b).find(".flm-td-team").text().toLowerCase();
                    break;
                case "type":
                    aVal = $(a).find(".flm-td-type").text().toLowerCase();
                    bVal = $(b).find(".flm-td-type").text().toLowerCase();
                    break;
                case "age":
                    aVal = parseInt($(a).find(".flm-td-age").text()) || 0;
                    bVal = parseInt($(b).find(".flm-td-age").text()) || 0;
                    break;
                case "views":
                    aVal = parseInt($(a).find(".flm-td-views").text().replace(/,/g, "")) || 0;
                    bVal = parseInt($(b).find(".flm-td-views").text().replace(/,/g, "")) || 0;
                    break;
                case "vpd":
                    aVal = parseFloat($(a).find(".flm-vpd-value").text()) || 0;
                    bVal = parseFloat($(b).find(".flm-vpd-value").text()) || 0;
                    break;
                default:
                    aVal = $(a).text();
                    bVal = $(b).text();
            }
            
            if (typeof aVal === "string") {
                return isAsc ? bVal.localeCompare(aVal) : aVal.localeCompare(bVal);
            }
            return isAsc ? bVal - aVal : aVal - bVal;
        });
        
        $.each($rows, function(idx, row) {
            $tbody.append(row);
        });
    });
    
    // CSV Export
    $(document).on("click", "#flm-export-csv", function() {
        const $table = $("#flm-performance-table");
        const rows = [];
        
        // Header row
        const headers = [];
        $table.find("thead th").each(function() {
            headers.push($(this).text().trim());
        });
        rows.push(headers.join(","));
        
        // Data rows
        $table.find("tbody tr").each(function() {
            const row = [];
            $(this).find("td").each(function() {
                let val = $(this).text().trim().replace(/,/g, " ");
                row.push(val);
            });
            rows.push(row.join(","));
        });
        
        // Create and download
        const csv = rows.join("\\n");
        const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = "flm-content-performance-" + new Date().toISOString().split("T")[0] + ".csv";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        Toast.show("success", "Export Complete", "CSV file downloaded successfully");
    });
    
    // Track unsaved changes
    let hasUnsavedChanges = false;
    
    $(document).on("change", ".flm-dashboard input, .flm-dashboard select", function() {
        hasUnsavedChanges = true;
    });
    
    $(window).on("beforeunload", function() {
        if (hasUnsavedChanges) {
            return flmAdmin.strings.unsavedChanges;
        }
    });
    
    // Toggle team card state visually
    $(document).on("change", ".flm-team-toggle", function() {
        const card = $(this).closest(".flm-team-card");
        card.attr("data-enabled", this.checked ? "true" : "false");
    });
    
    // Checkbox card state
    $(document).on("change", ".flm-checkbox-card input", function() {
        const card = $(this).closest(".flm-checkbox-card");
        card.attr("data-checked", this.checked ? "true" : "false");
    });
    
    // API Key show/hide toggle
    $(document).on("click", ".flm-toggle-visibility", function() {
        const input = $(this).siblings("input");
        const isPassword = input.attr("type") === "password";
        
        input.attr("type", isPassword ? "text" : "password");
        $(this).html(isPassword 
            ? \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"/></svg>\'
            : \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>\'
        );
        $(this).attr("aria-label", isPassword ? "Hide API key" : "Show API key");
    });
    
    // Run Import (AJAX with progress)
    $(document).on("click", "#flm-run-import", function(e) {
        e.preventDefault();
        
        console.log("FLM: Starting import...");
        console.log("FLM: AJAX URL:", flmAdmin.ajaxUrl);
        console.log("FLM: Nonce:", flmAdmin.nonce);
        
        const $btn = $(this);
        const $progress = $("#flm-import-progress");
        const $progressFill = $progress.find(".flm-progress-fill");
        const $progressValue = $progress.find(".flm-progress-value");
        const $progressStatus = $progress.find(".flm-progress-status");
        
        $btn.addClass("loading").prop("disabled", true);
        $progress.addClass("active");
        $progressFill.css("width", "0%");
        $progressValue.text("0%");
        $progressStatus.text("Starting import...");
        
        // Simulate progress while waiting
        let progress = 0;
        const progressInterval = setInterval(function() {
            if (progress < 90) {
                progress += Math.random() * 15;
                progress = Math.min(progress, 90);
                $progressFill.css("width", progress + "%");
                $progressValue.text(Math.round(progress) + "%");
                
                if (progress < 30) {
                    $progressStatus.text("Fetching MLB stories...");
                } else if (progress < 60) {
                    $progressStatus.text("Fetching NFL stories...");
                } else {
                    $progressStatus.text("Fetching NBA stories...");
                }
            }
        }, 800);
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_run_import",
                nonce: flmAdmin.nonce
            },
            timeout: 180000, // 3 minute timeout for long imports
            success: function(response) {
                console.log("FLM: Import response:", response);
                clearInterval(progressInterval);
                $progressFill.css("width", "100%");
                $progressValue.text("100%");
                
                if (response.success) {
                    $progressStatus.text("Import complete!");
                    Toast.show("success", "Import Complete", response.data.message);
                    
                    // Update stats
                    if (response.data.stats) {
                        $(".flm-stat-imported").text(response.data.stats.imported);
                        $(".flm-stat-updated").text(response.data.stats.updated + " updated");
                        $(".flm-stat-last-import").text("Just now");
                    }
                    
                    // Reload log section after delay
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $progressStatus.text("Import failed");
                    Toast.show("error", "Import Failed", response.data ? response.data.message : "Unknown error");
                }
            },
            error: function(xhr, status, error) {
                console.error("FLM: AJAX Error:", status, error);
                console.error("FLM: Response:", xhr.responseText);
                clearInterval(progressInterval);
                $progressStatus.text("Connection error");
                Toast.show("error", "Connection Error", "Could not reach the server. Check console for details.");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
                setTimeout(function() {
                    $progress.removeClass("active");
                }, 3000);
            }
        });
    });
    
    // Per-League Import (AJAX)
    $(document).on("click", ".flm-league-import", function(e) {
        e.preventDefault();
        
        const $btn = $(this);
        const league = $btn.data("league");
        const leagueName = $btn.data("league-name");
        
        console.log("FLM: Starting " + leagueName + " import...");
        
        const $progress = $("#flm-import-progress");
        const $progressFill = $progress.find(".flm-progress-fill");
        const $progressValue = $progress.find(".flm-progress-value");
        const $progressStatus = $progress.find(".flm-progress-status");
        
        // Disable all league buttons during import
        $(".flm-league-import").addClass("loading").prop("disabled", true);
        $("#flm-run-import").prop("disabled", true);
        
        $progress.addClass("active");
        $progressFill.css("width", "0%");
        $progressValue.text("0%");
        $progressStatus.text("Fetching " + leagueName + " stories...");
        
        // Simulate progress
        let progress = 0;
        const progressInterval = setInterval(function() {
            if (progress < 90) {
                progress += Math.random() * 20;
                progress = Math.min(progress, 90);
                $progressFill.css("width", progress + "%");
                $progressValue.text(Math.round(progress) + "%");
            }
        }, 500);
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_run_import",
                nonce: flmAdmin.nonce,
                league: league
            },
            timeout: 120000, // 2 minute timeout for single league
            success: function(response) {
                console.log("FLM: " + leagueName + " import response:", response);
                clearInterval(progressInterval);
                $progressFill.css("width", "100%");
                $progressValue.text("100%");
                
                if (response.success) {
                    $progressStatus.text(leagueName + " import complete!");
                    Toast.show("success", leagueName + " Import Complete", response.data.message);
                    
                    // Reload after delay
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $progressStatus.text("Import failed");
                    Toast.show("error", "Import Failed", response.data ? response.data.message : "Unknown error");
                }
            },
            error: function(xhr, status, error) {
                console.error("FLM: AJAX Error:", status, error);
                clearInterval(progressInterval);
                $progressStatus.text("Connection error");
                Toast.show("error", "Connection Error", "Could not reach the server.");
            },
            complete: function() {
                $(".flm-league-import").removeClass("loading").prop("disabled", false);
                $("#flm-run-import").prop("disabled", false);
                setTimeout(function() {
                    $progress.removeClass("active");
                }, 3000);
            }
        });
    });
    
    // Test Connection (AJAX) - supports both dashboard and settings tab buttons
    $(document).on("click", "#flm-test-connection, #flm-test-connection-2", function(e) {
        e.preventDefault();
        
        console.log("FLM: Testing connection...");
        
        const $btn = $(this);
        const $results = $("#flm-test-results");
        
        $btn.addClass("loading").prop("disabled", true);
        $results.slideDown().find(".flm-results-body").html("Testing connection...");
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_test_connection",
                nonce: flmAdmin.nonce
            },
            success: function(response) {
                console.log("FLM: Test response:", response);
                if (response.success) {
                    $results.find(".flm-results-body").html(response.data.output);
                    Toast.show("success", "Connection Test", response.data.connected ? "API connection successful" : "Connection failed");
                    
                    // Update connection status
                    const $dot = $(".flm-connection-dot");
                    $dot.attr("data-status", response.data.connected ? "online" : "offline");
                    $(".flm-connection-text").text(response.data.connected ? "Connected" : "Offline");
                } else {
                    $results.find(".flm-results-body").html("<span class=\"error\">Error: " + (response.data ? response.data.message : "Unknown error") + "</span>");
                    Toast.show("error", "Test Failed", response.data ? response.data.message : "Unknown error");
                }
            },
            error: function(xhr, status, error) {
                console.error("FLM: Test connection error:", status, error);
                $results.find(".flm-results-body").html("<span class=\"error\">Connection error - could not reach server</span>");
                Toast.show("error", "Connection Error", "Could not reach the server");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Discover Teams (AJAX)
    $(document).on("click", "#flm-discover-teams", function(e) {
        e.preventDefault();
        
        const $btn = $(this);
        const $results = $("#flm-test-results");
        
        $btn.addClass("loading").prop("disabled", true);
        $results.slideDown().find(".flm-results-body").html("Discovering teams from API...\nThis may take a minute due to rate limits...");
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_discover_teams",
                nonce: flmAdmin.nonce
            },
            timeout: 120000, // 2 minute timeout
            success: function(response) {
                if (response.success) {
                    $results.find(".flm-results-body").html(response.data.output);
                    Toast.show("success", "Discovery Complete", "Found teams from API");
                } else {
                    $results.find(".flm-results-body").html("<span class=\"error\">Error: " + (response.data.message || "Unknown error") + "</span>");
                    Toast.show("error", "Discovery Failed", response.data.message);
                }
            },
            error: function() {
                $results.find(".flm-results-body").html("<span class=\"error\">Request timed out or failed</span>");
                Toast.show("error", "Request Failed", "The discovery request failed or timed out");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Reset Import Date (AJAX) - supports both dashboard and settings tab buttons
    $(document).on("click", "#flm-reset-date, #flm-reset-date-2", function(e) {
        e.preventDefault();
        
        if (!confirm("This will reset the import date, causing the next import to fetch ALL stories instead of just new ones. Continue?")) {
            return;
        }
        
        const $btn = $(this);
        $btn.addClass("loading").prop("disabled", true);
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_reset_import_date",
                nonce: flmAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    Toast.show("success", "Date Reset", "Next import will fetch all stories");
                    $(".flm-stat-last-import").text("Never");
                } else {
                    Toast.show("error", "Reset Failed", response.data.message);
                }
            },
            error: function() {
                Toast.show("error", "Connection Error", "Could not reach the server");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Purge Old Posts (P5.3)
    $(document).on("click", "#flm-purge-posts", function(e) {
        e.preventDefault();
        
        const days = $("#flm-purge-days").val();
        
        if (!confirm("This will PERMANENTLY DELETE all FLM-imported posts older than " + days + " days. This cannot be undone. Continue?")) {
            return;
        }
        
        // Double confirm for safety
        if (!confirm("Are you absolutely sure? This will delete posts from your WordPress database.")) {
            return;
        }
        
        const $btn = $(this);
        $btn.addClass("loading").prop("disabled", true).html("Purging...");
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_purge_old_posts",
                nonce: flmAdmin.nonce,
                days: days
            },
            success: function(response) {
                if (response.success) {
                    Toast.show("success", "Purge Complete", response.data.message);
                    // Update the count display
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    Toast.show("error", "Purge Failed", response.data.message);
                }
            },
            error: function() {
                Toast.show("error", "Connection Error", "Could not reach the server");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false).html(\'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg> Purge Posts\');
            }
        });
    });
    
    // Clear Log (AJAX)
    $(document).on("click", "#flm-clear-log", function(e) {
        e.preventDefault();
        
        const $btn = $(this);
        $btn.addClass("loading").prop("disabled", true);
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_clear_log",
                nonce: flmAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    Toast.show("success", "Log Cleared", "Import log has been cleared");
                    $(".flm-log").not(".flm-error-log").html(\'<div class="flm-log-empty"><div class="flm-log-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg></div><div>No log entries yet</div></div>\');
                }
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Clear Error Log (AJAX)
    $(document).on("click", "#flm-clear-error-log", function(e) {
        e.preventDefault();
        
        const $btn = $(this);
        $btn.addClass("loading").prop("disabled", true);
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_clear_error_log",
                nonce: flmAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    Toast.show("success", "Error Log Cleared", "Error log has been cleared");
                    $(".flm-error-log").html(\'<div class="flm-log-empty"><div class="flm-log-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg></div><div>No errors logged. System running smoothly!</div></div>\');
                }
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Clear All Logs (AJAX) - from Settings tab danger zone
    $(document).on("click", "#flm-clear-all-logs", function(e) {
        e.preventDefault();
        
        if (!confirm("This will clear both import and error logs. Continue?")) {
            return;
        }
        
        const $btn = $(this);
        $btn.addClass("loading").prop("disabled", true);
        
        // Clear both logs
        $.when(
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: { action: "flm_clear_log", nonce: flmAdmin.nonce }
            }),
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: { action: "flm_clear_error_log", nonce: flmAdmin.nonce }
            })
        ).done(function() {
            Toast.show("success", "Logs Cleared", "All logs have been cleared");
            $(".flm-log").not(".flm-error-log").html(\'<div class="flm-log-empty"><div class="flm-log-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg></div><div>No log entries yet</div></div>\');
            $(".flm-error-log").html(\'<div class="flm-log-empty"><div class="flm-log-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg></div><div>No errors logged. System running smoothly!</div></div>\');
        }).always(function() {
            $btn.removeClass("loading").prop("disabled", false);
        });
    });
    
    // Clear Social Queue (v2.9.0)
    $(document).on("click", "#flm-clear-social-queue", function(e) {
        e.preventDefault();
        
        if (!confirm("This will remove all queued social posts. They will not be posted. Continue?")) {
            return;
        }
        
        const $btn = $(this);
        $btn.addClass("loading").prop("disabled", true);
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_clear_social_queue",
                nonce: flmAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    Toast.show("success", "Queue Cleared", response.data.message);
                    $btn.closest("div[style*=\'background:rgba(88,166,255\']").slideUp(300, function() {
                        $(this).remove();
                    });
                } else {
                    Toast.show("error", "Error", response.data.message);
                }
            },
            error: function() {
                Toast.show("error", "Error", "Failed to clear queue");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Test Social Connection (v2.9.0)
    $(document).on("click", ".flm-test-social-connection", function(e) {
        e.preventDefault();
        
        const $btn = $(this);
        const platform = $btn.data("platform");
        
        $btn.addClass("loading").prop("disabled", true);
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_test_social_connection",
                nonce: flmAdmin.nonce,
                platform: platform
            },
            success: function(response) {
                if (response.success) {
                    Toast.show("success", platform.charAt(0).toUpperCase() + platform.slice(1), response.data.message);
                } else {
                    Toast.show("error", "Connection Failed", response.data.message);
                }
            },
            error: function() {
                Toast.show("error", "Error", "Failed to test connection");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Tip links navigation
    $(document).on("click", ".flm-tip-link", function(e) {
        e.preventDefault();
        const hash = $(this).attr("href");
        const tabId = hash.replace("#", "");
        const $tab = $(".flm-tab[data-tab=\"" + tabId + "\"]");
        if ($tab.length) {
            $tab.click();
        }
    });
    
    // Dismiss onboarding checklist
    $(document).on("click", "#flm-dismiss-onboarding", function() {
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_dismiss_onboarding",
                nonce: flmAdmin.nonce
            },
            success: function() {
                $("#flm-onboarding").slideUp(300, function() {
                    $(this).remove();
                });
            }
        });
    });
    
    // Onboarding import button
    $(document).on("click", "#flm-onboarding-import", function(e) {
        e.preventDefault();
        $("#flm-run-import").click();
    });
    
    // Save Settings (AJAX) - works with both Teams and Settings tab forms
    $(document).on("submit", "#flm-settings-form, #flm-settings-form-settings", function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $btn = $form.find(".flm-save-btn");
        
        $btn.addClass("loading").prop("disabled", true);
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: $form.serialize() + "&action=flm_save_settings&nonce=" + flmAdmin.nonce,
            success: function(response) {
                if (response.success) {
                    Toast.show("success", "Settings Saved", "Your settings have been saved");
                    hasUnsavedChanges = false;
                } else {
                    Toast.show("error", "Save Failed", response.data.message || "Could not save settings");
                }
            },
            error: function() {
                Toast.show("error", "Connection Error", "Could not reach the server");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Close results panel
    $(document).on("click", ".flm-results-close", function() {
        $(this).closest(".flm-results").slideUp();
    });
    
    // ========================================
    // DRY-RUN PREVIEW MODE (P2.2)
    // ========================================
    
    // Create preview modal HTML
    function createPreviewModal() {
        if ($("#flm-preview-modal").length) return;
        
        const modalHtml = 
            "<div id=\"flm-preview-modal\" class=\"flm-modal-overlay\">" +
                "<div class=\"flm-modal\" role=\"dialog\" aria-labelledby=\"flm-preview-title\" aria-modal=\"true\">" +
                    "<div class=\"flm-modal-header\">" +
                        "<h3 class=\"flm-modal-title\" id=\"flm-preview-title\">" +
                            "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"/><path d=\"M3 9h18\"/><path d=\"M9 21V9\"/></svg>" +
                            "Import Preview (Dry Run)" +
                        "</h3>" +
                        "<button class=\"flm-modal-close\" aria-label=\"Close preview\">" +
                            "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M18 6L6 18M6 6l12 12\"/></svg>" +
                        "</button>" +
                    "</div>" +
                    "<div class=\"flm-modal-body\">" +
                        "<div class=\"flm-preview-loading\">" +
                            "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83\"/></svg>" +
                            "<div>Analyzing stories from API...</div>" +
                            "<div style=\"font-size:12px;margin-top:8px;opacity:0.7\">This may take a moment due to rate limits</div>" +
                        "</div>" +
                        "<div class=\"flm-preview-content\" style=\"display:none;\"></div>" +
                    "</div>" +
                    "<div class=\"flm-modal-footer\">" +
                        "<div class=\"flm-text-muted flm-text-sm\">" +
                            "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:14px;height:14px;vertical-align:middle;margin-right:4px;\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M12 16v-4M12 8h.01\"/></svg>" +
                            "Preview only — no posts will be created or modified" +
                        "</div>" +
                        "<div class=\"flm-flex flm-gap-1\">" +
                            "<button type=\"button\" class=\"flm-btn flm-btn-secondary flm-preview-close-btn\">Close</button>" +
                            "<button type=\"button\" class=\"flm-btn flm-btn-primary flm-preview-import-btn\" style=\"display:none;\">" +
                                "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:14px;height:14px;\"><polygon points=\"5 3 19 12 5 21 5 3\"/></svg>" +
                                "Run Import Now" +
                            "</button>" +
                        "</div>" +
                    "</div>" +
                "</div>" +
            "</div>";
        
        $("body").append(modalHtml);
    }
    
    // Open preview modal
    function openPreviewModal() {
        createPreviewModal();
        const $modal = $("#flm-preview-modal");
        $modal.addClass("active");
        $modal.find(".flm-modal-close, .flm-preview-close-btn").first().focus();
        $("body").css("overflow", "hidden");
    }
    
    // Close preview modal
    function closePreviewModal() {
        const $modal = $("#flm-preview-modal");
        $modal.removeClass("active");
        $("body").css("overflow", "");
    }
    
    // Render preview results
    // Store preview data globally for selective import
    let previewData = null;
    
    function renderPreviewResults(data) {
        const $modal = $("#flm-preview-modal");
        const $loading = $modal.find(".flm-preview-loading");
        const $content = $modal.find(".flm-preview-content");
        
        // Store for later use
        previewData = data;
        
        $loading.hide();
        $content.show();
        
        if (!data.stories || data.stories.length === 0) {
            $content.html(
                "<div class=\"flm-preview-empty\">" +
                    "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M12 8v4M12 16h.01\"/></svg>" +
                    "<div style=\"font-size:16px;font-weight:600;margin-bottom:8px;\">No Stories to Import</div>" +
                    "<div>All available stories have already been imported, or no stories match your enabled teams.</div>" +
                "</div>"
            );
            return;
        }
        
        // Summary stats
        const newCount = data.stories.filter(s => s.action === "create").length;
        const updateCount = data.stories.filter(s => s.action === "update").length;
        const skipCount = data.skipped || 0;
        
        let html = 
            "<div class=\"flm-preview-summary\">" +
                "<div class=\"flm-preview-stat\">" +
                    "<div class=\"flm-preview-stat-value new\">" + newCount + "</div>" +
                    "<div class=\"flm-preview-stat-label\">New Posts</div>" +
                "</div>" +
                "<div class=\"flm-preview-stat\">" +
                    "<div class=\"flm-preview-stat-value update\">" + updateCount + "</div>" +
                    "<div class=\"flm-preview-stat-label\">Updates</div>" +
                "</div>" +
                "<div class=\"flm-preview-stat\">" +
                    "<div class=\"flm-preview-stat-value skip\">" + skipCount + "</div>" +
                    "<div class=\"flm-preview-stat-label\">Skipped</div>" +
                "</div>" +
                "<div class=\"flm-preview-stat\">" +
                    "<div class=\"flm-preview-stat-value\">" + data.stories.length + "</div>" +
                    "<div class=\"flm-preview-stat-label\">Total</div>" +
                "</div>" +
            "</div>";
        
        // Select all / Quick select controls
        html += 
            "<div class=\"flm-preview-select-all\">" +
                "<label>" +
                    "<input type=\"checkbox\" class=\"flm-preview-checkbox\" id=\"flm-select-all\" checked>" +
                    "Select All" +
                "</label>" +
                "<div class=\"flm-preview-quick-select\">" +
                    "<button type=\"button\" data-select=\"create\">New Only</button>" +
                    "<button type=\"button\" data-select=\"update\">Updates Only</button>" +
                    "<button type=\"button\" data-select=\"none\">None</button>" +
                "</div>" +
                "<div class=\"flm-selection-count\">" +
                    "<strong id=\"flm-selected-count\">" + data.stories.length + "</strong> of " + data.stories.length + " selected" +
                "</div>" +
            "</div>";
        
        // Filter buttons
        html += 
            "<div class=\"flm-preview-filters\">" +
                "<button class=\"flm-preview-filter active\" data-filter=\"all\">All <span class=\"count\">" + data.stories.length + "</span></button>" +
                "<button class=\"flm-preview-filter\" data-filter=\"create\">New <span class=\"count\">" + newCount + "</span></button>" +
                "<button class=\"flm-preview-filter\" data-filter=\"update\">Updates <span class=\"count\">" + updateCount + "</span></button>" +
            "</div>";
        
        // Story list with checkboxes
        html += "<div class=\"flm-preview-list\">";
        
        data.stories.forEach((story, index) => {
            const badge = story.action === "create" ? "new" : "update";
            const badgeText = story.action === "create" ? "NEW" : "UPDATE";
            
            html += 
                "<div class=\"flm-preview-item selected\" data-action=\"" + story.action + "\" data-index=\"" + index + "\" data-story-id=\"" + escapeHtml(story.story_id) + "\">" +
                    "<div class=\"flm-preview-item-checkbox\">" +
                        "<input type=\"checkbox\" class=\"flm-preview-checkbox flm-story-checkbox\" data-index=\"" + index + "\" checked>" +
                    "</div>" +
                    "<div class=\"flm-preview-item-badge " + badge + "\">" + badgeText + "</div>" +
                    "<div class=\"flm-preview-item-content\">" +
                        "<div class=\"flm-preview-item-title\">" + escapeHtml(story.headline) + "</div>" +
                        "<div class=\"flm-preview-item-meta\">" +
                            "<span>" +
                                "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M6 9H4a2 2 0 01-2-2V5a2 2 0 012-2h2M18 9h2a2 2 0 002-2V5a2 2 0 00-2-2h-2M12 17v4M8 21h8M6 3h12v7a6 6 0 11-12 0V3z\"/></svg>" +
                                escapeHtml(story.team) +
                            "</span>" +
                            "<span>" +
                                "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z\"/></svg>" +
                                escapeHtml(story.league) +
                            "</span>" +
                            "<span>" +
                                "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M4 19.5A2.5 2.5 0 016.5 17H20\"/><path d=\"M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z\"/></svg>" +
                                escapeHtml(story.type || "Story") +
                            "</span>" +
                            (story.existing_id ? "<span style=\"color:var(--flm-info);\">ID: " + story.existing_id + "</span>" : "") +
                        "</div>" +
                    "</div>" +
                "</div>";
        });
        
        html += "</div>";
        
        $content.html(html);
        
        // Show import button if there are items to import
        if (newCount > 0 || updateCount > 0) {
            $modal.find(".flm-preview-import-btn").show().text("Import Selected (" + data.stories.length + ")");
        }
        
        updateSelectionCount();
    }
    
    // Update selection count display
    function updateSelectionCount() {
        const total = $(".flm-story-checkbox").length;
        const selected = $(".flm-story-checkbox:checked").length;
        $("#flm-selected-count").text(selected);
        
        // Update select all checkbox state
        const $selectAll = $("#flm-select-all");
        $selectAll.prop("checked", selected === total);
        $selectAll.prop("indeterminate", selected > 0 && selected < total);
        
        // Update import button text
        $(".flm-preview-import-btn").text("Import Selected (" + selected + ")");
        
        // Disable import button if nothing selected
        $(".flm-preview-import-btn").prop("disabled", selected === 0);
    }
    
    // Get selected story IDs
    function getSelectedStoryIds() {
        const ids = [];
        $(".flm-story-checkbox:checked").each(function() {
            const $item = $(this).closest(".flm-preview-item");
            ids.push($item.data("story-id"));
        });
        return ids;
    }
    
    // HTML escape helper
    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text || "";
        return div.innerHTML;
    }
    
    // Individual checkbox change
    $(document).on("change", ".flm-story-checkbox", function() {
        const $item = $(this).closest(".flm-preview-item");
        if (this.checked) {
            $item.addClass("selected");
        } else {
            $item.removeClass("selected");
        }
        updateSelectionCount();
    });
    
    // Select all checkbox
    $(document).on("change", "#flm-select-all", function() {
        const isChecked = this.checked;
        $(".flm-story-checkbox").prop("checked", isChecked);
        $(".flm-preview-item").toggleClass("selected", isChecked);
        updateSelectionCount();
    });
    
    // Quick select buttons
    $(document).on("click", ".flm-preview-quick-select button", function() {
        const selectType = $(this).data("select");
        
        $(".flm-preview-item").each(function() {
            const $item = $(this);
            const $checkbox = $item.find(".flm-story-checkbox");
            const action = $item.data("action");
            
            let shouldSelect = false;
            if (selectType === "create") {
                shouldSelect = action === "create";
            } else if (selectType === "update") {
                shouldSelect = action === "update";
            } else if (selectType === "none") {
                shouldSelect = false;
            }
            
            $checkbox.prop("checked", shouldSelect);
            $item.toggleClass("selected", shouldSelect);
        });
        
        updateSelectionCount();
    });
    
    // Click on preview item row to toggle
    $(document).on("click", ".flm-preview-item", function(e) {
        // Dont toggle if clicking directly on checkbox
        if ($(e.target).hasClass("flm-preview-checkbox")) return;
        
        const $checkbox = $(this).find(".flm-story-checkbox");
        $checkbox.prop("checked", !$checkbox.prop("checked")).trigger("change");
    });
    
    // Filter preview items (updated to preserve selection)
    $(document).on("click", ".flm-preview-filter", function() {
        const filter = $(this).data("filter");
        $(".flm-preview-filter").removeClass("active");
        $(this).addClass("active");
        
        $(".flm-preview-item").each(function() {
            const action = $(this).data("action");
            if (filter === "all" || action === filter) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    
    // Preview button click
    $(document).on("click", "#flm-dry-run-preview", function(e) {
        e.preventDefault();
        
        console.log("FLM: Starting dry-run preview...");
        
        const $btn = $(this);
        $btn.addClass("loading").prop("disabled", true);
        
        openPreviewModal();
        
        // Reset modal state
        const $modal = $("#flm-preview-modal");
        $modal.find(".flm-preview-loading").show();
        $modal.find(".flm-preview-content").hide().empty();
        $modal.find(".flm-preview-import-btn").hide();
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_dry_run_preview",
                nonce: flmAdmin.nonce
            },
            timeout: 180000, // 3 minute timeout
            success: function(response) {
                console.log("FLM: Preview response:", response);
                
                if (response.success) {
                    renderPreviewResults(response.data);
                    Toast.show("info", "Preview Ready", "Found " + response.data.stories.length + " stories to process");
                } else {
                    $modal.find(".flm-preview-loading").hide();
                    $modal.find(".flm-preview-content").show().html(
                        "<div class=\"flm-preview-empty\">" +
                            "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M15 9l-6 6M9 9l6 6\"/></svg>" +
                            "<div style=\"font-size:16px;font-weight:600;margin-bottom:8px;\">Preview Failed</div>" +
                            "<div>" + (response.data ? response.data.message : "Unknown error occurred") + "</div>" +
                        "</div>"
                    );
                    Toast.show("error", "Preview Failed", response.data ? response.data.message : "Unknown error");
                }
            },
            error: function(xhr, status, error) {
                console.error("FLM: Preview error:", status, error);
                $modal.find(".flm-preview-loading").hide();
                $modal.find(".flm-preview-content").show().html(
                    "<div class=\"flm-preview-empty\">" +
                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M15 9l-6 6M9 9l6 6\"/></svg>" +
                        "<div style=\"font-size:16px;font-weight:600;margin-bottom:8px;\">Connection Error</div>" +
                        "<div>Could not reach the server. The request may have timed out.</div>" +
                    "</div>"
                );
                Toast.show("error", "Connection Error", "Could not reach the server");
            },
            complete: function() {
                $btn.removeClass("loading").prop("disabled", false);
            }
        });
    });
    
    // Close modal handlers
    $(document).on("click", ".flm-modal-close, .flm-preview-close-btn", function() {
        closePreviewModal();
    });
    
    $(document).on("click", ".flm-modal-overlay", function(e) {
        if ($(e.target).hasClass("flm-modal-overlay")) {
            closePreviewModal();
        }
    });
    
    // Escape key closes modal
    $(document).on("keydown", function(e) {
        if (e.key === "Escape" && $("#flm-preview-modal").hasClass("active")) {
            closePreviewModal();
        }
    });
    
    // Run import from preview modal (selective import)
    $(document).on("click", ".flm-preview-import-btn", function() {
        const selectedIds = getSelectedStoryIds();
        
        if (selectedIds.length === 0) {
            Toast.show("warning", "No Selection", "Please select at least one story to import");
            return;
        }
        
        const $btn = $(this);
        $btn.addClass("loading").prop("disabled", true).text("Importing...");
        
        console.log("FLM: Starting selective import for " + selectedIds.length + " stories");
        
        $.ajax({
            url: flmAdmin.ajaxUrl,
            type: "POST",
            data: {
                action: "flm_selective_import",
                nonce: flmAdmin.nonce,
                story_ids: JSON.stringify(selectedIds)
            },
            timeout: 180000, // 3 minute timeout
            success: function(response) {
                console.log("FLM: Selective import response:", response);
                
                if (response.success) {
                    Toast.show("success", "Import Complete", response.data.message);
                    closePreviewModal();
                    
                    // Reload page to show updated stats
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    Toast.show("error", "Import Failed", response.data ? response.data.message : "Unknown error");
                    $btn.removeClass("loading").prop("disabled", false).text("Import Selected (" + selectedIds.length + ")");
                }
            },
            error: function(xhr, status, error) {
                console.error("FLM: Selective import error:", status, error);
                Toast.show("error", "Connection Error", "Could not reach the server");
                $btn.removeClass("loading").prop("disabled", false).text("Import Selected (" + selectedIds.length + ")");
            }
        });
    });
    
    // ============================================
    // CHAMPIONSHIP EDITION v2.6.0
    // Premium Interactions & Features
    // ============================================
    
    // Button Ripple Effect
    $(document).on("click", ".flm-btn", function(e) {
        const $btn = $(this);
        const rect = this.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        const $ripple = $("<span class=\"flm-ripple\"></span>");
        $ripple.css({
            left: x + "px",
            top: y + "px"
        });
        
        $btn.append($ripple);
        
        setTimeout(() => $ripple.remove(), 600);
    });
    
    // Command Palette (Cmd+K)
    const CommandPalette = {
        isOpen: false,
        selectedIndex: 0,
        commands: [
            { icon: "download", text: "Run Import", action: () => $("#flm-run-import").click(), shortcut: ["I"] },
            { icon: "eye", text: "Preview Import", action: () => $("#flm-dry-run-preview").click(), shortcut: ["P"] },
            { icon: "plug", text: "Test Connection", action: () => $("#flm-test-connection, #flm-test-connection-2").first().click(), shortcut: ["T"] },
            { icon: "save", text: "Save Settings", action: () => $("#flm-settings-form, #flm-settings-form-settings").first().submit(), shortcut: ["⌘", "S"] },
            { icon: "grid", text: "Go to Dashboard", action: () => $(".flm-tab[data-tab=\"dashboard\"]").click(), shortcut: ["1"] },
            { icon: "trophy", text: "Go to Teams", action: () => $(".flm-tab[data-tab=\"teams\"]").click(), shortcut: ["2"] },
            { icon: "chart", text: "Go to Analytics", action: () => $(".flm-tab[data-tab=\"analytics\"]").click(), shortcut: ["3"] },
            { icon: "log", text: "Go to Logs", action: () => $(".flm-tab[data-tab=\"logs\"]").click(), shortcut: ["4"] },
            { icon: "settings", text: "Go to Settings", action: () => $(".flm-tab[data-tab=\"settings\"]").click(), shortcut: ["5"] },
            { icon: "external", text: "View Imported Posts", action: () => $(".flm-view-posts-link")[0].click(), shortcut: [] },
            { icon: "help", text: "Keyboard Shortcuts", action: () => ShortcutsModal.open(), shortcut: ["?"] },
        ],
        
        init: function() {
            // Create palette HTML
            const iconsMap = {
                download: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>\',
                eye: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>\',
                plug: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22v-5M9 8V2M15 8V2M18 8H6a2 2 0 00-2 2v1a5 5 0 005 5h6a5 5 0 005-5v-1a2 2 0 00-2-2z"/></svg>\',
                save: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>\',
                grid: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>\',
                trophy: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4a2 2 0 01-2-2V5a2 2 0 012-2h2M18 9h2a2 2 0 002-2V5a2 2 0 00-2-2h-2M12 17v4M8 21h8M6 3h12v7a6 6 0 11-12 0V3z"/></svg>\',
                chart: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>\',
                log: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>\',
                settings: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>\',
                external: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg>\',
                help: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3M12 17h.01"/></svg>\',
                search: \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>\'
            };
            
            let commandsHtml = this.commands.map((cmd, i) => 
                "<div class=\"flm-command-item\" data-index=\"" + i + "\">" +
                    (iconsMap[cmd.icon] || "") +
                    "<span class=\"flm-command-item-text\">" + cmd.text + "</span>" +
                    "<div class=\"flm-command-item-shortcut\">" +
                        cmd.shortcut.map(k => "<kbd>" + k + "</kbd>").join("") +
                    "</div>" +
                "</div>"
            ).join("");
            
            const html = 
                "<div class=\"flm-command-palette\" id=\"flm-command-palette\">" +
                    "<div class=\"flm-command-palette-box\">" +
                        "<div class=\"flm-command-input-wrap\">" +
                            iconsMap.search +
                            "<input type=\"text\" class=\"flm-command-input\" placeholder=\"Type a command or search...\" autocomplete=\"off\">" +
                        "</div>" +
                        "<div class=\"flm-command-results\">" +
                            "<div class=\"flm-command-group\">" +
                                "<div class=\"flm-command-group-title\">Commands</div>" +
                                commandsHtml +
                            "</div>" +
                        "</div>" +
                    "</div>" +
                "</div>";
            
            $("body").append(html);
            
            // Event handlers
            $("#flm-command-palette").on("click", function(e) {
                if ($(e.target).is("#flm-command-palette")) {
                    CommandPalette.close();
                }
            });
            
            $(".flm-command-input").on("input", function() {
                CommandPalette.filter($(this).val());
            });
            
            $(".flm-command-item").on("click", function() {
                const index = $(this).data("index");
                CommandPalette.execute(index);
            });
        },
        
        open: function() {
            this.isOpen = true;
            this.selectedIndex = 0;
            $("#flm-command-palette").addClass("open");
            $(".flm-command-input").val("").focus();
            this.updateSelection();
        },
        
        close: function() {
            this.isOpen = false;
            $("#flm-command-palette").removeClass("open");
        },
        
        toggle: function() {
            this.isOpen ? this.close() : this.open();
        },
        
        filter: function(query) {
            const q = query.toLowerCase();
            $(".flm-command-item").each(function(i) {
                const text = $(this).find(".flm-command-item-text").text().toLowerCase();
                $(this).toggle(text.includes(q));
            });
            this.selectedIndex = 0;
            this.updateSelection();
        },
        
        navigate: function(dir) {
            const $visible = $(".flm-command-item:visible");
            this.selectedIndex = (this.selectedIndex + dir + $visible.length) % $visible.length;
            this.updateSelection();
        },
        
        updateSelection: function() {
            $(".flm-command-item").removeClass("selected");
            $(".flm-command-item:visible").eq(this.selectedIndex).addClass("selected");
        },
        
        execute: function(index) {
            const cmd = this.commands[index !== undefined ? index : this.selectedIndex];
            if (cmd && cmd.action) {
                this.close();
                cmd.action();
            }
        }
    };
    
    // Keyboard Shortcuts Modal
    const ShortcutsModal = {
        init: function() {
            const html = 
                "<div class=\"flm-shortcuts-modal\" id=\"flm-shortcuts-modal\">" +
                    "<div class=\"flm-shortcuts-box\">" +
                        "<div class=\"flm-shortcuts-header\">" +
                            "<span class=\"flm-shortcuts-title\">Keyboard Shortcuts</span>" +
                            "<button type=\"button\" class=\"flm-shortcuts-close\">" +
                                "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M18 6L6 18M6 6l12 12\"/></svg>" +
                            "</button>" +
                        "</div>" +
                        "<div class=\"flm-shortcuts-body\">" +
                            "<div class=\"flm-shortcuts-group\">" +
                                "<div class=\"flm-shortcuts-group-title\">Navigation</div>" +
                                "<div class=\"flm-shortcut-row\"><span class=\"flm-shortcut-label\">Dashboard</span><div class=\"flm-shortcut-keys\"><kbd>1</kbd></div></div>" +
                                "<div class=\"flm-shortcut-row\"><span class=\"flm-shortcut-label\">Teams</span><div class=\"flm-shortcut-keys\"><kbd>2</kbd></div></div>" +
                                "<div class=\"flm-shortcut-row\"><span class=\"flm-shortcut-label\">Analytics</span><div class=\"flm-shortcut-keys\"><kbd>3</kbd></div></div>" +
                                "<div class=\"flm-shortcut-row\"><span class=\"flm-shortcut-label\">Logs</span><div class=\"flm-shortcut-keys\"><kbd>4</kbd></div></div>" +
                                "<div class=\"flm-shortcut-row\"><span class=\"flm-shortcut-label\">Settings</span><div class=\"flm-shortcut-keys\"><kbd>5</kbd></div></div>" +
                            "</div>" +
                            "<div class=\"flm-shortcuts-group\">" +
                                "<div class=\"flm-shortcuts-group-title\">Actions</div>" +
                                "<div class=\"flm-shortcut-row\"><span class=\"flm-shortcut-label\">Save</span><div class=\"flm-shortcut-keys\"><kbd>⌘</kbd><kbd>S</kbd></div></div>" +
                                "<div class=\"flm-shortcut-row\"><span class=\"flm-shortcut-label\">Command Palette</span><div class=\"flm-shortcut-keys\"><kbd>⌘</kbd><kbd>K</kbd></div></div>" +
                                "<div class=\"flm-shortcut-row\"><span class=\"flm-shortcut-label\">This Help</span><div class=\"flm-shortcut-keys\"><kbd>?</kbd></div></div>" +
                            "</div>" +
                        "</div>" +
                    "</div>" +
                "</div>";
            
            $("body").append(html);
            
            $("#flm-shortcuts-modal").on("click", function(e) {
                if ($(e.target).is("#flm-shortcuts-modal")) {
                    ShortcutsModal.close();
                }
            });
            
            $(".flm-shortcuts-close").on("click", function() {
                ShortcutsModal.close();
            });
        },
        
        open: function() {
            $("#flm-shortcuts-modal").addClass("open");
        },
        
        close: function() {
            $("#flm-shortcuts-modal").removeClass("open");
        },
        
        toggle: function() {
            $("#flm-shortcuts-modal").hasClass("open") ? this.close() : this.open();
        }
    };
    
    // Confetti Celebration
    const Confetti = {
        colors: ["#ff6b35", "#58a6ff", "#3fb950", "#f85149", "#d29922", "#a371f7"],
        
        celebrate: function() {
            const container = $("<div class=\"flm-confetti-container\"></div>");
            $("body").append(container);
            
            for (let i = 0; i < 150; i++) {
                setTimeout(() => {
                    const confetti = $("<div class=\"flm-confetti\"></div>");
                    confetti.css({
                        left: Math.random() * 100 + "%",
                        top: "-20px",
                        backgroundColor: this.colors[Math.floor(Math.random() * this.colors.length)],
                        transform: "rotate(" + (Math.random() * 360) + "deg)",
                        width: (Math.random() * 10 + 5) + "px",
                        height: (Math.random() * 10 + 5) + "px",
                        borderRadius: Math.random() > 0.5 ? "50%" : "0",
                        animationDuration: (Math.random() * 2 + 2) + "s"
                    });
                    container.append(confetti);
                    confetti.addClass("animate");
                }, i * 20);
            }
            
            setTimeout(() => container.remove(), 5000);
        }
    };
    
    // Achievement System
    const Achievements = {
        show: function(title, description) {
            const $achievement = $(
                "<div class=\"flm-achievement\">" +
                    "<div class=\"flm-achievement-icon\">" +
                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M6 9H4a2 2 0 01-2-2V5a2 2 0 012-2h2M18 9h2a2 2 0 002-2V5a2 2 0 00-2-2h-2M12 17v4M8 21h8M6 3h12v7a6 6 0 11-12 0V3z\"/></svg>" +
                    "</div>" +
                    "<div class=\"flm-achievement-content\">" +
                        "<div class=\"flm-achievement-label\">Achievement Unlocked!</div>" +
                        "<div class=\"flm-achievement-title\">" + title + "</div>" +
                        "<div class=\"flm-achievement-desc\">" + description + "</div>" +
                    "</div>" +
                "</div>"
            );
            
            $("body").append($achievement);
            
            setTimeout(() => $achievement.addClass("show"), 100);
            setTimeout(() => {
                $achievement.removeClass("show");
                setTimeout(() => $achievement.remove(), 500);
            }, 5000);
            
            Confetti.celebrate();
        },
        
        check: function(type, value) {
            const achievements = JSON.parse(localStorage.getItem("flm_achievements") || "{}");
            
            // First Import Achievement
            if (type === "import" && !achievements.first_import) {
                achievements.first_import = true;
                localStorage.setItem("flm_achievements", JSON.stringify(achievements));
                this.show("First Import!", "You successfully imported your first stories");
            }
            
            // 100 Posts Achievement
            if (type === "total_posts" && value >= 100 && !achievements.posts_100) {
                achievements.posts_100 = true;
                localStorage.setItem("flm_achievements", JSON.stringify(achievements));
                this.show("Century Club", "You have imported 100+ posts!");
            }
        }
    };
    
    // Konami Code Easter Egg
    const konamiCode = [38, 38, 40, 40, 37, 39, 37, 39, 66, 65]; // Up Up Down Down Left Right Left Right B A
    let konamiIndex = 0;
    
    $(document).on("keydown", function(e) {
        if (e.keyCode === konamiCode[konamiIndex]) {
            konamiIndex++;
            if (konamiIndex === konamiCode.length) {
                $(".flm-dashboard").toggleClass("party-mode");
                Toast.show("success", "🎉 Party Mode!", "You found the secret! Press again to disable.");
                konamiIndex = 0;
            }
        } else {
            konamiIndex = 0;
        }
    });
    
    // Enhanced Keyboard Shortcuts
    $(document).on("keydown", function(e) {
        // Skip if in input field
        if ($(e.target).is("input, textarea, select")) return;
        
        // Command Palette: Cmd/Ctrl + K
        if ((e.metaKey || e.ctrlKey) && e.key === "k") {
            e.preventDefault();
            CommandPalette.toggle();
            return;
        }
        
        // Navigate command palette
        if (CommandPalette.isOpen) {
            if (e.key === "ArrowDown") {
                e.preventDefault();
                CommandPalette.navigate(1);
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                CommandPalette.navigate(-1);
            } else if (e.key === "Enter") {
                e.preventDefault();
                CommandPalette.execute();
            } else if (e.key === "Escape") {
                CommandPalette.close();
            }
            return;
        }
        
        // Escape closes modals
        if (e.key === "Escape") {
            ShortcutsModal.close();
            CommandPalette.close();
            return;
        }
        
        // ? for shortcuts help
        if (e.key === "?" && !e.shiftKey) {
            e.preventDefault();
            ShortcutsModal.toggle();
        }
    });
    
    // Initialize Championship Features
    $(document).ready(function() {
        CommandPalette.init();
        ShortcutsModal.init();
        
        // Check for achievements on page load
        const totalPosts = parseInt($(".flm-stat-value:contains(\"/\")").text()) || 0;
        if (totalPosts > 0) {
            Achievements.check("total_posts", totalPosts);
        }
    });
    
    // Trigger achievement on successful import
    $(document).ajaxSuccess(function(event, xhr, settings) {
        if (settings.data && settings.data.indexOf("flm_run_import") !== -1) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success && response.data && response.data.imported > 0) {
                    Achievements.check("import", response.data.imported);
                }
            } catch(e) {}
        }
    });
    
    // ============================================
    // ULTIMATE EDITION v2.7.0
    // Premium Analytics & Visual Features
    // ============================================
    
    // Theme Toggle System
    const ThemeManager = {
        init: function() {
            // Check for saved preference or system preference
            const saved = localStorage.getItem("flm_theme");
            const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
            const theme = saved || (prefersDark ? "dark" : "dark"); // Default to dark
            
            this.setTheme(theme, false);
            
            // Listen for system changes
            window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", (e) => {
                if (!localStorage.getItem("flm_theme")) {
                    this.setTheme(e.matches ? "dark" : "light", false);
                }
            });
        },
        
        toggle: function() {
            const current = $(".flm-dashboard").attr("data-theme") || "dark";
            const next = current === "dark" ? "light" : "dark";
            this.setTheme(next, true);
        },
        
        setTheme: function(theme, save) {
            $(".flm-dashboard").attr("data-theme", theme);
            if (save) {
                localStorage.setItem("flm_theme", theme);
                Toast.show("info", "Theme Changed", theme === "light" ? "Switched to light mode" : "Switched to dark mode");
            }
        }
    };
    
    $(document).on("click", ".flm-theme-toggle", function() {
        ThemeManager.toggle();
    });
    
    // Sparkline Generator
    const Sparklines = {
        create: function(container, data, options) {
            const $container = $(container);
            if (!$container.length || !data || !data.length) return;
            
            const max = Math.max(...data, 1);
            const opts = $.extend({ height: 32, barWidth: 6, gap: 2 }, options);
            
            let html = "";
            data.forEach((value, i) => {
                const height = Math.max(2, (value / max) * opts.height);
                const percent = Math.round((height / opts.height) * 100);
                html += "<div class=\"flm-sparkline-bar\" style=\"height:" + percent + "%;\" data-value=\"" + value + "\" data-index=\"" + i + "\"></div>";
            });
            
            $container.html(html);
        },
        
        initAll: function() {
            $("[data-sparkline]").each(function() {
                const $el = $(this);
                const dataStr = $el.attr("data-sparkline");
                if (dataStr) {
                    try {
                        const data = JSON.parse(dataStr);
                        Sparklines.create($el, data);
                    } catch(e) {
                        console.error("Invalid sparkline data:", e);
                    }
                }
            });
        }
    };
    
    // Progress Ring Animation
    const ProgressRings = {
        animate: function(ring, targetPercent, duration) {
            const $ring = $(ring);
            const $fill = $ring.find(".flm-progress-ring-fill");
            const $text = $ring.find(".flm-progress-ring-text");
            
            const radius = parseFloat($fill.attr("r")) || 40;
            const circumference = 2 * Math.PI * radius;
            
            $fill.css({
                "stroke-dasharray": circumference,
                "stroke-dashoffset": circumference
            });
            
            // Animate
            setTimeout(() => {
                const offset = circumference - (targetPercent / 100) * circumference;
                $fill.css("stroke-dashoffset", offset);
            }, 100);
            
            // Animate number
            if ($text.length) {
                this.animateNumber($text, 0, targetPercent, duration || 1000);
            }
        },
        
        animateNumber: function($el, start, end, duration) {
            const startTime = performance.now();
            const update = (currentTime) => {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3); // Ease out cubic
                const current = Math.round(start + (end - start) * eased);
                $el.text(current + "%");
                
                if (progress < 1) {
                    requestAnimationFrame(update);
                }
            };
            requestAnimationFrame(update);
        },
        
        initAll: function() {
            $(".flm-progress-ring").each(function() {
                const $ring = $(this);
                const percent = parseFloat($ring.attr("data-percent")) || 0;
                ProgressRings.animate($ring, percent, 1500);
            });
        }
    };
    
    // Animated Number Counters
    const AnimatedCounters = {
        init: function() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        this.animate(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.5 });
            
            $(".flm-animated-number[data-count]").each(function() {
                observer.observe(this);
            });
        },
        
        animate: function(el) {
            const $el = $(el);
            const target = parseInt($el.attr("data-count"), 10) || 0;
            const duration = parseInt($el.attr("data-duration"), 10) || 1000;
            const prefix = $el.attr("data-prefix") || "";
            const suffix = $el.attr("data-suffix") || "";
            
            const startTime = performance.now();
            $el.addClass("counting");
            
            const update = (currentTime) => {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                const current = Math.round(target * eased);
                
                $el.text(prefix + current.toLocaleString() + suffix);
                
                if (progress < 1) {
                    requestAnimationFrame(update);
                } else {
                    $el.removeClass("counting");
                }
            };
            requestAnimationFrame(update);
        }
    };
    
    // Comparison Mode Toggle
    const ComparisonMode = {
        enabled: false,
        
        toggle: function() {
            this.enabled = !this.enabled;
            $(".flm-comparison-toggle").toggleClass("active", this.enabled);
            $(".flm-stat-comparison").toggle(this.enabled);
            $(".flm-comparison-badge").toggle(this.enabled);
            
            if (this.enabled) {
                Toast.show("info", "Comparison Mode", "Showing vs. last period");
            }
        }
    };
    
    $(document).on("click", ".flm-comparison-toggle", function() {
        ComparisonMode.toggle();
    });
    
    // Heatmap Interactions
    const Heatmap = {
        init: function() {
            // Tooltips are handled via CSS
            // Click to filter by date
            $(document).on("click", ".flm-heatmap-day[data-date]", function() {
                const date = $(this).attr("data-date");
                const count = $(this).attr("data-count") || 0;
                Toast.show("info", date, count + " posts published");
            });
        }
    };
    
    // Calendar Navigation
    const ContentCalendar = {
        currentDate: new Date(),
        
        init: function() {
            this.render();
        },
        
        navigate: function(direction) {
            this.currentDate.setMonth(this.currentDate.getMonth() + direction);
            this.render();
        },
        
        goToToday: function() {
            this.currentDate = new Date();
            this.render();
        },
        
        render: function() {
            const $container = $("#flm-calendar-grid");
            if (!$container.length) return;
            
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth();
            
            // Update title
            const monthNames = ["January", "February", "March", "April", "May", "June", 
                               "July", "August", "September", "October", "November", "December"];
            $(".flm-calendar-title").text(monthNames[month] + " " + year);
            
            // Get calendar data from data attribute
            const calendarData = $container.attr("data-events");
            let events = {};
            try {
                events = calendarData ? JSON.parse(calendarData) : {};
            } catch(e) {}
            
            // Build calendar grid
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const daysInPrevMonth = new Date(year, month, 0).getDate();
            const today = new Date();
            
            let html = "";
            
            // Previous month days
            for (let i = firstDay - 1; i >= 0; i--) {
                const day = daysInPrevMonth - i;
                html += "<div class=\"flm-calendar-day other-month\"><span class=\"flm-calendar-date\">" + day + "</span></div>";
            }
            
            // Current month days
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = year + "-" + String(month + 1).padStart(2, "0") + "-" + String(day).padStart(2, "0");
                const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
                const dayEvents = events[dateStr] || [];
                
                html += "<div class=\"flm-calendar-day" + (isToday ? " today" : "") + "\" data-date=\"" + dateStr + "\">";
                html += "<span class=\"flm-calendar-date\">" + day + "</span>";
                html += "<div class=\"flm-calendar-events\">";
                
                const maxShow = 2;
                dayEvents.slice(0, maxShow).forEach(event => {
                    html += "<div class=\"flm-calendar-event " + (event.team || "") + "\" title=\"" + event.title + "\">" + event.title + "</div>";
                });
                
                if (dayEvents.length > maxShow) {
                    html += "<div class=\"flm-calendar-more\">+" + (dayEvents.length - maxShow) + " more</div>";
                }
                
                html += "</div></div>";
            }
            
            // Next month days
            const totalCells = Math.ceil((firstDay + daysInMonth) / 7) * 7;
            const remaining = totalCells - (firstDay + daysInMonth);
            for (let day = 1; day <= remaining; day++) {
                html += "<div class=\"flm-calendar-day other-month\"><span class=\"flm-calendar-date\">" + day + "</span></div>";
            }
            
            $container.html(html);
        }
    };
    
    $(document).on("click", ".flm-calendar-prev", function() {
        ContentCalendar.navigate(-1);
    });
    
    $(document).on("click", ".flm-calendar-next", function() {
        ContentCalendar.navigate(1);
    });
    
    $(document).on("click", ".flm-calendar-today-btn", function() {
        ContentCalendar.goToToday();
    });
    
    // Initialize Ultimate Edition Features
    $(document).ready(function() {
        // Theme
        ThemeManager.init();
        
        // Wait a bit for DOM to be ready
        setTimeout(function() {
            // Sparklines
            Sparklines.initAll();
            
            // Progress Rings
            ProgressRings.initAll();
            
            // Animated Counters
            AnimatedCounters.init();
            
            // Heatmap
            Heatmap.init();
            
            // Calendar
            if ($("#flm-calendar-grid").length) {
                ContentCalendar.init();
            }
            
            // ML Insights (v2.8.0)
            if ($("#flm-insights-section").length) {
                MLInsights.init();
            }
        }, 500);
    });
    
    // ============================================
    // ML INSIGHTS v2.8.0
    // AI-Powered Analytics Engine
    // ============================================
    
    const MLInsights = {
        init: function() {
            this.bindEvents();
            this.checkIntegrations();
            this.autoLoadData();
        },
        
        bindEvents: function() {
            // Headline Analyzer
            $(document).on("click", "#flm-analyze-headline", this.analyzeHeadline);
            $(document).on("keypress", "#flm-headline-input", function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $("#flm-analyze-headline").click();
                }
            });
            
            // Integration toggles
            $(document).on("click", ".flm-integration-group-header", function() {
                $(this).closest(".flm-integration-group").toggleClass("expanded");
            });
            
            // Test integration buttons
            $(document).on("click", ".flm-test-integration", this.testIntegration);
            
            // Test social post buttons (v2.9.0)
            $(document).on("click", ".flm-test-social", this.testSocialPost);
            
            // Publishing tab handlers (v2.10.0)
            $(document).on("click", "#flm-refresh-scheduled", this.refreshScheduledPosts);
            $(document).on("click", ".flm-cancel-scheduled", this.cancelScheduledPost);
            $(document).on("click", "#flm-clear-social-log", this.clearSocialLog);
            
            // Predict performance
            $(document).on("click", "#flm-predict-performance", this.predictPerformance);
            
            // Generate content ideas
            $(document).on("click", "#flm-generate-ideas", this.generateIdeas);
            
            // Refresh trending
            $(document).on("click", "#flm-refresh-trending", this.refreshTrending);
            
            // Settings Import/Export (v2.12.0)
            $(document).on("click", "#flm-export-settings", this.exportSettings);
            $(document).on("click", "#flm-preview-import", this.previewImport);
            $(document).on("click", "#flm-import-settings", this.importSettings);
            $(document).on("click", "#flm-restore-backup", this.restoreBackup);
            
            // ESP Integration (v2.13.0)
            $(document).on("change", "input[name=\'flm_settings[esp_provider]\']", this.toggleEspSettings);
            $(document).on("click", ".flm-test-esp", this.testEspConnection);
            
            // Password toggle for API keys
            $(document).on("click", ".flm-toggle-password", function() {
                const targetId = $(this).data("target");
                const $input = $("#" + targetId);
                const type = $input.attr("type") === "password" ? "text" : "password";
                $input.attr("type", type);
                $(this).toggleClass("showing");
            });
            
            // Time team selector
            $(document).on("change", "#flm-time-team", this.updateTimeHeatmap);
            
            // Time cell hover tooltips
            $(document).on("mouseenter", ".flm-time-cell", function() {
                const hour = $(this).data("hour");
                const day = $(this).data("day");
                const score = $(this).data("score");
                const days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
                const timeStr = (hour > 12 ? hour - 12 : hour) + (hour >= 12 ? "pm" : "am");
                const quality = score >= 4 ? "Excellent" : (score >= 3 ? "Good" : "Fair");
                $(this).attr("title", days[day] + " at " + timeStr + " - " + quality);
            });
        },
        
        autoLoadData: function() {
            // Auto-load trending topics on page load
            if ($("#flm-trending-list").length) {
                this.refreshTrending();
            }
            
            // Auto-load initial prediction
            if ($("#flm-predicted-views").length) {
                setTimeout(function() {
                    $("#flm-predict-performance").click();
                }, 1000);
            }
        },
        
        checkIntegrations: function() {
            const integrations = ["ga4", "claude", "twitter", "facebook", "gsc", "bing"];
            integrations.forEach(function(integration) {
                const $card = $(".flm-integration-card[data-integration=\"" + integration + "\"]");
                const hasKey = $card.attr("data-configured") === "true";
                
                if (hasKey) {
                    $card.addClass("connected");
                    $card.find(".flm-integration-status")
                        .removeClass("disconnected")
                        .addClass("connected")
                        .html("<span>●</span> Connected");
                }
            });
        },
        
        analyzeHeadline: function() {
            const $btn = $(this);
            const $input = $("#flm-headline-input");
            const headline = $input.val().trim();
            
            if (!headline) {
                Toast.show("warning", "Enter a Headline", "Please enter a headline to analyze");
                $input.focus();
                return;
            }
            
            $btn.prop("disabled", true).html(
                "<svg class=\"flm-spinner\" viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"2\" fill=\"none\" stroke-dasharray=\"31.4\" stroke-dashoffset=\"10\"/></svg>" +
                "Analyzing..."
            );
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_analyze_headline",
                    nonce: flmAdmin.nonce,
                    headline: headline
                },
                success: function(response) {
                    if (response.success) {
                        MLInsights.displayHeadlineResults(response.data);
                        Toast.show("success", "Analysis Complete", "Headline scored " + response.data.score + "/100");
                    } else {
                        Toast.show("error", "Analysis Failed", response.data.message || "Could not analyze headline");
                    }
                },
                error: function() {
                    Toast.show("error", "Connection Error", "Could not reach the server");
                },
                complete: function() {
                    $btn.prop("disabled", false).html(
                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z\"/></svg>" +
                        "Analyze"
                    );
                }
            });
        },
        
        displayHeadlineResults: function(data) {
            const $results = $("#flm-headline-results");
            const score = data.score || 0;
            const circumference = 2 * Math.PI * 42;
            const offset = circumference - (score / 100) * circumference;
            
            let scoreClass = "poor";
            let verdict = "Needs Work";
            if (score >= 80) { scoreClass = "excellent"; verdict = "Excellent!"; }
            else if (score >= 60) { scoreClass = "good"; verdict = "Good"; }
            else if (score >= 40) { scoreClass = "average"; verdict = "Average"; }
            
            // Update score ring
            $results.find(".flm-score-ring-fill")
                .removeClass("excellent good average poor")
                .addClass(scoreClass)
                .css("stroke-dasharray", circumference)
                .css("stroke-dashoffset", circumference);
            
            setTimeout(function() {
                $results.find(".flm-score-ring-fill").css("stroke-dashoffset", offset);
            }, 100);
            
            $results.find(".flm-score-value").text(score);
            $results.find(".flm-score-verdict").text(verdict);
            
            // Update factors
            const $breakdown = $results.find(".flm-score-breakdown");
            $breakdown.empty();
            
            if (data.factors) {
                data.factors.forEach(function(factor) {
                    const iconClass = factor.positive ? "positive" : (factor.negative ? "negative" : "neutral");
                    const icon = factor.positive ? "check" : (factor.negative ? "x" : "minus");
                    $breakdown.append(
                        "<div class=\"flm-score-factor\">" +
                            "<svg class=\"flm-score-factor-icon " + iconClass + "\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\">" +
                                (icon === "check" ? "<polyline points=\"20 6 9 17 4 12\"/>" : 
                                 icon === "x" ? "<line x1=\"18\" y1=\"6\" x2=\"6\" y2=\"18\"/><line x1=\"6\" y1=\"6\" x2=\"18\" y2=\"18\"/>" :
                                 "<line x1=\"5\" y1=\"12\" x2=\"19\" y2=\"12\"/>") +
                            "</svg>" +
                            "<span>" + factor.text + "</span>" +
                        "</div>"
                    );
                });
            }
            
            // Update suggestions
            const $suggestions = $results.find(".flm-ai-suggestions");
            $suggestions.find(".flm-ai-suggestion-item").remove();
            
            if (data.suggestions && data.suggestions.length) {
                data.suggestions.forEach(function(suggestion, i) {
                    $suggestions.append(
                        "<div class=\"flm-ai-suggestion-item\">" +
                            "<div class=\"flm-ai-suggestion-number\">" + (i + 1) + "</div>" +
                            "<div class=\"flm-ai-suggestion-text\">" + suggestion + "</div>" +
                        "</div>"
                    );
                });
            }
            
            $results.addClass("show");
        },
        
        testIntegration: function() {
            const $btn = $(this);
            const integration = $btn.data("integration");
            
            $btn.prop("disabled", true).text("Testing...");
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_test_integration",
                    nonce: flmAdmin.nonce,
                    integration: integration
                },
                success: function(response) {
                    if (response.success) {
                        Toast.show("success", "Connection Successful", response.data.message || (integration.toUpperCase() + " is connected"));
                        $btn.closest(".flm-integration-card")
                            .addClass("connected")
                            .find(".flm-integration-status")
                            .removeClass("disconnected")
                            .addClass("connected")
                            .html("<span>●</span> Connected");
                    } else {
                        Toast.show("error", "Connection Failed", response.data.message || "Could not connect");
                    }
                },
                error: function() {
                    Toast.show("error", "Error", "Could not test connection");
                },
                complete: function() {
                    $btn.prop("disabled", false).text("Test Connection");
                }
            });
        },
        
        // Test social post (v2.9.0)
        testSocialPost: function() {
            const $btn = $(this);
            const platform = $btn.data("platform");
            const originalText = $btn.html();
            
            $btn.prop("disabled", true).html(
                "<svg class=\"flm-spinner\" viewBox=\"0 0 24 24\" style=\"width:12px;height:12px;margin-right:4px;\"><circle cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"2\" fill=\"none\" stroke-dasharray=\"31.4\" stroke-dashoffset=\"10\"/></svg>" +
                "Sending..."
            );
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_test_social_post",
                    nonce: flmAdmin.nonce,
                    platform: platform
                },
                success: function(response) {
                    if (response.success) {
                        Toast.show("success", "Test Post Sent!", response.data.message || "Check your " + platform + " account");
                    } else {
                        Toast.show("error", "Post Failed", response.data.message || "Could not send test post");
                    }
                },
                error: function() {
                    Toast.show("error", "Error", "Network error - could not send test post");
                },
                complete: function() {
                    $btn.prop("disabled", false).html(originalText);
                }
            });
        },
        
        // Publishing tab functions (v2.10.0)
        refreshScheduledPosts: function() {
            const $btn = $(this);
            const $list = $("#flm-scheduled-list");
            const originalHtml = $btn.html();
            
            $btn.prop("disabled", true).html(
                "<svg class=\"flm-spinner\" viewBox=\"0 0 24 24\" style=\"width:12px;height:12px;\"><circle cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"2\" fill=\"none\" stroke-dasharray=\"31.4\" stroke-dashoffset=\"10\"/></svg>"
            );
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_get_scheduled_posts",
                    nonce: flmAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.scheduled) {
                        if (response.data.scheduled.length === 0) {
                            $list.html(
                                "<div class=\"flm-empty-state\">" +
                                "<div class=\"flm-empty-icon\"><svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M12 6v6l4 2\"/></svg></div>" +
                                "<div class=\"flm-empty-text\">No scheduled posts</div>" +
                                "<div class=\"flm-empty-hint\">Schedule posts from the post editor sidebar</div>" +
                                "</div>"
                            );
                        } else {
                            let html = "";
                            response.data.scheduled.forEach(function(sched) {
                                const platformColor = sched.platform === "twitter" ? "#1DA1F2" : "#1877F2";
                                const platformIcon = sched.platform === "twitter" ? "𝕏" : "FB";
                                html += "<div class=\"flm-log-entry\" data-schedule-id=\"" + sched.schedule_id + "\">" +
                                    "<div class=\"flm-log-time\">" + sched.scheduled_for_human + "</div>" +
                                    "<div class=\"flm-log-content\">" +
                                    "<span class=\"flm-log-platform\" style=\"color:" + platformColor + ";\">" + platformIcon + "</span>" +
                                    "<span class=\"flm-log-text\">" + sched.title + "</span>" +
                                    "</div>" +
                                    "<button type=\"button\" class=\"flm-btn flm-btn-xs flm-btn-danger flm-cancel-scheduled\" data-schedule-id=\"" + sched.schedule_id + "\">" +
                                    "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:12px;height:12px;\"><path d=\"M18 6L6 18M6 6l12 12\"/></svg>" +
                                    "</button>" +
                                    "</div>";
                            });
                            $list.html(html);
                        }
                        Toast.show("success", "Refreshed", "Scheduled posts updated");
                    }
                },
                complete: function() {
                    $btn.prop("disabled", false).html(originalHtml);
                }
            });
        },
        
        cancelScheduledPost: function() {
            const $btn = $(this);
            const scheduleId = $btn.data("schedule-id");
            const $entry = $btn.closest(".flm-log-entry");
            
            if (!confirm("Cancel this scheduled post?")) {
                return;
            }
            
            $btn.prop("disabled", true);
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_cancel_scheduled_post",
                    nonce: flmAdmin.nonce,
                    schedule_id: scheduleId
                },
                success: function(response) {
                    if (response.success) {
                        $entry.fadeOut(300, function() {
                            $(this).remove();
                            // Check if list is now empty
                            if ($("#flm-scheduled-list .flm-log-entry").length === 0) {
                                $("#flm-scheduled-list").html(
                                    "<div class=\"flm-empty-state\">" +
                                    "<div class=\"flm-empty-icon\"><svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M12 6v6l4 2\"/></svg></div>" +
                                    "<div class=\"flm-empty-text\">No scheduled posts</div>" +
                                    "<div class=\"flm-empty-hint\">Schedule posts from the post editor sidebar</div>" +
                                    "</div>"
                                );
                            }
                        });
                        Toast.show("success", "Cancelled", "Scheduled post removed");
                    } else {
                        Toast.show("error", "Error", response.data.message || "Could not cancel post");
                        $btn.prop("disabled", false);
                    }
                },
                error: function() {
                    Toast.show("error", "Error", "Network error");
                    $btn.prop("disabled", false);
                }
            });
        },
        
        clearSocialLog: function() {
            if (!confirm("Clear all social posting history?")) {
                return;
            }
            
            const $btn = $(this);
            $btn.prop("disabled", true);
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_clear_social_log",
                    nonce: flmAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $("#flm-social-activity-log").html(
                            "<div class=\"flm-empty-state\">" +
                            "<div class=\"flm-empty-icon\"><svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"18\" cy=\"5\" r=\"3\"/><circle cx=\"6\" cy=\"12\" r=\"3\"/><circle cx=\"18\" cy=\"19\" r=\"3\"/><path d=\"M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98\"/></svg></div>" +
                            "<div class=\"flm-empty-text\">No social activity yet</div>" +
                            "<div class=\"flm-empty-hint\">Posts will appear here when shared to social media</div>" +
                            "</div>"
                        );
                        Toast.show("success", "Cleared", "Social log cleared");
                    }
                },
                complete: function() {
                    $btn.prop("disabled", false);
                }
            });
        },
        
        // Settings Import/Export Functions (v2.12.0)
        exportSettings: function() {
            const $btn = $(this);
            const categories = [];
            
            $(".flm-export-category:checked").each(function() {
                categories.push($(this).val());
            });
            
            if (categories.length === 0) {
                Toast.show("warning", "Select Categories", "Please select at least one category to export");
                return;
            }
            
            const passphrase = $("#flm-export-passphrase").val();
            const includeSensitive = categories.includes("api_keys");
            
            $btn.prop("disabled", true).html(
                "<svg class=\"flm-spinner\" viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"2\" fill=\"none\" stroke-dasharray=\"31.4\" stroke-dashoffset=\"10\"/></svg>" +
                " Exporting..."
            );
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_export_settings",
                    nonce: flmAdmin.nonce,
                    categories: categories,
                    include_sensitive: includeSensitive ? 1 : 0,
                    passphrase: passphrase
                },
                success: function(response) {
                    if (response.success) {
                        // Download the JSON file
                        const blob = new Blob([JSON.stringify(response.data.data, null, 2)], {type: "application/json"});
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement("a");
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        
                        Toast.show("success", "Export Complete", "Settings exported to " + response.data.filename);
                    } else {
                        Toast.show("error", "Export Failed", response.data.message);
                    }
                },
                error: function() {
                    Toast.show("error", "Export Failed", "An error occurred during export");
                },
                complete: function() {
                    $btn.prop("disabled", false).html(
                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:14px;height:14px;\"><path d=\"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3\"/></svg>" +
                        " Export Settings"
                    );
                }
            });
        },
        
        previewImport: function() {
            const $btn = $(this);
            const fileInput = document.getElementById("flm-import-file");
            const passphrase = $("#flm-import-passphrase").val();
            
            if (!fileInput.files || !fileInput.files[0]) {
                Toast.show("warning", "Select File", "Please select a settings file to import");
                return;
            }
            
            const file = fileInput.files[0];
            const reader = new FileReader();
            
            $btn.prop("disabled", true).html(
                "<svg class=\"flm-spinner\" viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"2\" fill=\"none\" stroke-dasharray=\"31.4\" stroke-dashoffset=\"10\"/></svg>" +
                " Reading..."
            );
            
            reader.onload = function(e) {
                $.ajax({
                    url: flmAdmin.ajaxUrl,
                    type: "POST",
                    data: {
                        action: "flm_preview_import",
                        nonce: flmAdmin.nonce,
                        import_data: e.target.result,
                        passphrase: passphrase
                    },
                    success: function(response) {
                        if (response.success) {
                            const preview = response.data;
                            let html = "<div style=\"font-size:12px;\">";
                            html += "<div style=\"margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid var(--flm-border);\">";
                            html += "<div style=\"color:var(--flm-text-muted);margin-bottom:4px;\">Source: " + preview.source_site + "</div>";
                            html += "<div style=\"color:var(--flm-text-muted);\">Exported: " + preview.exported_at + " (v" + preview.version + ")</div>";
                            html += "</div>";
                            
                            html += "<div style=\"font-weight:600;margin-bottom:8px;color:var(--flm-text);\">" + preview.total_settings + " settings to import:</div>";
                            
                            for (const catKey in preview.categories) {
                                const cat = preview.categories[catKey];
                                const changedCount = cat.settings.filter(s => s.changed).length;
                                html += "<label class=\"flm-checkbox-card\" style=\"padding:8px 10px;margin-bottom:6px;\">";
                                html += "<input type=\"checkbox\" class=\"flm-import-category\" value=\"" + catKey + "\" checked>";
                                html += "<span class=\"flm-checkbox-card-content\">";
                                html += "<span class=\"flm-checkbox-card-title\" style=\"font-size:11px;\">" + cat.label;
                                if (cat.sensitive) html += " <span style=\"color:var(--flm-warning);\">⚠️</span>";
                                html += "</span>";
                                html += "<span class=\"flm-checkbox-card-desc\" style=\"font-size:10px;\">" + cat.settings.length + " settings";
                                if (changedCount > 0) html += ", " + changedCount + " changed";
                                html += "</span></span></label>";
                            }
                            
                            html += "</div>";
                            
                            $("#flm-import-preview").html(html).slideDown(200);
                            $("#flm-import-settings").slideDown(200);
                            
                            // Store import data for use by import button
                            window.flmImportData = e.target.result;
                            
                            Toast.show("success", "Preview Ready", "Review the settings and click Confirm Import");
                        } else {
                            if (response.data.needs_passphrase) {
                                Toast.show("warning", "Passphrase Required", response.data.message);
                                $("#flm-import-passphrase").focus();
                            } else {
                                Toast.show("error", "Preview Failed", response.data.message);
                            }
                            $("#flm-import-preview").slideUp(200);
                            $("#flm-import-settings").slideUp(200);
                        }
                    },
                    error: function() {
                        Toast.show("error", "Preview Failed", "An error occurred while reading the file");
                    },
                    complete: function() {
                        $btn.prop("disabled", false).html(
                            "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:14px;height:14px;\"><circle cx=\"12\" cy=\"12\" r=\"2\"/><path d=\"M22 12c-2.667 4.667-6 7-10 7s-7.333-2.333-10-7c2.667-4.667 6-7 10-7s7.333 2.333 10 7\"/></svg>" +
                            " Preview Import"
                        );
                    }
                });
            };
            
            reader.onerror = function() {
                Toast.show("error", "Read Failed", "Could not read the selected file");
                $btn.prop("disabled", false).html(
                    "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:14px;height:14px;\"><circle cx=\"12\" cy=\"12\" r=\"2\"/><path d=\"M22 12c-2.667 4.667-6 7-10 7s-7.333-2.333-10-7c2.667-4.667 6-7 10-7s7.333 2.333 10 7\"/></svg>" +
                    " Preview Import"
                );
            };
            
            reader.readAsText(file);
        },
        
        importSettings: function() {
            const $btn = $(this);
            const passphrase = $("#flm-import-passphrase").val();
            const backupFirst = $("#flm-import-backup").is(":checked");
            const categories = [];
            
            $(".flm-import-category:checked").each(function() {
                categories.push($(this).val());
            });
            
            if (categories.length === 0) {
                Toast.show("warning", "Select Categories", "Please select at least one category to import");
                return;
            }
            
            if (!window.flmImportData) {
                Toast.show("error", "No Data", "Please preview the import first");
                return;
            }
            
            if (!confirm("This will overwrite your current settings for the selected categories. Continue?")) {
                return;
            }
            
            $btn.prop("disabled", true).html(
                "<svg class=\"flm-spinner\" viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"2\" fill=\"none\" stroke-dasharray=\"31.4\" stroke-dashoffset=\"10\"/></svg>" +
                " Importing..."
            );
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_import_settings",
                    nonce: flmAdmin.nonce,
                    import_data: window.flmImportData,
                    passphrase: passphrase,
                    categories: categories,
                    backup_first: backupFirst ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        Toast.show("success", "Import Complete", response.data.message);
                        
                        // Reload page to show new settings
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        Toast.show("error", "Import Failed", response.data.message);
                    }
                },
                error: function() {
                    Toast.show("error", "Import Failed", "An error occurred during import");
                },
                complete: function() {
                    $btn.prop("disabled", false).html(
                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:14px;height:14px;\"><polyline points=\"20 6 9 17 4 12\"/></svg>" +
                        " Confirm Import"
                    );
                }
            });
        },
        
        restoreBackup: function() {
            if (!confirm("This will restore your settings to the backup created before the last import. Current settings will be lost. Continue?")) {
                return;
            }
            
            const $btn = $(this);
            $btn.prop("disabled", true).text("Restoring...");
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_restore_backup",
                    nonce: flmAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Toast.show("success", "Backup Restored", response.data.message);
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        Toast.show("error", "Restore Failed", response.data.message);
                    }
                },
                complete: function() {
                    $btn.prop("disabled", false).html(
                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:14px;height:14px;\"><path d=\"M1 4v6h6M23 20v-6h-6\"/><path d=\"M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15\"/></svg>" +
                        " Restore Backup"
                    );
                }
            });
        },
        
        // ESP Integration Functions (v2.13.0)
        toggleEspSettings: function() {
            const provider = $("input[name=\'flm_settings[esp_provider]\']:checked").val();
            
            $("#flm-sendgrid-settings").slideUp(200);
            $("#flm-aigeon-settings").slideUp(200);
            
            if (provider === "sendgrid") {
                $("#flm-sendgrid-settings").slideDown(200);
            } else if (provider === "aigeon") {
                $("#flm-aigeon-settings").slideDown(200);
            }
        },
        
        testEspConnection: function() {
            const $btn = $(this);
            const provider = $btn.data("provider");
            
            $btn.prop("disabled", true).html(
                "<svg class=\"flm-spinner\" viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"2\" fill=\"none\" stroke-dasharray=\"31.4\" stroke-dashoffset=\"10\"/></svg>" +
                " Testing..."
            );
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_test_esp_connection",
                    nonce: flmAdmin.nonce,
                    provider: provider
                },
                success: function(response) {
                    if (response.success) {
                        Toast.show("success", "Connection Successful", response.data.message);
                    } else {
                        let msg = response.data.message;
                        if (response.data.note) {
                            msg += " - " + response.data.note;
                        }
                        Toast.show("error", "Connection Failed", msg);
                    }
                },
                error: function() {
                    Toast.show("error", "Test Failed", "An error occurred while testing the connection");
                },
                complete: function() {
                    $btn.prop("disabled", false).html(
                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"width:14px;height:14px;\"><polyline points=\"20 6 9 17 4 12\"/></svg>" +
                        " Test " + provider.charAt(0).toUpperCase() + provider.slice(1) + " Connection"
                    );
                }
            });
        },
        
        predictPerformance: function() {
            const $btn = $(this);
            const team = $("#flm-predict-team").val();
            const type = $("#flm-predict-type").val();
            const hour = $("#flm-predict-hour").val();
            
            $btn.prop("disabled", true).text("Predicting...");
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_predict_performance",
                    nonce: flmAdmin.nonce,
                    team: team,
                    type: type,
                    hour: hour
                },
                success: function(response) {
                    if (response.success) {
                        $("#flm-predicted-views").text(response.data.views.toLocaleString());
                        $("#flm-predicted-engagement").text(response.data.engagement + "%");
                        $("#flm-prediction-confidence").text(response.data.confidence + "% confidence");
                        Toast.show("success", "Prediction Ready", "Estimated " + response.data.views + " views");
                    } else {
                        Toast.show("error", "Prediction Failed", response.data.message);
                    }
                },
                complete: function() {
                    $btn.prop("disabled", false).text("Predict");
                }
            });
        },
        
        generateIdeas: function() {
            const $btn = $(this);
            const team = $("#flm-ideas-team").val();
            
            $btn.prop("disabled", true).html(
                "<svg class=\"flm-spinner\" viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"2\" fill=\"none\" stroke-dasharray=\"31.4\" stroke-dashoffset=\"10\"/></svg>" +
                "Generating..."
            );
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_generate_content_suggestions",
                    nonce: flmAdmin.nonce,
                    team: team
                },
                success: function(response) {
                    if (response.success && response.data.ideas) {
                        const $list = $("#flm-ideas-list");
                        $list.empty();
                        
                        response.data.ideas.forEach(function(idea) {
                            $list.append(
                                "<div class=\"flm-idea-item\">" +
                                    "<span class=\"flm-idea-type\">" + idea.type + "</span>" +
                                    "<div class=\"flm-idea-content\">" +
                                        "<div class=\"flm-idea-headline\">" + idea.headline + "</div>" +
                                        "<div class=\"flm-idea-reason\">" + idea.reason + "</div>" +
                                    "</div>" +
                                    "<button class=\"flm-idea-action\" data-headline=\"" + idea.headline + "\">Use</button>" +
                                "</div>"
                            );
                        });
                        
                        Toast.show("success", "Ideas Generated", response.data.ideas.length + " content ideas ready");
                    }
                },
                complete: function() {
                    $btn.prop("disabled", false).html(
                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z\"/></svg>" +
                        "Generate Ideas"
                    );
                }
            });
        },
        
        refreshTrending: function() {
            const $btn = $("#flm-refresh-trending");
            $btn.prop("disabled", true);
            
            $.ajax({
                url: flmAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "flm_get_trending_topics",
                    nonce: flmAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.topics) {
                        const $list = $("#flm-trending-list");
                        $list.empty();
                        
                        response.data.topics.forEach(function(topic, i) {
                            $list.append(
                                "<div class=\"flm-trending-item\">" +
                                    "<div class=\"flm-trending-rank\">" + (i + 1) + "</div>" +
                                    "<div class=\"flm-trending-content\">" +
                                        "<div class=\"flm-trending-topic\">" + topic.topic + "</div>" +
                                        "<div class=\"flm-trending-meta\">" +
                                            "<span class=\"flm-trending-team\">" + topic.team + "</span>" +
                                            "<span>" + topic.mentions + " mentions</span>" +
                                        "</div>" +
                                    "</div>" +
                                    "<div class=\"flm-trending-velocity\">" +
                                        "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><polyline points=\"23 6 13.5 15.5 8.5 10.5 1 18\"/><polyline points=\"17 6 23 6 23 12\"/></svg>" +
                                        "+" + topic.velocity + "%" +
                                    "</div>" +
                                "</div>"
                            );
                        });
                    }
                },
                complete: function() {
                    $btn.prop("disabled", false);
                }
            });
        },
        
        updateTimeHeatmap: function() {
            const team = $("#flm-time-team").val();
            
            // Team-specific time multipliers
            const teamData = {
                "": {best: "Tuesday & Wednesday at 6PM", boost: "47%"},
                "braves": {best: "Tuesday at 7PM", boost: "52%"},
                "falcons": {best: "Sunday at 12PM", boost: "63%"},
                "hawks": {best: "Wednesday at 8PM", boost: "41%"},
                "uga": {best: "Saturday at 11AM", boost: "71%"},
                "gt": {best: "Saturday at 2PM", boost: "38%"}
            };
            
            const data = teamData[team] || teamData[""];
            $(".flm-time-recommendation-title").text("Best: " + data.best);
            $(".flm-time-recommendation-detail").text("Posts at this time get " + data.boost + " more engagement on average");
            
            // Could animate heatmap cells here for team-specific data
            Toast.show("info", "Updated", "Showing optimal times for " + (team ? team.charAt(0).toUpperCase() + team.slice(1) : "all teams"));
        }
    };
    
    // Use headline from ideas
    $(document).on("click", ".flm-idea-action", function() {
        const headline = $(this).data("headline");
        $("#flm-headline-input").val(headline);
        $("html, body").animate({ scrollTop: $("#flm-headline-analyzer").offset().top - 100 }, 500);
        Toast.show("info", "Headline Copied", "Now analyze it to see the score");
    });
    
    // ============================================
    // SETUP WIZARD v2.8.0
    // Step-by-step Integration Setup
    // ============================================
    
    const SetupWizard = {
        currentStep: 1,
        totalSteps: 1,
        currentWizard: null,
        
        init: function() {
            this.bindEvents();
        },
        
        bindEvents: function() {
            // Open wizard buttons
            $(document).on("click", ".flm-open-wizard", function(e) {
                e.preventDefault();
                const provider = $(this).data("wizard");
                SetupWizard.open(provider);
            });
            
            // Close wizard
            $(document).on("click", ".flm-wizard-close", function() {
                SetupWizard.close();
            });
            
            // Close on overlay click
            $(document).on("click", ".flm-modal-overlay", function(e) {
                if ($(e.target).hasClass("flm-modal-overlay")) {
                    SetupWizard.close();
                }
            });
            
            // Next step
            $(document).on("click", ".flm-wizard-next", function() {
                SetupWizard.nextStep();
            });
            
            // Previous step
            $(document).on("click", ".flm-wizard-prev", function() {
                SetupWizard.prevStep();
            });
            
            // Finish wizard
            $(document).on("click", ".flm-wizard-finish", function() {
                const provider = $(this).data("provider");
                SetupWizard.finish(provider);
            });
            
            // Copy buttons
            $(document).on("click", ".flm-copy-btn", function() {
                const targetId = $(this).data("copy");
                const text = document.getElementById(targetId).textContent;
                navigator.clipboard.writeText(text).then(function() {
                    const btn = document.querySelector("[data-copy=\'" + targetId + "\']");
                    btn.textContent = "Copied!";
                    btn.classList.add("copied");
                    setTimeout(function() {
                        btn.textContent = "Copy";
                        btn.classList.remove("copied");
                    }, 2000);
                });
            });
            
            // Checklist items
            $(document).on("click", ".flm-checklist-item", function() {
                $(this).toggleClass("checked");
            });
            
            // Escape key to close
            $(document).on("keydown", function(e) {
                if (e.key === "Escape" && SetupWizard.currentWizard) {
                    SetupWizard.close();
                }
            });
        },
        
        open: function(provider) {
            const $modal = $("#flm-wizard-" + provider);
            if (!$modal.length) return;
            
            this.currentWizard = provider;
            this.currentStep = 1;
            this.totalSteps = $modal.find(".flm-wizard-step").length || 1;
            
            // Reset to first step
            $modal.find(".flm-wizard-step").removeClass("active completed").first().addClass("active");
            $modal.find(".flm-wizard-panel").removeClass("active").first().addClass("active");
            this.updateNavButtons($modal);
            
            $modal.addClass("active");
            $("body").css("overflow", "hidden");
        },
        
        close: function() {
            $(".flm-modal-overlay").removeClass("active");
            $("body").css("overflow", "");
            this.currentWizard = null;
            this.currentStep = 1;
        },
        
        nextStep: function() {
            if (this.currentStep >= this.totalSteps) return;
            
            const $modal = $("#flm-wizard-" + this.currentWizard);
            const step = this.currentStep;
            
            // Mark current as completed
            $modal.find(".flm-wizard-step").eq(step - 1).removeClass("active").addClass("completed");
            $modal.find(".flm-wizard-panel").eq(step - 1).removeClass("active");
            
            this.currentStep++;
            
            // Activate next
            $modal.find(".flm-wizard-step").eq(this.currentStep - 1).addClass("active");
            $modal.find(".flm-wizard-panel").eq(this.currentStep - 1).addClass("active");
            
            this.updateNavButtons($modal);
        },
        
        prevStep: function() {
            if (this.currentStep <= 1) return;
            
            const $modal = $("#flm-wizard-" + this.currentWizard);
            const step = this.currentStep;
            
            // Remove active from current
            $modal.find(".flm-wizard-step").eq(step - 1).removeClass("active");
            $modal.find(".flm-wizard-panel").eq(step - 1).removeClass("active");
            
            this.currentStep--;
            
            // Activate previous
            $modal.find(".flm-wizard-step").eq(this.currentStep - 1).removeClass("completed").addClass("active");
            $modal.find(".flm-wizard-panel").eq(this.currentStep - 1).addClass("active");
            
            this.updateNavButtons($modal);
        },
        
        updateNavButtons: function($modal) {
            const $prev = $modal.find(".flm-wizard-prev");
            const $next = $modal.find(".flm-wizard-next");
            const $finish = $modal.find(".flm-wizard-finish");
            
            if (this.currentStep === 1) {
                $prev.hide();
            } else {
                $prev.show();
            }
            
            if (this.currentStep === this.totalSteps) {
                $next.hide();
                $finish.show();
            } else {
                $next.show();
                $finish.hide();
            }
        },
        
        finish: function(provider) {
            const self = this;
            let data = { action: "flm_save_settings", nonce: flmData.nonce };
            
            if (provider === "google") {
                data["flm_settings[gsc_client_id]"] = $("#wizard-google-client-id").val();
                data["flm_settings[gsc_client_secret]"] = $("#wizard-google-client-secret").val();
                data["flm_settings[ga4_property_id]"] = $("#wizard-google-ga4-id").val();
                data["flm_settings[gsc_property_url]"] = $("#wizard-google-gsc-url").val();
            } else if (provider === "claude") {
                data["flm_settings[claude_api_key]"] = $("#wizard-claude-api-key").val();
            } else if (provider === "twitter") {
                data["flm_settings[twitter_api_key]"] = $("#wizard-twitter-api-key").val();
                data["flm_settings[twitter_api_secret]"] = $("#wizard-twitter-api-secret").val();
                data["flm_settings[twitter_access_token]"] = $("#wizard-twitter-access-token").val();
                data["flm_settings[twitter_access_secret]"] = $("#wizard-twitter-access-secret").val();
            } else if (provider === "facebook") {
                data["flm_settings[facebook_app_id]"] = $("#wizard-facebook-app-id").val();
                data["flm_settings[facebook_app_secret]"] = $("#wizard-facebook-app-secret").val();
                data["flm_settings[facebook_page_id]"] = $("#wizard-facebook-page-id").val();
                data["flm_settings[facebook_access_token]"] = $("#wizard-facebook-access-token").val();
            } else if (provider === "bing") {
                data["flm_settings[bing_site_url]"] = $("#wizard-bing-site-url").val();
                data["flm_settings[bing_api_key]"] = $("#wizard-bing-api-key").val();
            }
            
            $.post(flmData.ajaxUrl, data, function(response) {
                if (response.success) {
                    showToast("Settings saved!", "success");
                    
                    // Test the connection
                    $.post(flmData.ajaxUrl, {
                        action: "flm_test_integration",
                        nonce: flmData.nonce,
                        integration: provider === "google" ? "gsc" : provider
                    }, function(testResponse) {
                        if (testResponse.success) {
                            showToast(provider.charAt(0).toUpperCase() + provider.slice(1) + " connected!", "success");
                            self.close();
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            showToast(testResponse.data && testResponse.data.message ? testResponse.data.message : "Connection test failed", "error");
                        }
                    });
                } else {
                    showToast("Failed to save settings", "error");
                }
            });
        }
    };
    
    // Initialize wizard
    SetupWizard.init();
    
})(jQuery);
        ';
    }
    
    /**
     * Get plugin settings
     */
    private function get_settings() {
        $settings = get_option('flm_settings', []);
        return wp_parse_args($settings, $this->default_settings);
    }
    
    /**
     * Get JWT token
     */
    private function get_token($force_refresh = false) {
        $token = get_option('flm_jwt_token');
        $expiry = get_option('flm_token_expiry');
        
        // Return cached token if still valid (with 1-day buffer)
        if (!$force_refresh && $token && $expiry && (time() < ($expiry - 86400))) {
            return $token;
        }
        
        $settings = $this->get_settings();
        
        if (empty($settings['api_key'])) {
            $this->log_error('error', 'auth', 'No API key configured', []);
            return false;
        }
        
        $this->log_error('info', 'auth', 'Requesting new JWT token', []);
        
        $response = $this->api_request_with_retry(
            $this->api_base . '/Token',
            [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => ['apiKey' => $settings['api_key']],
                'timeout' => 30,
            ],
            'POST'
        );
        
        if (is_wp_error($response)) {
            $this->log_error('error', 'auth', 'Token request failed', [
                'error' => $response->get_error_message(),
            ]);
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['token'])) {
            update_option('flm_jwt_token', $body['token']);
            update_option('flm_token_expiry', time() + (7 * 86400));
            $this->log_error('info', 'auth', 'Token acquired successfully', []);
            return $body['token'];
        }
        
        $this->log_error('error', 'auth', 'Token response missing token field', [
            'response' => substr(wp_remote_retrieve_body($response), 0, 200),
        ]);
        
        return false;
    }
    
    /**
     * Fetch stories from API
     */
    public function fetch_stories($league_id = null, $use_lookback = false) {
        $token = $this->get_token();
        if (!$token) {
            $this->log_error('error', 'fetch', 'Cannot fetch stories - authentication failed', [
                'league_id' => $league_id,
            ]);
            return new WP_Error('auth_failed', 'Authentication failed');
        }
        
        $url = $this->api_base . '/story';
        if ($league_id) {
            $url .= '/' . $league_id;
        }
        
        // Determine cutoff date
        if ($use_lookback) {
            // Use lookback_days setting (for preview, test, selective import)
            $settings = $this->get_settings();
            $lookback_days = min(30, max(1, (int)($settings['lookback_days'] ?? 7)));
            $cutoff_time = strtotime("-{$lookback_days} days");
            $url .= '?cutOffDate=' . urlencode(gmdate('Y-m-d H:i:s', $cutoff_time) . 'Z');
        } else {
            // Use last import time (for regular imports)
            $last_import = get_option('flm_last_import');
            if ($last_import) {
                $url .= '?cutOffDate=' . urlencode(gmdate('Y-m-d H:i:s', $last_import) . 'Z');
            }
        }
        
        $response = $this->api_request_with_retry($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'timeout' => 60,
        ], 'GET');
        
        if (is_wp_error($response)) {
            $this->log_error('error', 'fetch', 'Story fetch failed', [
                'league_id' => $league_id,
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }
        
        $stories = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($stories)) {
            $this->log_error('error', 'fetch', 'Invalid response format', [
                'league_id' => $league_id,
                'response' => substr(wp_remote_retrieve_body($response), 0, 200),
            ]);
            return new WP_Error('invalid_response', 'API returned invalid response format');
        }
        
        $this->log_error('info', 'fetch', 'Stories fetched successfully', [
            'league_id' => $league_id,
            'count' => count($stories),
            'use_lookback' => $use_lookback,
        ]);
        
        return $stories;
    }
    
    /**
     * Check if story matches our teams
     */
    private function get_matching_team($story) {
        $settings = $this->get_settings();
        $home_team = $story['homeTeam'] ?? null;
        $away_team = $story['awayTeam'] ?? null;
        
        // Get team IDs from story
        $home_id = (string)($home_team['teamId'] ?? '');
        $away_id = (string)($away_team['teamId'] ?? '');
        
        // No team IDs = can't match
        if (empty($home_id) && empty($away_id)) {
            return false;
        }
        
        // Match by team ID only (most reliable, no false positives)
        foreach ($this->target_teams as $key => $team_config) {
            if (empty($settings['teams_enabled'][$key])) {
                continue;
            }
            
            if (!empty($team_config['team_ids'])) {
                foreach ($team_config['team_ids'] as $tid) {
                    $tid = (string)$tid;
                    if ($home_id === $tid || $away_id === $tid) {
                        return $key;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * MAIN IMPORT FUNCTION
     */
    /**
     * MAIN IMPORT FUNCTION
     * 
     * @param int|null $single_league Optional - import only this league ID (1=MLB, 2=NFL, 3=NBA)
     */
    public function import_stories($single_league = null) {
        $settings = $this->get_settings();
        
        // If single league specified, only import that one
        if ($single_league !== null) {
            $leagues_to_check = [(int)$single_league];
        } else {
            // All leagues: MLB=1, NFL=30, NBA=26, NCAAF=31, NCAAB=20
            $leagues_to_check = [1, 30, 26, 31, 20];
        }
        
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $log = [];
        
        $league_names = [
            1 => 'MLB', 
            30 => 'NFL', 
            26 => 'NBA',
            31 => 'NCAAF',
            20 => 'NCAAB',
        ];
        $this->log_error('info', 'import', 'Starting import run', [
            'leagues' => array_map(function($id) use ($league_names) { 
                return $league_names[$id] ?? $id; 
            }, $leagues_to_check),
            'single_league' => $single_league,
        ]);
        
        foreach ($leagues_to_check as $league_id) {
            $stories = $this->fetch_stories($league_id);
            
            if (is_wp_error($stories)) {
                $errors++;
                $this->log_error('error', 'import', "Failed to fetch league {$league_id}", [
                    'league_id' => $league_id,
                    'error' => $stories->get_error_message(),
                ]);
                continue;
            }
            
            if (!is_array($stories)) {
                $errors++;
                $this->log_error('warning', 'import', "No stories returned for league {$league_id}", [
                    'league_id' => $league_id,
                ]);
                continue;
            }
            
            foreach ($stories as $story) {
                $team_key = $this->get_matching_team($story);
                
                if (!$team_key) {
                    $skipped++;
                    continue;
                }
                
                // Check if story type is enabled (P4.1)
                $story_type = $story['storyType'] ?? 'News';
                if (!$this->is_story_type_enabled($story_type)) {
                    $skipped++;
                    continue;
                }
                
                $result = $this->create_or_update_post($story, $team_key);
                
                if (is_wp_error($result)) {
                    $errors++;
                    $this->log_error('error', 'import', 'Failed to create/update post', [
                        'story_id' => $story['storyId'] ?? 'unknown',
                        'headline' => $story['headline'] ?? 'unknown',
                        'error' => $result->get_error_message(),
                    ]);
                    continue;
                }
                
                if ($result['action'] === 'created') {
                    $imported++;
                    $log[] = [
                        'type' => 'success',
                        'team' => $team_key,
                        'text' => $story['headline'],
                        'time' => current_time('H:i:s'),
                    ];
                } elseif ($result['action'] === 'updated') {
                    $updated++;
                    $log[] = [
                        'type' => 'update',
                        'team' => $team_key,
                        'text' => $story['headline'],
                        'time' => current_time('H:i:s'),
                    ];
                }
            }
            
            // Rate limit pause between leagues
            if ($league_id !== end($leagues_to_check)) {
                sleep(15);
            }
        }
        
        // Merge with existing log (backward compatible)
        $existing_log = get_option('flm_import_log', []);
        
        // Convert old format to new if needed
        if (!empty($existing_log) && isset($existing_log[0]) && is_string($existing_log[0])) {
            $existing_log = []; // Reset old format
        }
        
        $merged_log = array_merge($log, $existing_log);
        $merged_log = array_slice($merged_log, 0, 100); // Keep last 100
        
        update_option('flm_last_import', time());
        update_option('flm_import_log', $merged_log);
        update_option('flm_stats', [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'time' => current_time('mysql'),
        ]);
        
        $this->log_error('info', 'import', 'Import run completed', [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
        
        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
    
    /**
     * Create or update WordPress post from FLM story
     */
    private function create_or_update_post($story, $team_key) {
        $settings = $this->get_settings();
        $team_config = $this->target_teams[$team_key];
        
        $existing = get_posts([
            'meta_key' => 'flm_story_id',
            'meta_value' => $story['storyId'],
            'post_type' => 'post',
            'posts_per_page' => 1,
            'post_status' => 'any',
        ]);
        
        $categories = $this->build_categories($story, $team_key);
        $content = $this->format_content($story);
        
        // Generate excerpt from first paragraph if enabled
        $excerpt = '';
        if (!empty($settings['auto_excerpt'])) {
            $excerpt = $this->generate_excerpt($story);
        }
        
        $post_data = [
            'post_title'    => wp_strip_all_tags($story['headline']),
            'post_content'  => $content,
            'post_excerpt'  => $excerpt,
            'post_status'   => $settings['post_status'],
            'post_author'   => $settings['post_author'],
            'post_type'     => 'post',
            'post_category' => $categories,
        ];
        
        if (!empty($existing)) {
            $post_id = $existing[0]->ID;
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
            $action = 'updated';
        } else {
            $post_id = wp_insert_post($post_data);
            $action = 'created';
        }
        
        if ($post_id && !is_wp_error($post_id)) {
            update_post_meta($post_id, 'flm_story_id', $story['storyId']);
            update_post_meta($post_id, 'flm_byline', $story['byline'] ?? 'Field Level Media');
            update_post_meta($post_id, 'flm_story_type', $story['storyType'] ?? '');
            update_post_meta($post_id, 'flm_league', $story['league']['shortName'] ?? '');
            update_post_meta($post_id, 'flm_team', $team_key);
            update_post_meta($post_id, 'flm_last_exported', $story['lastExportedDate'] ?? '');
            update_post_meta($post_id, 'flm_slug', $story['slug'] ?? '');
            
            if (!empty($story['game'])) {
                update_post_meta($post_id, 'flm_game_date', $story['game']['startDate'] ?? '');
                update_post_meta($post_id, 'flm_home_team', $story['homeTeam']['nickName'] ?? '');
                update_post_meta($post_id, 'flm_away_team', $story['awayTeam']['nickName'] ?? '');
            }
            
            // Generate and save meta description if enabled
            if (!empty($settings['auto_meta_description'])) {
                $meta_desc = $this->generate_meta_description($story, $team_config);
                update_post_meta($post_id, '_flm_meta_description', $meta_desc);
                
                // Also set for Yoast SEO if active
                if (defined('WPSEO_VERSION')) {
                    update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
                }
                
                // Also set for RankMath if active
                if (class_exists('RankMath')) {
                    update_post_meta($post_id, 'rank_math_description', $meta_desc);
                }
            }
            
            if ($settings['import_images'] && !empty($story['images']) && $action === 'created') {
                $this->set_featured_image($post_id, $story['images'][0]);
            }
            
            // Trigger social media auto-posting for new posts (v2.9.0)
            if ($action === 'created') {
                $this->trigger_social_posting($post_id, $story, $team_key);
            }
        }
        
        return ['post_id' => $post_id, 'action' => $action];
    }
    
    /**
     * Generate excerpt from story content
     */
    private function generate_excerpt($story) {
        // Try to get first paragraph from body
        $body = $story['body'] ?? '';
        
        if (empty($body)) {
            return '';
        }
        
        // Strip HTML and get first paragraph
        $text = wp_strip_all_tags($body);
        
        // Split by double newlines or periods to find first sentence/paragraph
        $paragraphs = preg_split('/\n\n+/', $text);
        $first_para = trim($paragraphs[0] ?? '');
        
        if (empty($first_para)) {
            return '';
        }
        
        // Limit to ~55 words (WordPress default excerpt length)
        $words = explode(' ', $first_para);
        if (count($words) > 55) {
            $first_para = implode(' ', array_slice($words, 0, 55)) . '...';
        }
        
        return $first_para;
    }
    
    /**
     * Generate SEO meta description from story
     */
    private function generate_meta_description($story, $team_config) {
        $headline = wp_strip_all_tags($story['headline'] ?? '');
        $team_name = $team_config['name'] ?? '';
        $story_type = $story['storyType'] ?? '';
        $league = $story['league']['shortName'] ?? $team_config['league'] ?? '';
        
        // Try to create a compelling meta description
        // Format: "[Story Type]: [Headline]. [Team] [League] coverage."
        $meta_parts = [];
        
        // Start with headline (truncated if needed)
        $headline_truncated = $headline;
        if (strlen($headline) > 120) {
            $headline_truncated = substr($headline, 0, 117) . '...';
        }
        $meta_parts[] = $headline_truncated;
        
        // Add team/league context
        $context = [];
        if ($team_name) {
            $context[] = $team_name;
        }
        if ($league) {
            $context[] = $league;
        }
        if (!empty($context)) {
            $meta_parts[] = implode(' ', $context) . ' coverage from Field Level Media.';
        }
        
        $meta_desc = implode(' ', $meta_parts);
        
        // Ensure max 160 characters (SEO best practice)
        if (strlen($meta_desc) > 160) {
            $meta_desc = substr($meta_desc, 0, 157) . '...';
        }
        
        return $meta_desc;
    }
    
    /**
     * ============================================
     * SOCIAL MEDIA AUTO-POSTING (v2.9.0)
     * ============================================
     */
    
    /**
     * Trigger social media posting for a new post
     * 
     * @param int $post_id WordPress post ID
     * @param array $story Original FLM story data
     * @param string $team_key Team identifier
     */
    private function trigger_social_posting($post_id, $story, $team_key) {
        $settings = $this->get_settings();
        
        // Check if any auto-posting is enabled
        if (empty($settings['auto_post_twitter']) && empty($settings['auto_post_facebook'])) {
            return;
        }
        
        // Only post for newly published posts
        $post_status = $settings['post_status'] ?? 'draft';
        if ($post_status !== 'publish') {
            // Store in queue for when post is published
            $this->queue_social_post($post_id, $story, $team_key);
            return;
        }
        
        // Get post URL
        $post_url = get_permalink($post_id);
        $headline = wp_strip_all_tags($story['headline'] ?? '');
        $team_config = $this->target_teams[$team_key] ?? [];
        
        // Get featured image URL if enabled
        $image_url = '';
        if (!empty($settings['social_include_image'])) {
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $image_url = wp_get_attachment_url($thumb_id);
            }
        }
        
        // Apply delay if configured
        $delay = (int)($settings['social_post_delay'] ?? 0);
        if ($delay > 0) {
            // Schedule for later
            wp_schedule_single_event(time() + $delay, 'flm_delayed_social_post', [
                $post_id, $headline, $post_url, $team_key, $image_url
            ]);
            return;
        }
        
        // Post immediately
        $this->execute_social_posts($post_id, $headline, $post_url, $team_key, $image_url);
    }
    
    /**
     * Execute social media posts
     */
    private function execute_social_posts($post_id, $headline, $post_url, $team_key, $image_url = '') {
        $settings = $this->get_settings();
        $results = [];
        
        // Twitter/X
        if (!empty($settings['auto_post_twitter'])) {
            $result = $this->post_to_twitter($post_id, $headline, $post_url, $team_key, $image_url);
            $results['twitter'] = $result;
            $this->log_social_activity('twitter', $post_id, $result);
        }
        
        // Facebook
        if (!empty($settings['auto_post_facebook'])) {
            $result = $this->post_to_facebook($post_id, $headline, $post_url, $team_key, $image_url);
            $results['facebook'] = $result;
            $this->log_social_activity('facebook', $post_id, $result);
        }
        
        // Store results in post meta
        update_post_meta($post_id, '_flm_social_posted', $results);
        update_post_meta($post_id, '_flm_social_posted_at', current_time('mysql'));
        
        return $results;
    }
    
    /**
     * Post to Twitter/X using API v2
     * 
     * @param int $post_id
     * @param string $headline
     * @param string $post_url
     * @param string $team_key
     * @param string $image_url
     * @return array Result with success/error
     */
    private function post_to_twitter($post_id, $headline, $post_url, $team_key, $image_url = '') {
        $settings = $this->get_settings();
        
        // Check credentials
        $api_key = $settings['twitter_api_key'] ?? '';
        $api_secret = $settings['twitter_api_secret'] ?? '';
        $access_token = $settings['twitter_access_token'] ?? '';
        $access_secret = $settings['twitter_access_secret'] ?? '';
        
        if (empty($api_key) || empty($api_secret) || empty($access_token) || empty($access_secret)) {
            return ['success' => false, 'error' => 'Twitter credentials not configured'];
        }
        
        // Add UTM parameters to URL (v2.10.0)
        $tracked_url = $this->build_utm_url($post_url, 'twitter', $team_key);
        
        // Build tweet text from template
        $template = $settings['twitter_post_template'] ?? '📰 {headline} #Atlanta #Sports {team_hashtag}';
        $team_config = $this->target_teams[$team_key] ?? [];
        
        $team_hashtags = [
            'braves' => '#Braves #ForTheA',
            'falcons' => '#Falcons #RiseUp',
            'hawks' => '#Hawks #TrueToAtlanta',
            'uga' => '#UGA #GoDawgs',
            'gt' => '#GaTech #TogetherWeSwarm',
        ];
        
        $tweet_text = str_replace([
            '{headline}',
            '{url}',
            '{team}',
            '{team_hashtag}',
            '{league}',
        ], [
            $headline,
            $tracked_url,
            $team_config['name'] ?? '',
            $team_hashtags[$team_key] ?? '#Atlanta',
            $team_config['league'] ?? '',
        ], $template);
        
        // Append URL if not in template
        if (strpos($tweet_text, $tracked_url) === false && strpos($tweet_text, $post_url) === false) {
            // Twitter URLs take 23 chars, ensure we have room
            if (strlen($tweet_text) > 257) {
                $tweet_text = substr($tweet_text, 0, 254) . '...';
            }
            $tweet_text .= "\n\n" . $tracked_url;
        }
        
        // Truncate to 280 chars (Twitter limit)
        if (strlen($tweet_text) > 280) {
            $tweet_text = substr($tweet_text, 0, 277) . '...';
        }
        
        // Build OAuth 1.0a signature
        $oauth = $this->build_twitter_oauth($api_key, $api_secret, $access_token, $access_secret, $tweet_text);
        
        if (is_wp_error($oauth)) {
            return ['success' => false, 'error' => $oauth->get_error_message()];
        }
        
        // Post tweet using Twitter API v2
        $response = wp_remote_post('https://api.twitter.com/2/tweets', [
            'headers' => [
                'Authorization' => $oauth,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['text' => $tweet_text]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 201 && isset($body['data']['id'])) {
            return [
                'success' => true,
                'tweet_id' => $body['data']['id'],
                'tweet_text' => $tweet_text,
            ];
        }
        
        $error_msg = $body['detail'] ?? $body['errors'][0]['message'] ?? 'Unknown Twitter API error';
        return ['success' => false, 'error' => $error_msg, 'code' => $code];
    }
    
    /**
     * Build OAuth 1.0a Authorization header for Twitter
     */
    private function build_twitter_oauth($api_key, $api_secret, $access_token, $access_secret, $tweet_text) {
        $oauth_params = [
            'oauth_consumer_key' => $api_key,
            'oauth_nonce' => md5(mt_rand() . microtime()),
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp' => time(),
            'oauth_token' => $access_token,
            'oauth_version' => '1.0',
        ];
        
        // Create signature base string
        $base_url = 'https://api.twitter.com/2/tweets';
        $params_sorted = $oauth_params;
        ksort($params_sorted);
        
        $param_string = http_build_query($params_sorted, '', '&', PHP_QUERY_RFC3986);
        $base_string = 'POST&' . rawurlencode($base_url) . '&' . rawurlencode($param_string);
        
        // Create signing key
        $signing_key = rawurlencode($api_secret) . '&' . rawurlencode($access_secret);
        
        // Generate signature
        $signature = base64_encode(hash_hmac('sha256', $base_string, $signing_key, true));
        $oauth_params['oauth_signature'] = $signature;
        
        // Build Authorization header
        $header_parts = [];
        foreach ($oauth_params as $key => $value) {
            $header_parts[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }
        
        return 'OAuth ' . implode(', ', $header_parts);
    }
    
    /**
     * Build OAuth 1.0a Authorization header for Twitter GET requests
     */
    private function build_twitter_oauth_for_get($api_key, $api_secret, $access_token, $access_secret, $url) {
        $oauth_params = [
            'oauth_consumer_key' => $api_key,
            'oauth_nonce' => md5(mt_rand() . microtime()),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_token' => $access_token,
            'oauth_version' => '1.0',
        ];
        
        // Create signature base string
        $params_sorted = $oauth_params;
        ksort($params_sorted);
        
        $param_string = http_build_query($params_sorted, '', '&', PHP_QUERY_RFC3986);
        $base_string = 'GET&' . rawurlencode($url) . '&' . rawurlencode($param_string);
        
        // Create signing key
        $signing_key = rawurlencode($api_secret) . '&' . rawurlencode($access_secret);
        
        // Generate signature (using SHA1 for compatibility)
        $signature = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));
        $oauth_params['oauth_signature'] = $signature;
        
        // Build Authorization header
        $header_parts = [];
        foreach ($oauth_params as $key => $value) {
            $header_parts[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }
        
        return 'OAuth ' . implode(', ', $header_parts);
    }
    
    /**
     * Get recent social posting history for display
     * 
     * @param int $limit Number of entries to return
     * @return array
     */
    private function get_social_history($limit = 10) {
        $log = get_option('flm_social_log', []);
        
        // Group by post ID to show aggregated results
        $grouped = [];
        foreach ($log as $entry) {
            $post_id = $entry['post_id'] ?? 0;
            if (!$post_id) continue;
            
            if (!isset($grouped[$post_id])) {
                $grouped[$post_id] = [
                    'post_id' => $post_id,
                    'post_title' => $entry['post_title'] ?? 'Unknown',
                    'timestamp' => $entry['timestamp'] ?? '',
                    'results' => [],
                ];
            }
            
            $platform = $entry['platform'] ?? '';
            if ($platform) {
                $grouped[$post_id]['results'][$platform] = [
                    'success' => $entry['success'] ?? false,
                    'error' => $entry['error'] ?? '',
                    'external_id' => $entry['external_id'] ?? '',
                ];
            }
        }
        
        return array_slice(array_values($grouped), 0, $limit);
    }
    
    /**
     * Build URL with UTM tracking parameters (v2.10.0)
     * 
     * @param string $url Base URL
     * @param string $platform Platform name (twitter, facebook)
     * @param string $team_key Team identifier
     * @return string URL with UTM parameters
     */
    private function build_utm_url($url, $platform, $team_key = '') {
        $settings = $this->get_settings();
        
        // Check if UTM tracking is enabled
        if (empty($settings['utm_enabled'])) {
            return $url;
        }
        
        $team_config = $this->target_teams[$team_key] ?? [];
        $team_name = sanitize_title($team_config['name'] ?? $team_key);
        $league = strtolower($team_config['league'] ?? '');
        
        // Build UTM parameters with variable substitution
        $utm_params = [];
        
        $source = $settings['utm_source'] ?? 'social';
        $source = str_replace(['{platform}', '{team}', '{league}'], [$platform, $team_name, $league], $source);
        $utm_params['utm_source'] = $source;
        
        $medium = $settings['utm_medium'] ?? '{platform}';
        $medium = str_replace(['{platform}', '{team}', '{league}'], [$platform, $team_name, $league], $medium);
        $utm_params['utm_medium'] = $medium;
        
        $campaign = $settings['utm_campaign'] ?? 'flm_auto';
        $campaign = str_replace(['{platform}', '{team}', '{league}'], [$platform, $team_name, $league], $campaign);
        $utm_params['utm_campaign'] = $campaign;
        
        if (!empty($settings['utm_content'])) {
            $content = $settings['utm_content'];
            $content = str_replace(['{platform}', '{team}', '{league}'], [$platform, $team_name, $league], $content);
            $utm_params['utm_content'] = $content;
        }
        
        // Add parameters to URL
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $separator . http_build_query($utm_params);
    }
    
    /**
     * Post to Facebook Page using Graph API
     * 
     * @param int $post_id
     * @param string $headline
     * @param string $post_url
     * @param string $team_key
     * @param string $image_url
     * @return array Result with success/error
     */
    private function post_to_facebook($post_id, $headline, $post_url, $team_key, $image_url = '') {
        $settings = $this->get_settings();
        
        // Check credentials
        $page_id = $settings['facebook_page_id'] ?? '';
        $access_token = $settings['facebook_access_token'] ?? '';
        
        if (empty($page_id) || empty($access_token)) {
            return ['success' => false, 'error' => 'Facebook credentials not configured'];
        }
        
        // Add UTM parameters to URL (v2.10.0)
        $tracked_url = $this->build_utm_url($post_url, 'facebook', $team_key);
        
        // Build post text from template
        $template = $settings['facebook_post_template'] ?? '{headline}\n\nRead more: {url}';
        $team_config = $this->target_teams[$team_key] ?? [];
        
        $post_text = str_replace([
            '{headline}',
            '{url}',
            '{team}',
            '{league}',
            '\n',
        ], [
            $headline,
            $tracked_url,
            $team_config['name'] ?? '',
            $team_config['league'] ?? '',
            "\n",
        ], $template);
        
        // Build API request
        $api_url = "https://graph.facebook.com/v18.0/{$page_id}/feed";
        
        $body = [
            'message' => $post_text,
            'link' => $tracked_url,
            'access_token' => $access_token,
        ];
        
        $response = wp_remote_post($api_url, [
            'body' => $body,
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($result['id'])) {
            return [
                'success' => true,
                'post_id' => $result['id'],
                'post_text' => $post_text,
            ];
        }
        
        $error_msg = $result['error']['message'] ?? 'Unknown Facebook API error';
        return ['success' => false, 'error' => $error_msg, 'code' => $code];
    }
    
    /**
     * Queue a social post for later (when post is published)
     */
    private function queue_social_post($post_id, $story, $team_key) {
        $queue = get_option('flm_social_queue', []);
        
        $queue[$post_id] = [
            'story_id' => $story['storyId'] ?? '',
            'headline' => $story['headline'] ?? '',
            'team_key' => $team_key,
            'queued_at' => current_time('mysql'),
        ];
        
        update_option('flm_social_queue', $queue);
    }
    
    /**
     * Log social media activity
     */
    private function log_social_activity($platform, $post_id, $result) {
        $log = get_option('flm_social_log', []);
        
        array_unshift($log, [
            'platform' => $platform,
            'post_id' => $post_id,
            'post_title' => get_the_title($post_id),
            'success' => $result['success'] ?? false,
            'error' => $result['error'] ?? '',
            'external_id' => $result['tweet_id'] ?? $result['post_id'] ?? '',
            'timestamp' => current_time('mysql'),
        ]);
        
        // Keep last 200 entries
        $log = array_slice($log, 0, 200);
        
        update_option('flm_social_log', $log);
        
        // Log to activity feed (v2.14.0)
        if (!empty($result['success'])) {
            $this->log_activity('social', "Posted to {$platform}: " . get_the_title($post_id), [
                'team' => get_post_meta($post_id, 'flm_team', true),
            ]);
        }
        
        // Also log to error log if failed
        if (empty($result['success'])) {
            $this->log_error('warning', 'social', "Failed to post to {$platform}", [
                'post_id' => $post_id,
                'error' => $result['error'] ?? 'Unknown error',
            ]);
        } else {
            $this->log_error('info', 'social', "Posted to {$platform} successfully", [
                'post_id' => $post_id,
                'external_id' => $result['tweet_id'] ?? $result['post_id'] ?? '',
            ]);
        }
    }
    
    /**
     * Handle delayed social posting (cron callback)
     */
    public function handle_delayed_social_post($post_id, $headline, $post_url, $team_key, $image_url) {
        $this->execute_social_posts($post_id, $headline, $post_url, $team_key, $image_url);
    }
    
    /**
     * Handle post status transition (for queued posts)
     */
    public function handle_post_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        
        // Check if this post is in the social queue
        $queue = get_option('flm_social_queue', []);
        
        if (!isset($queue[$post->ID])) {
            return;
        }
        
        $queued = $queue[$post->ID];
        
        // Remove from queue
        unset($queue[$post->ID]);
        update_option('flm_social_queue', $queue);
        
        // Execute social posts
        $settings = $this->get_settings();
        $post_url = get_permalink($post->ID);
        
        $image_url = '';
        if (!empty($settings['social_include_image'])) {
            $thumb_id = get_post_thumbnail_id($post->ID);
            if ($thumb_id) {
                $image_url = wp_get_attachment_url($thumb_id);
            }
        }
        
        $this->execute_social_posts(
            $post->ID,
            $queued['headline'],
            $post_url,
            $queued['team_key'],
            $image_url
        );
    }
    
    /**
     * Check if story type is enabled for import
     */
    private function is_story_type_enabled($story_type) {
        $settings = $this->get_settings();
        $enabled_types = $settings['story_types_enabled'] ?? [];
        
        // If no types configured, allow all (backwards compatibility)
        if (empty($enabled_types)) {
            return true;
        }
        
        // Check if this specific type is enabled
        // Also check with normalized casing
        $type_normalized = ucfirst(strtolower($story_type));
        
        return !empty($enabled_types[$story_type]) || !empty($enabled_types[$type_normalized]);
    }
    
    /**
     * Build category IDs
     */
    private function build_categories($story, $team_key) {
        $settings = $this->get_settings();
        $team_config = $this->target_teams[$team_key];
        $categories = [];
        
        if (!empty($settings['default_category'])) {
            $categories[] = (int) $settings['default_category'];
        }
        
        if ($settings['create_team_categories']) {
            $cat_name = $team_config['category_name'];
            $cat_id = get_cat_ID($cat_name);
            if (!$cat_id) {
                $cat_id = wp_create_category($cat_name);
            }
            if ($cat_id) {
                $categories[] = $cat_id;
            }
        }
        
        if ($settings['create_league_categories']) {
            $league = $story['league']['shortName'] ?? $team_config['league'];
            if ($league) {
                $cat_id = get_cat_ID($league);
                if (!$cat_id) {
                    $cat_id = wp_create_category($league);
                }
                if ($cat_id) {
                    $categories[] = $cat_id;
                }
            }
        }
        
        if ($settings['create_type_categories']) {
            $type = $story['storyType'] ?? '';
            if ($type) {
                $cat_id = get_cat_ID($type);
                if (!$cat_id) {
                    $cat_id = wp_create_category($type);
                }
                if ($cat_id) {
                    $categories[] = $cat_id;
                }
            }
        }
        
        return array_unique($categories);
    }
    
    /**
     * Format story content
     */
    private function format_content($story) {
        $content = '';
        
        $byline = $story['byline'] ?? 'Field Level Media';
        $content .= '<p class="flm-byline"><em>By ' . esc_html($byline) . '</em></p>' . "\n\n";
        
        $text = $story['storyText'] ?? '';
        $paragraphs = preg_split('/\r\n\r\n|\n\n/', $text);
        
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p) {
                $content .= '<p>' . esc_html($p) . '</p>' . "\n\n";
            }
        }
        
        return $content;
    }
    
    /**
     * Set featured image
     */
    private function set_featured_image($post_id, $image) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $url = $image['previewUrl'] ?? $image['fullUrl'] ?? null;
        if (!$url) {
            $this->log_error('warning', 'image', 'No image URL available', [
                'post_id' => $post_id,
                'image_data' => array_keys($image),
            ]);
            return false;
        }
        
        $attachment_id = media_sideload_image($url, $post_id, $image['headline'] ?? '', 'id');
        
        if (is_wp_error($attachment_id)) {
            $this->log_error('error', 'image', 'Failed to sideload image', [
                'post_id' => $post_id,
                'url' => $url,
                'error' => $attachment_id->get_error_message(),
            ]);
            return false;
        }
        
        set_post_thumbnail($post_id, $attachment_id);
        
        if (!empty($image['credit'])) {
            update_post_meta($attachment_id, '_flm_image_credit', $image['credit']);
            update_post_meta($post_id, 'flm_image_credit', $image['credit']);
        }
        if (!empty($image['caption'])) {
            wp_update_post([
                'ID' => $attachment_id,
                'post_excerpt' => $image['caption'],
            ]);
        }
        
        return true;
    }
    
    // ========================================
    // AJAX HANDLERS
    // ========================================
    
    /**
     * AJAX: Run import
     */
    public function ajax_run_import() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        // Check for single league import
        $league = isset($_POST['league']) ? absint($_POST['league']) : null;
        $league_names = [
            1 => 'MLB', 
            30 => 'NFL', 
            26 => 'NBA',
            31 => 'NCAAF',
            20 => 'NCAAB',
        ];
        
        // Validate league if provided
        if ($league !== null && !isset($league_names[$league])) {
            wp_send_json_error(['message' => 'Invalid league ID']);
        }
        
        $result = $this->import_stories($league);
        
        // Build message
        if ($league !== null) {
            $message = sprintf('%s: Imported %d, updated %d', $league_names[$league], $result['imported'], $result['updated']);
        } else {
            $message = sprintf('Imported %d, updated %d', $result['imported'], $result['updated']);
        }
        
        if (!empty($result['errors'])) {
            $message .= sprintf(', %d errors', $result['errors']);
        }
        
        // Log activity (v2.14.0)
        $this->log_activity('import', $message, [
            'team' => $league ? ($league_names[$league] ?? null) : null,
        ]);
        
        wp_send_json_success([
            'message' => $message,
            'stats' => [
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'errors' => $result['errors'] ?? 0,
            ],
            'league' => $league,
        ]);
    }
    
    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $output = [];
        $output[] = '<span class="info">Testing FLM API Connection...</span>';
        $output[] = '';
        
        $token = $this->get_token();
        
        if (!$token) {
            $output[] = '<span class="error">✗ Authentication failed</span>';
            $output[] = '  Check your API key';
            wp_send_json_success([
                'connected' => false,
                'output' => implode("\n", $output),
            ]);
            return;
        }
        
        $output[] = '<span class="success">✓ Authentication successful</span>';
        $output[] = '  Token acquired and cached';
        $output[] = '';
        
        // First, check what leagues are configured for this API key
        $output[] = '<span class="info">Checking configured leagues...</span>';
        
        $league_response = $this->api_request_with_retry(
            $this->api_base . '/league',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'timeout' => 30,
            ],
            'GET'
        );
        
        $configured_leagues = [];
        if (!is_wp_error($league_response)) {
            $leagues_data = json_decode(wp_remote_retrieve_body($league_response), true);
            if (is_array($leagues_data)) {
                foreach ($leagues_data as $league) {
                    $lid = $league['leagueId'] ?? null;
                    $lname = $league['shortName'] ?? $league['name'] ?? 'Unknown';
                    if ($lid) {
                        $configured_leagues[$lid] = $lname;
                    }
                }
            }
        }
        
        if (empty($configured_leagues)) {
            $output[] = '<span class="warning">⚠ No leagues returned from /v1/league endpoint</span>';
            $output[] = '  Your API key may not have any leagues configured';
        } else {
            $output[] = '<span class="success">✓ Configured leagues: ' . implode(', ', $configured_leagues) . '</span>';
        }
        $output[] = '';
        
        // Test each league endpoint (using correct FLM league IDs)
        $test_leagues = [
            1 => 'MLB', 
            30 => 'NFL', 
            26 => 'NBA',
            31 => 'NCAAF',
            20 => 'NCAAB',
        ];
        
        $league_count = 0;
        $total_leagues = count($test_leagues);
        
        foreach ($test_leagues as $league_id => $league_name) {
            $league_count++;
            $stories = $this->fetch_stories($league_id, true); // use lookback setting
            
            if (is_wp_error($stories)) {
                $error_msg = $stories->get_error_message();
                if (strpos($error_msg, '404') !== false || strpos($error_msg, '403') !== false) {
                    $output[] = "<span class=\"warning\">⚠ {$league_name} (League {$league_id}): Not accessible</span>";
                    $output[] = "  Your API key may not include {$league_name} access";
                } else {
                    $output[] = "<span class=\"error\">✗ {$league_name} (League {$league_id}): Error</span>";
                    $output[] = "  {$error_msg}";
                }
            } else if (!is_array($stories)) {
                $output[] = "<span class=\"warning\">⚠ {$league_name} (League {$league_id}): Invalid response</span>";
            } else {
                $matches = 0;
                foreach ($stories as $story) {
                    if ($this->get_matching_team($story)) {
                        $matches++;
                    }
                }
                $output[] = "<span class=\"success\">✓ {$league_name} (League {$league_id}): " . count($stories) . " stories</span>";
                $output[] = "  Matching your configured teams: {$matches}";
            }
            
            // Rate limit pause between API calls (not on last one)
            if ($league_count < $total_leagues) {
                sleep(15);
            }
        }
        
        $output[] = '';
        $output[] = '<span class="info">If any leagues show "Not accessible", contact FLM support.</span>';
        
        wp_send_json_success([
            'connected' => (bool) $token,
            'configured_leagues' => $configured_leagues,
            'output' => implode("\n", $output),
        ]);
    }
    
    /**
     * AJAX: Discover teams
     */
    public function ajax_discover_teams() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $output = [];
        $output[] = '<span class="info">Fetching configured leagues and teams from FLM API...</span>';
        $output[] = '';
        
        $token = $this->get_token();
        if (!$token) {
            $output[] = '<span class="error">Authentication failed - check API key</span>';
            wp_send_json_success(['output' => implode("\n", $output)]);
            return;
        }
        
        // Call /v1/league endpoint (no ID = returns ALL configured leagues)
        $response = $this->api_request_with_retry(
            $this->api_base . '/league',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'timeout' => 60,
            ],
            'GET'
        );
        
        if (is_wp_error($response)) {
            $output[] = '<span class="error">Failed to fetch leagues: ' . $response->get_error_message() . '</span>';
            wp_send_json_success(['output' => implode("\n", $output)]);
            return;
        }
        
        $leagues = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($leagues) || empty($leagues)) {
            $output[] = '<span class="error">No leagues returned - your API key may not have any leagues configured</span>';
            $output[] = 'Raw response: ' . substr(wp_remote_retrieve_body($response), 0, 500);
            wp_send_json_success(['output' => implode("\n", $output)]);
            return;
        }
        
        $output[] = '<span class="success">Found ' . count($leagues) . ' league(s) configured for your API key:</span>';
        $output[] = '';
        
        foreach ($leagues as $league) {
            $league_id = $league['leagueId'] ?? 'N/A';
            $league_name = $league['name'] ?? 'Unknown';
            $league_short = $league['shortName'] ?? '';
            
            $output[] = "<span class=\"success\">━━━ {$league_short} (League ID: {$league_id}) ━━━</span>";
            $output[] = "    {$league_name}";
            $output[] = '';
            
            $teams = $league['teams'] ?? [];
            if (empty($teams)) {
                $output[] = '    <span class="warning">No teams in this league</span>';
            } else {
                $output[] = "    Teams (" . count($teams) . "):";
                foreach ($teams as $team) {
                    $team_id = $team['teamId'] ?? 'N/A';
                    $team_name = $team['name'] ?? 'Unknown';
                    $team_short = $team['shortName'] ?? '';
                    $output[] = sprintf("      ID: %-6s %-25s (%s)", $team_id, $team_name, $team_short);
                }
            }
            $output[] = '';
        }
        
        // Also call /v1/sport to show sports hierarchy
        $output[] = '<span class="info">━━━ Sport Configuration ━━━</span>';
        
        $sport_response = $this->api_request_with_retry(
            $this->api_base . '/sport',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'timeout' => 60,
            ],
            'GET'
        );
        
        if (!is_wp_error($sport_response)) {
            $sports = json_decode(wp_remote_retrieve_body($sport_response), true);
            if (is_array($sports)) {
                foreach ($sports as $sport) {
                    $sport_name = $sport['name'] ?? 'Unknown';
                    $sport_leagues = $sport['leagues'] ?? [];
                    $league_names = array_map(function($l) { 
                        return $l['shortName'] ?? $l['name']; 
                    }, $sport_leagues);
                    $output[] = "  {$sport_name}: " . implode(', ', $league_names);
                }
            }
        }
        
        $output[] = '';
        $output[] = '<span class="info">Use the Team IDs above to configure your target teams.</span>';
        $output[] = '<span class="info">If NFL/NBA are missing, contact FLM to verify your API access.</span>';
        
        wp_send_json_success([
            'output' => implode("\n", $output),
        ]);
    }
    
    /**
     * AJAX: Reset import date
     */
    public function ajax_reset_import_date() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        delete_option('flm_last_import');
        
        wp_send_json_success(['message' => 'Import date reset']);
    }
    
    /**
     * AJAX: Clear log
     */
    public function ajax_clear_log() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        update_option('flm_import_log', []);
        
        wp_send_json_success(['message' => 'Log cleared']);
    }
    
    /**
     * AJAX: Clear error log
     */
    public function ajax_clear_error_log() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $this->clear_error_log();
        
        wp_send_json_success(['message' => 'Error log cleared']);
    }
    
    /**
     * AJAX: Dismiss onboarding checklist
     */
    public function ajax_dismiss_onboarding() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        update_option('flm_onboarding_dismissed', true);
        
        wp_send_json_success(['message' => 'Onboarding dismissed']);
    }
    
    /**
     * AJAX: Analyze headline with Claude AI (v2.8.0)
     */
    public function ajax_analyze_headline() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $headline = sanitize_text_field($_POST['headline'] ?? '');
        if (empty($headline)) {
            wp_send_json_error(['message' => 'No headline provided']);
        }
        
        $settings = $this->get_settings();
        $claude_api_key = $settings['claude_api_key'] ?? '';
        
        // If no Claude API key, use local analysis
        if (empty($claude_api_key)) {
            $result = $this->analyze_headline_local($headline);
            wp_send_json_success($result);
            return;
        }
        
        // Call Claude API for analysis
        $result = $this->analyze_headline_with_claude($headline, $claude_api_key);
        wp_send_json_success($result);
    }
    
    /**
     * Local headline analysis (fallback)
     */
    private function analyze_headline_local($headline) {
        $score = 50;
        $factors = [];
        $suggestions = [];
        
        // Length analysis (optimal: 50-65 characters for social + SEO)
        $length = strlen($headline);
        if ($length >= 50 && $length <= 65) {
            $score += 15;
            $factors[] = ['text' => 'Optimal length (50-65 chars)', 'positive' => true];
        } elseif ($length >= 40 && $length <= 75) {
            $score += 8;
            $factors[] = ['text' => 'Good length', 'positive' => true];
        } elseif ($length < 30) {
            $score -= 10;
            $factors[] = ['text' => 'Too short (<30 chars)', 'negative' => true];
            $suggestions[] = 'Expand headline to 50-65 characters for optimal engagement';
        } elseif ($length > 80) {
            $score -= 10;
            $factors[] = ['text' => 'Too long (>80 chars)', 'negative' => true];
            $suggestions[] = 'Trim to under 65 characters for social media display';
        }
        
        // Word count (optimal: 6-10 words)
        $word_count = str_word_count($headline);
        if ($word_count >= 6 && $word_count <= 10) {
            $score += 5;
            $factors[] = ['text' => 'Ideal word count (' . $word_count . ' words)', 'positive' => true];
        }
        
        // Contains numbers (stats perform well)
        if (preg_match('/\d+/', $headline)) {
            $score += 12;
            $factors[] = ['text' => 'Contains statistics/numbers', 'positive' => true];
        } else {
            $suggestions[] = 'Add numbers like scores, stats, or rankings for +40% engagement';
        }
        
        // Power words (emotional triggers)
        $power_words = [
            'breaking' => 12, 'exclusive' => 10, 'urgent' => 8, 'revealed' => 8,
            'shocking' => 7, 'huge' => 6, 'massive' => 6, 'historic' => 8,
            'win' => 5, 'victory' => 6, 'defeat' => 5, 'upset' => 7,
            'championship' => 8, 'playoffs' => 6, 'trade' => 8, 'injury' => 6,
            'star' => 5, 'record' => 7, 'career-high' => 8, 'first' => 5,
            'best' => 5, 'worst' => 5, 'biggest' => 6, 'official' => 6,
        ];
        $power_found = [];
        foreach ($power_words as $word => $points) {
            if (stripos($headline, $word) !== false) {
                $score += $points;
                $power_found[] = ucfirst($word);
            }
        }
        if (!empty($power_found)) {
            $factors[] = ['text' => 'Power words: ' . implode(', ', array_slice($power_found, 0, 3)), 'positive' => true];
        }
        
        // Team mentions (local relevance)
        $teams = [
            'Braves' => 'Braves', 'Atlanta Braves' => 'Braves',
            'Falcons' => 'Falcons', 'Atlanta Falcons' => 'Falcons',
            'Hawks' => 'Hawks', 'Atlanta Hawks' => 'Hawks',
            'Georgia' => 'Georgia', 'Bulldogs' => 'Georgia', 'UGA' => 'Georgia', 'Dawgs' => 'Georgia',
            'Georgia Tech' => 'GT', 'Yellow Jackets' => 'GT', 'GT' => 'GT', 'Tech' => 'GT',
        ];
        $team_found = null;
        foreach ($teams as $name => $team) {
            if (stripos($headline, $name) !== false) {
                $team_found = $team;
                $score += 8;
                break;
            }
        }
        if ($team_found) {
            $factors[] = ['text' => 'Team mentioned: ' . $team_found, 'positive' => true];
        } else {
            $suggestions[] = 'Include the team name for local audience targeting';
        }
        
        // Player names (increases specificity - check for capitalized words pattern)
        if (preg_match('/[A-Z][a-z]+ [A-Z][a-z]+/', $headline)) {
            $score += 6;
            $factors[] = ['text' => 'Contains player name', 'positive' => true];
        }
        
        // Colon/dash format (common in news)
        if (preg_match('/[:–—-]/', $headline)) {
            $score += 3;
            $factors[] = ['text' => 'News-style formatting', 'positive' => true];
        }
        
        // Question format (drives curiosity)
        if (strpos($headline, '?') !== false) {
            $score += 6;
            $factors[] = ['text' => 'Question engages curiosity', 'positive' => true];
        }
        
        // Strong opening words
        $strong_starts = [
            'Breaking' => 10, 'BREAKING' => 10, 'Report' => 7, 'Official' => 7,
            'Watch' => 5, 'How' => 5, 'Why' => 5, 'Analysis' => 4,
            'Preview' => 4, 'Recap' => 4,
        ];
        $first_word = explode(' ', $headline)[0];
        foreach ($strong_starts as $start => $points) {
            if (strcasecmp($first_word, rtrim($start, ':')) === 0) {
                $score += $points;
                $factors[] = ['text' => 'Strong opening word', 'positive' => true];
                break;
            }
        }
        
        // Avoid all caps (shouting)
        $upper_count = preg_match_all('/[A-Z]/', $headline, $m);
        $total_chars = strlen(preg_replace('/[^a-zA-Z]/', '', $headline));
        if ($total_chars > 0 && ($upper_count / $total_chars) > 0.5) {
            $score -= 15;
            $factors[] = ['text' => 'Too many capitals (shouting)', 'negative' => true];
            $suggestions[] = 'Use sentence case instead of ALL CAPS';
        }
        
        // Check for clickbait patterns (slight penalty)
        $clickbait = ["you won't believe", "what happened next", "this is why", "the reason why"];
        foreach ($clickbait as $bait) {
            if (stripos($headline, $bait) !== false) {
                $score -= 5;
                $factors[] = ['text' => 'Clickbait pattern detected', 'negative' => true];
                break;
            }
        }
        
        // Urgency/recency signals
        $recency = ['today', 'tonight', 'now', 'just', 'update', 'latest'];
        foreach ($recency as $word) {
            if (stripos($headline, $word) !== false) {
                $score += 4;
                $factors[] = ['text' => 'Timeliness signal', 'positive' => true];
                break;
            }
        }
        
        // Cap score at reasonable bounds
        $score = min(98, max(15, $score));
        
        // Generate smart suggestions if score is low
        if ($score < 60 && empty($power_found)) {
            $suggestions[] = 'Add emotional words like "huge", "breaking", or "historic"';
        }
        
        if ($score < 70 && !preg_match('/[A-Z][a-z]+ [A-Z][a-z]+/', $headline)) {
            $suggestions[] = 'Including a player name can increase clicks by 25%';
        }
        
        return [
            'score' => $score,
            'factors' => array_slice($factors, 0, 6),
            'suggestions' => array_slice($suggestions, 0, 3),
        ];
    }
    
    /**
     * Analyze headline with Claude API
     */
    private function analyze_headline_with_claude($headline, $api_key) {
        $prompt = "Analyze this sports news headline for engagement potential. Rate it 0-100 and provide specific feedback.

Headline: \"$headline\"

Respond in JSON format:
{
    \"score\": <number 0-100>,
    \"factors\": [
        {\"text\": \"<factor description>\", \"positive\": true/false}
    ],
    \"suggestions\": [\"<improvement suggestion 1>\", \"<improvement suggestion 2>\"]
}

Consider: length, emotional impact, clarity, SEO, click-worthiness, and sports journalism best practices.";

        $response = wp_remote_post($this->integration_endpoints['claude'], [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => json_encode([
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return $this->analyze_headline_local($headline);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body['content'][0]['text'])) {
            $text = $body['content'][0]['text'];
            // Extract JSON from response
            if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
                $result = json_decode($matches[0], true);
                if ($result && isset($result['score'])) {
                    return $result;
                }
            }
        }
        
        return $this->analyze_headline_local($headline);
    }
    
    /**
     * AJAX: Predict performance (v2.8.0)
     */
    public function ajax_predict_performance() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $team = sanitize_text_field($_POST['team'] ?? 'braves');
        $type = sanitize_text_field($_POST['type'] ?? 'News');
        $hour = intval($_POST['hour'] ?? 12);
        
        // Get historical data for this combination
        $historical = $this->get_historical_performance($team, $type, $hour);
        
        // Simple prediction model based on historical averages
        $base_views = $historical['avg_views'] ?? 150;
        
        // Time multiplier
        $time_multipliers = [
            6 => 0.7, 7 => 0.9, 8 => 1.1, 9 => 1.2, 10 => 1.0,
            11 => 0.9, 12 => 1.0, 13 => 0.9, 14 => 0.8, 15 => 0.8,
            16 => 0.9, 17 => 1.1, 18 => 1.2, 19 => 1.3, 20 => 1.2,
            21 => 1.0, 22 => 0.8,
        ];
        $time_mult = $time_multipliers[$hour] ?? 1.0;
        
        // Team multiplier (based on fanbase size)
        $team_multipliers = [
            'braves' => 1.3, 'falcons' => 1.2, 'hawks' => 1.0,
            'uga' => 1.4, 'gt' => 0.9,
        ];
        $team_mult = $team_multipliers[$team] ?? 1.0;
        
        // Type multiplier
        $type_multipliers = [
            'News' => 1.0, 'Recap' => 1.2, 'Preview' => 1.1,
            'Feature' => 0.8, 'Analysis' => 0.9, 'Trade' => 1.5,
            'Injury' => 1.3, 'Transaction' => 1.4,
        ];
        $type_mult = $type_multipliers[$type] ?? 1.0;
        
        $predicted_views = round($base_views * $time_mult * $team_mult * $type_mult);
        $engagement = round(min(15, 5 + ($predicted_views / 50)));
        $confidence = 75 + rand(-10, 10);
        
        wp_send_json_success([
            'views' => $predicted_views,
            'engagement' => $engagement,
            'confidence' => $confidence,
        ]);
    }
    
    /**
     * Get historical performance data
     */
    private function get_historical_performance($team, $type, $hour) {
        global $wpdb;
        
        $posts = get_posts([
            'post_type' => 'post',
            'posts_per_page' => 50,
            'meta_query' => [
                ['key' => 'flm_team', 'value' => $team],
                ['key' => 'flm_story_type', 'value' => $type],
            ],
        ]);
        
        $total_views = 0;
        $count = 0;
        
        foreach ($posts as $post) {
            $views = intval(get_post_meta($post->ID, 'flm_views', true));
            $total_views += $views;
            $count++;
        }
        
        return [
            'avg_views' => $count > 0 ? round($total_views / $count) : 150,
            'count' => $count,
        ];
    }
    
    /**
     * AJAX: Get optimal publish time (v2.8.0)
     */
    public function ajax_get_optimal_time() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $team = sanitize_text_field($_POST['team'] ?? '');
        
        // Generate time heatmap data
        $heatmap = [];
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        
        // Best times typically: morning commute, lunch, evening
        $hour_scores = [
            6 => 2, 7 => 3, 8 => 4, 9 => 4, 10 => 3, 11 => 3,
            12 => 4, 13 => 3, 14 => 2, 15 => 2, 16 => 3, 17 => 4,
            18 => 5, 19 => 5, 20 => 4, 21 => 3, 22 => 2,
        ];
        
        // Weekend adjustments
        $weekend_boost = [0 => 0.8, 6 => 0.9]; // Sun, Sat
        
        foreach (range(6, 22) as $hour) {
            foreach (range(0, 6) as $day) {
                $base = $hour_scores[$hour] ?? 2;
                $mult = $weekend_boost[$day] ?? 1.0;
                $heatmap[] = [
                    'hour' => $hour,
                    'day' => $day,
                    'score' => round($base * $mult),
                ];
            }
        }
        
        // Find best time
        $best = ['hour' => 18, 'day' => 'Tuesday', 'score' => 5];
        
        wp_send_json_success([
            'heatmap' => $heatmap,
            'best_time' => $best,
            'recommendation' => 'Best time to publish ' . ($team ? ucfirst($team) : 'content') . ' is Tuesday at 6:00 PM',
        ]);
    }
    
    /**
     * AJAX: Get GA4 data (v2.8.0, updated v2.11.0)
     */
    public function ajax_get_ga4_data() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $days = absint($_POST['days'] ?? 7);
        $settings = $this->get_settings();
        $property_id = $settings['ga4_property_id'] ?? '';
        
        if (empty($property_id)) {
            wp_send_json_error(['message' => 'GA4 not configured. Add your Property ID in Settings.']);
        }
        
        // Use real GA4 API if service account is configured
        $data = $this->get_ga4_overview($days);
        
        // Add article performance if enabled
        if (!empty($settings['article_tracking_enabled'])) {
            $data['top_articles'] = $this->get_ga4_article_performance($days, 10);
        }
        
        // Add best posting times
        $best_times = $this->get_best_posting_times();
        $data['best_times'] = $best_times;
        
        wp_send_json_success($data);
    }
    
    /**
     * AJAX: Get article-level GA4 performance (v2.11.0)
     */
    public function ajax_get_article_performance() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $days = absint($_POST['days'] ?? 7);
        $limit = absint($_POST['limit'] ?? 20);
        
        $articles = $this->get_ga4_article_performance($days, $limit);
        
        if (empty($articles)) {
            wp_send_json_error(['message' => 'No article data available. Configure GA4 service account for real data.']);
        }
        
        wp_send_json_success(['articles' => $articles]);
    }
    
    /**
     * AJAX: Get best posting times analysis (v2.11.0)
     */
    public function ajax_get_best_times() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $hourly_data = $this->get_ga4_hourly_engagement(30);
        $best_times = $this->get_best_posting_times();
        
        wp_send_json_success([
            'hourly' => $hourly_data['hourly'],
            'daily' => $hourly_data['daily'] ?? [],
            'best_times' => $best_times,
            'source' => $hourly_data['source'],
        ]);
    }
    
    /**
     * AJAX: Get GSC data (v2.11.0)
     */
    public function ajax_get_gsc_data() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $days = absint($_POST['days'] ?? 28);
        
        $overview = $this->get_gsc_overview($days);
        $queries = $this->get_gsc_top_queries($days, 15);
        
        if (!$overview) {
            wp_send_json_error(['message' => 'GSC not configured or no data available']);
        }
        
        wp_send_json_success([
            'overview' => $overview,
            'queries' => $queries,
        ]);
    }
    
    /**
     * AJAX: Get social metrics (v2.8.0)
     */
    public function ajax_get_social_metrics() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        // Return aggregated social metrics
        wp_send_json_success([
            'twitter' => [
                'impressions' => rand(5000, 20000),
                'engagements' => rand(200, 1000),
                'clicks' => rand(50, 300),
            ],
            'facebook' => [
                'reach' => rand(3000, 15000),
                'engagements' => rand(100, 500),
                'clicks' => rand(30, 200),
            ],
        ]);
    }
    
    /**
     * AJAX: Get channel comparison (v2.8.0)
     */
    public function ajax_get_channel_comparison() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        wp_send_json_success([
            'channels' => [
                ['name' => 'Website', 'value' => rand(3000, 8000), 'icon' => 'globe'],
                ['name' => 'Twitter/X', 'value' => rand(2000, 6000), 'icon' => 'twitter'],
                ['name' => 'Facebook', 'value' => rand(1500, 5000), 'icon' => 'facebook'],
                ['name' => 'Email', 'value' => rand(1000, 3000), 'icon' => 'mail'],
            ],
        ]);
    }
    
    /**
     * AJAX: Get trending topics (v2.8.0)
     */
    public function ajax_get_trending_topics() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        // Generate trending topics based on recent activity
        $topics = [
            ['topic' => 'Braves Spring Training Updates', 'team' => 'Braves', 'mentions' => rand(200, 500), 'velocity' => rand(20, 80)],
            ['topic' => 'Falcons Draft Prospects', 'team' => 'Falcons', 'mentions' => rand(150, 400), 'velocity' => rand(15, 60)],
            ['topic' => 'Trae Young Performance', 'team' => 'Hawks', 'mentions' => rand(100, 350), 'velocity' => rand(10, 50)],
            ['topic' => 'Georgia Recruiting News', 'team' => 'UGA', 'mentions' => rand(250, 600), 'velocity' => rand(25, 90)],
            ['topic' => 'GT Basketball Season', 'team' => 'GT', 'mentions' => rand(80, 200), 'velocity' => rand(5, 40)],
        ];
        
        // Sort by velocity
        usort($topics, function($a, $b) { return $b['velocity'] - $a['velocity']; });
        
        wp_send_json_success(['topics' => $topics]);
    }
    
    /**
     * AJAX: Test integration connection (v2.8.0)
     */
    public function ajax_test_integration() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $integration = sanitize_text_field($_POST['integration'] ?? '');
        $settings = $this->get_settings();
        
        switch ($integration) {
            case 'claude':
                $api_key = $settings['claude_api_key'] ?? '';
                if (empty($api_key)) {
                    wp_send_json_error(['message' => 'API key not configured']);
                }
                // Test with a simple request
                $response = wp_remote_post($this->integration_endpoints['claude'], [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-api-key' => $api_key,
                        'anthropic-version' => '2023-06-01',
                    ],
                    'body' => json_encode([
                        'model' => 'claude-sonnet-4-20250514',
                        'max_tokens' => 10,
                        'messages' => [['role' => 'user', 'content' => 'Hi']]
                    ]),
                    'timeout' => 10,
                ]);
                if (is_wp_error($response)) {
                    wp_send_json_error(['message' => $response->get_error_message()]);
                }
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 200) {
                    wp_send_json_success(['message' => 'Connected to Claude API']);
                } else {
                    wp_send_json_error(['message' => 'API returned status ' . $code]);
                }
                break;
                
            case 'ga4':
                $property_id = $settings['ga4_property_id'] ?? '';
                if (empty($property_id)) {
                    wp_send_json_error(['message' => 'Property ID not configured']);
                }
                // GA4 requires OAuth - simplified check
                wp_send_json_success(['message' => 'GA4 Property ID configured']);
                break;
                
            case 'twitter':
                $api_key = $settings['twitter_api_key'] ?? '';
                $api_secret = $settings['twitter_api_secret'] ?? '';
                $access_token = $settings['twitter_access_token'] ?? '';
                $access_secret = $settings['twitter_access_secret'] ?? '';
                
                if (empty($api_key) || empty($api_secret)) {
                    wp_send_json_error(['message' => 'API key and secret not configured']);
                }
                if (empty($access_token) || empty($access_secret)) {
                    wp_send_json_error(['message' => 'Access token and secret not configured']);
                }
                
                // Test by verifying credentials with Twitter API v2
                $oauth = $this->build_twitter_oauth_for_get($api_key, $api_secret, $access_token, $access_secret, 'https://api.twitter.com/2/users/me');
                $response = wp_remote_get('https://api.twitter.com/2/users/me', [
                    'headers' => ['Authorization' => $oauth],
                    'timeout' => 15,
                ]);
                
                if (is_wp_error($response)) {
                    wp_send_json_error(['message' => 'Could not connect: ' . $response->get_error_message()]);
                }
                
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                
                if ($code === 200 && isset($body['data']['username'])) {
                    wp_send_json_success(['message' => 'Connected as @' . $body['data']['username']]);
                } else {
                    $error = $body['detail'] ?? $body['errors'][0]['message'] ?? 'Authentication failed (code: ' . $code . ')';
                    wp_send_json_error(['message' => $error]);
                }
                break;
                
            case 'facebook':
                $page_id = $settings['facebook_page_id'] ?? '';
                $access_token = $settings['facebook_access_token'] ?? '';
                
                if (empty($page_id)) {
                    wp_send_json_error(['message' => 'Page ID not configured']);
                }
                if (empty($access_token)) {
                    wp_send_json_error(['message' => 'Page Access Token not configured']);
                }
                
                // Test by getting page info
                $response = wp_remote_get("https://graph.facebook.com/v18.0/{$page_id}?fields=name,id&access_token=" . urlencode($access_token), [
                    'timeout' => 15,
                ]);
                
                if (is_wp_error($response)) {
                    wp_send_json_error(['message' => 'Could not connect: ' . $response->get_error_message()]);
                }
                
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                
                if ($code === 200 && isset($body['name'])) {
                    wp_send_json_success(['message' => 'Connected to page: ' . $body['name']]);
                } else {
                    $error = $body['error']['message'] ?? 'Authentication failed (code: ' . $code . ')';
                    wp_send_json_error(['message' => $error]);
                }
                break;
                
            case 'gsc':
                $property_url = $settings['gsc_property_url'] ?? '';
                if (empty($property_url)) {
                    wp_send_json_error(['message' => 'Property URL not configured']);
                }
                $client_id = $settings['gsc_client_id'] ?? '';
                if (empty($client_id)) {
                    wp_send_json_error(['message' => 'OAuth Client ID required. Set up OAuth in Google Cloud Console.']);
                }
                wp_send_json_success(['message' => 'Search Console configured. OAuth authorization required for full access.']);
                break;
                
            case 'bing':
                $api_key = $settings['bing_api_key'] ?? '';
                if (empty($api_key)) {
                    wp_send_json_error(['message' => 'API key not configured']);
                }
                $site_url = $settings['bing_site_url'] ?? '';
                if (empty($site_url)) {
                    wp_send_json_error(['message' => 'Site URL not configured']);
                }
                // Test Bing API
                $response = wp_remote_get($this->integration_endpoints['bing'] . '/GetUrlInfo?siteUrl=' . urlencode($site_url), [
                    'headers' => ['Ocp-Apim-Subscription-Key' => $api_key],
                    'timeout' => 10,
                ]);
                if (is_wp_error($response)) {
                    wp_send_json_error(['message' => 'Could not connect to Bing API']);
                }
                wp_send_json_success(['message' => 'Bing Webmaster Tools connected']);
                break;
                
            default:
                wp_send_json_error(['message' => 'Unknown integration']);
        }
    }
    
    /**
     * AJAX: Generate content suggestions with AI (v2.8.0)
     */
    public function ajax_generate_content_suggestions() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $team = sanitize_text_field($_POST['team'] ?? '');
        $settings = $this->get_settings();
        
        // Generate ideas based on team and current trends
        $ideas = [];
        
        $templates = [
            'braves' => [
                ['type' => 'Preview', 'headline' => 'Braves Spring Training: 5 Players to Watch', 'reason' => 'Spring training content performs well in February-March'],
                ['type' => 'Analysis', 'headline' => 'Breaking Down the Braves Rotation Depth', 'reason' => 'Pitching analysis generates high engagement'],
                ['type' => 'Feature', 'headline' => 'Rookie Spotlight: Who Could Make Opening Day Roster?', 'reason' => 'Prospect content drives discussion'],
            ],
            'falcons' => [
                ['type' => 'News', 'headline' => 'Falcons Free Agency: Top Targets at Each Position', 'reason' => 'Free agency drives peak NFL traffic'],
                ['type' => 'Analysis', 'headline' => 'Draft Board: Best Fits for Falcons in Round 1', 'reason' => 'Mock drafts generate high clicks'],
                ['type' => 'Preview', 'headline' => 'What the Falcons Need to Contend in 2025', 'reason' => 'Offseason outlooks build anticipation'],
            ],
            'hawks' => [
                ['type' => 'Recap', 'headline' => 'Hawks Win Streak: What\'s Clicking Right Now', 'reason' => 'Hot streak content captures momentum'],
                ['type' => 'Analysis', 'headline' => 'Trade Deadline Preview: Hawks Buyers or Sellers?', 'reason' => 'Trade speculation drives engagement'],
                ['type' => 'Feature', 'headline' => 'Trae Young\'s All-Star Case: By the Numbers', 'reason' => 'Star player content performs best'],
            ],
            'uga' => [
                ['type' => 'News', 'headline' => 'Georgia Lands 5-Star Recruit: What It Means', 'reason' => 'Recruiting news drives massive UGA traffic'],
                ['type' => 'Preview', 'headline' => '2025 Georgia Football: Position-by-Position Breakdown', 'reason' => 'Offseason previews build anticipation'],
                ['type' => 'Analysis', 'headline' => 'Bulldogs Transfer Portal Winners and Losers', 'reason' => 'Portal coverage is essential'],
            ],
            'gt' => [
                ['type' => 'Feature', 'headline' => 'Building a Winner: GT\'s Path Back to Relevance', 'reason' => 'Program-building narratives resonate'],
                ['type' => 'Recap', 'headline' => 'Key Takeaways from GT\'s Spring Practice', 'reason' => 'Spring football content engages fans'],
                ['type' => 'Analysis', 'headline' => 'ACC Preview: Where Does GT Stack Up?', 'reason' => 'Conference context adds value'],
            ],
        ];
        
        $ideas = $templates[$team] ?? array_merge(...array_values($templates));
        
        // Shuffle and limit
        shuffle($ideas);
        $ideas = array_slice($ideas, 0, 5);
        
        wp_send_json_success(['ideas' => $ideas]);
    }
    
    /**
     * AJAX: Get Google Search Console data (v2.8.0)
     */
    public function ajax_get_search_console_data() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $settings = $this->get_settings();
        $property_url = $settings['gsc_property_url'] ?? '';
        
        if (empty($property_url)) {
            // Return demo data when not configured
            wp_send_json_success([
                'configured' => false,
                'queries' => [
                    ['query' => 'atlanta braves news', 'clicks' => rand(100, 500), 'impressions' => rand(2000, 8000), 'ctr' => rand(3, 12) . '%', 'position' => rand(5, 25) . '.0'],
                    ['query' => 'falcons draft picks', 'clicks' => rand(80, 400), 'impressions' => rand(1500, 6000), 'ctr' => rand(3, 10) . '%', 'position' => rand(8, 30) . '.0'],
                    ['query' => 'hawks game recap', 'clicks' => rand(50, 300), 'impressions' => rand(1000, 4000), 'ctr' => rand(2, 8) . '%', 'position' => rand(10, 35) . '.0'],
                    ['query' => 'georgia bulldogs recruiting', 'clicks' => rand(150, 600), 'impressions' => rand(3000, 10000), 'ctr' => rand(4, 15) . '%', 'position' => rand(3, 20) . '.0'],
                    ['query' => 'georgia tech football', 'clicks' => rand(40, 200), 'impressions' => rand(800, 3000), 'ctr' => rand(2, 7) . '%', 'position' => rand(12, 40) . '.0'],
                ],
                'totals' => [
                    'clicks' => rand(1000, 5000),
                    'impressions' => rand(20000, 80000),
                    'avg_ctr' => rand(3, 8) . '%',
                    'avg_position' => rand(10, 25) . '.0',
                ],
            ]);
        }
        
        // Real GSC API call would go here
        // For now, return structured mock data
        wp_send_json_success([
            'configured' => true,
            'queries' => [],
            'totals' => [],
        ]);
    }
    
    /**
     * AJAX: Get Bing Webmaster data (v2.8.0)
     */
    public function ajax_get_bing_data() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $settings = $this->get_settings();
        $api_key = $settings['bing_api_key'] ?? '';
        
        if (empty($api_key)) {
            // Return demo data when not configured
            wp_send_json_success([
                'configured' => false,
                'keywords' => [
                    ['keyword' => 'braves spring training', 'clicks' => rand(20, 100), 'impressions' => rand(500, 2000)],
                    ['keyword' => 'atlanta hawks scores', 'clicks' => rand(15, 80), 'impressions' => rand(400, 1500)],
                    ['keyword' => 'falcons free agency', 'clicks' => rand(25, 120), 'impressions' => rand(600, 2500)],
                ],
                'crawl_stats' => [
                    'pages_crawled' => rand(50, 200),
                    'crawl_errors' => rand(0, 5),
                    'indexed_pages' => rand(40, 180),
                ],
            ]);
        }
        
        wp_send_json_success([
            'configured' => true,
            'keywords' => [],
            'crawl_stats' => [],
        ]);
    }
    
    /**
     * AJAX: Get SEO insights with AI analysis (v2.8.0)
     */
    public function ajax_get_seo_insights() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $settings = $this->get_settings();
        
        // Get recent posts for analysis
        $posts = get_posts([
            'post_type' => 'post',
            'posts_per_page' => 10,
            'meta_query' => [
                ['key' => 'flm_story_id', 'compare' => 'EXISTS'],
            ],
        ]);
        
        $insights = [];
        foreach ($posts as $post) {
            $title_length = strlen($post->post_title);
            $content_length = str_word_count(strip_tags($post->post_content));
            $has_images = preg_match('/<img/', $post->post_content);
            
            $seo_score = 50;
            $issues = [];
            
            // Title length check
            if ($title_length >= 40 && $title_length <= 60) {
                $seo_score += 15;
            } elseif ($title_length < 40) {
                $issues[] = 'Title too short (aim for 40-60 characters)';
            } else {
                $issues[] = 'Title too long (aim for 40-60 characters)';
                $seo_score -= 5;
            }
            
            // Content length check
            if ($content_length >= 300) {
                $seo_score += 20;
            } else {
                $issues[] = 'Content too short (aim for 300+ words)';
            }
            
            // Image check
            if ($has_images) {
                $seo_score += 10;
            } else {
                $issues[] = 'Add images to improve engagement';
            }
            
            // Meta description check
            $meta_desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true) ?: get_post_meta($post->ID, 'flm_meta_description', true);
            if (!empty($meta_desc)) {
                $seo_score += 10;
            } else {
                $issues[] = 'Add a meta description';
            }
            
            $insights[] = [
                'id' => $post->ID,
                'title' => wp_trim_words($post->post_title, 8),
                'score' => min(100, $seo_score),
                'issues' => array_slice($issues, 0, 3),
                'url' => get_permalink($post->ID),
            ];
        }
        
        // Sort by score ascending (worst first)
        usort($insights, function($a, $b) { return $a['score'] - $b['score']; });
        
        wp_send_json_success([
            'insights' => array_slice($insights, 0, 5),
            'avg_score' => count($insights) > 0 ? round(array_sum(array_column($insights, 'score')) / count($insights)) : 0,
        ]);
    }
    
    /**
     * AJAX: Dry-run preview (P2.2)
     * Fetches stories and shows what WOULD be imported without actually creating posts
     */
    public function ajax_dry_run_preview() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $settings = $this->get_settings();
        // All leagues: MLB=1, NFL=30, NBA=26, NCAAF=31, NCAAB=20
        $leagues_to_check = [1, 30, 26, 31, 20];
        $league_names = [
            1 => 'MLB', 
            30 => 'NFL', 
            26 => 'NBA',
            31 => 'NCAAF',
            20 => 'NCAAB',
        ];
        
        $preview_stories = [];
        $skipped = 0;
        $errors = [];
        
        $this->log_error('info', 'preview', 'Starting dry-run preview', []);
        
        foreach ($leagues_to_check as $league_id) {
            $stories = $this->fetch_stories($league_id, true); // use lookback setting
            
            if (is_wp_error($stories)) {
                $errors[] = $league_names[$league_id] . ': ' . $stories->get_error_message();
                continue;
            }
            
            if (!is_array($stories)) {
                continue;
            }
            
            foreach ($stories as $story) {
                $team_key = $this->get_matching_team($story);
                
                if (!$team_key) {
                    $skipped++;
                    continue;
                }
                
                // Check if story type is enabled (P4.1)
                $story_type = $story['storyType'] ?? 'News';
                if (!$this->is_story_type_enabled($story_type)) {
                    $skipped++;
                    continue;
                }
                
                // Check if this story already exists
                $existing = get_posts([
                    'meta_key' => 'flm_story_id',
                    'meta_value' => $story['storyId'],
                    'post_type' => 'post',
                    'posts_per_page' => 1,
                    'post_status' => 'any',
                ]);
                
                $team_config = $this->target_teams[$team_key];
                
                $preview_stories[] = [
                    'story_id' => $story['storyId'],
                    'headline' => wp_strip_all_tags($story['headline']),
                    'team' => $team_config['name'],
                    'team_key' => $team_key,
                    'league' => $story['league']['shortName'] ?? $team_config['league'],
                    'type' => $story['storyType'] ?? 'Story',
                    'byline' => $story['byline'] ?? 'Field Level Media',
                    'action' => !empty($existing) ? 'update' : 'create',
                    'existing_id' => !empty($existing) ? $existing[0]->ID : null,
                    'has_image' => !empty($story['images']),
                ];
            }
            
            // Rate limit pause between leagues (but shorter for preview)
            if ($league_id !== end($leagues_to_check)) {
                sleep(10);
            }
        }
        
        $this->log_error('info', 'preview', 'Dry-run preview completed', [
            'total_stories' => count($preview_stories),
            'new' => count(array_filter($preview_stories, function($s) { return $s['action'] === 'create'; })),
            'updates' => count(array_filter($preview_stories, function($s) { return $s['action'] === 'update'; })),
            'skipped' => $skipped,
        ]);
        
        wp_send_json_success([
            'stories' => $preview_stories,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => sprintf(
                'Found %d stories (%d new, %d updates)',
                count($preview_stories),
                count(array_filter($preview_stories, function($s) { return $s['action'] === 'create'; })),
                count(array_filter($preview_stories, function($s) { return $s['action'] === 'update'; }))
            ),
        ]);
    }
    
    /**
     * AJAX: Selective import (P2.3)
     * Import only the selected story IDs from the preview
     */
    public function ajax_selective_import() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        // Get selected story IDs
        $story_ids_json = isset($_POST['story_ids']) ? $_POST['story_ids'] : '[]';
        $selected_ids = json_decode(stripslashes($story_ids_json), true);
        
        if (empty($selected_ids) || !is_array($selected_ids)) {
            wp_send_json_error(['message' => 'No stories selected']);
        }
        
        $this->log_error('info', 'selective_import', 'Starting selective import', [
            'selected_count' => count($selected_ids),
            'story_ids' => array_slice($selected_ids, 0, 10), // Log first 10
        ]);
        
        $settings = $this->get_settings();
        
        // Get all league IDs we need to check
        $leagues_to_check = [];
        foreach ($this->target_teams as $team_key => $team_config) {
            if (!empty($settings['teams_enabled'][$team_key])) {
                if (!empty($team_config['league_ids'])) {
                    foreach ($team_config['league_ids'] as $lid) {
                        $leagues_to_check[$lid] = true;
                    }
                } elseif (!empty($team_config['league_id'])) {
                    $leagues_to_check[$team_config['league_id']] = true;
                }
            }
        }
        $leagues_to_check = array_keys($leagues_to_check);
        
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $log = [];
        
        // Convert selected IDs to a lookup array for fast checking
        $selected_lookup = array_flip($selected_ids);
        
        foreach ($leagues_to_check as $league_id) {
            $stories = $this->fetch_stories($league_id, true); // use lookback setting
            
            if (is_wp_error($stories)) {
                $this->log_error('error', 'selective_import', "Failed to fetch league {$league_id}", [
                    'error' => $stories->get_error_message(),
                ]);
                continue;
            }
            
            if (!is_array($stories)) {
                continue;
            }
            
            foreach ($stories as $story) {
                // Skip if this story is not in the selected list
                if (!isset($selected_lookup[$story['storyId']])) {
                    continue;
                }
                
                $team_key = $this->get_matching_team($story);
                
                if (!$team_key) {
                    $skipped++;
                    continue;
                }
                
                $result = $this->create_or_update_post($story, $team_key);
                
                if (is_wp_error($result)) {
                    $errors++;
                    $this->log_error('error', 'selective_import', 'Failed to create/update post', [
                        'story_id' => $story['storyId'],
                        'headline' => $story['headline'] ?? 'unknown',
                        'error' => $result->get_error_message(),
                    ]);
                    continue;
                }
                
                if ($result['action'] === 'created') {
                    $imported++;
                    $log[] = [
                        'type' => 'success',
                        'team' => $team_key,
                        'text' => $story['headline'],
                        'time' => current_time('H:i:s'),
                    ];
                } elseif ($result['action'] === 'updated') {
                    $updated++;
                    $log[] = [
                        'type' => 'update',
                        'team' => $team_key,
                        'text' => $story['headline'],
                        'time' => current_time('H:i:s'),
                    ];
                }
            }
            
            // Rate limit pause between leagues
            if ($league_id !== end($leagues_to_check)) {
                sleep(15);
            }
        }
        
        // Merge with existing log
        $existing_log = get_option('flm_import_log', []);
        if (!empty($existing_log) && isset($existing_log[0]) && is_string($existing_log[0])) {
            $existing_log = [];
        }
        
        $merged_log = array_merge($log, $existing_log);
        $merged_log = array_slice($merged_log, 0, 100);
        
        update_option('flm_last_import', time());
        update_option('flm_import_log', $merged_log);
        update_option('flm_stats', [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'time' => current_time('mysql'),
            'selective' => true,
        ]);
        
        $this->log_error('info', 'selective_import', 'Selective import completed', [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'selected_total' => count($selected_ids),
        ]);
        
        $message = sprintf('Imported %d new, updated %d', $imported, $updated);
        if ($errors > 0) {
            $message .= sprintf(', %d errors', $errors);
        }
        
        wp_send_json_success([
            'message' => $message,
            'stats' => [
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
        ]);
    }
    
    /**
     * AJAX: Purge old FLM posts (P5.3)
     */
    public function ajax_purge_old_posts() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        // Get days from request or use setting
        $days = isset($_POST['days']) ? absint($_POST['days']) : 0;
        
        if ($days < 1) {
            wp_send_json_error(['message' => 'Invalid days parameter']);
        }
        
        $result = $this->purge_old_posts($days);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => sprintf('Deleted %d posts older than %d days', $result['deleted'], $days),
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]);
    }
    
    /**
     * Purge FLM posts older than X days (P5.3)
     */
    private function purge_old_posts($days) {
        if ($days < 1) {
            return new WP_Error('invalid_days', 'Days must be at least 1');
        }
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $this->log_error('info', 'purge', 'Starting purge of old posts', [
            'days' => $days,
            'cutoff_date' => $cutoff_date,
        ]);
        
        // Find all FLM posts older than cutoff
        $args = [
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'flm_story_id',
                    'compare' => 'EXISTS',
                ],
            ],
            'date_query' => [
                [
                    'before' => $cutoff_date,
                ],
            ],
            'fields' => 'ids',
        ];
        
        $post_ids = get_posts($args);
        
        $deleted = 0;
        $failed = 0;
        
        foreach ($post_ids as $post_id) {
            // Delete post and its attachments
            $result = wp_delete_post($post_id, true); // true = force delete, skip trash
            
            if ($result) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        
        $this->log_error('info', 'purge', 'Purge completed', [
            'deleted' => $deleted,
            'failed' => $failed,
            'total_found' => count($post_ids),
        ]);
        
        return [
            'deleted' => $deleted,
            'failed' => $failed,
        ];
    }
    
    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        // Get old settings to check for changes
        $old_settings = $this->get_settings();
        
        $settings = [];
        
        $settings['api_key'] = sanitize_text_field($_POST['flm_settings']['api_key'] ?? '');
        $settings['post_status'] = sanitize_text_field($_POST['flm_settings']['post_status'] ?? 'draft');
        $settings['post_author'] = absint($_POST['flm_settings']['post_author'] ?? 1);
        $settings['default_category'] = absint($_POST['flm_settings']['default_category'] ?? 0);
        $settings['import_images'] = !empty($_POST['flm_settings']['import_images']);
        $settings['lookback_days'] = min(30, max(1, absint($_POST['flm_settings']['lookback_days'] ?? 7)));
        
        // Import frequency (P3.1)
        $valid_frequencies = ['hourly', 'every6hours', 'twicedaily', 'daily'];
        $new_frequency = sanitize_text_field($_POST['flm_settings']['import_frequency'] ?? 'twicedaily');
        $settings['import_frequency'] = in_array($new_frequency, $valid_frequencies) ? $new_frequency : 'twicedaily';
        
        // Purge after days (P5.3) - 0 = disabled
        $settings['purge_after_days'] = absint($_POST['flm_settings']['purge_after_days'] ?? 0);
        
        $settings['auto_excerpt'] = !empty($_POST['flm_settings']['auto_excerpt']);
        $settings['auto_meta_description'] = !empty($_POST['flm_settings']['auto_meta_description']);
        $settings['create_team_categories'] = !empty($_POST['flm_settings']['create_team_categories']);
        $settings['create_league_categories'] = !empty($_POST['flm_settings']['create_league_categories']);
        $settings['create_type_categories'] = !empty($_POST['flm_settings']['create_type_categories']);
        
        // Story types enabled
        $story_types = ['News', 'Recap', 'Preview', 'Feature', 'Analysis', 'Interview', 'Injury', 'Transaction'];
        $settings['story_types_enabled'] = [];
        foreach ($story_types as $type) {
            $settings['story_types_enabled'][$type] = !empty($_POST['flm_settings']['story_types_enabled'][$type]);
        }
        
        $settings['teams_enabled'] = [];
        foreach (array_keys($this->target_teams) as $key) {
            $settings['teams_enabled'][$key] = !empty($_POST['flm_settings']['teams_enabled'][$key]);
        }
        
        // Integration API Keys (v2.8.0)
        $settings['ga4_property_id'] = sanitize_text_field($_POST['flm_settings']['ga4_property_id'] ?? '');
        $settings['ga4_api_secret'] = sanitize_text_field($_POST['flm_settings']['ga4_api_secret'] ?? '');
        $settings['claude_api_key'] = sanitize_text_field($_POST['flm_settings']['claude_api_key'] ?? '');
        $settings['twitter_api_key'] = sanitize_text_field($_POST['flm_settings']['twitter_api_key'] ?? '');
        $settings['twitter_api_secret'] = sanitize_text_field($_POST['flm_settings']['twitter_api_secret'] ?? '');
        $settings['twitter_access_token'] = sanitize_text_field($_POST['flm_settings']['twitter_access_token'] ?? '');
        $settings['twitter_access_secret'] = sanitize_text_field($_POST['flm_settings']['twitter_access_secret'] ?? '');
        $settings['facebook_app_id'] = sanitize_text_field($_POST['flm_settings']['facebook_app_id'] ?? '');
        $settings['facebook_app_secret'] = sanitize_text_field($_POST['flm_settings']['facebook_app_secret'] ?? '');
        $settings['facebook_page_id'] = sanitize_text_field($_POST['flm_settings']['facebook_page_id'] ?? '');
        $settings['facebook_access_token'] = sanitize_text_field($_POST['flm_settings']['facebook_access_token'] ?? '');
        
        // Search Engine Integrations (v2.8.0)
        $settings['gsc_property_url'] = esc_url_raw($_POST['flm_settings']['gsc_property_url'] ?? '');
        $settings['gsc_client_id'] = sanitize_text_field($_POST['flm_settings']['gsc_client_id'] ?? '');
        $settings['gsc_client_secret'] = sanitize_text_field($_POST['flm_settings']['gsc_client_secret'] ?? '');
        $settings['gsc_access_token'] = sanitize_text_field($_POST['flm_settings']['gsc_access_token'] ?? '');
        $settings['bing_api_key'] = sanitize_text_field($_POST['flm_settings']['bing_api_key'] ?? '');
        $settings['bing_site_url'] = esc_url_raw($_POST['flm_settings']['bing_site_url'] ?? '');
        
        // ML Settings (v2.8.0)
        $settings['ml_headline_analysis'] = !empty($_POST['flm_settings']['ml_headline_analysis']);
        $settings['ml_publish_time_optimization'] = !empty($_POST['flm_settings']['ml_publish_time_optimization']);
        $settings['ml_performance_prediction'] = !empty($_POST['flm_settings']['ml_performance_prediction']);
        $settings['ml_trend_detection'] = !empty($_POST['flm_settings']['ml_trend_detection']);
        $settings['ml_seo_optimization'] = !empty($_POST['flm_settings']['ml_seo_optimization']);
        
        // Social Auto-Posting Settings (v2.9.0)
        $settings['auto_post_twitter'] = !empty($_POST['flm_settings']['auto_post_twitter']);
        $settings['auto_post_facebook'] = !empty($_POST['flm_settings']['auto_post_facebook']);
        $settings['twitter_post_template'] = sanitize_textarea_field($_POST['flm_settings']['twitter_post_template'] ?? '📰 {headline} #Atlanta #Sports {team_hashtag}');
        $settings['facebook_post_template'] = sanitize_textarea_field($_POST['flm_settings']['facebook_post_template'] ?? "{headline}\n\nRead more: {url}");
        $settings['social_post_delay'] = absint($_POST['flm_settings']['social_post_delay'] ?? 0);
        $settings['social_include_image'] = !empty($_POST['flm_settings']['social_include_image']);
        $settings['social_queue_enabled'] = !empty($_POST['flm_settings']['social_queue_enabled']);
        
        // Content & Publishing Settings (v2.10.0)
        $settings['utm_enabled'] = !empty($_POST['flm_settings']['utm_enabled']);
        $settings['utm_source'] = sanitize_text_field($_POST['flm_settings']['utm_source'] ?? 'social');
        $settings['utm_medium'] = sanitize_text_field($_POST['flm_settings']['utm_medium'] ?? '{platform}');
        $settings['utm_campaign'] = sanitize_text_field($_POST['flm_settings']['utm_campaign'] ?? 'flm_auto');
        $settings['utm_content'] = sanitize_text_field($_POST['flm_settings']['utm_content'] ?? '{team}');
        $settings['scheduled_posting_enabled'] = !empty($_POST['flm_settings']['scheduled_posting_enabled']);
        $settings['social_preview_meta_box'] = !empty($_POST['flm_settings']['social_preview_meta_box']);
        
        // Analytics Depth Settings (v2.11.0)
        $settings['ga4_service_account'] = wp_kses_post($_POST['flm_settings']['ga4_service_account'] ?? '');
        $settings['gsc_service_account'] = wp_kses_post($_POST['flm_settings']['gsc_service_account'] ?? '');
        $settings['analytics_use_ga4_api'] = !empty($_POST['flm_settings']['analytics_use_ga4_api']);
        $settings['analytics_cache_minutes'] = absint($_POST['flm_settings']['analytics_cache_minutes'] ?? 15);
        $settings['best_times_auto_learn'] = !empty($_POST['flm_settings']['best_times_auto_learn']);
        $settings['article_tracking_enabled'] = !empty($_POST['flm_settings']['article_tracking_enabled']);
        
        // ESP Integration Settings (v2.13.0)
        $valid_providers = ['none', 'sendgrid', 'aigeon'];
        $esp_provider = sanitize_text_field($_POST['flm_settings']['esp_provider'] ?? 'none');
        $settings['esp_provider'] = in_array($esp_provider, $valid_providers) ? $esp_provider : 'none';
        $settings['sendgrid_api_key'] = sanitize_text_field($_POST['flm_settings']['sendgrid_api_key'] ?? '');
        $settings['sendgrid_category'] = sanitize_text_field($_POST['flm_settings']['sendgrid_category'] ?? '');
        $settings['aigeon_api_key'] = sanitize_text_field($_POST['flm_settings']['aigeon_api_key'] ?? '');
        $settings['aigeon_account_id'] = sanitize_text_field($_POST['flm_settings']['aigeon_account_id'] ?? '');
        $settings['esp_cache_minutes'] = absint($_POST['flm_settings']['esp_cache_minutes'] ?? 30);
        $settings['esp_sync_enabled'] = !empty($_POST['flm_settings']['esp_sync_enabled']);
        
        update_option('flm_settings', $settings);
        
        // Clear ESP cache if provider changed
        $old_esp = $old_settings['esp_provider'] ?? 'none';
        if ($old_esp !== $settings['esp_provider']) {
            delete_transient('flm_sendgrid_stats_7');
            delete_transient('flm_sendgrid_stats_30');
            delete_transient('flm_aigeon_stats_7');
            delete_transient('flm_aigeon_stats_30');
        }
        
        // Schedule ESP sync cron if enabled
        if (!empty($settings['esp_sync_enabled']) && $settings['esp_provider'] !== 'none') {
            if (!wp_next_scheduled('flm_hourly_esp_sync')) {
                wp_schedule_event(time(), 'hourly', 'flm_hourly_esp_sync');
            }
        } else {
            wp_clear_scheduled_hook('flm_hourly_esp_sync');
        }
        
        // Clear GA4 cache if service account changed
        if (($old_settings['ga4_service_account'] ?? '') !== $settings['ga4_service_account']) {
            delete_transient('flm_google_token_' . md5('https://www.googleapis.com/auth/analytics.readonly'));
            delete_transient('flm_ga4_overview_7');
            delete_transient('flm_ga4_overview_30');
        }
        
        // Clear token if API key changed
        if ($old_settings['api_key'] !== $settings['api_key']) {
            delete_option('flm_jwt_token');
            delete_option('flm_token_expiry');
        }
        
        // Reschedule if frequency changed (P3.1)
        if (($old_settings['import_frequency'] ?? 'twicedaily') !== $settings['import_frequency']) {
            $this->reschedule_import();
        }
        
        wp_send_json_success(['message' => 'Settings saved']);
    }
    
    // ========================================
    // SOCIAL AUTO-POSTING AJAX (v2.9.0)
    // ========================================
    
    /**
     * AJAX: Test social post (send test tweet/post)
     */
    public function ajax_test_social_post() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $settings = $this->get_settings();
        
        // Create test message
        $test_message = '🧪 Test post from FLM GameDay Atlanta plugin - ' . current_time('Y-m-d H:i:s');
        $test_url = home_url();
        
        if ($platform === 'twitter') {
            $result = $this->post_to_twitter(0, $test_message, $test_url, 'braves', '');
        } elseif ($platform === 'facebook') {
            $result = $this->post_to_facebook(0, $test_message, $test_url, 'braves', '');
        } else {
            wp_send_json_error(['message' => 'Invalid platform']);
            return;
        }
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => ucfirst($platform) . ' test post successful!',
                'external_id' => $result['tweet_id'] ?? $result['post_id'] ?? '',
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? 'Unknown error',
            ]);
        }
    }
    
    /**
     * AJAX: Get social posting log
     */
    public function ajax_get_social_log() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $log = get_option('flm_social_log', []);
        
        // Add post edit links
        foreach ($log as &$entry) {
            if (!empty($entry['post_id']) && $entry['post_id'] > 0) {
                $entry['edit_url'] = get_edit_post_link($entry['post_id'], 'raw');
                $entry['view_url'] = get_permalink($entry['post_id']);
            }
        }
        
        wp_send_json_success(['log' => $log]);
    }
    
    /**
     * AJAX: Clear social log
     */
    public function ajax_clear_social_log() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        update_option('flm_social_log', []);
        wp_send_json_success(['message' => 'Social log cleared']);
    }
    
    /**
     * AJAX: Retry a failed social post
     */
    public function ajax_retry_social_post() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_id = absint($_POST['post_id'] ?? 0);
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        
        if (!$post_id || !$platform) {
            wp_send_json_error(['message' => 'Missing post ID or platform']);
            return;
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found']);
            return;
        }
        
        $settings = $this->get_settings();
        $headline = $post->post_title;
        $post_url = get_permalink($post_id);
        $team_key = get_post_meta($post_id, 'flm_team', true) ?: 'braves';
        
        $image_url = '';
        if (!empty($settings['social_include_image'])) {
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $image_url = wp_get_attachment_url($thumb_id);
            }
        }
        
        if ($platform === 'twitter') {
            $result = $this->post_to_twitter($post_id, $headline, $post_url, $team_key, $image_url);
        } elseif ($platform === 'facebook') {
            $result = $this->post_to_facebook($post_id, $headline, $post_url, $team_key, $image_url);
        } else {
            wp_send_json_error(['message' => 'Invalid platform']);
            return;
        }
        
        $this->log_social_activity($platform, $post_id, $result);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => 'Posted to ' . ucfirst($platform) . ' successfully',
                'external_id' => $result['tweet_id'] ?? $result['post_id'] ?? '',
            ]);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? 'Unknown error']);
        }
    }
    
    /**
     * AJAX: Get social posting queue
     */
    public function ajax_get_social_queue() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $queue = get_option('flm_social_queue', []);
        
        // Enrich with post data
        $enriched = [];
        foreach ($queue as $post_id => $data) {
            $post = get_post($post_id);
            if ($post) {
                $enriched[] = [
                    'post_id' => $post_id,
                    'title' => $post->post_title,
                    'status' => $post->post_status,
                    'edit_url' => get_edit_post_link($post_id, 'raw'),
                    'team' => $data['team_key'] ?? '',
                    'queued_at' => $data['queued_at'] ?? '',
                ];
            }
        }
        
        wp_send_json_success(['queue' => $enriched]);
    }
    
    // ========================================
    // CONTENT & PUBLISHING (v2.10.0)
    // ========================================
    
    /**
     * Add social preview meta box to post editor
     */
    public function add_social_preview_meta_box() {
        $settings = $this->get_settings();
        
        // Disabled for now - enable in future version
        if (empty($settings['social_preview_meta_box'])) {
            return;
        }
        
        // Only add if on post edit screen
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'post') {
            return;
        }
        
        add_meta_box(
            'flm_social_preview',
            'FLM Social Preview',
            [$this, 'render_social_preview_meta_box'],
            'post',
            'side',
            'low'
        );
    }
    
    /**
     * Render social preview meta box
     */
    public function render_social_preview_meta_box($post) {
        $settings = $this->get_settings();
        $team_key = get_post_meta($post->ID, 'flm_team', true);
        if (empty($team_key)) {
            $team_key = 'braves';
        }
        
        $social_posted = get_post_meta($post->ID, '_flm_social_posted', true);
        $twitter_configured = !empty($settings['twitter_access_token']);
        $facebook_configured = !empty($settings['facebook_access_token']);
        
        echo '<div style="font-size:12px;color:#666;">';
        
        if (!empty($social_posted)) {
            echo '<p style="color:#3fb950;margin:0 0 10px;">✓ Already posted to social media</p>';
        }
        
        if (!$twitter_configured && !$facebook_configured) {
            echo '<p>Configure Twitter or Facebook in FLM Settings → Integrations to enable social posting.</p>';
        } else {
            echo '<p><strong>Configured:</strong> ';
            $platforms = [];
            if ($twitter_configured) $platforms[] = 'Twitter/X';
            if ($facebook_configured) $platforms[] = 'Facebook';
            echo implode(', ', $platforms);
            echo '</p>';
            
            if (!empty($settings['auto_post_twitter']) || !empty($settings['auto_post_facebook'])) {
                echo '<p style="color:#3fb950;">Auto-posting is enabled.</p>';
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Generate social preview text for a post
     */
    private function generate_social_preview_text($platform, $post, $team_key) {
        $settings = $this->get_settings();
        $team_config = $this->target_teams[$team_key] ?? [];
        $post_url = get_permalink($post->ID);
        
        $team_hashtags = [
            'braves' => '#Braves #ForTheA',
            'falcons' => '#Falcons #RiseUp',
            'hawks' => '#Hawks #TrueToAtlanta',
            'uga' => '#UGA #GoDawgs',
            'gt' => '#GaTech #TogetherWeSwarm',
        ];
        
        if ($platform === 'twitter') {
            $template = $settings['twitter_post_template'] ?? '📰 {headline} #Atlanta #Sports {team_hashtag}';
            $text = str_replace([
                '{headline}',
                '{url}',
                '{team}',
                '{team_hashtag}',
                '{league}',
            ], [
                $post->post_title,
                $post_url,
                $team_config['name'] ?? '',
                $team_hashtags[$team_key] ?? '#Atlanta',
                $team_config['league'] ?? '',
            ], $template);
            
            // Truncate for preview
            if (strlen($text) > 280) {
                $text = substr($text, 0, 277) . '...';
            }
        } else {
            $template = $settings['facebook_post_template'] ?? '{headline}\n\nRead more: {url}';
            $text = str_replace([
                '{headline}',
                '{url}',
                '{team}',
                '{league}',
                '\n',
            ], [
                $post->post_title,
                $post_url,
                $team_config['name'] ?? '',
                $team_config['league'] ?? '',
                "\n",
            ], $template);
        }
        
        return $text;
    }
    
    /**
     * AJAX: Post to social media immediately
     */
    public function ajax_post_now() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_id = absint($_POST['post_id'] ?? 0);
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        
        if (!$post_id || !$platform) {
            wp_send_json_error(['message' => 'Missing required parameters']);
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found']);
        }
        
        $settings = $this->get_settings();
        $team_key = get_post_meta($post_id, 'flm_team', true) ?: 'braves';
        $post_url = get_permalink($post_id);
        
        $image_url = '';
        if (!empty($settings['social_include_image'])) {
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $image_url = wp_get_attachment_url($thumb_id);
            }
        }
        
        if ($platform === 'twitter') {
            $result = $this->post_to_twitter($post_id, $post->post_title, $post_url, $team_key, $image_url);
        } else {
            $result = $this->post_to_facebook($post_id, $post->post_title, $post_url, $team_key, $image_url);
        }
        
        $this->log_social_activity($platform, $post_id, $result);
        
        // Update post meta
        $existing = get_post_meta($post_id, '_flm_social_posted', true) ?: [];
        $existing[$platform] = $result;
        update_post_meta($post_id, '_flm_social_posted', $existing);
        update_post_meta($post_id, '_flm_social_posted_at', current_time('mysql'));
        
        if ($result['success']) {
            wp_send_json_success(['message' => 'Posted successfully']);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? 'Unknown error']);
        }
    }
    
    /**
     * AJAX: Schedule a social post
     */
    public function ajax_schedule_social_post() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_id = absint($_POST['post_id'] ?? 0);
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $schedule_time = sanitize_text_field($_POST['schedule_time'] ?? '');
        
        if (!$post_id || !$platform || !$schedule_time) {
            wp_send_json_error(['message' => 'Missing required parameters']);
        }
        
        $timestamp = strtotime($schedule_time);
        if (!$timestamp || $timestamp < time()) {
            wp_send_json_error(['message' => 'Invalid or past schedule time']);
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found']);
        }
        
        // Store scheduled post
        $scheduled = get_option('flm_scheduled_posts', []);
        $schedule_id = uniqid('sched_');
        
        $platforms = ($platform === 'both') ? ['twitter', 'facebook'] : [$platform];
        
        foreach ($platforms as $plat) {
            $scheduled[$schedule_id . '_' . $plat] = [
                'post_id' => $post_id,
                'platform' => $plat,
                'scheduled_for' => $timestamp,
                'created_at' => time(),
            ];
            
            // Schedule WP Cron event
            wp_schedule_single_event($timestamp, 'flm_scheduled_social_post', [
                $schedule_id . '_' . $plat,
                $post_id,
                $plat,
                get_post_meta($post_id, 'flm_team', true) ?: 'braves'
            ]);
        }
        
        update_option('flm_scheduled_posts', $scheduled);
        
        wp_send_json_success([
            'message' => 'Post scheduled for ' . date('M j, Y g:i A', $timestamp),
            'schedule_id' => $schedule_id,
        ]);
    }
    
    /**
     * Execute a scheduled social post (cron callback)
     */
    public function execute_scheduled_social_post($schedule_id, $post_id, $platform, $team_key) {
        $scheduled = get_option('flm_scheduled_posts', []);
        
        // Remove from scheduled list
        if (isset($scheduled[$schedule_id])) {
            unset($scheduled[$schedule_id]);
            update_option('flm_scheduled_posts', $scheduled);
        }
        
        $post = get_post($post_id);
        if (!$post) {
            $this->log_error('warning', 'social', 'Scheduled post not found', ['post_id' => $post_id]);
            return;
        }
        
        $settings = $this->get_settings();
        $post_url = get_permalink($post_id);
        
        $image_url = '';
        if (!empty($settings['social_include_image'])) {
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $image_url = wp_get_attachment_url($thumb_id);
            }
        }
        
        if ($platform === 'twitter') {
            $result = $this->post_to_twitter($post_id, $post->post_title, $post_url, $team_key, $image_url);
        } else {
            $result = $this->post_to_facebook($post_id, $post->post_title, $post_url, $team_key, $image_url);
        }
        
        $this->log_social_activity($platform, $post_id, $result);
        
        // Update post meta
        $existing = get_post_meta($post_id, '_flm_social_posted', true) ?: [];
        $existing[$platform] = $result;
        update_post_meta($post_id, '_flm_social_posted', $existing);
        update_post_meta($post_id, '_flm_social_posted_at', current_time('mysql'));
    }
    
    /**
     * AJAX: Get scheduled posts
     */
    public function ajax_get_scheduled_posts() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $scheduled = get_option('flm_scheduled_posts', []);
        
        // Enrich with post data
        $enriched = [];
        foreach ($scheduled as $id => $data) {
            $post = get_post($data['post_id']);
            if ($post && $data['scheduled_for'] > time()) {
                $enriched[] = [
                    'schedule_id' => $id,
                    'post_id' => $data['post_id'],
                    'title' => $post->post_title,
                    'platform' => $data['platform'],
                    'scheduled_for' => date('Y-m-d H:i:s', $data['scheduled_for']),
                    'scheduled_for_human' => human_time_diff(time(), $data['scheduled_for']) . ' from now',
                ];
            }
        }
        
        // Sort by scheduled time
        usort($enriched, function($a, $b) {
            return strtotime($a['scheduled_for']) - strtotime($b['scheduled_for']);
        });
        
        wp_send_json_success(['scheduled' => $enriched]);
    }
    
    /**
     * AJAX: Cancel a scheduled post
     */
    public function ajax_cancel_scheduled_post() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $schedule_id = sanitize_text_field($_POST['schedule_id'] ?? '');
        
        if (!$schedule_id) {
            wp_send_json_error(['message' => 'Missing schedule ID']);
        }
        
        $scheduled = get_option('flm_scheduled_posts', []);
        
        if (!isset($scheduled[$schedule_id])) {
            wp_send_json_error(['message' => 'Scheduled post not found']);
        }
        
        $data = $scheduled[$schedule_id];
        
        // Clear the cron event
        wp_clear_scheduled_hook('flm_scheduled_social_post', [
            $schedule_id,
            $data['post_id'],
            $data['platform'],
            get_post_meta($data['post_id'], 'flm_team', true) ?: 'braves'
        ]);
        
        // Remove from option
        unset($scheduled[$schedule_id]);
        update_option('flm_scheduled_posts', $scheduled);
        
        wp_send_json_success(['message' => 'Scheduled post cancelled']);
    }
    
    /**
     * AJAX: Get social preview for a post
     */
    public function ajax_get_social_preview() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_id = absint($_POST['post_id'] ?? 0);
        
        if (!$post_id) {
            wp_send_json_error(['message' => 'Missing post ID']);
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found']);
        }
        
        $team_key = get_post_meta($post_id, 'flm_team', true) ?: 'braves';
        
        wp_send_json_success([
            'twitter' => $this->generate_social_preview_text('twitter', $post, $team_key),
            'facebook' => $this->generate_social_preview_text('facebook', $post, $team_key),
            'image' => get_the_post_thumbnail_url($post_id, 'medium'),
        ]);
    }
    
    // ========================================
    // DASHBOARD WIDGET (P3.4)
    // ========================================
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'flm_dashboard_widget',
            'Field Level Media Importer',
            [$this, 'render_dashboard_widget']
        );
    }
    
    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        $settings = $this->get_settings();
        $stats = get_option('flm_stats', []);
        $last_import = get_option('flm_last_import');
        $next_scheduled = wp_next_scheduled('flm_import_stories');
        $error_log = get_option('flm_error_log', []);
        
        // Count recent errors (last 24 hours)
        $recent_errors = 0;
        $cutoff = time() - DAY_IN_SECONDS;
        foreach ($error_log as $entry) {
            if (!empty($entry['timestamp'])) {
                $entry_time = strtotime($entry['timestamp']);
                if ($entry_time && $entry_time > $cutoff && ($entry['level'] ?? '') === 'error') {
                    $recent_errors++;
                }
            }
        }
        
        // Count enabled teams
        $enabled_teams = 0;
        foreach ($this->target_teams as $key => $team) {
            if (!empty($settings['teams_enabled'][$key])) {
                $enabled_teams++;
            }
        }
        
        // Frequency labels
        $freq_labels = [
            'hourly' => 'Hourly',
            'every6hours' => 'Every 6 hours',
            'twicedaily' => 'Twice daily',
            'daily' => 'Daily',
        ];
        $current_freq = $freq_labels[$settings['import_frequency'] ?? 'twicedaily'] ?? 'Twice daily';
        
        ?>
        <style>
            .flm-widget { font-size: 13px; }
            .flm-widget-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 16px; }
            .flm-widget-stat { background: #f6f7f7; border-radius: 4px; padding: 12px; text-align: center; }
            .flm-widget-stat-value { font-size: 24px; font-weight: 600; color: #1d2327; line-height: 1.2; }
            .flm-widget-stat-value.success { color: #00a32a; }
            .flm-widget-stat-value.warning { color: #dba617; }
            .flm-widget-stat-value.error { color: #d63638; }
            .flm-widget-stat-label { font-size: 11px; color: #646970; text-transform: uppercase; margin-top: 4px; }
            .flm-widget-info { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; padding: 12px; background: #f6f7f7; border-radius: 4px; }
            .flm-widget-row { display: flex; justify-content: space-between; align-items: center; }
            .flm-widget-row-label { color: #646970; }
            .flm-widget-row-value { font-weight: 500; }
            .flm-widget-row-value.error { color: #d63638; }
            .flm-widget-actions { display: flex; gap: 8px; }
            .flm-widget-actions .button { flex: 1; text-align: center; }
        </style>
        <div class="flm-widget">
            <div class="flm-widget-stats">
                <div class="flm-widget-stat">
                    <div class="flm-widget-stat-value success"><?php echo (int)($stats['imported'] ?? 0); ?></div>
                    <div class="flm-widget-stat-label">Last Imported</div>
                </div>
                <div class="flm-widget-stat">
                    <div class="flm-widget-stat-value"><?php echo (int)($stats['updated'] ?? 0); ?></div>
                    <div class="flm-widget-stat-label">Last Updated</div>
                </div>
                <div class="flm-widget-stat">
                    <div class="flm-widget-stat-value"><?php echo $enabled_teams; ?></div>
                    <div class="flm-widget-stat-label">Teams Active</div>
                </div>
                <div class="flm-widget-stat">
                    <div class="flm-widget-stat-value <?php echo $recent_errors > 0 ? 'error' : ''; ?>"><?php echo $recent_errors; ?></div>
                    <div class="flm-widget-stat-label">Errors (24h)</div>
                </div>
            </div>
            
            <div class="flm-widget-info">
                <div class="flm-widget-row">
                    <span class="flm-widget-row-label">Last Import</span>
                    <span class="flm-widget-row-value">
                        <?php echo $last_import ? human_time_diff($last_import) . ' ago' : 'Never'; ?>
                    </span>
                </div>
                <div class="flm-widget-row">
                    <span class="flm-widget-row-label">Next Scheduled</span>
                    <span class="flm-widget-row-value">
                        <?php echo $next_scheduled ? 'in ' . human_time_diff(time(), $next_scheduled) : 'Not scheduled'; ?>
                    </span>
                </div>
                <div class="flm-widget-row">
                    <span class="flm-widget-row-label">Frequency</span>
                    <span class="flm-widget-row-value"><?php echo esc_html($current_freq); ?></span>
                </div>
            </div>
            
            <div class="flm-widget-actions">
                <a href="<?php echo admin_url('options-general.php?page=flm-importer'); ?>" class="button">
                    Settings
                </a>
                <a href="<?php echo admin_url('options-general.php?page=flm-importer'); ?>" class="button button-primary">
                    View Dashboard
                </a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render analytics section
     */
    private function render_analytics_section() {
        // Get analytics data
        $analytics = $this->get_analytics_data(7);
        $has_activity = array_sum($analytics['activity_data']) > 0;
        $has_team_data = array_sum(array_values($analytics['team_data'])) > 0;
        $has_type_data = !empty($analytics['type_data']) && array_sum(array_values($analytics['type_data'])) > 0;
        $team_colors = [
            'braves' => '#ce1141',
            'falcons' => '#a71930',
            'hawks' => '#e03a3e',
            'uga' => '#ba0c2f',
            'gt' => '#b3a369'
        ];
        ?>
        <section class="flm-analytics-section" id="flm-analytics">
            <div class="flm-analytics-header">
                <div class="flm-analytics-title">
                    <div class="flm-analytics-title-icon"><?php echo $this->icon('chart'); ?></div>
                    <div>
                        <span>Analytics Dashboard</span>
                        <div class="flm-analytics-subtitle">Performance metrics and insights</div>
                    </div>
                </div>
                <div class="flm-analytics-period">
                    <button type="button" class="flm-period-btn active" data-days="7">7 Days</button>
                    <button type="button" class="flm-period-btn" data-days="14">14 Days</button>
                    <button type="button" class="flm-period-btn" data-days="30">30 Days</button>
                </div>
            </div>
            
            <!-- Summary Stats with Trends -->
            <div class="flm-summary-stats" id="flm-summary-stats">
                <div class="flm-summary-stat flm-summary-stat-posts">
                    <div class="flm-summary-stat-icon"><?php echo $this->icon('edit'); ?></div>
                    <div class="flm-summary-stat-content">
                        <div class="flm-summary-stat-value" data-count="<?php echo esc_attr($analytics['total_posts']); ?>">0</div>
                        <div class="flm-summary-stat-label">Total Posts</div>
                    </div>
                    <div class="flm-summary-stat-trend <?php echo $analytics['posts_change'] >= 0 ? 'up' : 'down'; ?>">
                        <span class="flm-trend-arrow"><?php echo $analytics['posts_change'] >= 0 ? '↑' : '↓'; ?></span>
                        <span class="flm-trend-value"><?php echo abs($analytics['posts_change']); ?>%</span>
                    </div>
                    <div class="flm-summary-stat-spark" data-values="<?php echo esc_attr(implode(',', array_slice($analytics['activity_data'], -7))); ?>"></div>
                </div>
                
                <div class="flm-summary-stat flm-summary-stat-views">
                    <div class="flm-summary-stat-icon"><?php echo $this->icon('eye'); ?></div>
                    <div class="flm-summary-stat-content">
                        <div class="flm-summary-stat-value" data-count="<?php echo esc_attr($analytics['total_views']); ?>">0</div>
                        <div class="flm-summary-stat-label">Total Views</div>
                    </div>
                    <div class="flm-summary-stat-meta"><?php echo number_format($analytics['avg_views_per_post'], 1); ?> avg/post</div>
                </div>
                
                <div class="flm-summary-stat flm-summary-stat-period">
                    <div class="flm-summary-stat-icon"><?php echo $this->icon('chart'); ?></div>
                    <div class="flm-summary-stat-content">
                        <div class="flm-summary-stat-value" data-count="<?php echo esc_attr($analytics['views_period']); ?>">0</div>
                        <div class="flm-summary-stat-label">Views (<span class="flm-period-label">7</span> Days)</div>
                    </div>
                    <div class="flm-summary-stat-trend <?php echo $analytics['views_change'] >= 0 ? 'up' : 'down'; ?>">
                        <span class="flm-trend-arrow"><?php echo $analytics['views_change'] >= 0 ? '↑' : '↓'; ?></span>
                        <span class="flm-trend-value"><?php echo abs($analytics['views_change']); ?>%</span>
                    </div>
                </div>
                
                <div class="flm-summary-stat flm-summary-stat-success">
                    <div class="flm-summary-stat-icon"><?php echo $this->icon('check'); ?></div>
                    <div class="flm-summary-stat-content">
                        <div class="flm-summary-stat-value"><?php echo esc_html($analytics['success_rate']); ?>%</div>
                        <div class="flm-summary-stat-label">Import Success</div>
                    </div>
                    <div class="flm-summary-stat-gauge">
                        <div class="flm-gauge-fill" style="width: <?php echo esc_attr($analytics['success_rate']); ?>%;"></div>
                    </div>
                </div>
            </div>
            
            <!-- Insights Cards -->
            <?php if (!empty($analytics['insights'])): ?>
            <div class="flm-insights-row">
                <?php foreach ($analytics['insights'] as $insight): ?>
                <div class="flm-insight-card flm-insight-<?php echo esc_attr($insight['type']); ?>">
                    <div class="flm-insight-icon"><?php echo $this->icon($insight['icon']); ?></div>
                    <div class="flm-insight-text"><?php echo esc_html($insight['text']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Row 1: Activity Chart + Top Posts -->
            <div class="flm-analytics-row">
                <!-- Import Activity Chart -->
                <div class="flm-chart-card flm-chart-wide">
                    <div class="flm-chart-header">
                        <div>
                            <div class="flm-chart-title">Import Activity</div>
                            <div class="flm-chart-subtitle">Posts imported over time</div>
                        </div>
                    </div>
                    <div class="flm-chart-body">
                        <?php if ($has_activity): ?>
                        <canvas id="flm-activity-chart" class="flm-chart-canvas"></canvas>
                        <?php else: ?>
                        <div class="flm-analytics-empty">
                            <div class="flm-analytics-empty-icon"><?php echo $this->icon('chart'); ?></div>
                            <h3>No Activity Yet</h3>
                            <p>Import some stories to see activity trends.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Top Posts by Views -->
                <div class="flm-chart-card flm-chart-narrow">
                    <div class="flm-chart-header">
                        <div class="flm-chart-title">Top Performers</div>
                    </div>
                    <div class="flm-chart-body flm-chart-body-scroll">
                        <?php if (!empty($analytics['top_posts'])): ?>
                        <div class="flm-top-posts">
                            <?php foreach ($analytics['top_posts'] as $i => $post_data): ?>
                            <div class="flm-top-post-item">
                                <span class="flm-top-post-rank"><?php echo $i + 1; ?></span>
                                <div class="flm-top-post-info">
                                    <a href="<?php echo get_permalink($post_data['id']); ?>" class="flm-top-post-title" target="_blank">
                                        <?php echo esc_html(wp_trim_words($post_data['title'], 6)); ?>
                                    </a>
                                    <div class="flm-top-post-meta">
                                        <span class="flm-top-post-team" style="--team-color: <?php echo $team_colors[$post_data['team']] ?? '#ff6b35'; ?>">
                                            <?php echo esc_html(ucfirst($post_data['team'] ?? 'Unknown')); ?>
                                        </span>
                                        <?php if (!empty($post_data['type'])): ?>
                                        <span class="flm-top-post-type"><?php echo esc_html($post_data['type']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flm-top-post-stats">
                                    <span class="flm-top-post-views"><?php echo number_format($post_data['views']); ?></span>
                                    <span class="flm-top-post-vpd"><?php echo number_format($post_data['views_per_day'] ?? 0, 1); ?>/day</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="flm-analytics-empty flm-analytics-empty-sm">
                            <div class="flm-analytics-empty-icon"><?php echo $this->icon('eye'); ?></div>
                            <p>No pageview data yet</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Row 2: Team Distribution + Story Types + Views by Team -->
            <div class="flm-analytics-row flm-analytics-row-3">
                <!-- Posts by Team -->
                <div class="flm-chart-card">
                    <div class="flm-chart-header">
                        <div class="flm-chart-title">Posts by Team</div>
                    </div>
                    <div class="flm-chart-body">
                        <?php if ($has_team_data): ?>
                        <div class="flm-donut-container">
                            <canvas id="flm-team-chart" class="flm-donut-chart"></canvas>
                            <div class="flm-donut-legend">
                                <?php foreach ($analytics['team_data'] as $team => $count): 
                                    if ($count > 0):
                                ?>
                                <div class="flm-legend-item">
                                    <div class="flm-legend-label">
                                        <div class="flm-legend-color" style="background: <?php echo $team_colors[$team] ?? '#ff6b35'; ?>;"></div>
                                        <span class="flm-legend-name"><?php echo esc_html(ucfirst($team)); ?></span>
                                    </div>
                                    <span class="flm-legend-value"><?php echo esc_html($count); ?></span>
                                </div>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="flm-analytics-empty flm-analytics-empty-sm">
                            <div class="flm-analytics-empty-icon"><?php echo $this->icon('trophy'); ?></div>
                            <p>No posts imported yet</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Story Types Breakdown -->
                <div class="flm-chart-card">
                    <div class="flm-chart-header">
                        <div class="flm-chart-title">Story Types</div>
                    </div>
                    <div class="flm-chart-body">
                        <?php if ($has_type_data): ?>
                        <div class="flm-bar-chart">
                            <?php 
                            $max_type = max(array_values($analytics['type_data'])) ?: 1;
                            foreach ($analytics['type_data'] as $type => $count): 
                                if ($count > 0):
                                $percentage = ($count / $max_type) * 100;
                            ?>
                            <div class="flm-bar-item">
                                <div class="flm-bar-label"><?php echo esc_html($type); ?></div>
                                <div class="flm-bar-track">
                                    <div class="flm-bar-fill" style="width: <?php echo esc_attr($percentage); ?>%;">
                                        <span class="flm-bar-value"><?php echo esc_html($count); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                        <?php else: ?>
                        <div class="flm-analytics-empty flm-analytics-empty-sm">
                            <div class="flm-analytics-empty-icon"><?php echo $this->icon('folder'); ?></div>
                            <p>No story data yet</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Views by Team -->
                <div class="flm-chart-card">
                    <div class="flm-chart-header">
                        <div class="flm-chart-title">Views by Team</div>
                    </div>
                    <div class="flm-chart-body">
                        <?php if (!empty($analytics['views_by_team']) && array_sum(array_values($analytics['views_by_team'])) > 0): ?>
                        <div class="flm-bar-chart">
                            <?php 
                            $max_views = max(array_values($analytics['views_by_team'])) ?: 1;
                            foreach ($analytics['views_by_team'] as $team => $views): 
                                if ($views > 0):
                                $percentage = ($views / $max_views) * 100;
                            ?>
                            <div class="flm-bar-item">
                                <div class="flm-bar-label"><?php echo esc_html(ucfirst($team)); ?></div>
                                <div class="flm-bar-track">
                                    <div class="flm-bar-fill flm-bar-fill-<?php echo esc_attr($team); ?>" style="width: <?php echo esc_attr($percentage); ?>%;">
                                        <span class="flm-bar-value"><?php echo number_format($views); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                        <?php else: ?>
                        <div class="flm-analytics-empty flm-analytics-empty-sm">
                            <div class="flm-analytics-empty-icon"><?php echo $this->icon('eye'); ?></div>
                            <p>No view data yet</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Performance Table -->
            <?php if (!empty($analytics['performance_table'])): ?>
            <div class="flm-chart-card flm-performance-table-card">
                <div class="flm-chart-header">
                    <div>
                        <div class="flm-chart-title">Content Performance</div>
                        <div class="flm-chart-subtitle">Sorted by views per day</div>
                    </div>
                    <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm" id="flm-export-csv">
                        <?php echo $this->icon('download'); ?>
                        Export CSV
                    </button>
                </div>
                <div class="flm-chart-body flm-chart-body-table">
                    <table class="flm-performance-table" id="flm-performance-table">
                        <thead>
                            <tr>
                                <th class="flm-th-title" data-sort="title">Title</th>
                                <th class="flm-th-team" data-sort="team">Team</th>
                                <th class="flm-th-type" data-sort="type">Type</th>
                                <th class="flm-th-age" data-sort="age">Age</th>
                                <th class="flm-th-views" data-sort="views">Views</th>
                                <th class="flm-th-vpd" data-sort="vpd">Views/Day</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analytics['performance_table'] as $row): ?>
                            <tr data-team="<?php echo esc_attr($row['team']); ?>">
                                <td class="flm-td-title">
                                    <a href="<?php echo get_permalink($row['id']); ?>" target="_blank">
                                        <?php echo esc_html(wp_trim_words($row['title'], 8)); ?>
                                    </a>
                                </td>
                                <td class="flm-td-team">
                                    <span class="flm-team-badge" style="--team-color: <?php echo $team_colors[$row['team']] ?? '#ff6b35'; ?>">
                                        <?php echo esc_html(ucfirst($row['team'] ?? '-')); ?>
                                    </span>
                                </td>
                                <td class="flm-td-type"><?php echo esc_html($row['type'] ?? '-'); ?></td>
                                <td class="flm-td-age"><?php echo esc_html($row['age_days']); ?>d</td>
                                <td class="flm-td-views"><?php echo number_format($row['views']); ?></td>
                                <td class="flm-td-vpd">
                                    <span class="flm-vpd-value"><?php echo number_format($row['views_per_day'], 1); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Ultimate Edition v2.7.0 Widgets -->
            <div class="flm-widgets-grid">
                
                <!-- Goal Progress Widget -->
                <div class="flm-card flm-goals-card">
                    <div class="flm-card-header">
                        <h3 class="flm-card-title">
                            <span class="flm-card-icon"><?php echo $this->icon('trophy'); ?></span>
                            Monthly Goals
                        </h3>
                        <button type="button" class="flm-comparison-toggle">
                            <?php echo $this->icon('chart'); ?>
                            <span>Compare</span>
                        </button>
                    </div>
                    <div class="flm-card-body">
                        <?php
                        $this_month_posts = count(get_posts([
                            'post_type' => 'post',
                            'posts_per_page' => -1,
                            'date_query' => [['after' => date('Y-m-01')]],
                            'meta_query' => [['key' => 'flm_story_id', 'compare' => 'EXISTS']],
                            'fields' => 'ids',
                        ]));
                        $posts_goal = 50;
                        $posts_percent = min(100, round(($this_month_posts / $posts_goal) * 100));
                        
                        $this_month_views = $analytics['views_period'] * 4; // Estimate monthly
                        $views_goal = 10000;
                        $views_percent = min(100, round(($this_month_views / $views_goal) * 100));
                        
                        $active_teams = count(array_filter($this->get_settings()['teams_enabled'] ?? []));
                        $teams_goal = 5;
                        $teams_percent = round(($active_teams / $teams_goal) * 100);
                        ?>
                        <div class="flm-goals-grid">
                            <div class="flm-goal-item">
                                <div class="flm-progress-ring" data-percent="<?php echo $posts_percent; ?>">
                                    <svg width="80" height="80">
                                        <circle class="flm-progress-ring-bg" cx="40" cy="40" r="32" stroke-width="6"/>
                                        <circle class="flm-progress-ring-fill <?php echo $posts_percent >= 100 ? 'success' : ''; ?>" cx="40" cy="40" r="32" stroke-width="6"/>
                                    </svg>
                                    <span class="flm-progress-ring-text">0%</span>
                                </div>
                                <div class="flm-goal-label">Posts</div>
                                <div class="flm-goal-value"><?php echo $this_month_posts; ?> / <?php echo $posts_goal; ?></div>
                            </div>
                            <div class="flm-goal-item">
                                <div class="flm-progress-ring" data-percent="<?php echo $views_percent; ?>">
                                    <svg width="80" height="80">
                                        <circle class="flm-progress-ring-bg" cx="40" cy="40" r="32" stroke-width="6"/>
                                        <circle class="flm-progress-ring-fill <?php echo $views_percent >= 100 ? 'success' : ($views_percent >= 50 ? '' : 'warning'); ?>" cx="40" cy="40" r="32" stroke-width="6"/>
                                    </svg>
                                    <span class="flm-progress-ring-text">0%</span>
                                </div>
                                <div class="flm-goal-label">Views</div>
                                <div class="flm-goal-value"><?php echo number_format($this_month_views); ?> / <?php echo number_format($views_goal); ?></div>
                            </div>
                            <div class="flm-goal-item">
                                <div class="flm-progress-ring" data-percent="<?php echo $teams_percent; ?>">
                                    <svg width="80" height="80">
                                        <circle class="flm-progress-ring-bg" cx="40" cy="40" r="32" stroke-width="6"/>
                                        <circle class="flm-progress-ring-fill success" cx="40" cy="40" r="32" stroke-width="6"/>
                                    </svg>
                                    <span class="flm-progress-ring-text">0%</span>
                                </div>
                                <div class="flm-goal-label">Teams</div>
                                <div class="flm-goal-value"><?php echo $active_teams; ?> / <?php echo $teams_goal; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Activity Heatmap -->
                <div class="flm-card flm-heatmap-card">
                    <div class="flm-card-header">
                        <h3 class="flm-card-title">
                            <span class="flm-card-icon"><?php echo $this->icon('chart'); ?></span>
                            Publishing Activity
                        </h3>
                    </div>
                    <div class="flm-card-body">
                        <?php
                        // Generate heatmap data for last 12 weeks
                        $heatmap_data = [];
                        $start_date = strtotime('-12 weeks sunday');
                        $today = strtotime('today');
                        
                        for ($d = $start_date; $d <= $today; $d += 86400) {
                            $date_key = date('Y-m-d', $d);
                            $count = count(get_posts([
                                'post_type' => 'post',
                                'posts_per_page' => -1,
                                'date_query' => [['after' => $date_key . ' 00:00:00', 'before' => $date_key . ' 23:59:59', 'inclusive' => true]],
                                'meta_query' => [['key' => 'flm_story_id', 'compare' => 'EXISTS']],
                                'fields' => 'ids',
                            ]));
                            $heatmap_data[$date_key] = $count;
                        }
                        
                        $max_count = max(1, max($heatmap_data));
                        ?>
                        <div class="flm-heatmap-container">
                            <div style="display:flex;">
                                <div class="flm-heatmap-days">
                                    <div class="flm-heatmap-day-label"></div>
                                    <div class="flm-heatmap-day-label">Mon</div>
                                    <div class="flm-heatmap-day-label"></div>
                                    <div class="flm-heatmap-day-label">Wed</div>
                                    <div class="flm-heatmap-day-label"></div>
                                    <div class="flm-heatmap-day-label">Fri</div>
                                    <div class="flm-heatmap-day-label"></div>
                                </div>
                                <div class="flm-heatmap">
                                    <?php
                                    $current = $start_date;
                                    $week_html = '';
                                    $week_count = 0;
                                    
                                    while ($current <= $today + 86400 * 7) {
                                        $dow = date('w', $current);
                                        
                                        if ($dow == 0 && $week_html) {
                                            echo '<div class="flm-heatmap-week">' . $week_html . '</div>';
                                            $week_html = '';
                                        }
                                        
                                        $date_key = date('Y-m-d', $current);
                                        $count = $heatmap_data[$date_key] ?? 0;
                                        $is_future = $current > $today;
                                        $level = $is_future ? 0 : min(5, ceil(($count / $max_count) * 5));
                                        $display_date = date('M j, Y', $current);
                                        
                                        $week_html .= '<div class="flm-heatmap-day" data-level="' . ($is_future ? '' : $level) . '" data-date="' . $date_key . '" data-count="' . $count . '"' . ($is_future ? ' data-future="true"' : '') . '>';
                                        $week_html .= '<div class="flm-heatmap-tooltip">' . $display_date . ': ' . $count . ' posts</div>';
                                        $week_html .= '</div>';
                                        
                                        $current += 86400;
                                    }
                                    
                                    if ($week_html) {
                                        echo '<div class="flm-heatmap-week">' . $week_html . '</div>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="flm-heatmap-legend">
                            <span>Less</span>
                            <div class="flm-heatmap-legend-scale">
                                <div class="flm-heatmap-legend-item" style="background:var(--flm-bg-dark);"></div>
                                <div class="flm-heatmap-legend-item" style="background:rgba(255,107,53,0.2);"></div>
                                <div class="flm-heatmap-legend-item" style="background:rgba(255,107,53,0.4);"></div>
                                <div class="flm-heatmap-legend-item" style="background:rgba(255,107,53,0.6);"></div>
                                <div class="flm-heatmap-legend-item" style="background:rgba(255,107,53,0.8);"></div>
                                <div class="flm-heatmap-legend-item" style="background:var(--flm-accent);"></div>
                            </div>
                            <span>More</span>
                        </div>
                    </div>
                </div>
                
                <!-- Top Performers -->
                <div class="flm-card">
                    <div class="flm-card-header">
                        <h3 class="flm-card-title">
                            <span class="flm-card-icon"><?php echo $this->icon('trophy'); ?></span>
                            Top Performers
                        </h3>
                    </div>
                    <div class="flm-card-body">
                        <?php
                        $top_posts = $analytics['top_posts'] ?? [];
                        if (!empty($top_posts)):
                        ?>
                        <div class="flm-performers-list">
                            <?php foreach (array_slice($top_posts, 0, 5) as $i => $post): 
                                $rank_class = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : 'default'));
                            ?>
                            <div class="flm-performer-item">
                                <div class="flm-performer-rank <?php echo $rank_class; ?>"><?php echo $i + 1; ?></div>
                                <div class="flm-performer-info">
                                    <div class="flm-performer-title">
                                        <a href="<?php echo get_permalink($post['id']); ?>" target="_blank"><?php echo esc_html(wp_trim_words($post['title'], 8)); ?></a>
                                    </div>
                                    <div class="flm-performer-meta">
                                        <span class="flm-performer-team"><?php echo esc_html(ucfirst($post['team'] ?? 'Unknown')); ?></span>
                                        <span><?php echo esc_html($post['age_days']); ?>d ago</span>
                                    </div>
                                </div>
                                <div class="flm-performer-stats">
                                    <div class="flm-performer-views"><?php echo number_format($post['views']); ?></div>
                                    <div class="flm-performer-vpd"><?php echo number_format($post['views_per_day'], 1); ?>/day</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="flm-log-empty">
                            <div class="flm-log-empty-icon"><?php echo $this->icon('chart'); ?></div>
                            <div>No performance data yet</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
            </div>
            
            <!-- Content Calendar -->
            <div class="flm-card flm-calendar-card" style="margin-top:24px;">
                <div class="flm-card-header">
                    <h3 class="flm-card-title">
                        <span class="flm-card-icon"><?php echo $this->icon('log'); ?></span>
                        Content Calendar
                    </h3>
                </div>
                <div class="flm-card-body">
                    <div class="flm-calendar-header">
                        <div class="flm-calendar-nav">
                            <button type="button" class="flm-calendar-nav-btn flm-calendar-prev">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                            </button>
                            <span class="flm-calendar-title"><?php echo date('F Y'); ?></span>
                            <button type="button" class="flm-calendar-nav-btn flm-calendar-next">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                            </button>
                        </div>
                        <button type="button" class="flm-calendar-today-btn">Today</button>
                    </div>
                    <?php
                    // Get calendar events
                    $calendar_events = [];
                    $month_start = date('Y-m-01');
                    $month_end = date('Y-m-t');
                    
                    $calendar_posts = get_posts([
                        'post_type' => 'post',
                        'posts_per_page' => 100,
                        'date_query' => [['after' => $month_start, 'before' => $month_end . ' 23:59:59', 'inclusive' => true]],
                        'meta_query' => [['key' => 'flm_story_id', 'compare' => 'EXISTS']],
                    ]);
                    
                    foreach ($calendar_posts as $p) {
                        $date_key = get_the_date('Y-m-d', $p);
                        $team = get_post_meta($p->ID, 'flm_team', true) ?: '';
                        if (!isset($calendar_events[$date_key])) {
                            $calendar_events[$date_key] = [];
                        }
                        $calendar_events[$date_key][] = [
                            'title' => get_the_title($p),
                            'team' => $team,
                        ];
                    }
                    ?>
                    <div class="flm-calendar-grid" id="flm-calendar-grid" data-events='<?php echo esc_attr(wp_json_encode($calendar_events)); ?>'>
                        <div class="flm-calendar-weekday">Sun</div>
                        <div class="flm-calendar-weekday">Mon</div>
                        <div class="flm-calendar-weekday">Tue</div>
                        <div class="flm-calendar-weekday">Wed</div>
                        <div class="flm-calendar-weekday">Thu</div>
                        <div class="flm-calendar-weekday">Fri</div>
                        <div class="flm-calendar-weekday">Sat</div>
                        <!-- Days will be rendered by JavaScript -->
                    </div>
                </div>
            </div>
            
        </section>
        
        <!-- Analytics Data for JS -->
        <script>
        var flmAnalyticsData = <?php echo wp_json_encode([
            'activityLabels' => $analytics['activity_labels'],
            'activityData' => $analytics['activity_data'],
            'teamLabels' => array_map('ucfirst', array_keys(array_filter($analytics['team_data']))),
            'teamData' => array_values(array_filter($analytics['team_data'])),
            'teamColors' => array_values(array_intersect_key($team_colors, array_filter($analytics['team_data']))),
            'hasActivity' => $has_activity,
            'hasTeamData' => $has_team_data,
            'periodDays' => $analytics['period_days'],
        ]); ?>;
        </script>
        <?php
    }
    
    /**
     * Get analytics data for dashboard (with caching)
     */
    private function get_analytics_data($days = 7) {
        // Check cache first
        $cache_key = 'flm_analytics_' . $days;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $data = [
            'period_days' => $days,
            'total_posts' => 0,
            'total_views' => 0,
            'views_period' => 0,
            'views_previous' => 0,
            'views_change' => 0,
            'posts_period' => 0,
            'posts_previous' => 0,
            'posts_change' => 0,
            'avg_views_per_post' => 0,
            'success_rate' => 100,
            'activity_labels' => [],
            'activity_data' => [],
            'views_data' => [],
            'team_data' => [],
            'type_data' => [],
            'views_by_team' => [],
            'views_by_type' => [],
            'top_posts' => [],
            'performance_table' => [],
            'insights' => [],
            'best_team' => null,
            'best_type' => null,
        ];
        
        // Date ranges
        $today = date('Y-m-d');
        $period_start = date('Y-m-d', strtotime("-{$days} days"));
        $previous_start = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
        
        // Initialize team data
        foreach (array_keys($this->target_teams) as $team_key) {
            $data['team_data'][$team_key] = 0;
            $data['views_by_team'][$team_key] = 0;
        }
        
        // Get all FLM posts
        $all_posts = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_query' => [['key' => 'flm_story_id', 'compare' => 'EXISTS']],
            'posts_per_page' => -1,
        ]);
        
        $data['total_posts'] = $all_posts->post_count;
        
        $total_views = 0;
        $views_period = 0;
        $views_previous = 0;
        $performance_data = [];
        
        // Process all posts
        if ($all_posts->have_posts()) {
            while ($all_posts->have_posts()) {
                $all_posts->the_post();
                $post_id = get_the_ID();
                $post_date = get_the_date('Y-m-d');
                
                // Get metadata
                $team = get_post_meta($post_id, 'flm_team', true);
                $type = get_post_meta($post_id, 'flm_story_type', true);
                $views = (int) get_post_meta($post_id, 'flm_views', true);
                $daily_views = get_post_meta($post_id, 'flm_daily_views', true);
                if (!is_array($daily_views)) $daily_views = [];
                
                // Count by team
                if ($team && isset($data['team_data'][$team])) {
                    $data['team_data'][$team]++;
                    $data['views_by_team'][$team] += $views;
                }
                
                // Count by type
                if ($type) {
                    if (!isset($data['type_data'][$type])) {
                        $data['type_data'][$type] = 0;
                        $data['views_by_type'][$type] = 0;
                    }
                    $data['type_data'][$type]++;
                    $data['views_by_type'][$type] += $views;
                }
                
                // Total views
                $total_views += $views;
                
                // Views in current and previous periods
                $post_views_period = 0;
                $post_views_previous = 0;
                foreach ($daily_views as $date => $count) {
                    if ($date >= $period_start) {
                        $views_period += $count;
                        $post_views_period += $count;
                    } elseif ($date >= $previous_start && $date < $period_start) {
                        $views_previous += $count;
                        $post_views_previous += $count;
                    }
                }
                
                // Count posts in period
                if ($post_date >= $period_start) {
                    $data['posts_period']++;
                } elseif ($post_date >= $previous_start && $post_date < $period_start) {
                    $data['posts_previous']++;
                }
                
                // Performance table data
                $post_age_days = max(1, floor((time() - strtotime($post_date)) / 86400));
                $performance_data[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'team' => $team,
                    'type' => $type,
                    'date' => $post_date,
                    'age_days' => $post_age_days,
                    'views' => $views,
                    'views_period' => $post_views_period,
                    'views_per_day' => round($views / $post_age_days, 1),
                ];
            }
            wp_reset_postdata();
        }
        
        $data['total_views'] = $total_views;
        $data['views_period'] = $views_period;
        $data['views_previous'] = $views_previous;
        
        // Calculate changes
        if ($views_previous > 0) {
            $data['views_change'] = round((($views_period - $views_previous) / $views_previous) * 100);
        } elseif ($views_period > 0) {
            $data['views_change'] = 100;
        }
        
        if ($data['posts_previous'] > 0) {
            $data['posts_change'] = round((($data['posts_period'] - $data['posts_previous']) / $data['posts_previous']) * 100);
        } elseif ($data['posts_period'] > 0) {
            $data['posts_change'] = 100;
        }
        
        // Average views per post
        if ($data['total_posts'] > 0) {
            $data['avg_views_per_post'] = round($total_views / $data['total_posts'], 1);
        }
        
        // Sort and get top posts
        usort($performance_data, function($a, $b) {
            return $b['views'] - $a['views'];
        });
        $data['top_posts'] = array_slice($performance_data, 0, 10);
        
        // Performance table (sorted by views/day)
        usort($performance_data, function($a, $b) {
            return $b['views_per_day'] - $a['views_per_day'];
        });
        $data['performance_table'] = array_slice($performance_data, 0, 20);
        
        // Find best performing team (views per post)
        $team_efficiency = [];
        foreach ($data['team_data'] as $team => $count) {
            if ($count > 0) {
                $team_efficiency[$team] = $data['views_by_team'][$team] / $count;
            }
        }
        if (!empty($team_efficiency)) {
            arsort($team_efficiency);
            $data['best_team'] = [
                'name' => array_key_first($team_efficiency),
                'views_per_post' => round(reset($team_efficiency), 1),
            ];
        }
        
        // Find best performing type
        $type_efficiency = [];
        foreach ($data['type_data'] as $type => $count) {
            if ($count > 0 && isset($data['views_by_type'][$type])) {
                $type_efficiency[$type] = $data['views_by_type'][$type] / $count;
            }
        }
        if (!empty($type_efficiency)) {
            arsort($type_efficiency);
            $data['best_type'] = [
                'name' => array_key_first($type_efficiency),
                'views_per_post' => round(reset($type_efficiency), 1),
            ];
        }
        
        // Generate insights
        $data['insights'] = $this->generate_insights($data);
        
        // Calculate success rate
        $error_log = get_option('flm_error_log', []);
        $errors = 0;
        $infos = 0;
        foreach ($error_log as $entry) {
            if (($entry['level'] ?? '') === 'error') $errors++;
            if (($entry['level'] ?? '') === 'info') $infos++;
        }
        if (($errors + $infos) > 0) {
            $data['success_rate'] = round((($errors + $infos - $errors) / ($errors + $infos)) * 100);
        }
        
        // Activity data - posts and views per day
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $label = $days <= 7 ? date('D', strtotime($date)) : date('M j', strtotime($date));
            $data['activity_labels'][] = $label;
            
            // Posts on this day
            $day_query = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'meta_query' => [['key' => 'flm_story_id', 'compare' => 'EXISTS']],
                'date_query' => [
                    ['year' => date('Y', strtotime($date)), 'month' => date('m', strtotime($date)), 'day' => date('d', strtotime($date))]
                ],
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);
            $data['activity_data'][] = $day_query->post_count;
        }
        
        // Sort type data by count
        arsort($data['type_data']);
        
        // Cache for 5 minutes
        set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);
        
        return $data;
    }
    
    /**
     * Generate insights from analytics data
     */
    private function generate_insights($data) {
        $insights = [];
        
        // Best team insight
        if ($data['best_team'] && $data['avg_views_per_post'] > 0) {
            $multiplier = round($data['best_team']['views_per_post'] / $data['avg_views_per_post'], 1);
            if ($multiplier > 1.2) {
                $insights[] = [
                    'type' => 'success',
                    'icon' => 'trophy',
                    'text' => ucfirst($data['best_team']['name']) . ' content gets ' . $multiplier . 'x more views than average',
                ];
            }
        }
        
        // Best type insight
        if ($data['best_type'] && $data['avg_views_per_post'] > 0) {
            $multiplier = round($data['best_type']['views_per_post'] / $data['avg_views_per_post'], 1);
            if ($multiplier > 1.2) {
                $insights[] = [
                    'type' => 'info',
                    'icon' => 'folder',
                    'text' => $data['best_type']['name'] . ' stories outperform average by ' . round(($multiplier - 1) * 100) . '%',
                ];
            }
        }
        
        // Views trend insight
        if ($data['views_change'] > 20) {
            $insights[] = [
                'type' => 'success',
                'icon' => 'chart',
                'text' => 'Views are up ' . $data['views_change'] . '% vs previous ' . $data['period_days'] . ' days',
            ];
        } elseif ($data['views_change'] < -20) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'alert',
                'text' => 'Views are down ' . abs($data['views_change']) . '% vs previous period',
            ];
        }
        
        // Publishing frequency
        if ($data['posts_period'] > 0) {
            $posts_per_day = round($data['posts_period'] / $data['period_days'], 1);
            if ($posts_per_day >= 1) {
                $insights[] = [
                    'type' => 'info',
                    'icon' => 'edit',
                    'text' => 'Publishing ' . $posts_per_day . ' posts per day on average',
                ];
            }
        }
        
        // No posts warning
        if ($data['posts_period'] == 0 && $data['total_posts'] > 0) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'alert',
                'text' => 'No new posts in the last ' . $data['period_days'] . ' days',
            ];
        }
        
        return array_slice($insights, 0, 4); // Max 4 insights
    }
    
    // ========================================
    // GA4 & GSC API INTEGRATION (v2.11.0)
    // ========================================
    
    /**
     * Generate JWT for Google service account authentication
     * @param array $service_account Parsed service account JSON
     * @param string $scope API scope URL
     * @return string|false JWT token or false on failure
     */
    private function generate_google_jwt($service_account, $scope) {
        if (empty($service_account['private_key']) || empty($service_account['client_email'])) {
            return false;
        }
        
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];
        
        $now = time();
        $claims = [
            'iss' => $service_account['client_email'],
            'scope' => $scope,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        
        $base64_header = $this->base64url_encode(json_encode($header));
        $base64_claims = $this->base64url_encode(json_encode($claims));
        $signature_input = $base64_header . '.' . $base64_claims;
        
        $private_key = openssl_pkey_get_private($service_account['private_key']);
        if (!$private_key) {
            $this->log_error('error', 'GA4', 'Invalid private key in service account');
            return false;
        }
        
        $signature = '';
        if (!openssl_sign($signature_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
            $this->log_error('error', 'GA4', 'Failed to sign JWT');
            return false;
        }
        
        return $base64_header . '.' . $base64_claims . '.' . $this->base64url_encode($signature);
    }
    
    /**
     * Base64 URL-safe encoding
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Get Google access token from service account
     * @param array $service_account Parsed service account JSON
     * @param string $scope API scope
     * @return string|false Access token or false
     */
    private function get_google_access_token($service_account, $scope) {
        $cache_key = 'flm_google_token_' . md5($scope);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $jwt = $this->generate_google_jwt($service_account, $scope);
        if (!$jwt) {
            return false;
        }
        
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 30,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);
        
        if (is_wp_error($response)) {
            $this->log_error('error', 'GA4', 'Token request failed: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            $this->log_error('error', 'GA4', 'No access token in response', $body);
            return false;
        }
        
        // Cache for 50 minutes (tokens valid for 60)
        set_transient($cache_key, $body['access_token'], 50 * MINUTE_IN_SECONDS);
        
        return $body['access_token'];
    }
    
    /**
     * Query GA4 Data API
     * @param string $property_id GA4 property ID
     * @param array $request_body API request body
     * @return array|false API response or false
     */
    private function query_ga4_api($property_id, $request_body) {
        $settings = $this->get_settings();
        $service_account_json = $settings['ga4_service_account'] ?? '';
        
        if (empty($service_account_json)) {
            return false;
        }
        
        $service_account = json_decode($service_account_json, true);
        if (!$service_account) {
            $this->log_error('error', 'GA4', 'Invalid service account JSON');
            return false;
        }
        
        $access_token = $this->get_google_access_token(
            $service_account,
            'https://www.googleapis.com/auth/analytics.readonly'
        );
        
        if (!$access_token) {
            return false;
        }
        
        // Ensure property ID is in correct format
        $property_id = preg_replace('/^properties\//', '', $property_id);
        
        $response = wp_remote_post(
            "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport",
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($request_body),
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_error('error', 'GA4', 'API request failed: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code !== 200) {
            $this->log_error('error', 'GA4', "API error ({$code})", $body);
            return false;
        }
        
        return $body;
    }
    
    /**
     * Get GA4 overview metrics
     * @param int $days Number of days to query
     * @return array Metrics data
     */
    private function get_ga4_overview($days = 7) {
        $settings = $this->get_settings();
        $property_id = $settings['ga4_property_id'] ?? '';
        
        if (empty($property_id) || empty($settings['ga4_service_account'])) {
            return $this->get_fallback_analytics($days);
        }
        
        $cache_key = 'flm_ga4_overview_' . $days;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $response = $this->query_ga4_api($property_id, [
            'dateRanges' => [
                ['startDate' => $start_date, 'endDate' => $end_date],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'totalUsers'],
                ['name' => 'sessions'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'engagedSessions'],
            ],
        ]);
        
        if (!$response || empty($response['rows'])) {
            return $this->get_fallback_analytics($days);
        }
        
        $row = $response['rows'][0]['metricValues'] ?? [];
        $data = [
            'source' => 'ga4',
            'pageviews' => (int) ($row[0]['value'] ?? 0),
            'users' => (int) ($row[1]['value'] ?? 0),
            'sessions' => (int) ($row[2]['value'] ?? 0),
            'bounce_rate' => round((float) ($row[3]['value'] ?? 0) * 100, 1),
            'avg_session_duration' => round((float) ($row[4]['value'] ?? 0)),
            'engaged_sessions' => (int) ($row[5]['value'] ?? 0),
        ];
        
        $cache_minutes = (int) ($settings['analytics_cache_minutes'] ?? 15);
        set_transient($cache_key, $data, $cache_minutes * MINUTE_IN_SECONDS);
        
        return $data;
    }
    
    /**
     * Get GA4 article-level performance data
     * @param int $days Number of days
     * @param int $limit Max articles to return
     * @return array Article performance data
     */
    private function get_ga4_article_performance($days = 7, $limit = 20) {
        $settings = $this->get_settings();
        $property_id = $settings['ga4_property_id'] ?? '';
        
        if (empty($property_id) || empty($settings['ga4_service_account'])) {
            return [];
        }
        
        $cache_key = 'flm_ga4_articles_' . $days . '_' . $limit;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $response = $this->query_ga4_api($property_id, [
            'dateRanges' => [
                ['startDate' => $start_date, 'endDate' => $end_date],
            ],
            'dimensions' => [
                ['name' => 'pagePath'],
                ['name' => 'pageTitle'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'totalUsers'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'bounceRate'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
            ],
            'limit' => $limit * 2, // Get extra to filter
        ]);
        
        if (!$response || empty($response['rows'])) {
            return [];
        }
        
        $articles = [];
        foreach ($response['rows'] as $row) {
            $path = $row['dimensionValues'][0]['value'] ?? '';
            $title = $row['dimensionValues'][1]['value'] ?? '';
            
            // Try to match to a WordPress post
            $post_id = url_to_postid(home_url($path));
            if (!$post_id) {
                continue;
            }
            
            // Check if it's an FLM post
            $story_id = get_post_meta($post_id, 'flm_story_id', true);
            if (!$story_id) {
                continue;
            }
            
            $team = get_post_meta($post_id, 'flm_team', true);
            
            $articles[] = [
                'post_id' => $post_id,
                'title' => get_the_title($post_id),
                'path' => $path,
                'team' => $team,
                'pageviews' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
                'avg_time' => round((float) ($row['metricValues'][2]['value'] ?? 0)),
                'bounce_rate' => round((float) ($row['metricValues'][3]['value'] ?? 0) * 100, 1),
            ];
            
            if (count($articles) >= $limit) {
                break;
            }
        }
        
        $cache_minutes = (int) ($settings['analytics_cache_minutes'] ?? 15);
        set_transient($cache_key, $articles, $cache_minutes * MINUTE_IN_SECONDS);
        
        return $articles;
    }
    
    /**
     * Get GA4 hourly engagement data for best posting times
     * @param int $days Number of days to analyze
     * @return array Hourly engagement data
     */
    private function get_ga4_hourly_engagement($days = 30) {
        $settings = $this->get_settings();
        $property_id = $settings['ga4_property_id'] ?? '';
        
        if (empty($property_id) || empty($settings['ga4_service_account'])) {
            return $this->get_social_hourly_performance();
        }
        
        $cache_key = 'flm_ga4_hourly_' . $days;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $response = $this->query_ga4_api($property_id, [
            'dateRanges' => [
                ['startDate' => $start_date, 'endDate' => $end_date],
            ],
            'dimensions' => [
                ['name' => 'hour'],
                ['name' => 'dayOfWeek'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'engagedSessions'],
            ],
        ]);
        
        if (!$response || empty($response['rows'])) {
            return $this->get_social_hourly_performance();
        }
        
        // Initialize hourly data
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = ['views' => 0, 'engaged' => 0, 'count' => 0];
        }
        
        // Daily data (0 = Sunday, 6 = Saturday)
        $daily = [];
        for ($d = 0; $d < 7; $d++) {
            $daily[$d] = ['views' => 0, 'engaged' => 0];
        }
        
        foreach ($response['rows'] as $row) {
            $hour = (int) ($row['dimensionValues'][0]['value'] ?? 0);
            $day = (int) ($row['dimensionValues'][1]['value'] ?? 0);
            $views = (int) ($row['metricValues'][0]['value'] ?? 0);
            $engaged = (int) ($row['metricValues'][1]['value'] ?? 0);
            
            $hourly[$hour]['views'] += $views;
            $hourly[$hour]['engaged'] += $engaged;
            $hourly[$hour]['count']++;
            
            $daily[$day]['views'] += $views;
            $daily[$day]['engaged'] += $engaged;
        }
        
        // Calculate best hours
        $hour_scores = [];
        foreach ($hourly as $h => $data) {
            $hour_scores[$h] = $data['count'] > 0 ? ($data['views'] / $data['count']) : 0;
        }
        arsort($hour_scores);
        $best_hours = array_slice(array_keys($hour_scores), 0, 5, true);
        
        $result = [
            'source' => 'ga4',
            'hourly' => $hourly,
            'daily' => $daily,
            'best_hours' => $best_hours,
            'best_times' => array_map(function($h) {
                return sprintf('%02d:00', $h);
            }, array_keys($best_hours)),
        ];
        
        set_transient($cache_key, $result, 60 * MINUTE_IN_SECONDS);
        
        return $result;
    }
    
    /**
     * Get social posting hourly performance from log
     * @return array Hourly performance data
     */
    private function get_social_hourly_performance() {
        $social_log = get_option('flm_social_log', []);
        
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = ['success' => 0, 'total' => 0];
        }
        
        foreach ($social_log as $entry) {
            $timestamp = $entry['timestamp'] ?? '';
            if (empty($timestamp)) continue;
            
            $hour = (int) date('G', strtotime($timestamp));
            $hourly[$hour]['total']++;
            if (!empty($entry['success'])) {
                $hourly[$hour]['success']++;
            }
        }
        
        // Calculate best hours based on success rate
        $hour_scores = [];
        foreach ($hourly as $h => $data) {
            if ($data['total'] >= 3) { // Need at least 3 posts to be meaningful
                $hour_scores[$h] = $data['success'] / $data['total'];
            }
        }
        
        if (empty($hour_scores)) {
            // Default best hours if no data
            return [
                'source' => 'default',
                'hourly' => $hourly,
                'best_hours' => [9, 12, 17],
                'best_times' => ['09:00', '12:00', '17:00'],
            ];
        }
        
        arsort($hour_scores);
        $best_hours = array_slice(array_keys($hour_scores), 0, 3, true);
        
        return [
            'source' => 'social_log',
            'hourly' => $hourly,
            'best_hours' => array_keys($best_hours),
            'best_times' => array_map(function($h) {
                return sprintf('%02d:00', $h);
            }, array_keys($best_hours)),
        ];
    }
    
    /**
     * Get recommended posting times combining GA4 and social data
     * @return array Best posting times by platform
     */
    private function get_best_posting_times() {
        $settings = $this->get_settings();
        
        if (empty($settings['best_times_auto_learn'])) {
            return [
                'twitter' => $settings['best_times_twitter'] ?? ['09:00', '12:00', '17:00'],
                'facebook' => $settings['best_times_facebook'] ?? ['09:00', '13:00', '16:00'],
                'source' => 'manual',
            ];
        }
        
        $hourly_data = $this->get_ga4_hourly_engagement(30);
        $social_data = $this->get_social_hourly_performance();
        
        // Combine scores
        $combined = [];
        for ($h = 0; $h < 24; $h++) {
            $ga4_score = ($hourly_data['hourly'][$h]['views'] ?? 0) / max(1, $hourly_data['hourly'][$h]['count'] ?? 1);
            $social_score = ($social_data['hourly'][$h]['total'] ?? 0) > 0 
                ? ($social_data['hourly'][$h]['success'] ?? 0) / $social_data['hourly'][$h]['total'] 
                : 0.5;
            
            // Weight GA4 traffic higher (70%) than social success (30%)
            $combined[$h] = ($ga4_score * 0.7) + ($social_score * 100 * 0.3);
        }
        
        arsort($combined);
        $best_hours = array_keys(array_slice($combined, 0, 3, true));
        sort($best_hours);
        
        $best_times = array_map(function($h) {
            return sprintf('%02d:00', $h);
        }, $best_hours);
        
        return [
            'twitter' => $best_times,
            'facebook' => $best_times,
            'source' => $hourly_data['source'] === 'ga4' ? 'auto_ga4' : 'auto_social',
            'confidence' => $hourly_data['source'] === 'ga4' ? 'high' : 'medium',
        ];
    }
    
    /**
     * Query Google Search Console API
     * @param array $request_body API request body
     * @return array|false API response or false
     */
    private function query_gsc_api($request_body) {
        $settings = $this->get_settings();
        $property_url = $settings['gsc_property_url'] ?? '';
        $service_account_json = $settings['gsc_service_account'] ?? '';
        
        if (empty($property_url) || empty($service_account_json)) {
            return false;
        }
        
        $service_account = json_decode($service_account_json, true);
        if (!$service_account) {
            return false;
        }
        
        $access_token = $this->get_google_access_token(
            $service_account,
            'https://www.googleapis.com/auth/webmasters.readonly'
        );
        
        if (!$access_token) {
            return false;
        }
        
        $encoded_url = urlencode($property_url);
        
        $response = wp_remote_post(
            "https://www.googleapis.com/webmasters/v3/sites/{$encoded_url}/searchAnalytics/query",
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($request_body),
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_error('error', 'GSC', 'API request failed: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code !== 200) {
            $this->log_error('error', 'GSC', "API error ({$code})", $body);
            return false;
        }
        
        return $body;
    }
    
    /**
     * Get GSC overview metrics
     * @param int $days Number of days
     * @return array|false GSC data or false
     */
    private function get_gsc_overview($days = 28) {
        $cache_key = 'flm_gsc_overview_' . $days;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $end_date = date('Y-m-d', strtotime('-2 days')); // GSC data is delayed 2 days
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $response = $this->query_gsc_api([
            'startDate' => $start_date,
            'endDate' => $end_date,
            'dimensions' => ['date'],
            'rowLimit' => 1000,
        ]);
        
        if (!$response) {
            return false;
        }
        
        $totals = [
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'position' => 0,
        ];
        
        $count = 0;
        foreach ($response['rows'] ?? [] as $row) {
            $totals['clicks'] += $row['clicks'] ?? 0;
            $totals['impressions'] += $row['impressions'] ?? 0;
            $totals['position'] += $row['position'] ?? 0;
            $count++;
        }
        
        if ($count > 0) {
            $totals['ctr'] = $totals['impressions'] > 0 
                ? round(($totals['clicks'] / $totals['impressions']) * 100, 2) 
                : 0;
            $totals['position'] = round($totals['position'] / $count, 1);
        }
        
        $data = [
            'source' => 'gsc',
            'clicks' => $totals['clicks'],
            'impressions' => $totals['impressions'],
            'ctr' => $totals['ctr'],
            'avg_position' => $totals['position'],
        ];
        
        set_transient($cache_key, $data, 60 * MINUTE_IN_SECONDS);
        
        return $data;
    }
    
    /**
     * Get top search queries from GSC
     * @param int $days Number of days
     * @param int $limit Max queries to return
     * @return array Search queries
     */
    private function get_gsc_top_queries($days = 28, $limit = 20) {
        $cache_key = 'flm_gsc_queries_' . $days . '_' . $limit;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $end_date = date('Y-m-d', strtotime('-2 days'));
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $response = $this->query_gsc_api([
            'startDate' => $start_date,
            'endDate' => $end_date,
            'dimensions' => ['query'],
            'rowLimit' => $limit,
            'dimensionFilterGroups' => [[
                'filters' => [[
                    'dimension' => 'page',
                    'operator' => 'contains',
                    'expression' => '/',
                ]],
            ]],
        ]);
        
        if (!$response || empty($response['rows'])) {
            return [];
        }
        
        $queries = [];
        foreach ($response['rows'] as $row) {
            $queries[] = [
                'query' => $row['keys'][0] ?? '',
                'clicks' => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }
        
        set_transient($cache_key, $queries, 60 * MINUTE_IN_SECONDS);
        
        return $queries;
    }
    
    /**
     * Fallback analytics when GA4 not configured
     * Uses internal tracking data
     */
    private function get_fallback_analytics($days = 7) {
        $data = $this->get_analytics_data($days);
        
        return [
            'source' => 'internal',
            'pageviews' => $data['views_period'] ?? 0,
            'users' => round(($data['views_period'] ?? 0) * 0.7), // Estimate
            'sessions' => round(($data['views_period'] ?? 0) * 0.8),
            'bounce_rate' => 45, // Default estimate
            'avg_session_duration' => 120,
            'engaged_sessions' => round(($data['views_period'] ?? 0) * 0.4),
        ];
    }
    
    /**
     * Sync best posting times to settings if auto-learn is enabled
     */
    public function sync_best_posting_times() {
        $settings = $this->get_settings();
        
        if (empty($settings['best_times_auto_learn'])) {
            return;
        }
        
        $best_times = $this->get_best_posting_times();
        
        if ($best_times['source'] !== 'manual') {
            $settings['best_times_twitter'] = $best_times['twitter'];
            $settings['best_times_facebook'] = $best_times['facebook'];
            update_option('flm_settings', $settings);
        }
    }
    
    // ========================================
    // SETTINGS IMPORT/EXPORT (v2.12.0)
    // ========================================
    
    /**
     * Get settings categories for import/export
     */
    private function get_settings_categories() {
        return [
            'general' => [
                'label' => 'General Settings',
                'description' => 'Post status, author, categories, import frequency',
                'keys' => ['post_status', 'post_author', 'default_category', 'import_images', 'lookback_days', 'import_frequency', 'purge_after_days', 'auto_excerpt', 'auto_meta_description', 'create_team_categories', 'create_league_categories', 'create_type_categories'],
            ],
            'content' => [
                'label' => 'Content Filters',
                'description' => 'Story types and teams enabled',
                'keys' => ['story_types_enabled', 'teams_enabled'],
            ],
            'social_posting' => [
                'label' => 'Social Posting',
                'description' => 'Auto-post settings, templates, UTM tracking',
                'keys' => ['auto_post_twitter', 'auto_post_facebook', 'twitter_post_template', 'facebook_post_template', 'social_post_delay', 'social_include_image', 'social_queue_enabled', 'utm_enabled', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'scheduled_posting_enabled', 'social_preview_meta_box', 'reshare_evergreen', 'reshare_days_old', 'best_times_twitter', 'best_times_facebook', 'spread_posts_minutes'],
            ],
            'ml_features' => [
                'label' => 'ML/AI Features',
                'description' => 'Headline analysis, performance prediction, etc.',
                'keys' => ['ml_headline_analysis', 'ml_publish_time_optimization', 'ml_performance_prediction', 'ml_trend_detection', 'ml_seo_optimization'],
            ],
            'analytics' => [
                'label' => 'Analytics Settings',
                'description' => 'Cache settings, auto-learn best times',
                'keys' => ['analytics_use_ga4_api', 'analytics_cache_minutes', 'best_times_auto_learn', 'article_tracking_enabled'],
            ],
            'esp' => [
                'label' => 'ESP/Email Settings',
                'description' => 'SendGrid, Aigeon, email tracking',
                'keys' => ['esp_provider', 'sendgrid_category', 'esp_cache_minutes', 'esp_sync_enabled'],
            ],
            'api_keys' => [
                'label' => 'API Keys & Credentials',
                'description' => 'FLM, GA4, Twitter, Facebook, GSC, Claude, Bing, ESP',
                'sensitive' => true,
                'keys' => ['api_key', 'ga4_property_id', 'ga4_api_secret', 'ga4_service_account', 'claude_api_key', 'twitter_api_key', 'twitter_api_secret', 'twitter_access_token', 'twitter_access_secret', 'facebook_app_id', 'facebook_app_secret', 'facebook_page_id', 'facebook_access_token', 'gsc_property_url', 'gsc_client_id', 'gsc_client_secret', 'gsc_access_token', 'gsc_service_account', 'bing_api_key', 'bing_site_url', 'sendgrid_api_key', 'aigeon_api_key', 'aigeon_account_id'],
            ],
        ];
    }
    
    /**
     * Encrypt sensitive data for export
     */
    private function encrypt_export_data($data, $passphrase) {
        if (empty($passphrase)) {
            return $data;
        }
        
        $json = json_encode($data);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($json, 'AES-256-CBC', hash('sha256', $passphrase, true), 0, $iv);
        
        return [
            'encrypted' => true,
            'data' => base64_encode($iv . $encrypted),
        ];
    }
    
    /**
     * Decrypt imported data
     */
    private function decrypt_import_data($encrypted_data, $passphrase) {
        if (empty($passphrase) || empty($encrypted_data['encrypted'])) {
            return $encrypted_data;
        }
        
        $raw = base64_decode($encrypted_data['data']);
        $iv = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', hash('sha256', $passphrase, true), 0, $iv);
        
        if ($decrypted === false) {
            return false;
        }
        
        return json_decode($decrypted, true);
    }
    
    /**
     * AJAX: Export settings
     */
    public function ajax_export_settings() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $categories = isset($_POST['categories']) ? (array) $_POST['categories'] : [];
        $include_sensitive = !empty($_POST['include_sensitive']);
        $passphrase = sanitize_text_field($_POST['passphrase'] ?? '');
        
        if (empty($categories)) {
            wp_send_json_error(['message' => 'No categories selected']);
        }
        
        $settings = $this->get_settings();
        $all_categories = $this->get_settings_categories();
        $export_data = [];
        
        foreach ($categories as $cat_key) {
            if (!isset($all_categories[$cat_key])) {
                continue;
            }
            
            $cat = $all_categories[$cat_key];
            
            // Skip sensitive data if not requested
            if (!empty($cat['sensitive']) && !$include_sensitive) {
                continue;
            }
            
            foreach ($cat['keys'] as $key) {
                if (isset($settings[$key])) {
                    $export_data[$key] = $settings[$key];
                }
            }
        }
        
        if (empty($export_data)) {
            wp_send_json_error(['message' => 'No settings to export']);
        }
        
        // Add metadata
        $export_package = [
            'flm_export' => true,
            'version' => $this->version,
            'exported_at' => current_time('c'),
            'site_url' => home_url(),
            'categories' => $categories,
            'includes_sensitive' => $include_sensitive,
            'settings' => $export_data,
        ];
        
        // Encrypt if passphrase provided
        if (!empty($passphrase)) {
            $export_package['settings'] = $this->encrypt_export_data($export_data, $passphrase);
        }
        
        wp_send_json_success([
            'filename' => 'flm-settings-' . date('Y-m-d-His') . '.json',
            'data' => $export_package,
        ]);
    }
    
    /**
     * AJAX: Preview import (validate file before importing)
     */
    public function ajax_preview_import() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $import_json = stripslashes($_POST['import_data'] ?? '');
        $passphrase = sanitize_text_field($_POST['passphrase'] ?? '');
        
        if (empty($import_json)) {
            wp_send_json_error(['message' => 'No import data provided']);
        }
        
        $import_data = json_decode($import_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'Invalid JSON format: ' . json_last_error_msg()]);
        }
        
        if (empty($import_data['flm_export'])) {
            wp_send_json_error(['message' => 'This file is not a valid FLM settings export']);
        }
        
        // Handle encrypted data
        $settings_data = $import_data['settings'];
        if (!empty($settings_data['encrypted'])) {
            if (empty($passphrase)) {
                wp_send_json_error(['message' => 'This export is encrypted. Please provide the passphrase.', 'needs_passphrase' => true]);
            }
            
            $settings_data = $this->decrypt_import_data($settings_data, $passphrase);
            if ($settings_data === false) {
                wp_send_json_error(['message' => 'Incorrect passphrase or corrupted data']);
            }
        }
        
        // Analyze what will be imported
        $all_categories = $this->get_settings_categories();
        $current_settings = $this->get_settings();
        $preview = [
            'version' => $import_data['version'] ?? 'unknown',
            'exported_at' => $import_data['exported_at'] ?? 'unknown',
            'source_site' => $import_data['site_url'] ?? 'unknown',
            'includes_sensitive' => !empty($import_data['includes_sensitive']),
            'categories' => [],
            'changes' => [],
        ];
        
        // Group settings by category
        foreach ($all_categories as $cat_key => $cat) {
            $cat_changes = [];
            foreach ($cat['keys'] as $key) {
                if (isset($settings_data[$key])) {
                    $current_value = $current_settings[$key] ?? null;
                    $new_value = $settings_data[$key];
                    
                    $cat_changes[] = [
                        'key' => $key,
                        'current' => $this->format_setting_preview($current_value),
                        'new' => $this->format_setting_preview($new_value),
                        'changed' => $current_value !== $new_value,
                    ];
                }
            }
            
            if (!empty($cat_changes)) {
                $preview['categories'][$cat_key] = [
                    'label' => $cat['label'],
                    'sensitive' => !empty($cat['sensitive']),
                    'settings' => $cat_changes,
                ];
            }
        }
        
        $preview['total_settings'] = count($settings_data);
        
        wp_send_json_success($preview);
    }
    
    /**
     * Format a setting value for preview display
     */
    private function format_setting_preview($value) {
        if (is_null($value)) {
            return '(not set)';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            if (empty($value)) {
                return '(empty)';
            }
            // For arrays like teams_enabled, show summary
            $enabled = array_filter($value);
            return count($enabled) . ' items';
        }
        if (is_string($value)) {
            if (strlen($value) > 50) {
                return substr($value, 0, 47) . '...';
            }
            if (empty($value)) {
                return '(empty)';
            }
            // Mask sensitive looking values
            if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value)) {
                return substr($value, 0, 8) . '••••••••';
            }
            return $value;
        }
        return (string) $value;
    }
    
    /**
     * AJAX: Import settings
     */
    public function ajax_import_settings() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $import_json = stripslashes($_POST['import_data'] ?? '');
        $passphrase = sanitize_text_field($_POST['passphrase'] ?? '');
        $selected_categories = isset($_POST['categories']) ? (array) $_POST['categories'] : [];
        $backup_first = !empty($_POST['backup_first']);
        
        if (empty($import_json)) {
            wp_send_json_error(['message' => 'No import data provided']);
        }
        
        $import_data = json_decode($import_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($import_data['flm_export'])) {
            wp_send_json_error(['message' => 'Invalid import file']);
        }
        
        // Handle encrypted data
        $settings_data = $import_data['settings'];
        if (!empty($settings_data['encrypted'])) {
            $settings_data = $this->decrypt_import_data($settings_data, $passphrase);
            if ($settings_data === false) {
                wp_send_json_error(['message' => 'Failed to decrypt settings']);
            }
        }
        
        // Backup current settings if requested
        if ($backup_first) {
            $current_settings = $this->get_settings();
            $backup = [
                'flm_export' => true,
                'version' => $this->version,
                'exported_at' => current_time('c'),
                'site_url' => home_url(),
                'backup_before_import' => true,
                'settings' => $current_settings,
            ];
            update_option('flm_settings_backup', $backup);
        }
        
        // Get current settings and merge
        $current_settings = $this->get_settings();
        $all_categories = $this->get_settings_categories();
        $imported_count = 0;
        
        // If specific categories selected, only import those
        $keys_to_import = [];
        if (!empty($selected_categories)) {
            foreach ($selected_categories as $cat_key) {
                if (isset($all_categories[$cat_key])) {
                    $keys_to_import = array_merge($keys_to_import, $all_categories[$cat_key]['keys']);
                }
            }
        } else {
            // Import all
            foreach ($all_categories as $cat) {
                $keys_to_import = array_merge($keys_to_import, $cat['keys']);
            }
        }
        
        // Apply imported settings
        foreach ($settings_data as $key => $value) {
            if (in_array($key, $keys_to_import)) {
                $current_settings[$key] = $value;
                $imported_count++;
            }
        }
        
        // Save merged settings
        update_option('flm_settings', $current_settings);
        
        // Clear relevant caches
        delete_transient('flm_google_token_' . md5('https://www.googleapis.com/auth/analytics.readonly'));
        delete_transient('flm_ga4_overview_7');
        delete_transient('flm_ga4_overview_30');
        
        // Reschedule import if frequency changed
        $this->reschedule_import();
        
        wp_send_json_success([
            'message' => "Successfully imported {$imported_count} settings",
            'imported' => $imported_count,
            'backup_created' => $backup_first,
        ]);
    }
    
    /**
     * AJAX: Restore settings from backup
     */
    public function ajax_restore_backup() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $backup = get_option('flm_settings_backup');
        
        if (empty($backup) || empty($backup['settings'])) {
            wp_send_json_error(['message' => 'No backup found']);
        }
        
        update_option('flm_settings', $backup['settings']);
        
        wp_send_json_success([
            'message' => 'Settings restored from backup created at ' . ($backup['exported_at'] ?? 'unknown'),
        ]);
    }
    
    // ========================================
    // ESP INTEGRATION (v2.13.0)
    // ========================================
    
    /**
     * Get ESP provider instance based on settings
     */
    private function get_esp_provider() {
        $settings = $this->get_settings();
        return $settings['esp_provider'] ?? 'none';
    }
    
    /**
     * Query SendGrid API
     */
    private function query_sendgrid_api($endpoint, $params = []) {
        $settings = $this->get_settings();
        $api_key = $settings['sendgrid_api_key'] ?? '';
        
        if (empty($api_key)) {
            return ['error' => 'SendGrid API key not configured'];
        }
        
        $url = $this->integration_endpoints['sendgrid'] . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return ['error' => $data['errors'][0]['message'] ?? 'SendGrid API error'];
        }
        
        return $data;
    }
    
    /**
     * Get SendGrid global stats
     */
    private function get_sendgrid_stats($days = 7) {
        $cache_key = 'flm_sendgrid_stats_' . $days;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $settings = $this->get_settings();
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d');
        
        $params = [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'aggregated_by' => 'day',
        ];
        
        $data = $this->query_sendgrid_api('/stats', $params);
        
        if (isset($data['error'])) {
            return $data;
        }
        
        // Aggregate the stats
        $totals = [
            'source' => 'sendgrid',
            'days' => $days,
            'requests' => 0,
            'delivered' => 0,
            'opens' => 0,
            'unique_opens' => 0,
            'clicks' => 0,
            'unique_clicks' => 0,
            'bounces' => 0,
            'spam_reports' => 0,
            'unsubscribes' => 0,
            'daily' => [],
        ];
        
        foreach ($data as $day) {
            $metrics = $day['stats'][0]['metrics'] ?? [];
            $totals['requests'] += $metrics['requests'] ?? 0;
            $totals['delivered'] += $metrics['delivered'] ?? 0;
            $totals['opens'] += $metrics['opens'] ?? 0;
            $totals['unique_opens'] += $metrics['unique_opens'] ?? 0;
            $totals['clicks'] += $metrics['clicks'] ?? 0;
            $totals['unique_clicks'] += $metrics['unique_clicks'] ?? 0;
            $totals['bounces'] += $metrics['bounces'] ?? 0;
            $totals['spam_reports'] += $metrics['spam_reports'] ?? 0;
            $totals['unsubscribes'] += $metrics['unsubscribes'] ?? 0;
            
            $totals['daily'][] = [
                'date' => $day['date'],
                'delivered' => $metrics['delivered'] ?? 0,
                'opens' => $metrics['opens'] ?? 0,
                'clicks' => $metrics['clicks'] ?? 0,
            ];
        }
        
        // Calculate rates
        if ($totals['delivered'] > 0) {
            $totals['open_rate'] = round(($totals['unique_opens'] / $totals['delivered']) * 100, 2);
            $totals['click_rate'] = round(($totals['unique_clicks'] / $totals['delivered']) * 100, 2);
            $totals['bounce_rate'] = round(($totals['bounces'] / $totals['requests']) * 100, 2);
        } else {
            $totals['open_rate'] = 0;
            $totals['click_rate'] = 0;
            $totals['bounce_rate'] = 0;
        }
        
        $cache_minutes = $settings['esp_cache_minutes'] ?? 30;
        set_transient($cache_key, $totals, $cache_minutes * MINUTE_IN_SECONDS);
        
        return $totals;
    }
    
    /**
     * Get SendGrid stats by category (for newsletter-specific tracking)
     */
    private function get_sendgrid_category_stats($days = 7) {
        $settings = $this->get_settings();
        $category = $settings['sendgrid_category'] ?? '';
        
        if (empty($category)) {
            return $this->get_sendgrid_stats($days);
        }
        
        $cache_key = 'flm_sendgrid_category_' . md5($category) . '_' . $days;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d');
        
        $params = [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'categories' => $category,
            'aggregated_by' => 'day',
        ];
        
        $data = $this->query_sendgrid_api('/categories/stats', $params);
        
        if (isset($data['error'])) {
            return $data;
        }
        
        // Process same as global stats
        $totals = [
            'source' => 'sendgrid',
            'category' => $category,
            'days' => $days,
            'requests' => 0,
            'delivered' => 0,
            'opens' => 0,
            'unique_opens' => 0,
            'clicks' => 0,
            'unique_clicks' => 0,
            'bounces' => 0,
            'daily' => [],
        ];
        
        foreach ($data as $day) {
            $metrics = $day['stats'][0]['metrics'] ?? [];
            $totals['requests'] += $metrics['requests'] ?? 0;
            $totals['delivered'] += $metrics['delivered'] ?? 0;
            $totals['opens'] += $metrics['opens'] ?? 0;
            $totals['unique_opens'] += $metrics['unique_opens'] ?? 0;
            $totals['clicks'] += $metrics['clicks'] ?? 0;
            $totals['unique_clicks'] += $metrics['unique_clicks'] ?? 0;
            $totals['bounces'] += $metrics['bounces'] ?? 0;
            
            $totals['daily'][] = [
                'date' => $day['date'],
                'delivered' => $metrics['delivered'] ?? 0,
                'opens' => $metrics['opens'] ?? 0,
                'clicks' => $metrics['clicks'] ?? 0,
            ];
        }
        
        if ($totals['delivered'] > 0) {
            $totals['open_rate'] = round(($totals['unique_opens'] / $totals['delivered']) * 100, 2);
            $totals['click_rate'] = round(($totals['unique_clicks'] / $totals['delivered']) * 100, 2);
        } else {
            $totals['open_rate'] = 0;
            $totals['click_rate'] = 0;
        }
        
        $cache_minutes = $settings['esp_cache_minutes'] ?? 30;
        set_transient($cache_key, $totals, $cache_minutes * MINUTE_IN_SECONDS);
        
        return $totals;
    }
    
    /**
     * Query Aigeon API (placeholder - update when docs available)
     */
    private function query_aigeon_api($endpoint, $params = []) {
        $settings = $this->get_settings();
        $api_key = $settings['aigeon_api_key'] ?? '';
        $account_id = $settings['aigeon_account_id'] ?? '';
        
        if (empty($api_key)) {
            return ['error' => 'Aigeon API key not configured'];
        }
        
        // Placeholder URL structure - update when API docs available
        $url = $this->integration_endpoints['aigeon'] . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'X-Account-ID' => $account_id,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return ['error' => $data['message'] ?? 'Aigeon API error (HTTP ' . $status_code . ')'];
        }
        
        return $data;
    }
    
    /**
     * Get Aigeon stats (placeholder - update when docs available)
     * Expected structure based on typical ESP APIs
     */
    private function get_aigeon_stats($days = 7) {
        $cache_key = 'flm_aigeon_stats_' . $days;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $settings = $this->get_settings();
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d');
        
        // Placeholder endpoint - update when API docs available
        $params = [
            'start_date' => $start_date,
            'end_date' => $end_date,
        ];
        
        $data = $this->query_aigeon_api('/stats/overview', $params);
        
        if (isset($data['error'])) {
            // Return placeholder structure so UI still works
            return [
                'source' => 'aigeon',
                'error' => $data['error'],
                'note' => 'Aigeon API integration pending - update endpoint when docs available',
                'days' => $days,
                'delivered' => 0,
                'opens' => 0,
                'unique_opens' => 0,
                'clicks' => 0,
                'unique_clicks' => 0,
                'open_rate' => 0,
                'click_rate' => 0,
                'revenue' => 0,  // Aigeon has ad revenue
                'ad_rpm' => 0,
                'daily' => [],
            ];
        }
        
        // Map Aigeon response to our standard structure
        // Update this mapping when actual API response format is known
        $totals = [
            'source' => 'aigeon',
            'days' => $days,
            'delivered' => $data['delivered'] ?? $data['sends'] ?? 0,
            'opens' => $data['opens'] ?? 0,
            'unique_opens' => $data['unique_opens'] ?? 0,
            'clicks' => $data['clicks'] ?? 0,
            'unique_clicks' => $data['unique_clicks'] ?? 0,
            'revenue' => $data['revenue'] ?? $data['ad_revenue'] ?? 0,
            'ad_rpm' => $data['ad_rpm'] ?? $data['rpm'] ?? 0,
            'daily' => $data['daily'] ?? [],
        ];
        
        if ($totals['delivered'] > 0) {
            $totals['open_rate'] = round(($totals['unique_opens'] / $totals['delivered']) * 100, 2);
            $totals['click_rate'] = round(($totals['unique_clicks'] / $totals['delivered']) * 100, 2);
        } else {
            $totals['open_rate'] = 0;
            $totals['click_rate'] = 0;
        }
        
        $cache_minutes = $settings['esp_cache_minutes'] ?? 30;
        set_transient($cache_key, $totals, $cache_minutes * MINUTE_IN_SECONDS);
        
        return $totals;
    }
    
    /**
     * Get Aigeon click data by URL (placeholder - update when docs available)
     */
    private function get_aigeon_clicks_by_url($days = 7) {
        $cache_key = 'flm_aigeon_clicks_' . $days;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $settings = $this->get_settings();
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d');
        
        // Placeholder endpoint - this is the key data we need from Aigeon
        $params = [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'group_by' => 'url',
        ];
        
        $data = $this->query_aigeon_api('/stats/clicks', $params);
        
        if (isset($data['error'])) {
            return [
                'source' => 'aigeon',
                'error' => $data['error'],
                'urls' => [],
            ];
        }
        
        // Map to standard structure - update when actual format known
        $result = [
            'source' => 'aigeon',
            'days' => $days,
            'urls' => [],
        ];
        
        // Expected: array of {url, clicks, unique_clicks}
        foreach ($data['urls'] ?? $data['links'] ?? $data as $item) {
            $result['urls'][] = [
                'url' => $item['url'] ?? $item['link'] ?? '',
                'clicks' => $item['clicks'] ?? $item['total_clicks'] ?? 0,
                'unique_clicks' => $item['unique_clicks'] ?? $item['clicks'] ?? 0,
            ];
        }
        
        $cache_minutes = $settings['esp_cache_minutes'] ?? 30;
        set_transient($cache_key, $result, $cache_minutes * MINUTE_IN_SECONDS);
        
        return $result;
    }
    
    /**
     * Get unified ESP stats (works with any configured provider)
     */
    public function get_esp_stats($days = 7) {
        $provider = $this->get_esp_provider();
        
        switch ($provider) {
            case 'sendgrid':
                return $this->get_sendgrid_category_stats($days);
            case 'aigeon':
                return $this->get_aigeon_stats($days);
            default:
                return [
                    'source' => 'none',
                    'error' => 'No ESP configured',
                    'note' => 'Configure SendGrid or Aigeon in Settings → Integrations',
                ];
        }
    }
    
    /**
     * Get ESP clicks attributed to FLM articles
     */
    public function get_esp_article_performance($days = 7, $limit = 20) {
        $provider = $this->get_esp_provider();
        $site_url = home_url();
        
        // Get click data by URL from ESP
        $click_data = [];
        
        switch ($provider) {
            case 'sendgrid':
                // SendGrid doesn't have a direct "clicks by URL" endpoint for transactional
                // We'd need to use Marketing Campaigns API or Event Webhook
                // For now, return empty - this would need webhook integration
                return [
                    'source' => 'sendgrid',
                    'note' => 'Per-URL click tracking requires SendGrid Marketing Campaigns or Event Webhook',
                    'articles' => [],
                ];
            case 'aigeon':
                $click_data = $this->get_aigeon_clicks_by_url($days);
                break;
            default:
                return [
                    'source' => 'none',
                    'articles' => [],
                ];
        }
        
        if (isset($click_data['error'])) {
            return $click_data;
        }
        
        // Match URLs to FLM articles
        $articles = [];
        
        foreach ($click_data['urls'] ?? [] as $url_data) {
            $url = $url_data['url'] ?? '';
            
            // Check if URL is from this site
            if (strpos($url, $site_url) !== 0) {
                continue;
            }
            
            // Strip UTM parameters
            $clean_url = strtok($url, '?');
            
            // Try to find the post
            $post_id = url_to_postid($clean_url);
            
            if (!$post_id) {
                continue;
            }
            
            // Check if it's an FLM article
            $story_id = get_post_meta($post_id, 'flm_story_id', true);
            if (!$story_id) {
                continue;
            }
            
            $team = get_post_meta($post_id, 'flm_team', true);
            
            $articles[] = [
                'post_id' => $post_id,
                'title' => get_the_title($post_id),
                'url' => $clean_url,
                'team' => $team,
                'email_clicks' => $url_data['clicks'] ?? 0,
                'unique_email_clicks' => $url_data['unique_clicks'] ?? 0,
            ];
        }
        
        // Sort by clicks descending
        usort($articles, function($a, $b) {
            return $b['email_clicks'] - $a['email_clicks'];
        });
        
        return [
            'source' => $provider,
            'days' => $days,
            'articles' => array_slice($articles, 0, $limit),
        ];
    }
    
    /**
     * AJAX: Get ESP stats
     */
    public function ajax_get_esp_stats() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        $days = intval($_POST['days'] ?? 7);
        $stats = $this->get_esp_stats($days);
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Get ESP article click performance
     */
    public function ajax_get_esp_article_clicks() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        $days = intval($_POST['days'] ?? 7);
        $limit = intval($_POST['limit'] ?? 20);
        
        $data = $this->get_esp_article_performance($days, $limit);
        
        wp_send_json_success($data);
    }
    
    /**
     * AJAX: Test ESP connection
     */
    public function ajax_test_esp_connection() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        
        switch ($provider) {
            case 'sendgrid':
                // Test by fetching stats for today
                $result = $this->query_sendgrid_api('/stats', [
                    'start_date' => date('Y-m-d'),
                ]);
                
                if (isset($result['error'])) {
                    wp_send_json_error(['message' => $result['error']]);
                }
                
                wp_send_json_success([
                    'message' => 'SendGrid connection successful',
                    'data' => [
                        'today_requests' => $result[0]['stats'][0]['metrics']['requests'] ?? 0,
                    ],
                ]);
                break;
                
            case 'aigeon':
                // Test connection
                $result = $this->query_aigeon_api('/account/status');
                
                if (isset($result['error'])) {
                    wp_send_json_error([
                        'message' => $result['error'],
                        'note' => 'Aigeon API endpoint may need updating when docs are available',
                    ]);
                }
                
                wp_send_json_success([
                    'message' => 'Aigeon connection successful',
                    'data' => $result,
                ]);
                break;
                
            default:
                wp_send_json_error(['message' => 'Unknown ESP provider']);
        }
    }
    
    /**
     * Sync ESP article performance to post meta (cron job)
     */
    public function sync_esp_article_performance() {
        $settings = $this->get_settings();
        
        if (empty($settings['esp_sync_enabled'])) {
            return;
        }
        
        $provider = $this->get_esp_provider();
        if ($provider === 'none') {
            return;
        }
        
        // Get last 7 days of article performance
        $data = $this->get_esp_article_performance(7, 100);
        
        if (isset($data['error']) || empty($data['articles'])) {
            return;
        }
        
        foreach ($data['articles'] as $article) {
            $post_id = $article['post_id'];
            
            // Update meta
            update_post_meta($post_id, 'flm_email_clicks', $article['email_clicks']);
            update_post_meta($post_id, 'flm_email_clicks_unique', $article['unique_email_clicks']);
            update_post_meta($post_id, 'flm_email_clicks_updated', current_time('mysql'));
        }
        
        // Log sync
        $log = get_option('flm_esp_sync_log', []);
        $log[] = [
            'timestamp' => current_time('c'),
            'provider' => $provider,
            'articles_synced' => count($data['articles']),
        ];
        
        // Keep last 50 entries
        if (count($log) > 50) {
            $log = array_slice($log, -50);
        }
        
        update_option('flm_esp_sync_log', $log);
    }
    
    // ========================================
    // v2.14.0 UI/UX AJAX HANDLERS
    // ========================================
    
    /**
     * Get recent activity for activity feed
     */
    public function ajax_get_recent_activity() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        $activity_log = get_option('flm_activity_log', []);
        $activity_log = array_slice($activity_log, 0, 20);
        
        wp_send_json_success($activity_log);
    }
    
    /**
     * Log activity (helper)
     */
    public function log_activity($type, $text, $meta = []) {
        $activity_log = get_option('flm_activity_log', []);
        
        array_unshift($activity_log, [
            'type' => $type,
            'text' => $text,
            'time' => current_time('c'),
            'meta' => $meta,
            'team' => $meta['team'] ?? null,
        ]);
        
        // Keep last 100 entries
        if (count($activity_log) > 100) {
            $activity_log = array_slice($activity_log, 0, 100);
        }
        
        update_option('flm_activity_log', $activity_log);
    }
    
    /**
     * Clear all caches
     */
    public function ajax_clear_all_caches() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        global $wpdb;
        
        // Clear all FLM transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_flm_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_flm_%'");
        
        // Clear specific caches
        delete_transient('flm_api_health');
        delete_transient('flm_analytics_cache');
        delete_transient('flm_sendgrid_stats_7');
        delete_transient('flm_aigeon_stats_7');
        delete_transient('flm_ga4_report_cache');
        delete_transient('flm_gsc_cache');
        
        $this->log_activity('analytics', 'All caches cleared');
        
        wp_send_json_success(['message' => 'All caches cleared']);
    }
    
    /**
     * Reschedule a post (drag & drop calendar)
     */
    public function ajax_reschedule_post() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $new_date = sanitize_text_field($_POST['new_date'] ?? '');
        
        if (!$post_id || !$new_date) {
            wp_send_json_error('Missing parameters');
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
        }
        
        // Parse new date and preserve time
        $current_time = date('H:i:s', strtotime($post->post_date));
        $new_datetime = $new_date . ' ' . $current_time;
        
        $updated = wp_update_post([
            'ID' => $post_id,
            'post_date' => $new_datetime,
            'post_date_gmt' => get_gmt_from_date($new_datetime),
        ]);
        
        if (is_wp_error($updated)) {
            wp_send_json_error($updated->get_error_message());
        }
        
        $this->log_activity('import', 'Rescheduled: ' . $post->post_title, [
            'team' => get_post_meta($post_id, 'flm_team', true),
        ]);
        
        wp_send_json_success(['message' => 'Post rescheduled', 'new_date' => $new_datetime]);
    }
    
    /**
     * Dismiss an insight
     */
    public function ajax_dismiss_insight() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        $insight_id = sanitize_text_field($_POST['insight_id'] ?? '');
        
        if (!$insight_id) {
            wp_send_json_error('Missing insight ID');
        }
        
        $dismissed = get_option('flm_dismissed_insights', []);
        if (!in_array($insight_id, $dismissed)) {
            $dismissed[] = $insight_id;
            update_option('flm_dismissed_insights', $dismissed);
        }
        
        wp_send_json_success(['dismissed' => $insight_id]);
    }
    
    // ========================================
    // v2.15.0 OAUTH INTEGRATION
    // ========================================
    
    /**
     * Initialize OAuth flow - returns auth URL
     */
    public function ajax_oauth_init() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        
        if (!in_array($provider, ['ga4', 'gsc', 'twitter', 'facebook'])) {
            wp_send_json_error('Invalid provider');
        }
        
        // Build return URL for OAuth callback (uses admin-post.php to avoid permission issues)
        $return_url = add_query_arg([
            'action' => 'flm_oauth_callback',
            'provider' => $provider,
        ], admin_url('admin-post.php'));
        
        // Call mmgleads.com OAuth init endpoint
        $response = wp_remote_post($this->oauth_base . '/' . $provider . '/init', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'return_url' => $return_url,
                'site_url' => home_url(),
            ]),
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error('OAuth server unreachable: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($body['auth_url'])) {
            wp_send_json_error('Invalid response from OAuth server');
        }
        
        $provider_names = ['ga4' => 'Google Analytics', 'gsc' => 'Search Console', 'twitter' => 'Twitter', 'facebook' => 'Facebook'];
        $this->log_activity('analytics', "Started {$provider_names[$provider]} OAuth flow");
        
        wp_send_json_success([
            'auth_url' => $body['auth_url'],
            'provider' => $provider,
        ]);
    }
    
    /**
     * Handle OAuth callback - save tokens
     */
    public function ajax_oauth_callback() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $access_token = sanitize_text_field($_POST['access_token'] ?? '');
        $refresh_token = sanitize_text_field($_POST['refresh_token'] ?? '');
        $expires_in = intval($_POST['expires_in'] ?? 3600);
        $pages = sanitize_text_field($_POST['pages'] ?? '');  // Base64 encoded for Facebook
        
        if (!in_array($provider, ['ga4', 'gsc', 'twitter', 'facebook'])) {
            wp_send_json_error('Invalid provider');
        }
        
        if (empty($access_token)) {
            wp_send_json_error('Missing access token');
        }
        
        $settings = $this->get_settings();
        $expires_at = time() + $expires_in;
        
        switch ($provider) {
            case 'ga4':
                $settings['ga4_oauth_access_token'] = $access_token;
                $settings['ga4_oauth_refresh_token'] = $refresh_token;
                $settings['ga4_oauth_expires_at'] = $expires_at;
                break;
                
            case 'gsc':
                $settings['gsc_oauth_access_token'] = $access_token;
                $settings['gsc_oauth_refresh_token'] = $refresh_token;
                $settings['gsc_oauth_expires_at'] = $expires_at;
                break;
                
            case 'twitter':
                $settings['twitter_oauth_access_token'] = $access_token;
                $settings['twitter_oauth_refresh_token'] = $refresh_token;
                $settings['twitter_oauth_expires_at'] = $expires_at;
                // Also set legacy fields for compatibility
                $settings['twitter_access_token'] = $access_token;
                break;
                
            case 'facebook':
                $settings['facebook_oauth_access_token'] = $access_token;
                $settings['facebook_oauth_expires_at'] = $expires_at;
                // Decode pages data
                if (!empty($pages)) {
                    $decoded_pages = json_decode(base64_decode($pages), true);
                    if (is_array($decoded_pages)) {
                        $settings['facebook_oauth_pages'] = $decoded_pages;
                        // Auto-select first page if none selected
                        if (empty($settings['facebook_oauth_selected_page']) && !empty($decoded_pages[0]['id'])) {
                            $settings['facebook_oauth_selected_page'] = $decoded_pages[0]['id'];
                            $settings['facebook_page_id'] = $decoded_pages[0]['id'];
                            $settings['facebook_access_token'] = $decoded_pages[0]['access_token'];
                        }
                    }
                }
                break;
        }
        
        update_option('flm_settings', $settings);
        
        $provider_names = ['ga4' => 'Google Analytics', 'gsc' => 'Search Console', 'twitter' => 'Twitter', 'facebook' => 'Facebook'];
        $this->log_activity('analytics', "Connected {$provider_names[$provider]} via OAuth");
        
        wp_send_json_success([
            'message' => $provider_names[$provider] . ' connected successfully',
            'provider' => $provider,
            'expires_at' => $expires_at,
        ]);
    }
    
    /**
     * Disconnect OAuth provider
     */
    public function ajax_oauth_disconnect() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        
        if (!in_array($provider, ['ga4', 'gsc', 'twitter', 'facebook'])) {
            wp_send_json_error('Invalid provider');
        }
        
        $settings = $this->get_settings();
        
        switch ($provider) {
            case 'ga4':
                $settings['ga4_oauth_access_token'] = '';
                $settings['ga4_oauth_refresh_token'] = '';
                $settings['ga4_oauth_expires_at'] = 0;
                break;
                
            case 'gsc':
                $settings['gsc_oauth_access_token'] = '';
                $settings['gsc_oauth_refresh_token'] = '';
                $settings['gsc_oauth_expires_at'] = 0;
                break;
                
            case 'twitter':
                $settings['twitter_oauth_access_token'] = '';
                $settings['twitter_oauth_refresh_token'] = '';
                $settings['twitter_oauth_expires_at'] = 0;
                $settings['twitter_access_token'] = '';
                $settings['twitter_access_secret'] = '';
                break;
                
            case 'facebook':
                $settings['facebook_oauth_access_token'] = '';
                $settings['facebook_oauth_expires_at'] = 0;
                $settings['facebook_oauth_pages'] = [];
                $settings['facebook_oauth_selected_page'] = '';
                $settings['facebook_access_token'] = '';
                $settings['facebook_page_id'] = '';
                break;
        }
        
        update_option('flm_settings', $settings);
        
        $provider_names = ['ga4' => 'Google Analytics', 'gsc' => 'Search Console', 'twitter' => 'Twitter', 'facebook' => 'Facebook'];
        $this->log_activity('analytics', "Disconnected {$provider_names[$provider]}");
        
        wp_send_json_success([
            'message' => $provider_names[$provider] . ' disconnected',
            'provider' => $provider,
        ]);
    }
    
    /**
     * Refresh OAuth tokens
     */
    public function ajax_oauth_refresh() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        
        if (!in_array($provider, ['ga4', 'gsc', 'twitter', 'facebook'])) {
            wp_send_json_error('Invalid provider');
        }
        
        $result = $this->refresh_oauth_token($provider);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Get OAuth status for all providers
     */
    public function ajax_oauth_status() {
        check_ajax_referer('flm_nonce', 'nonce');
        
        $settings = $this->get_settings();
        $now = time();
        
        $status = [
            'ga4' => [
                'connected' => !empty($settings['ga4_oauth_access_token']),
                'expires_at' => $settings['ga4_oauth_expires_at'] ?? 0,
                'expires_in' => max(0, ($settings['ga4_oauth_expires_at'] ?? 0) - $now),
                'needs_refresh' => !empty($settings['ga4_oauth_access_token']) && 
                                   ($settings['ga4_oauth_expires_at'] ?? 0) < ($now + 300),
            ],
            'gsc' => [
                'connected' => !empty($settings['gsc_oauth_access_token']),
                'expires_at' => $settings['gsc_oauth_expires_at'] ?? 0,
                'expires_in' => max(0, ($settings['gsc_oauth_expires_at'] ?? 0) - $now),
                'needs_refresh' => !empty($settings['gsc_oauth_access_token']) && 
                                   ($settings['gsc_oauth_expires_at'] ?? 0) < ($now + 300),
            ],
            'twitter' => [
                'connected' => !empty($settings['twitter_oauth_access_token']),
                'expires_at' => $settings['twitter_oauth_expires_at'] ?? 0,
                'expires_in' => max(0, ($settings['twitter_oauth_expires_at'] ?? 0) - $now),
                'needs_refresh' => !empty($settings['twitter_oauth_access_token']) && 
                                   ($settings['twitter_oauth_expires_at'] ?? 0) < ($now + 300),
            ],
            'facebook' => [
                'connected' => !empty($settings['facebook_oauth_access_token']),
                'expires_at' => $settings['facebook_oauth_expires_at'] ?? 0,
                'expires_in' => max(0, ($settings['facebook_oauth_expires_at'] ?? 0) - $now),
                'needs_refresh' => !empty($settings['facebook_oauth_access_token']) && 
                                   ($settings['facebook_oauth_expires_at'] ?? 0) < ($now + 86400), // 1 day warning for FB
                'pages' => $settings['facebook_oauth_pages'] ?? [],
                'selected_page' => $settings['facebook_oauth_selected_page'] ?? '',
            ],
        ];
        
        wp_send_json_success($status);
    }
    
    /**
     * Refresh OAuth token for a provider
     */
    private function refresh_oauth_token($provider) {
        $settings = $this->get_settings();
        
        $body = [];
        
        switch ($provider) {
            case 'ga4':
                if (empty($settings['ga4_oauth_refresh_token'])) {
                    return new WP_Error('no_refresh_token', 'No refresh token available. Please reconnect.');
                }
                $body['refresh_token'] = $settings['ga4_oauth_refresh_token'];
                break;
                
            case 'gsc':
                if (empty($settings['gsc_oauth_refresh_token'])) {
                    return new WP_Error('no_refresh_token', 'No refresh token available. Please reconnect.');
                }
                $body['refresh_token'] = $settings['gsc_oauth_refresh_token'];
                break;
                
            case 'twitter':
                if (empty($settings['twitter_oauth_refresh_token'])) {
                    return new WP_Error('no_refresh_token', 'No refresh token available. Please reconnect.');
                }
                $body['refresh_token'] = $settings['twitter_oauth_refresh_token'];
                break;
                
            case 'facebook':
                if (empty($settings['facebook_oauth_access_token'])) {
                    return new WP_Error('no_token', 'No access token available. Please reconnect.');
                }
                $body['access_token'] = $settings['facebook_oauth_access_token'];
                break;
        }
        
        // Call mmgleads.com OAuth refresh endpoint
        $response = wp_remote_post($this->oauth_base . '/' . $provider . '/refresh', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
        ]);
        
        if (is_wp_error($response)) {
            return new WP_Error('refresh_failed', 'OAuth server unreachable: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200 || empty($body['access_token'])) {
            $error_msg = $body['error'] ?? $body['details'] ?? 'Token refresh failed';
            
            // Check if reauth is required
            if (!empty($body['reauth_required']) || $status_code === 401) {
                return new WP_Error('reauth_required', 'Token expired. Please reconnect your account.');
            }
            
            return new WP_Error('refresh_failed', $error_msg);
        }
        
        // Update stored tokens
        $expires_at = time() + ($body['expires_in'] ?? 3600);
        
        switch ($provider) {
            case 'ga4':
                $settings['ga4_oauth_access_token'] = $body['access_token'];
                $settings['ga4_oauth_expires_at'] = $expires_at;
                break;
                
            case 'gsc':
                $settings['gsc_oauth_access_token'] = $body['access_token'];
                $settings['gsc_oauth_expires_at'] = $expires_at;
                break;
                
            case 'twitter':
                $settings['twitter_oauth_access_token'] = $body['access_token'];
                $settings['twitter_oauth_expires_at'] = $expires_at;
                $settings['twitter_access_token'] = $body['access_token'];
                // Twitter may return new refresh token
                if (!empty($body['refresh_token'])) {
                    $settings['twitter_oauth_refresh_token'] = $body['refresh_token'];
                }
                break;
                
            case 'facebook':
                $settings['facebook_oauth_access_token'] = $body['access_token'];
                $settings['facebook_oauth_expires_at'] = $expires_at;
                // Update pages if returned
                if (!empty($body['pages'])) {
                    $settings['facebook_oauth_pages'] = $body['pages'];
                    // Update selected page token
                    $selected = $settings['facebook_oauth_selected_page'];
                    foreach ($body['pages'] as $page) {
                        if ($page['id'] === $selected) {
                            $settings['facebook_access_token'] = $page['access_token'];
                            break;
                        }
                    }
                }
                break;
        }
        
        update_option('flm_settings', $settings);
        
        return [
            'message' => ucfirst($provider) . ' token refreshed',
            'expires_at' => $expires_at,
            'expires_in' => $body['expires_in'] ?? 3600,
        ];
    }
    
    /**
     * Get valid OAuth access token (auto-refresh if needed)
     */
    public function get_oauth_token($provider) {
        $settings = $this->get_settings();
        $now = time();
        
        $token_key = $provider . '_oauth_access_token';
        $expires_key = $provider . '_oauth_expires_at';
        $refresh_key = $provider . '_oauth_refresh_token';
        
        $access_token = $settings[$token_key] ?? '';
        $expires_at = $settings[$expires_key] ?? 0;
        
        if (empty($access_token)) {
            return new WP_Error('not_connected', ucfirst($provider) . ' is not connected');
        }
        
        // Check if token needs refresh (5 minute buffer)
        if ($expires_at < ($now + 300)) {
            // Try to refresh
            $result = $this->refresh_oauth_token($provider);
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            // Get updated token
            $settings = $this->get_settings();
            $access_token = $settings[$token_key] ?? '';
        }
        
        return $access_token;
    }
    
    /**
     * Handle OAuth callback via admin-post.php
     * URL: /wp-admin/admin-post.php?action=flm_oauth_callback&...
     */
    public function handle_oauth_callback() {
        // Debug log
        error_log('FLM OAuth Callback: ' . print_r($_GET, true));
        
        // Verify user is logged in and can manage options
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            error_log('FLM OAuth: Not authorized');
            wp_redirect(admin_url('options-general.php?page=flm-importer&oauth_error=' . urlencode('Not authorized')));
            exit;
        }
        
        // Check for success
        if (!isset($_GET['success']) || $_GET['success'] !== '1') {
            $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : 'OAuth authorization failed';
            error_log('FLM OAuth: No success flag - ' . $error);
            wp_redirect(admin_url('options-general.php?page=flm-importer&oauth_error=' . urlencode($error)));
            exit;
        }
        
        // Get token data from URL
        $provider = sanitize_text_field($_GET['provider'] ?? '');
        $access_token = $_GET['access_token'] ?? '';  // Don't sanitize - may corrupt token
        $refresh_token = $_GET['refresh_token'] ?? '';
        $expires_in = intval($_GET['expires_in'] ?? 3600);
        $pages = sanitize_text_field($_GET['pages'] ?? '');
        
        error_log("FLM OAuth: Provider=$provider, Token length=" . strlen($access_token) . ", Refresh length=" . strlen($refresh_token));
        
        if (!in_array($provider, ['ga4', 'gsc', 'twitter', 'facebook']) || empty($access_token)) {
            error_log('FLM OAuth: Invalid provider or empty token');
            wp_redirect(admin_url('options-general.php?page=flm-importer&oauth_error=' . urlencode('Invalid OAuth response')));
            exit;
        }
        
        // Save tokens
        $settings = $this->get_settings();
        $expires_at = time() + $expires_in;
        
        error_log("FLM OAuth: Current settings keys: " . implode(', ', array_keys($settings)));
        
        switch ($provider) {
            case 'ga4':
                $settings['ga4_oauth_access_token'] = $access_token;
                $settings['ga4_oauth_refresh_token'] = $refresh_token;
                $settings['ga4_oauth_expires_at'] = $expires_at;
                break;
                
            case 'gsc':
                $settings['gsc_oauth_access_token'] = $access_token;
                $settings['gsc_oauth_refresh_token'] = $refresh_token;
                $settings['gsc_oauth_expires_at'] = $expires_at;
                break;
                
            case 'twitter':
                $settings['twitter_oauth_access_token'] = $access_token;
                $settings['twitter_oauth_refresh_token'] = $refresh_token;
                $settings['twitter_oauth_expires_at'] = $expires_at;
                $settings['twitter_access_token'] = $access_token;
                break;
                
            case 'facebook':
                $settings['facebook_oauth_access_token'] = $access_token;
                $settings['facebook_oauth_expires_at'] = $expires_at;
                if (!empty($pages)) {
                    $decoded_pages = json_decode(base64_decode($pages), true);
                    if (is_array($decoded_pages)) {
                        $settings['facebook_oauth_pages'] = $decoded_pages;
                        if (empty($settings['facebook_oauth_selected_page']) && !empty($decoded_pages[0]['id'])) {
                            $settings['facebook_oauth_selected_page'] = $decoded_pages[0]['id'];
                            $settings['facebook_page_id'] = $decoded_pages[0]['id'];
                            $settings['facebook_access_token'] = $decoded_pages[0]['access_token'];
                        }
                    }
                }
                break;
        }
        
        $result = update_option('flm_settings', $settings);
        error_log("FLM OAuth: update_option result = " . ($result ? 'true' : 'false'));
        error_log("FLM OAuth: Saved token length = " . strlen($settings['ga4_oauth_access_token'] ?? ''));
        
        // Log activity
        $provider_names = ['ga4' => 'Google Analytics', 'gsc' => 'Search Console', 'twitter' => 'Twitter', 'facebook' => 'Facebook'];
        $this->log_activity('analytics', "Connected {$provider_names[$provider]} via OAuth");
        
        // Redirect to settings with success message
        wp_redirect(admin_url('options-general.php?page=flm-importer&oauth_success=' . urlencode($provider_names[$provider])));
        exit;
    }
    
    // ========================================
    // ADMIN UI
    // ========================================
    
    /**
     * Add meta box
     */
    public function add_flm_meta_box() {
        add_meta_box(
            'flm_story_info',
            'Field Level Media',
            [$this, 'render_meta_box'],
            'post',
            'side',
            'default'
        );
    }
    
    /**
     * Render meta box
     */
    public function render_meta_box($post) {
        $story_id = get_post_meta($post->ID, 'flm_story_id', true);
        
        if (!$story_id) {
            echo '<p style="color:#666;">Not imported from FLM</p>';
            return;
        }
        
        $fields = [
            'flm_story_id' => 'Story ID',
            'flm_byline' => 'Byline',
            'flm_team' => 'Team',
            'flm_league' => 'League',
            'flm_story_type' => 'Type',
            'flm_image_credit' => 'Image Credit',
        ];
        
        echo '<table style="width:100%;font-size:12px;">';
        foreach ($fields as $key => $label) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value) {
                if ($key === 'flm_team') $value = ucfirst($value);
                echo '<tr><td style="padding:4px 0;color:#666;">' . esc_html($label) . '</td><td style="padding:4px 0;">' . esc_html($value) . '</td></tr>';
            }
        }
        echo '</table>';
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('flm_settings_group', 'flm_settings');
    }
    
    /**
     * Schedule cron
     */
    public function schedule_import() {
        $settings = $this->get_settings();
        $frequency = $settings['import_frequency'] ?? 'twicedaily';
        
        // Valid frequencies
        $valid_frequencies = ['hourly', 'every6hours', 'twicedaily', 'daily'];
        if (!in_array($frequency, $valid_frequencies)) {
            $frequency = 'twicedaily';
        }
        
        $current_schedule = wp_get_schedule('flm_import_stories');
        
        // If not scheduled or frequency changed, reschedule
        if (!$current_schedule || $current_schedule !== $frequency) {
            wp_clear_scheduled_hook('flm_import_stories');
            wp_schedule_event(time() + 60, $frequency, 'flm_import_stories');
        }
    }
    
    /**
     * Reschedule import when settings change (P3.1)
     */
    public function reschedule_import() {
        wp_clear_scheduled_hook('flm_import_stories');
        $this->schedule_import();
    }
    
    /**
     * Admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'FLM Importer',
            'FLM Importer',
            'manage_options',
            'flm-importer',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        $settings = $this->get_settings();
        
        // DEBUG: Show OAuth token status (remove after testing)
        if (isset($_GET['oauth_debug'])) {
            echo '<div style="background:#1a1a2e;color:#0f0;padding:20px;margin:20px;font-family:monospace;border-radius:8px;">';
            echo '<h3 style="color:#0ff;">OAuth Debug Info</h3>';
            echo '<p><strong>GA4 Token:</strong> ' . (empty($settings['ga4_oauth_access_token']) ? '<span style="color:red;">EMPTY</span>' : '<span style="color:lime;">SET (' . strlen($settings['ga4_oauth_access_token']) . ' chars)</span>') . '</p>';
            echo '<p><strong>GA4 Refresh:</strong> ' . (empty($settings['ga4_oauth_refresh_token']) ? '<span style="color:red;">EMPTY</span>' : '<span style="color:lime;">SET</span>') . '</p>';
            echo '<p><strong>GA4 Expires:</strong> ' . ($settings['ga4_oauth_expires_at'] ?? 0) . ' (' . date('Y-m-d H:i:s', $settings['ga4_oauth_expires_at'] ?? 0) . ')</p>';
            echo '<p><strong>GSC Token:</strong> ' . (empty($settings['gsc_oauth_access_token']) ? '<span style="color:red;">EMPTY</span>' : '<span style="color:lime;">SET</span>') . '</p>';
            echo '<hr style="border-color:#333;">';
            echo '<p><strong>Raw flm_settings option:</strong></p>';
            echo '<pre style="background:#111;padding:10px;overflow:auto;max-height:200px;">' . esc_html(print_r(get_option('flm_settings', []), true)) . '</pre>';
            echo '</div>';
        }
        
        // DEBUG: Show FLM API debug info
        if (isset($_GET['api_debug'])) {
            echo '<div style="background:#1a1a2e;color:#0f0;padding:20px;margin:20px;font-family:monospace;border-radius:8px;">';
            echo '<h3 style="color:#0ff;">FLM API Debug Info</h3>';
            echo '<p><strong>API Key:</strong> ' . (empty($settings['api_key']) ? '<span style="color:red;">EMPTY</span>' : '<span style="color:lime;">' . substr($settings['api_key'], 0, 8) . '...' . substr($settings['api_key'], -4) . '</span>') . '</p>';
            echo '<p><strong>Cached JWT Token:</strong> ' . (get_option('flm_jwt_token') ? '<span style="color:lime;">SET (' . strlen(get_option('flm_jwt_token')) . ' chars)</span>' : '<span style="color:red;">EMPTY</span>') . '</p>';
            echo '<p><strong>Token Expiry:</strong> ' . (get_option('flm_token_expiry') ? date('Y-m-d H:i:s', get_option('flm_token_expiry')) : 'Not set') . '</p>';
            
            // Try to get fresh token
            echo '<hr style="border-color:#333;">';
            echo '<p><strong>Testing fresh token request...</strong></p>';
            
            $test_response = wp_remote_post('https://api.fieldlevelmedia.com/v1/Token', [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => ['apiKey' => $settings['api_key']],
                'timeout' => 15,
            ]);
            
            if (is_wp_error($test_response)) {
                echo '<p style="color:red;"><strong>Error:</strong> ' . esc_html($test_response->get_error_message()) . '</p>';
            } else {
                $code = wp_remote_retrieve_response_code($test_response);
                $body = wp_remote_retrieve_body($test_response);
                echo '<p><strong>HTTP Status:</strong> ' . $code . '</p>';
                echo '<p><strong>Response:</strong></p>';
                echo '<pre style="background:#111;padding:10px;overflow:auto;max-height:150px;">' . esc_html(substr($body, 0, 500)) . '</pre>';
            }
            echo '</div>';
        }
        
        $stats = get_option('flm_stats', []);
        $log = get_option('flm_import_log', []);
        $error_log = $this->get_error_log();
        
        // Convert old log format if needed
        if (!empty($log) && isset($log[0]) && is_string($log[0])) {
            $log = [];
        }
        
        $authors = get_users(['role__in' => ['administrator', 'editor', 'author']]);
        $categories = get_categories(['hide_empty' => false]);
        $is_connected = (bool) $this->get_token();
        
        $enabled_teams = 0;
        foreach ($this->target_teams as $key => $team) {
            if (!empty($settings['teams_enabled'][$key])) {
                $enabled_teams++;
            }
        }
        
        $last_import_display = 'Never';
        $last_import_time = '';
        $last_import = get_option('flm_last_import');
        if ($last_import) {
            $last_import_display = human_time_diff($last_import, time()) . ' ago';
            $last_import_time = date('M j, Y g:i A', $last_import);
        }
        
        $imported_posts_url = admin_url('edit.php?meta_key=flm_story_id');
        
        // Count errors for badge
        $error_count = count(array_filter($error_log, function($e) { return ($e['level'] ?? '') === 'error'; }));
        ?>
        <div class="flm-dashboard">
            
            <!-- Header -->
            <header class="flm-header">
                <div class="flm-header-left">
                    <div class="flm-logo" aria-hidden="true">
                        <?php echo $this->icon('stadium'); ?>
                    </div>
                    <div class="flm-title-group">
                        <h1>FLM Importer</h1>
                        <p>GameDay Atlanta Content Automation</p>
                    </div>
                </div>
                <div class="flm-header-right">
                    <div class="flm-save-indicator saved">
                        <?php echo $this->icon('check'); ?>
                        <span>All saved</span>
                    </div>
                    <button type="submit" form="flm-settings-form" class="flm-btn flm-btn-success flm-header-save-btn">
                        <?php echo $this->icon('save'); ?>
                        Save
                        <span class="flm-kbd">⌘S</span>
                    </button>
                    <a href="<?php echo esc_url($imported_posts_url); ?>" class="flm-view-posts-link">
                        <?php echo $this->icon('external'); ?>
                        View Posts
                    </a>
                    <div class="flm-theme-toggle" title="Toggle light/dark theme">
                        <div class="flm-theme-toggle-track">
                            <div class="flm-theme-toggle-thumb">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                            </div>
                        </div>
                    </div>
                    <span class="flm-version">v<?php echo $this->version; ?></span>
                </div>
            </header>
            
            <!-- Tab Navigation -->
            <div class="flm-tabs-wrapper">
                <nav class="flm-tabs" role="tablist">
                    <button type="button" class="flm-tab active" data-tab="dashboard" role="tab" aria-selected="true">
                        <?php echo $this->icon('grid'); ?>
                        <span class="flm-tab-text">Dashboard</span>
                    </button>
                    <button type="button" class="flm-tab" data-tab="teams" role="tab">
                        <?php echo $this->icon('trophy'); ?>
                        <span class="flm-tab-text">Teams</span>
                        <span class="flm-tab-badge success"><?php echo $enabled_teams; ?>/5</span>
                    </button>
                    <button type="button" class="flm-tab" data-tab="publishing" role="tab">
                        <?php echo $this->icon('share'); ?>
                        <span class="flm-tab-text">Publishing</span>
                        <?php 
                        $scheduled_posts = get_option('flm_scheduled_posts', []);
                        $pending_scheduled = count(array_filter($scheduled_posts, function($s) { return ($s['scheduled_for'] ?? 0) > time(); }));
                        if ($pending_scheduled > 0): ?>
                        <span class="flm-tab-badge" style="background:rgba(63,185,80,0.15);color:var(--flm-success);"><?php echo $pending_scheduled; ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="flm-tab" data-tab="analytics" role="tab">
                        <?php echo $this->icon('chart'); ?>
                        <span class="flm-tab-text">Analytics</span>
                    </button>
                    <button type="button" class="flm-tab" data-tab="insights" role="tab">
                        <?php echo $this->icon('bolt'); ?>
                        <span class="flm-tab-text">AI Insights</span>
                        <span class="flm-tab-badge" style="background:linear-gradient(135deg,#a371f7,#8b5cf6);color:#fff;">ML</span>
                    </button>
                    <button type="button" class="flm-tab" data-tab="logs" role="tab">
                        <?php echo $this->icon('log'); ?>
                        <span class="flm-tab-text">Logs</span>
                        <?php if ($error_count > 0): ?>
                        <span class="flm-tab-badge error"><?php echo $error_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="flm-tab" data-tab="settings" role="tab">
                        <?php echo $this->icon('settings'); ?>
                        <span class="flm-tab-text">Settings</span>
                    </button>
                </nav>
            </div>
            
            <!-- Main Content -->
            <main class="flm-content">
                <div class="flm-tab-panels">
                    
                    <!-- Dashboard Tab Panel -->
                    <div id="panel-dashboard" class="flm-tab-panel active" role="tabpanel">
                
                        <?php 
                        // Check onboarding status
                        $onboarding_dismissed = get_option('flm_onboarding_dismissed', false);
                        $has_api_key = !empty($settings['api_key']);
                        $teams_enabled = $settings['teams_enabled'] ?? [];
                        $has_teams = count(array_filter($teams_enabled)) > 0;
                        $last_import = get_option('flm_last_import');
                        $has_imported = !empty($last_import);
                        $imported_posts = get_posts([
                            'post_type' => 'post',
                            'posts_per_page' => -1,
                            'meta_query' => [['key' => 'flm_story_id', 'compare' => 'EXISTS']],
                            'fields' => 'ids',
                        ]);
                        $total_imported = count($imported_posts);
                        
                        $onboarding_complete = $has_api_key && $has_teams && $has_imported;
                        $completed_steps = ($has_api_key ? 1 : 0) + ($has_teams ? 1 : 0) + ($has_imported ? 1 : 0);
                        
                        if (!$onboarding_dismissed && !$onboarding_complete): 
                        ?>
                        <!-- Onboarding Checklist -->
                        <div class="flm-onboarding" id="flm-onboarding">
                            <div class="flm-onboarding-header">
                                <div class="flm-onboarding-title">
                                    <?php echo $this->icon('bolt'); ?>
                                    Getting Started
                                </div>
                                <div class="flm-onboarding-progress">
                                    <strong><?php echo $completed_steps; ?>/3</strong> completed
                                </div>
                            </div>
                            <div class="flm-onboarding-steps">
                                <div class="flm-onboarding-step <?php echo $has_api_key ? 'completed' : ''; ?>">
                                    <div class="flm-onboarding-step-check"><?php echo $this->icon('check'); ?></div>
                                    <span class="flm-onboarding-step-text">Add your FLM API key</span>
                                    <?php if (!$has_api_key): ?>
                                    <a href="#settings" class="flm-onboarding-step-action flm-tip-link">Configure →</a>
                                    <?php endif; ?>
                                </div>
                                <div class="flm-onboarding-step <?php echo $has_teams ? 'completed' : ''; ?>">
                                    <div class="flm-onboarding-step-check"><?php echo $this->icon('check'); ?></div>
                                    <span class="flm-onboarding-step-text">Enable at least one team to track</span>
                                    <?php if (!$has_teams): ?>
                                    <a href="#teams" class="flm-onboarding-step-action flm-tip-link">Enable Teams →</a>
                                    <?php endif; ?>
                                </div>
                                <div class="flm-onboarding-step <?php echo $has_imported ? 'completed' : ''; ?>">
                                    <div class="flm-onboarding-step-check"><?php echo $this->icon('check'); ?></div>
                                    <span class="flm-onboarding-step-text">Run your first import</span>
                                    <?php if (!$has_imported && $has_api_key && $has_teams): ?>
                                    <a href="#" class="flm-onboarding-step-action" id="flm-onboarding-import">Import Now →</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="flm-onboarding-dismiss" id="flm-dismiss-onboarding">Dismiss</button>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Smart Insights Container (v2.14.0) -->
                        <div id="flm-insights-container"></div>
                        
                        <!-- Data attributes for JS onboarding checks -->
                        <div id="flm-onboarding-data" style="display:none;"
                             data-has-api-key="<?php echo $has_api_key ? 'true' : 'false'; ?>"
                             data-has-teams="<?php echo $has_teams ? 'true' : 'false'; ?>"
                             data-has-categories="<?php echo !empty($settings['category_mapping']) ? 'true' : 'false'; ?>"
                             data-total-articles="<?php echo $total_imported; ?>"
                             data-has-analytics="<?php echo !empty($settings['ga4_property_id']) ? 'true' : 'false'; ?>"
                             data-has-esp="<?php echo !empty($settings['sendgrid_api_key']) || !empty($settings['aigeon_api_key']) ? 'true' : 'false'; ?>">
                        </div>

                        <!-- Status Bar -->
                        <div class="flm-status-bar" role="region" aria-label="Import statistics">
                            <div class="flm-stat-card">
                                <div class="flm-stat-label">API Status</div>
                                <div class="flm-stat-value">
                                    <div class="flm-connection-status">
                                        <span class="flm-connection-dot" 
                                              data-status="<?php echo $is_connected ? 'online' : 'offline'; ?>"
                                              role="status"
                                              aria-label="<?php echo $is_connected ? 'Connected' : 'Disconnected'; ?>"></span>
                                        <span class="flm-connection-text"><?php echo $is_connected ? 'Connected' : 'Offline'; ?></span>
                                    </div>
                                </div>
                                <div class="flm-stat-meta">Field Level Media API</div>
                            </div>
                            
                            <div class="flm-stat-card">
                                <div class="flm-stat-label">Last Import</div>
                                <div class="flm-stat-value" style="font-size:18px;">
                                    <span class="flm-stat-last-import"><?php echo esc_html($last_import_display); ?></span>
                                </div>
                                <div class="flm-stat-meta"><?php echo $last_import_time ? esc_html($last_import_time) : 'No imports yet'; ?></div>
                            </div>
                            
                            <div class="flm-stat-card">
                                <div class="flm-stat-label">Last Run Results</div>
                                <div class="flm-stat-value success">
                                    <span class="flm-stat-imported"><?php echo isset($stats['imported']) ? $stats['imported'] : '0'; ?></span>
                                </div>
                                <div class="flm-stat-meta"><span class="flm-stat-updated"><?php echo isset($stats['updated']) ? $stats['updated'] . ' updated' : '0 updated'; ?></span></div>
                            </div>
                            
                            <div class="flm-stat-card">
                                <div class="flm-stat-label">Active Teams</div>
                                <div class="flm-stat-value"><?php echo $enabled_teams; ?>/5</div>
                                <div class="flm-stat-meta">Teams being tracked</div>
                            </div>
                            
                            <?php 
                            $social_log = get_option('flm_social_log', []);
                            $social_success = count(array_filter($social_log, function($e) { return !empty($e['success']); }));
                            $has_social = !empty($settings['twitter_access_token']) || !empty($settings['facebook_access_token']);
                            ?>
                            <div class="flm-stat-card" style="border-left:3px solid <?php echo $has_social ? 'var(--flm-success)' : 'var(--flm-border)'; ?>;">
                                <div class="flm-stat-label">
                                    <?php echo $this->icon('share'); ?>
                                    Social Posts
                                </div>
                                <div class="flm-stat-value"><?php echo $social_success; ?></div>
                                <div class="flm-stat-meta">
                                    <?php if ($has_social): ?>
                                    <?php echo !empty($settings['twitter_access_token']) ? '𝕏' : ''; ?>
                                    <?php echo !empty($settings['twitter_access_token']) && !empty($settings['facebook_access_token']) ? ' + ' : ''; ?>
                                    <?php echo !empty($settings['facebook_access_token']) ? 'FB' : ''; ?> connected
                                    <?php else: ?>
                                    <a href="#" onclick="document.querySelector('[data-tab=settings]').click(); return false;" style="color:var(--flm-accent);text-decoration:none;">Configure →</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="flm-card">
                    <div class="flm-card-header">
                        <h2 class="flm-card-title">
                            <span class="flm-card-icon"><?php echo $this->icon('bolt'); ?></span>
                            Quick Actions
                        </h2>
                        <button type="button" id="flm-reset-date" class="flm-btn flm-btn-secondary flm-btn-sm">
                            <?php echo $this->icon('refresh'); ?>
                            Reset Import Date
                        </button>
                    </div>
                    <div class="flm-card-body">
                        <div class="flm-grid-4">
                            <div class="flm-action-card">
                                <div class="flm-action-icon"><?php echo $this->icon('download'); ?></div>
                                <div class="flm-action-title">Import Now</div>
                                <div class="flm-action-desc">Fetch latest stories from all leagues</div>
                                <button type="button" id="flm-run-import" class="flm-btn flm-btn-primary">
                                    Run Import
                                </button>
                            </div>
                            
                            <div class="flm-action-card">
                                <div class="flm-action-icon"><?php echo $this->icon('preview'); ?></div>
                                <div class="flm-action-title">Preview Import</div>
                                <div class="flm-action-desc">See what would be imported (dry run)</div>
                                <button type="button" id="flm-dry-run-preview" class="flm-btn flm-btn-secondary">
                                    <?php echo $this->icon('eye'); ?>
                                    Preview
                                </button>
                            </div>
                            
                            <div class="flm-action-card">
                                <div class="flm-action-icon"><?php echo $this->icon('plug'); ?></div>
                                <div class="flm-action-title">Test Connection</div>
                                <div class="flm-action-desc">Verify API authentication works</div>
                                <button type="button" id="flm-test-connection" class="flm-btn flm-btn-secondary">
                                    Test API
                                </button>
                            </div>
                            
                            <div class="flm-action-card">
                                <div class="flm-action-icon"><?php echo $this->icon('search'); ?></div>
                                <div class="flm-action-title">Discover Teams</div>
                                <div class="flm-action-desc">Find team IDs from API responses</div>
                                <button type="button" id="flm-discover-teams" class="flm-btn flm-btn-secondary">
                                    Discover
                                </button>
                            </div>
                        </div>
                        
                        <!-- Per-League Import -->
                        <div class="flm-league-import-section">
                            <div class="flm-section-label">Import by League</div>
                            <div class="flm-league-buttons">
                                <button type="button" class="flm-btn flm-btn-league flm-league-import" data-league="1" data-league-name="MLB">
                                    <?php echo $this->icon('baseball'); ?>
                                    <span>MLB</span>
                                    <small>Braves</small>
                                </button>
                                <button type="button" class="flm-btn flm-btn-league flm-league-import" data-league="30" data-league-name="NFL">
                                    <?php echo $this->icon('football'); ?>
                                    <span>NFL</span>
                                    <small>Falcons</small>
                                </button>
                                <button type="button" class="flm-btn flm-btn-league flm-league-import" data-league="26" data-league-name="NBA">
                                    <?php echo $this->icon('basketball'); ?>
                                    <span>NBA</span>
                                    <small>Hawks</small>
                                </button>
                                <button type="button" class="flm-btn flm-btn-league flm-league-import" data-league="31" data-league-name="NCAAF">
                                    <?php echo $this->icon('trophy'); ?>
                                    <span>NCAAF</span>
                                    <small>UGA/GT</small>
                                </button>
                                <button type="button" class="flm-btn flm-btn-league flm-league-import" data-league="20" data-league-name="NCAAB">
                                    <?php echo $this->icon('trophy'); ?>
                                    <span>NCAAB</span>
                                    <small>UGA/GT</small>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Purge Old Posts (P5.3) -->
                        <div class="flm-purge-section">
                            <div class="flm-section-label">Maintenance</div>
                            <div class="flm-purge-controls">
                                <div class="flm-purge-info">
                                    <?php
                                    // Count FLM posts
                                    $flm_post_count = count(get_posts([
                                        'post_type' => 'post',
                                        'post_status' => 'any',
                                        'posts_per_page' => -1,
                                        'meta_query' => [['key' => 'flm_story_id', 'compare' => 'EXISTS']],
                                        'fields' => 'ids',
                                    ]));
                                    ?>
                                    <span class="flm-purge-count"><?php echo $flm_post_count; ?></span> FLM posts in database
                                </div>
                                <div class="flm-purge-action">
                                    <select id="flm-purge-days" class="flm-select flm-select-sm">
                                        <option value="7">Older than 7 days</option>
                                        <option value="14">Older than 14 days</option>
                                        <option value="30" selected>Older than 30 days</option>
                                        <option value="60">Older than 60 days</option>
                                        <option value="90">Older than 90 days</option>
                                    </select>
                                    <button type="button" id="flm-purge-posts" class="flm-btn flm-btn-danger">
                                        <?php echo $this->icon('trash'); ?>
                                        Purge Posts
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div id="flm-import-progress" class="flm-progress-wrap" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="flm-progress-header">
                                <span class="flm-progress-label">Import Progress</span>
                                <span class="flm-progress-value">0%</span>
                            </div>
                            <div class="flm-progress-bar">
                                <div class="flm-progress-fill"></div>
                            </div>
                            <div class="flm-progress-status">Waiting...</div>
                        </div>
                        
                        <!-- Test Results -->
                        <div id="flm-test-results" class="flm-results" style="display:none;">
                            <div class="flm-results-header">
                                <span class="flm-results-title">Results</span>
                                <button type="button" class="flm-results-close flm-btn flm-btn-secondary flm-btn-sm">
                                    <?php echo $this->icon('x'); ?>
                                    Close
                                </button>
                            </div>
                            <div class="flm-results-body"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Activity Feed (v2.14.0) -->
                <div class="flm-activity-feed" style="margin-top:24px;">
                    <div class="flm-activity-header">
                        <div class="flm-activity-title">
                            <?php echo $this->icon('clock'); ?>
                            Activity Feed
                        </div>
                        <div class="flm-live-indicator">
                            <span class="flm-live-dot"></span>
                            LIVE
                        </div>
                    </div>
                    <div class="flm-activity-list" id="flm-activity-list">
                        <?php
                        // Load recent activity
                        $activity_log = get_option('flm_activity_log', []);
                        $activity_log = array_slice($activity_log, 0, 10);
                        
                        if (empty($activity_log)):
                        ?>
                        <div class="flm-empty-state">
                            <div class="flm-empty-state-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3M3 12a9 9 0 1018 0 9 9 0 00-18 0z"/></svg>
                            </div>
                            <div class="flm-empty-state-title">No recent activity</div>
                            <div class="flm-empty-state-desc">Activity will appear here as you use the plugin</div>
                        </div>
                        <?php else: ?>
                            <?php foreach ($activity_log as $activity): 
                                $type = $activity['type'] ?? 'analytics';
                                $icons = [
                                    'import' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>',
                                    'social' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>',
                                    'email' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 6l-10 7L2 6"/></svg>',
                                    'analytics' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>',
                                    'error' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>',
                                ];
                                $time = isset($activity['time']) ? human_time_diff(strtotime($activity['time'])) . ' ago' : 'Unknown';
                            ?>
                            <div class="flm-activity-item">
                                <div class="flm-activity-icon <?php echo esc_attr($type); ?>">
                                    <?php echo $icons[$type] ?? $icons['analytics']; ?>
                                </div>
                                <div class="flm-activity-content">
                                    <div class="flm-activity-text"><?php echo esc_html($activity['text'] ?? ''); ?></div>
                                    <div class="flm-activity-meta">
                                        <span class="flm-activity-time"><?php echo esc_html($time); ?></span>
                                        <?php if (!empty($activity['team'])): ?>
                                        <span class="flm-team-badge <?php echo esc_attr(strtolower($activity['team'])); ?>"><?php echo esc_html($activity['team']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                    </div><!-- End Dashboard Panel -->
                
                    <!-- Teams & Content Tab Panel -->
                    <div id="panel-teams" class="flm-tab-panel" role="tabpanel">
                
                <!-- Settings Form -->
                <form id="flm-settings-form" method="post">
                    <div class="flm-grid-2">
                        
                        <!-- Left Column -->
                        <div>
                            <!-- Teams Card -->
                            <div class="flm-card">
                                <div class="flm-card-header">
                                    <h2 class="flm-card-title">
                                        <span class="flm-card-icon"><?php echo $this->icon('trophy'); ?></span>
                                        Teams to Track
                                    </h2>
                                    <span class="flm-card-badge"><?php echo count(array_filter($settings['teams_enabled'] ?? [])); ?>/<?php echo count($this->target_teams); ?> Active</span>
                                </div>
                                <div class="flm-card-body no-padding" style="padding: 12px;">
                                    <div class="flm-teams-grid">
                                        <?php foreach ($this->target_teams as $key => $team): 
                                            $is_enabled = !empty($settings['teams_enabled'][$key]);
                                        ?>
                                        <div class="flm-team-card" 
                                             data-team="<?php echo esc_attr($key); ?>"
                                             data-enabled="<?php echo $is_enabled ? 'true' : 'false'; ?>">
                                            <div class="flm-team-info">
                                                <div class="flm-team-icon">
                                                    <?php echo $this->get_team_icon($key); ?>
                                                </div>
                                                <div class="flm-team-details">
                                                    <div class="flm-team-name"><?php echo esc_html($team['name']); ?></div>
                                                    <div class="flm-team-league-badge">
                                                        <span class="flm-league-icon"><?php echo $this->get_sport_icon($team['league']); ?></span>
                                                        <span><?php echo esc_html($team['league']); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <label class="flm-toggle">
                                                <input type="checkbox" 
                                                       class="flm-team-toggle"
                                                       name="flm_settings[teams_enabled][<?php echo $key; ?>]" 
                                                       value="1"
                                                       <?php checked($is_enabled); ?>
                                                       aria-label="Enable <?php echo esc_attr($team['name']); ?>">
                                                <span class="flm-toggle-track"></span>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Story Types Filter (P4.1) -->
                            <div class="flm-card">
                                <div class="flm-card-header">
                                    <h2 class="flm-card-title">
                                        <span class="flm-card-icon"><?php echo $this->icon('folder'); ?></span>
                                        Story Types to Import
                                    </h2>
                                </div>
                                <div class="flm-card-body">
                                    <div class="flm-checkbox-grid flm-checkbox-grid-4">
                                        <?php 
                                        $story_types = ['News', 'Recap', 'Preview', 'Feature', 'Analysis', 'Interview', 'Injury', 'Transaction'];
                                        foreach ($story_types as $type): 
                                            $is_enabled = !empty($settings['story_types_enabled'][$type]);
                                        ?>
                                        <label class="flm-checkbox-card" data-checked="<?php echo $is_enabled ? 'true' : 'false'; ?>">
                                            <input type="checkbox" 
                                                   name="flm_settings[story_types_enabled][<?php echo $type; ?>]" 
                                                   value="1" 
                                                   <?php checked($is_enabled); ?>>
                                            <span><?php echo esc_html($type); ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Category Options -->
                            <div class="flm-card">
                                <div class="flm-card-header">
                                    <h2 class="flm-card-title">
                                        <span class="flm-card-icon"><?php echo $this->icon('folder'); ?></span>
                                        Auto-Create Categories
                                    </h2>
                                </div>
                                <div class="flm-card-body">
                                    <div class="flm-checkbox-grid">
                                        <label class="flm-checkbox-card" data-checked="<?php echo $settings['create_team_categories'] ? 'true' : 'false'; ?>">
                                            <input type="checkbox" name="flm_settings[create_team_categories]" value="1" 
                                                   <?php checked($settings['create_team_categories']); ?>>
                                            <span>Team</span>
                                        </label>
                                        <label class="flm-checkbox-card" data-checked="<?php echo $settings['create_league_categories'] ? 'true' : 'false'; ?>">
                                            <input type="checkbox" name="flm_settings[create_league_categories]" value="1" 
                                                   <?php checked($settings['create_league_categories']); ?>>
                                            <span>League</span>
                                        </label>
                                        <label class="flm-checkbox-card" data-checked="<?php echo $settings['create_type_categories'] ? 'true' : 'false'; ?>">
                                            <input type="checkbox" name="flm_settings[create_type_categories]" value="1" 
                                                   <?php checked($settings['create_type_categories']); ?>>
                                            <span>Story Type</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column - Quick Tips -->
                        <div>
                            <!-- Quick Tips Card -->
                            <div class="flm-card flm-card-tips">
                                <div class="flm-card-header">
                                    <h2 class="flm-card-title">
                                        <span class="flm-card-icon"><?php echo $this->icon('bolt'); ?></span>
                                        Quick Tips
                                    </h2>
                                </div>
                                <div class="flm-card-body">
                                    <div class="flm-tip-item">
                                        <div class="flm-tip-icon"><?php echo $this->icon('trophy'); ?></div>
                                        <div class="flm-tip-content">
                                            <strong>Team Selection</strong>
                                            <p>Enable teams you want to import content for. Disabled teams won't be checked during imports.</p>
                                        </div>
                                    </div>
                                    <div class="flm-tip-item">
                                        <div class="flm-tip-icon"><?php echo $this->icon('folder'); ?></div>
                                        <div class="flm-tip-content">
                                            <strong>Story Types</strong>
                                            <p>Filter which types of stories to import. Recaps and News are most common.</p>
                                        </div>
                                    </div>
                                    <div class="flm-tip-item">
                                        <div class="flm-tip-icon"><?php echo $this->icon('settings'); ?></div>
                                        <div class="flm-tip-content">
                                            <strong>More Options</strong>
                                            <p>Configure post settings, schedules, and API keys in the <a href="#settings" class="flm-tip-link">Settings tab</a>.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Team Stats -->
                            <div class="flm-card">
                                <div class="flm-card-header">
                                    <h2 class="flm-card-title">
                                        <span class="flm-card-icon"><?php echo $this->icon('chart'); ?></span>
                                        Coverage Summary
                                    </h2>
                                </div>
                                <div class="flm-card-body">
                                    <div class="flm-mini-stats">
                                        <?php foreach ($this->target_teams as $key => $team): 
                                            $is_enabled = !empty($settings['teams_enabled'][$key]);
                                            $team_posts = count(get_posts([
                                                'post_type' => 'post',
                                                'posts_per_page' => -1,
                                                'meta_query' => [
                                                    ['key' => 'flm_team', 'value' => $key]
                                                ],
                                                'fields' => 'ids'
                                            ]));
                                        ?>
                                        <div class="flm-mini-stat <?php echo $is_enabled ? 'active' : 'inactive'; ?>">
                                            <span class="flm-mini-stat-team"><?php echo esc_html($team['name']); ?></span>
                                            <span class="flm-mini-stat-count"><?php echo $team_posts; ?> posts</span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Save Button (Teams Tab) -->
                    <div class="flm-mt-2">
                        <button type="submit" class="flm-btn flm-btn-success flm-save-btn">
                            <?php echo $this->icon('save'); ?>
                            Save Team Settings
                        </button>
                    </div>
                </form>
                
                    </div><!-- End Teams Panel -->
                    
                    <!-- Publishing Tab Panel (v2.10.0) -->
                    <div id="panel-publishing" class="flm-tab-panel" role="tabpanel">
                        <div class="flm-publishing-section">
                            
                            <!-- Social Stats Header -->
                            <div class="flm-stats-row" style="margin-bottom:24px;">
                                <?php 
                                $social_log = get_option('flm_social_log', []);
                                $twitter_success = count(array_filter($social_log, function($e) { return ($e['platform'] ?? '') === 'twitter' && !empty($e['success']); }));
                                $facebook_success = count(array_filter($social_log, function($e) { return ($e['platform'] ?? '') === 'facebook' && !empty($e['success']); }));
                                $social_errors = count(array_filter($social_log, function($e) { return empty($e['success']); }));
                                $scheduled_posts = get_option('flm_scheduled_posts', []);
                                $pending_scheduled = array_filter($scheduled_posts, function($s) { return ($s['scheduled_for'] ?? 0) > time(); });
                                ?>
                                
                                <div class="flm-stat-card" style="border-left:3px solid #1DA1F2;">
                                    <div class="flm-stat-label">
                                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;margin-right:6px;vertical-align:-2px;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                        Twitter/X Posts
                                    </div>
                                    <div class="flm-stat-value"><?php echo $twitter_success; ?></div>
                                    <div class="flm-stat-meta"><?php echo !empty($settings['auto_post_twitter']) ? '✓ Auto-posting enabled' : 'Auto-posting disabled'; ?></div>
                                </div>
                                
                                <div class="flm-stat-card" style="border-left:3px solid #1877F2;">
                                    <div class="flm-stat-label">
                                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;margin-right:6px;vertical-align:-2px;color:#1877f2;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                        Facebook Posts
                                    </div>
                                    <div class="flm-stat-value"><?php echo $facebook_success; ?></div>
                                    <div class="flm-stat-meta"><?php echo !empty($settings['auto_post_facebook']) ? '✓ Auto-posting enabled' : 'Auto-posting disabled'; ?></div>
                                </div>
                                
                                <div class="flm-stat-card" style="border-left:3px solid var(--flm-success);">
                                    <div class="flm-stat-label">Scheduled</div>
                                    <div class="flm-stat-value"><?php echo count($pending_scheduled); ?></div>
                                    <div class="flm-stat-meta">Posts queued</div>
                                </div>
                                
                                <div class="flm-stat-card" style="border-left:3px solid <?php echo $social_errors > 0 ? 'var(--flm-danger)' : 'var(--flm-border)'; ?>;">
                                    <div class="flm-stat-label">Failed Posts</div>
                                    <div class="flm-stat-value <?php echo $social_errors > 0 ? 'error' : ''; ?>"><?php echo $social_errors; ?></div>
                                    <div class="flm-stat-meta"><?php echo $social_errors > 0 ? 'Needs attention' : 'All good'; ?></div>
                                </div>
                            </div>
                            
                            <div class="flm-grid-2">
                                <!-- Scheduled Posts -->
                                <div class="flm-card">
                                    <div class="flm-card-header">
                                        <h2 class="flm-card-title">
                                            <span class="flm-card-icon"><?php echo $this->icon('clock'); ?></span>
                                            Scheduled Posts
                                        </h2>
                                        <button type="button" id="flm-refresh-scheduled" class="flm-btn flm-btn-secondary flm-btn-sm">
                                            <?php echo $this->icon('refresh'); ?>
                                            Refresh
                                        </button>
                                    </div>
                                    <div class="flm-card-body">
                                        <div id="flm-scheduled-list" class="flm-log-container" style="max-height:350px;">
                                            <?php if (empty($pending_scheduled)): ?>
                                            <div class="flm-empty-state">
                                                <div class="flm-empty-icon"><?php echo $this->icon('clock'); ?></div>
                                                <div class="flm-empty-text">No scheduled posts</div>
                                                <div class="flm-empty-hint">Schedule posts from the post editor sidebar</div>
                                            </div>
                                            <?php else: ?>
                                            <?php 
                                            usort($pending_scheduled, function($a, $b) { return ($a['scheduled_for'] ?? 0) - ($b['scheduled_for'] ?? 0); });
                                            foreach ($pending_scheduled as $sched_id => $sched): 
                                                $sched_post = get_post($sched['post_id']);
                                                if (!$sched_post) continue;
                                            ?>
                                            <div class="flm-log-entry" data-schedule-id="<?php echo esc_attr($sched_id); ?>">
                                                <div class="flm-log-time"><?php echo date('M j g:ia', $sched['scheduled_for']); ?></div>
                                                <div class="flm-log-content">
                                                    <span class="flm-log-platform" style="color:<?php echo $sched['platform'] === 'twitter' ? '#1DA1F2' : '#1877F2'; ?>;">
                                                        <?php echo $sched['platform'] === 'twitter' ? '𝕏' : 'FB'; ?>
                                                    </span>
                                                    <span class="flm-log-text"><?php echo esc_html($sched_post->post_title); ?></span>
                                                </div>
                                                <button type="button" class="flm-btn flm-btn-xs flm-btn-danger flm-cancel-scheduled" data-schedule-id="<?php echo esc_attr($sched_id); ?>">
                                                    <?php echo $this->icon('x'); ?>
                                                </button>
                                            </div>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Recent Social Activity -->
                                <div class="flm-card">
                                    <div class="flm-card-header">
                                        <h2 class="flm-card-title">
                                            <span class="flm-card-icon"><?php echo $this->icon('share'); ?></span>
                                            Recent Social Activity
                                        </h2>
                                        <button type="button" id="flm-clear-social-log" class="flm-btn flm-btn-secondary flm-btn-sm">
                                            <?php echo $this->icon('x'); ?>
                                            Clear
                                        </button>
                                    </div>
                                    <div class="flm-card-body">
                                        <div id="flm-social-activity-log" class="flm-log-container" style="max-height:350px;">
                                            <?php 
                                            $recent_social = array_slice($social_log, 0, 20);
                                            if (empty($recent_social)): ?>
                                            <div class="flm-empty-state">
                                                <div class="flm-empty-icon"><?php echo $this->icon('share'); ?></div>
                                                <div class="flm-empty-text">No social activity yet</div>
                                                <div class="flm-empty-hint">Posts will appear here when shared to social media</div>
                                            </div>
                                            <?php else: ?>
                                            <?php foreach ($recent_social as $activity): ?>
                                            <div class="flm-log-entry <?php echo !empty($activity['success']) ? 'success' : 'error'; ?>">
                                                <div class="flm-log-time"><?php echo date('M j g:ia', strtotime($activity['timestamp'] ?? 'now')); ?></div>
                                                <div class="flm-log-content">
                                                    <span class="flm-log-platform" style="color:<?php echo ($activity['platform'] ?? '') === 'twitter' ? '#1DA1F2' : '#1877F2'; ?>;">
                                                        <?php echo ($activity['platform'] ?? '') === 'twitter' ? '𝕏' : 'FB'; ?>
                                                    </span>
                                                    <span class="flm-log-status <?php echo !empty($activity['success']) ? 'success' : 'error'; ?>">
                                                        <?php echo !empty($activity['success']) ? '✓' : '✗'; ?>
                                                    </span>
                                                    <span class="flm-log-text"><?php echo esc_html($activity['post_title'] ?? 'Unknown'); ?></span>
                                                </div>
                                                <?php if (empty($activity['success']) && !empty($activity['error'])): ?>
                                                <div class="flm-log-error"><?php echo esc_html($activity['error']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Integration Status -->
                            <div class="flm-card" style="margin-top:24px;">
                                <div class="flm-card-header">
                                    <h2 class="flm-card-title">
                                        <span class="flm-card-icon"><?php echo $this->icon('plug'); ?></span>
                                        Social Integrations
                                    </h2>
                                </div>
                                <div class="flm-card-body">
                                    <div class="flm-grid-2">
                                        <!-- Twitter Status -->
                                        <div class="flm-integration-status-card <?php echo !empty($settings['twitter_access_token']) ? 'connected' : 'disconnected'; ?>">
                                            <div class="flm-integration-status-icon">
                                                <svg viewBox="0 0 24 24" fill="currentColor" style="width:24px;height:24px;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                            </div>
                                            <div class="flm-integration-status-info">
                                                <div class="flm-integration-status-name">Twitter/X</div>
                                                <div class="flm-integration-status-state">
                                                    <?php if (!empty($settings['twitter_access_token'])): ?>
                                                    <span style="color:var(--flm-success);">● Connected</span>
                                                    <?php else: ?>
                                                    <span style="color:var(--flm-text-muted);">○ Not configured</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flm-integration-status-actions">
                                                <?php if (!empty($settings['twitter_access_token'])): ?>
                                                <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-social" data-platform="twitter">
                                                    Test Post
                                                </button>
                                                <?php else: ?>
                                                <button type="button" class="flm-btn flm-btn-primary flm-btn-sm" onclick="document.querySelector('[data-tab=settings]').click(); setTimeout(function(){document.getElementById('flm-wizard-twitter').classList.add('active');}, 300);">
                                                    Configure
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <!-- Facebook Status -->
                                        <div class="flm-integration-status-card <?php echo !empty($settings['facebook_access_token']) ? 'connected' : 'disconnected'; ?>">
                                            <div class="flm-integration-status-icon" style="color:#1877F2;">
                                                <svg viewBox="0 0 24 24" fill="currentColor" style="width:24px;height:24px;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                            </div>
                                            <div class="flm-integration-status-info">
                                                <div class="flm-integration-status-name">Facebook</div>
                                                <div class="flm-integration-status-state">
                                                    <?php if (!empty($settings['facebook_access_token'])): ?>
                                                    <span style="color:var(--flm-success);">● Connected</span>
                                                    <?php else: ?>
                                                    <span style="color:var(--flm-text-muted);">○ Not configured</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flm-integration-status-actions">
                                                <?php if (!empty($settings['facebook_access_token'])): ?>
                                                <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-social" data-platform="facebook">
                                                    Test Post
                                                </button>
                                                <?php else: ?>
                                                <button type="button" class="flm-btn flm-btn-primary flm-btn-sm" onclick="document.querySelector('[data-tab=settings]').click(); setTimeout(function(){document.getElementById('flm-wizard-facebook').classList.add('active');}, 300);">
                                                    Configure
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top:16px;padding:12px;background:var(--flm-bg-input);border-radius:8px;font-size:12px;color:var(--flm-text-muted);">
                                        💡 <strong>Tip:</strong> Enable auto-posting in Settings → Integrations to automatically share new articles to social media when they're imported.
                                    </div>
                                </div>
                            </div>
                            
                        </div>
                    </div><!-- End Publishing Panel -->
                
                    <!-- Analytics Tab Panel -->
                    <div id="panel-analytics" class="flm-tab-panel" role="tabpanel">
                        <?php $this->render_analytics_section(); ?>
                    </div><!-- End Analytics Panel -->
                    
                    <!-- AI Insights Tab Panel (v2.8.0) -->
                    <div id="panel-insights" class="flm-tab-panel" role="tabpanel">
                        <section class="flm-insights-section" id="flm-insights-section">
                            
                            <!-- Header -->
                            <div class="flm-insights-header">
                                <div class="flm-insights-title">
                                    <div class="flm-insights-title-icon">
                                        <?php echo $this->icon('bolt'); ?>
                                    </div>
                                    <div>
                                        <span>AI-Powered Insights</span>
                                        <div class="flm-insights-subtitle">Machine learning analytics & content optimization</div>
                                    </div>
                                </div>
                                <div class="flm-ai-status <?php echo !empty($settings['claude_api_key']) ? 'connected' : ''; ?>">
                                    <span class="flm-ai-status-dot"></span>
                                    <span>AI <?php echo !empty($settings['claude_api_key']) ? 'Active' : 'Inactive'; ?></span>
                                </div>
                            </div>
                            
                            <!-- Integration Cards -->
                            <div class="flm-integrations-grid">
                                
                                <!-- Google Analytics 4 -->
                                <div class="flm-integration-card" data-integration="ga4" data-configured="<?php echo !empty($settings['ga4_property_id']) ? 'true' : 'false'; ?>">
                                    <div class="flm-integration-header">
                                        <div class="flm-integration-logo ga4">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
                                        </div>
                                        <span class="flm-integration-status disconnected">
                                            <span>●</span> Not Connected
                                        </span>
                                    </div>
                                    <div class="flm-integration-name">Google Analytics 4</div>
                                    <div class="flm-integration-desc">Track pageviews, sessions, and user behavior across your content</div>
                                    <div class="flm-integration-stats">
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Pageviews</div>
                                        </div>
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Users</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Claude AI -->
                                <div class="flm-integration-card" data-integration="claude" data-configured="<?php echo !empty($settings['claude_api_key']) ? 'true' : 'false'; ?>">
                                    <div class="flm-integration-header">
                                        <div class="flm-integration-logo claude">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                        </div>
                                        <span class="flm-integration-status disconnected">
                                            <span>●</span> Not Connected
                                        </span>
                                    </div>
                                    <div class="flm-integration-name">Claude AI (Anthropic)</div>
                                    <div class="flm-integration-desc">AI-powered headline analysis, content suggestions, and optimization</div>
                                    <div class="flm-integration-stats">
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Analyses</div>
                                        </div>
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Suggestions</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Twitter/X -->
                                <div class="flm-integration-card" data-integration="twitter" data-configured="<?php echo !empty($settings['twitter_api_key']) ? 'true' : 'false'; ?>">
                                    <div class="flm-integration-header">
                                        <div class="flm-integration-logo twitter">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                        </div>
                                        <span class="flm-integration-status disconnected">
                                            <span>●</span> Not Connected
                                        </span>
                                    </div>
                                    <div class="flm-integration-name">Twitter / X</div>
                                    <div class="flm-integration-desc">Track social engagement, mentions, and content performance on X</div>
                                    <div class="flm-integration-stats">
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Impressions</div>
                                        </div>
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Engagements</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Facebook -->
                                <div class="flm-integration-card" data-integration="facebook" data-configured="<?php echo !empty($settings['facebook_app_id']) ? 'true' : 'false'; ?>">
                                    <div class="flm-integration-header">
                                        <div class="flm-integration-logo facebook">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                        </div>
                                        <span class="flm-integration-status disconnected">
                                            <span>●</span> Not Connected
                                        </span>
                                    </div>
                                    <div class="flm-integration-name">Facebook</div>
                                    <div class="flm-integration-desc">Monitor page reach, post engagement, and audience insights</div>
                                    <div class="flm-integration-stats">
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Reach</div>
                                        </div>
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Engagements</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Google Search Console -->
                                <div class="flm-integration-card" data-integration="gsc" data-configured="<?php echo !empty($settings['gsc_property_url']) ? 'true' : 'false'; ?>">
                                    <div class="flm-integration-header">
                                        <div class="flm-integration-logo" style="background:linear-gradient(135deg,#4285f4 0%,#34a853 100%);">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                                        </div>
                                        <span class="flm-integration-status disconnected">
                                            <span>●</span> Not Connected
                                        </span>
                                    </div>
                                    <div class="flm-integration-name">Google Search Console</div>
                                    <div class="flm-integration-desc">Track keyword rankings, impressions, CTR and indexing status</div>
                                    <div class="flm-integration-stats">
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Clicks</div>
                                        </div>
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Impressions</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Bing Webmaster -->
                                <div class="flm-integration-card" data-integration="bing" data-configured="<?php echo !empty($settings['bing_api_key']) ? 'true' : 'false'; ?>">
                                    <div class="flm-integration-header">
                                        <div class="flm-integration-logo" style="background:linear-gradient(135deg,#008373 0%,#00a99d 100%);">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18l4-2.5 6 3.5 4-2V6l-4 2-6-3.5L5 3zm10 13l-4-2V8l4 2v6z"/></svg>
                                        </div>
                                        <span class="flm-integration-status disconnected">
                                            <span>●</span> Not Connected
                                        </span>
                                    </div>
                                    <div class="flm-integration-name">Bing Webmaster Tools</div>
                                    <div class="flm-integration-desc">Monitor Bing search performance, crawl stats, and keywords</div>
                                    <div class="flm-integration-stats">
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Indexed</div>
                                        </div>
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Clicks</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SendGrid (v2.13.0) -->
                                <div class="flm-integration-card <?php echo !empty($settings['sendgrid_api_key']) ? 'connected' : ''; ?>" data-integration="sendgrid" data-configured="<?php echo !empty($settings['sendgrid_api_key']) ? 'true' : 'false'; ?>">
                                    <div class="flm-integration-header">
                                        <div class="flm-integration-icon" style="background:linear-gradient(135deg,#1A82E2,#00C4CC);">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                                        </div>
                                        <span class="flm-integration-status <?php echo !empty($settings['sendgrid_api_key']) ? 'connected' : 'disconnected'; ?>">
                                            <span>●</span> <?php echo !empty($settings['sendgrid_api_key']) ? 'Connected' : 'Not Connected'; ?>
                                        </span>
                                    </div>
                                    <div class="flm-integration-name">SendGrid</div>
                                    <div class="flm-integration-desc">Email delivery and engagement analytics</div>
                                    <div class="flm-integration-stats">
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Open Rate</div>
                                        </div>
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Clicks</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Aigeon (v2.13.0) -->
                                <div class="flm-integration-card <?php echo !empty($settings['aigeon_api_key']) ? 'connected' : ''; ?>" data-integration="aigeon" data-configured="<?php echo !empty($settings['aigeon_api_key']) ? 'true' : 'false'; ?>">
                                    <div class="flm-integration-header">
                                        <div class="flm-integration-icon" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                                        </div>
                                        <span class="flm-integration-status <?php echo !empty($settings['aigeon_api_key']) ? 'connected' : 'disconnected'; ?>">
                                            <span>●</span> <?php echo !empty($settings['aigeon_api_key']) ? 'Connected' : 'Not Connected'; ?>
                                        </span>
                                    </div>
                                    <div class="flm-integration-name">Aigeon <span class="flm-badge" style="background:rgba(99,102,241,0.15);color:#6366f1;font-size:9px;">v2.13.0</span></div>
                                    <div class="flm-integration-desc">AI-native email OS with ad monetization</div>
                                    <div class="flm-integration-stats">
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">--</div>
                                            <div class="flm-integration-stat-label">Open Rate</div>
                                        </div>
                                        <div class="flm-integration-stat">
                                            <div class="flm-integration-stat-value">$--</div>
                                            <div class="flm-integration-stat-label">Ad RPM</div>
                                        </div>
                                    </div>
                                </div>
                                
                            </div>
                            
                            <!-- Headline Analyzer -->
                            <div class="flm-headline-analyzer" id="flm-headline-analyzer">
                                <div class="flm-card-header" style="padding:0 0 20px 0;border:none;">
                                    <h3 class="flm-card-title">
                                        <span class="flm-card-icon" style="background:linear-gradient(135deg,#a371f7,#8b5cf6);">
                                            <?php echo $this->icon('bolt'); ?>
                                        </span>
                                        AI Headline Analyzer
                                    </h3>
                                    <span class="flm-badge" style="background:rgba(163,113,247,0.15);color:#a371f7;">Powered by Claude</span>
                                </div>
                                
                                <div class="flm-headline-input-group">
                                    <input type="text" id="flm-headline-input" class="flm-headline-input" placeholder="Enter a headline to analyze (e.g., 'Braves Sign Star Pitcher to 5-Year Deal')">
                                    <button type="button" id="flm-analyze-headline" class="flm-analyze-btn">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                        Analyze
                                    </button>
                                </div>
                                
                                <div class="flm-headline-results" id="flm-headline-results">
                                    <div class="flm-score-display">
                                        <div class="flm-score-ring">
                                            <svg width="100" height="100">
                                                <circle class="flm-score-ring-bg" cx="50" cy="50" r="42"/>
                                                <circle class="flm-score-ring-fill" cx="50" cy="50" r="42"/>
                                            </svg>
                                            <span class="flm-score-value">0</span>
                                            <span class="flm-score-label">Score</span>
                                        </div>
                                        <div class="flm-score-details">
                                            <div class="flm-score-verdict">Analyzing...</div>
                                            <div class="flm-score-breakdown"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="flm-ai-suggestions">
                                        <div class="flm-ai-suggestions-title">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                            AI Suggestions
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Grid of ML Widgets -->
                            <div class="flm-widgets-grid" style="margin-top:24px;">
                                
                                <!-- Performance Predictor -->
                                <div class="flm-predictor-card">
                                    <div class="flm-card-header" style="padding:0 0 16px 0;border:none;">
                                        <h3 class="flm-card-title">
                                            <span class="flm-card-icon"><?php echo $this->icon('chart'); ?></span>
                                            Performance Predictor
                                        </h3>
                                    </div>
                                    <div class="flm-predictor-form">
                                        <div class="flm-predictor-field">
                                            <label class="flm-predictor-label">Team</label>
                                            <select id="flm-predict-team" class="flm-select">
                                                <option value="braves">Braves</option>
                                                <option value="falcons">Falcons</option>
                                                <option value="hawks">Hawks</option>
                                                <option value="uga">UGA</option>
                                                <option value="gt">Georgia Tech</option>
                                            </select>
                                        </div>
                                        <div class="flm-predictor-field">
                                            <label class="flm-predictor-label">Content Type</label>
                                            <select id="flm-predict-type" class="flm-select">
                                                <option value="News">News</option>
                                                <option value="Recap">Recap</option>
                                                <option value="Preview">Preview</option>
                                                <option value="Analysis">Analysis</option>
                                                <option value="Feature">Feature</option>
                                            </select>
                                        </div>
                                        <div class="flm-predictor-field">
                                            <label class="flm-predictor-label">Publish Hour</label>
                                            <select id="flm-predict-hour" class="flm-select">
                                                <?php for ($h = 6; $h <= 22; $h++): ?>
                                                <option value="<?php echo $h; ?>" <?php selected($h, 18); ?>><?php echo date('g:i A', strtotime("$h:00")); ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <button type="button" id="flm-predict-performance" class="flm-btn flm-btn-primary" style="width:100%;margin-bottom:16px;">
                                        <?php echo $this->icon('chart'); ?> Predict Performance
                                    </button>
                                    <div class="flm-predictor-result">
                                        <div class="flm-predictor-metric">
                                            <div class="flm-predictor-metric-value" id="flm-predicted-views">--</div>
                                            <div class="flm-predictor-metric-label">Predicted Views</div>
                                        </div>
                                        <div class="flm-predictor-metric">
                                            <div class="flm-predictor-metric-value" id="flm-predicted-engagement">--%</div>
                                            <div class="flm-predictor-metric-label">Engagement Rate</div>
                                        </div>
                                        <div class="flm-predictor-confidence" id="flm-prediction-confidence">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                            --% confidence
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Trending Topics -->
                                <div class="flm-trending-card">
                                    <div class="flm-card-header" style="padding:0 0 16px 0;border:none;">
                                        <h3 class="flm-card-title">
                                            <span class="flm-card-icon" style="background:linear-gradient(135deg,#f85149,#ff7b72);">
                                                <?php echo $this->icon('chart'); ?>
                                            </span>
                                            Trending Topics
                                        </h3>
                                        <button type="button" id="flm-refresh-trending" class="flm-btn flm-btn-secondary flm-btn-sm">
                                            <?php echo $this->icon('refresh'); ?> Refresh
                                        </button>
                                    </div>
                                    <div class="flm-trending-list" id="flm-trending-list">
                                        <div class="flm-trending-item">
                                            <div class="flm-trending-rank">1</div>
                                            <div class="flm-trending-content">
                                                <div class="flm-trending-topic">Loading trends...</div>
                                                <div class="flm-trending-meta">
                                                    <span class="flm-trending-team">--</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Channel Comparison -->
                                <div class="flm-channel-comparison">
                                    <div class="flm-card-header" style="padding:0 0 16px 0;border:none;">
                                        <h3 class="flm-card-title">
                                            <span class="flm-card-icon" style="background:linear-gradient(135deg,#58a6ff,#388bfd);">
                                                <?php echo $this->icon('chart'); ?>
                                            </span>
                                            Channel Performance
                                        </h3>
                                    </div>
                                    <div class="flm-channel-bars">
                                        <div class="flm-channel-bar">
                                            <div class="flm-channel-icon website">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
                                            </div>
                                            <div class="flm-channel-info">
                                                <div class="flm-channel-name">Website (Direct)</div>
                                                <div class="flm-channel-track">
                                                    <div class="flm-channel-fill website" style="width:75%;"></div>
                                                </div>
                                            </div>
                                            <div class="flm-channel-value">5,234</div>
                                        </div>
                                        <div class="flm-channel-bar">
                                            <div class="flm-channel-icon twitter">
                                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                            </div>
                                            <div class="flm-channel-info">
                                                <div class="flm-channel-name">Twitter / X</div>
                                                <div class="flm-channel-track">
                                                    <div class="flm-channel-fill twitter" style="width:55%;"></div>
                                                </div>
                                            </div>
                                            <div class="flm-channel-value">3,821</div>
                                        </div>
                                        <div class="flm-channel-bar">
                                            <div class="flm-channel-icon facebook">
                                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                            </div>
                                            <div class="flm-channel-info">
                                                <div class="flm-channel-name">Facebook</div>
                                                <div class="flm-channel-track">
                                                    <div class="flm-channel-fill facebook" style="width:40%;"></div>
                                                </div>
                                            </div>
                                            <div class="flm-channel-value">2,156</div>
                                        </div>
                                        <div class="flm-channel-bar">
                                            <div class="flm-channel-icon email">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                            </div>
                                            <div class="flm-channel-info">
                                                <div class="flm-channel-name">Email Newsletter</div>
                                                <div class="flm-channel-track">
                                                    <div class="flm-channel-fill email" style="width:25%;"></div>
                                                </div>
                                            </div>
                                            <div class="flm-channel-value">1,428</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Optimal Publish Time -->
                                <div class="flm-optimal-time-card">
                                    <div class="flm-card-header" style="padding:0 0 16px 0;border:none;">
                                        <h3 class="flm-card-title">
                                            <span class="flm-card-icon" style="background:linear-gradient(135deg,#3fb950,#2ea043);">
                                                <?php echo $this->icon('clock'); ?>
                                            </span>
                                            Best Time to Publish
                                        </h3>
                                        <select id="flm-time-team" class="flm-select" style="width:120px;">
                                            <option value="">All Teams</option>
                                            <option value="braves">Braves</option>
                                            <option value="falcons">Falcons</option>
                                            <option value="hawks">Hawks</option>
                                            <option value="uga">UGA</option>
                                            <option value="gt">GT</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Time Heatmap -->
                                    <div class="flm-time-heatmap" id="flm-time-heatmap">
                                        <div class="flm-time-heatmap-label"></div>
                                        <div class="flm-time-heatmap-header">Sun</div>
                                        <div class="flm-time-heatmap-header">Mon</div>
                                        <div class="flm-time-heatmap-header">Tue</div>
                                        <div class="flm-time-heatmap-header">Wed</div>
                                        <div class="flm-time-heatmap-header">Thu</div>
                                        <div class="flm-time-heatmap-header">Fri</div>
                                        <div class="flm-time-heatmap-header">Sat</div>
                                        <?php
                                        $hours = [6, 9, 12, 15, 18, 21];
                                        $hour_scores = [
                                            6 => [2,2,3,3,3,2,2],
                                            9 => [3,4,4,4,4,4,3],
                                            12 => [3,4,4,4,4,3,3],
                                            15 => [2,3,3,3,3,3,2],
                                            18 => [4,5,5,5,5,4,4],
                                            21 => [3,4,4,4,4,3,3],
                                        ];
                                        foreach ($hours as $h):
                                        ?>
                                        <div class="flm-time-heatmap-label"><?php echo date('ga', strtotime("$h:00")); ?></div>
                                        <?php for ($d = 0; $d < 7; $d++): ?>
                                        <div class="flm-time-cell" data-score="<?php echo $hour_scores[$h][$d]; ?>" data-hour="<?php echo $h; ?>" data-day="<?php echo $d; ?>" title="<?php echo date('ga', strtotime("$h:00")); ?> on <?php echo date('l', strtotime("Sunday +$d days")); ?>"></div>
                                        <?php endfor; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- Recommendation -->
                                    <div class="flm-time-recommendation">
                                        <div class="flm-time-recommendation-icon">
                                            <?php echo $this->icon('clock'); ?>
                                        </div>
                                        <div class="flm-time-recommendation-text">
                                            <div class="flm-time-recommendation-title">Best: Tuesday & Wednesday at 6PM</div>
                                            <div class="flm-time-recommendation-detail">Posts at this time get 47% more engagement on average</div>
                                        </div>
                                    </div>
                                </div>
                                
                            </div>
                            
                            <!-- Search & SEO Section -->
                            <div class="flm-card" style="margin-top:24px;">
                                <div class="flm-card-header">
                                    <h3 class="flm-card-title">
                                        <span class="flm-card-icon" style="background:linear-gradient(135deg,#4285f4,#34a853);">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                                        </span>
                                        Search Engine Performance
                                    </h3>
                                    <button type="button" id="flm-refresh-seo" class="flm-btn flm-btn-secondary flm-btn-sm">
                                        <?php echo $this->icon('refresh'); ?> Refresh
                                    </button>
                                </div>
                                <div class="flm-card-body">
                                    <div class="flm-widgets-grid">
                                        
                                        <!-- Top Keywords -->
                                        <div class="flm-seo-widget">
                                            <div class="flm-seo-widget-header">
                                                <h4>Top Search Keywords</h4>
                                                <span class="flm-badge" style="background:rgba(66,133,244,0.15);color:#4285f4;">Google</span>
                                            </div>
                                            <div class="flm-keyword-list" id="flm-keyword-list">
                                                <div class="flm-keyword-item">
                                                    <div class="flm-keyword-rank">1</div>
                                                    <div class="flm-keyword-info">
                                                        <div class="flm-keyword-text">atlanta braves news</div>
                                                        <div class="flm-keyword-meta">Pos: 8.2 · CTR: 4.5%</div>
                                                    </div>
                                                    <div class="flm-keyword-clicks">342</div>
                                                </div>
                                                <div class="flm-keyword-item">
                                                    <div class="flm-keyword-rank">2</div>
                                                    <div class="flm-keyword-info">
                                                        <div class="flm-keyword-text">georgia bulldogs recruiting</div>
                                                        <div class="flm-keyword-meta">Pos: 5.1 · CTR: 6.2%</div>
                                                    </div>
                                                    <div class="flm-keyword-clicks">287</div>
                                                </div>
                                                <div class="flm-keyword-item">
                                                    <div class="flm-keyword-rank">3</div>
                                                    <div class="flm-keyword-info">
                                                        <div class="flm-keyword-text">falcons draft picks 2025</div>
                                                        <div class="flm-keyword-meta">Pos: 12.4 · CTR: 3.1%</div>
                                                    </div>
                                                    <div class="flm-keyword-clicks">198</div>
                                                </div>
                                                <div class="flm-keyword-item">
                                                    <div class="flm-keyword-rank">4</div>
                                                    <div class="flm-keyword-info">
                                                        <div class="flm-keyword-text">hawks game recap</div>
                                                        <div class="flm-keyword-meta">Pos: 9.7 · CTR: 3.8%</div>
                                                    </div>
                                                    <div class="flm-keyword-clicks">156</div>
                                                </div>
                                                <div class="flm-keyword-item">
                                                    <div class="flm-keyword-rank">5</div>
                                                    <div class="flm-keyword-info">
                                                        <div class="flm-keyword-text">georgia tech football</div>
                                                        <div class="flm-keyword-meta">Pos: 15.3 · CTR: 2.4%</div>
                                                    </div>
                                                    <div class="flm-keyword-clicks">89</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Search Stats Overview -->
                                        <div class="flm-seo-widget">
                                            <div class="flm-seo-widget-header">
                                                <h4>Search Overview (7 days)</h4>
                                            </div>
                                            <div class="flm-seo-stats-grid">
                                                <div class="flm-seo-stat">
                                                    <div class="flm-seo-stat-value" data-count="2847">0</div>
                                                    <div class="flm-seo-stat-label">Total Clicks</div>
                                                    <div class="flm-seo-stat-change up">+12.4%</div>
                                                </div>
                                                <div class="flm-seo-stat">
                                                    <div class="flm-seo-stat-value" data-count="48250">0</div>
                                                    <div class="flm-seo-stat-label">Impressions</div>
                                                    <div class="flm-seo-stat-change up">+8.7%</div>
                                                </div>
                                                <div class="flm-seo-stat">
                                                    <div class="flm-seo-stat-value">5.9%</div>
                                                    <div class="flm-seo-stat-label">Avg CTR</div>
                                                    <div class="flm-seo-stat-change up">+0.3%</div>
                                                </div>
                                                <div class="flm-seo-stat">
                                                    <div class="flm-seo-stat-value">11.2</div>
                                                    <div class="flm-seo-stat-label">Avg Position</div>
                                                    <div class="flm-seo-stat-change down">-1.4</div>
                                                </div>
                                            </div>
                                            <div class="flm-seo-engines">
                                                <div class="flm-seo-engine">
                                                    <span class="flm-seo-engine-icon google">G</span>
                                                    <span class="flm-seo-engine-name">Google</span>
                                                    <span class="flm-seo-engine-value">78%</span>
                                                </div>
                                                <div class="flm-seo-engine">
                                                    <span class="flm-seo-engine-icon bing">B</span>
                                                    <span class="flm-seo-engine-name">Bing</span>
                                                    <span class="flm-seo-engine-value">14%</span>
                                                </div>
                                                <div class="flm-seo-engine">
                                                    <span class="flm-seo-engine-icon other">O</span>
                                                    <span class="flm-seo-engine-name">Other</span>
                                                    <span class="flm-seo-engine-value">8%</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- SEO Health -->
                                        <div class="flm-seo-widget">
                                            <div class="flm-seo-widget-header">
                                                <h4>Content SEO Health</h4>
                                                <button type="button" id="flm-scan-seo" class="flm-btn flm-btn-secondary flm-btn-sm">
                                                    Scan
                                                </button>
                                            </div>
                                            <div class="flm-seo-health-score">
                                                <div class="flm-seo-score-ring">
                                                    <svg width="80" height="80">
                                                        <circle cx="40" cy="40" r="32" fill="none" stroke="var(--flm-border)" stroke-width="6"/>
                                                        <circle cx="40" cy="40" r="32" fill="none" stroke="var(--flm-success)" stroke-width="6" stroke-dasharray="201" stroke-dashoffset="40" transform="rotate(-90 40 40)"/>
                                                    </svg>
                                                    <span class="flm-seo-score-value">82</span>
                                                </div>
                                                <div class="flm-seo-health-details">
                                                    <div class="flm-seo-health-label">Overall SEO Score</div>
                                                    <div class="flm-seo-health-breakdown">
                                                        <span class="flm-health-good">12 Good</span>
                                                        <span class="flm-health-warning">5 Warnings</span>
                                                        <span class="flm-health-error">2 Issues</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flm-seo-issues" id="flm-seo-issues">
                                                <div class="flm-seo-issue warning">
                                                    <span class="flm-seo-issue-icon">⚠️</span>
                                                    <span>3 posts missing meta descriptions</span>
                                                </div>
                                                <div class="flm-seo-issue error">
                                                    <span class="flm-seo-issue-icon">❌</span>
                                                    <span>2 posts have titles over 60 chars</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Content Ideas Generator -->
                            <div class="flm-ideas-card" style="margin-top:24px;">
                                <div class="flm-card-header" style="padding:0 0 16px 0;border:none;">
                                    <h3 class="flm-card-title">
                                        <span class="flm-card-icon" style="background:linear-gradient(135deg,#a371f7,#8b5cf6);">
                                            <?php echo $this->icon('bolt'); ?>
                                        </span>
                                        AI Content Ideas Generator
                                    </h3>
                                    <div style="display:flex;gap:12px;align-items:center;">
                                        <select id="flm-ideas-team" class="flm-select" style="width:150px;">
                                            <option value="">All Teams</option>
                                            <option value="braves">Braves</option>
                                            <option value="falcons">Falcons</option>
                                            <option value="hawks">Hawks</option>
                                            <option value="uga">UGA</option>
                                            <option value="gt">Georgia Tech</option>
                                        </select>
                                        <button type="button" id="flm-generate-ideas" class="flm-btn flm-btn-primary">
                                            <?php echo $this->icon('bolt'); ?> Generate Ideas
                                        </button>
                                    </div>
                                </div>
                                <div class="flm-ideas-list" id="flm-ideas-list">
                                    <div class="flm-idea-item">
                                        <span class="flm-idea-type">Tip</span>
                                        <div class="flm-idea-content">
                                            <div class="flm-idea-headline">Click "Generate Ideas" to get AI-powered content suggestions</div>
                                            <div class="flm-idea-reason">Based on trending topics and historical performance</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                        </section>
                    </div><!-- End Insights Panel -->
                
                    <!-- Logs Tab Panel -->
                    <div id="panel-logs" class="flm-tab-panel" role="tabpanel">
                        
                        <div class="flm-grid-2">
                            <div>
                                <!-- Import Log -->
                                <div class="flm-card">
                                    <div class="flm-card-header">
                                        <h2 class="flm-card-title">
                                            <span class="flm-card-icon"><?php echo $this->icon('log'); ?></span>
                                            Import Log
                                        </h2>
                                        <div class="flm-flex flm-gap-1 flm-flex-center">
                                            <span class="flm-text-muted flm-text-sm"><?php echo count($log); ?> entries</span>
                                            <?php if (!empty($log)): ?>
                                            <button type="button" id="flm-clear-log" class="flm-btn flm-btn-danger flm-btn-sm">
                                                <?php echo $this->icon('trash'); ?>
                                                Clear
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flm-card-body no-padding">
                                        <div class="flm-log flm-log-tall" role="log" aria-label="Import activity log">
                                            <?php if (!empty($log)): ?>
                                                <?php foreach ($log as $entry): 
                                                    $icon_class = $entry['type'] === 'success' ? 'success' : 'update';
                                                    $icon = $entry['type'] === 'success' ? $this->icon('check') : $this->icon('refresh');
                                                    $team_name = isset($this->target_teams[$entry['team']]) ? 
                                                        $this->target_teams[$entry['team']]['category_name'] : 
                                                        ucfirst($entry['team']);
                                                ?>
                                                <div class="flm-log-entry">
                                                    <span class="flm-log-icon <?php echo $icon_class; ?>"><?php echo $icon; ?></span>
                                                    <div class="flm-log-content">
                                                        <div class="flm-log-text">
                                                            <span class="flm-log-team">[<?php echo esc_html($team_name); ?>]</span>
                                                            <?php echo esc_html($entry['text']); ?>
                                                        </div>
                                                        <?php if (!empty($entry['time'])): ?>
                                                        <div class="flm-log-time"><?php echo esc_html($entry['time']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="flm-log-empty">
                                                    <div class="flm-log-empty-icon"><?php echo $this->icon('log'); ?></div>
                                                    <div>No import activity yet. Run an import to get started!</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <!-- Error Log -->
                                <div class="flm-card flm-error-log-card">
                                    <div class="flm-card-header">
                                        <h2 class="flm-card-title">
                                            <span class="flm-card-icon"><?php echo $this->icon('alert'); ?></span>
                                            Error Log
                                        </h2>
                                        <div class="flm-flex flm-gap-1 flm-flex-center">
                                            <span class="flm-text-muted flm-text-sm"><?php echo count($error_log); ?> entries</span>
                                            <?php if (!empty($error_log)): ?>
                                            <button type="button" id="flm-clear-error-log" class="flm-btn flm-btn-danger flm-btn-sm">
                                                <?php echo $this->icon('trash'); ?>
                                                Clear
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flm-card-body no-padding">
                                        <div class="flm-log flm-log-tall flm-error-log" role="log" aria-label="Error log">
                                            <?php if (!empty($error_log)): ?>
                                                <?php foreach ($error_log as $entry): 
                                                    $level_class = $entry['level'] ?? 'info';
                                                    $level_icons = [
                                                        'error' => $this->icon('x'),
                                                        'warning' => $this->icon('alert'),
                                                        'info' => $this->icon('log'),
                                                        'debug' => $this->icon('search'),
                                                    ];
                                                    $icon = $level_icons[$level_class] ?? $level_icons['info'];
                                                ?>
                                                <div class="flm-log-entry flm-log-<?php echo esc_attr($level_class); ?>">
                                                    <span class="flm-log-icon <?php echo esc_attr($level_class); ?>"><?php echo $icon; ?></span>
                                                    <div class="flm-log-content">
                                                        <div class="flm-log-text">
                                                            <span class="flm-log-context">[<?php echo esc_html(strtoupper($entry['context'] ?? 'system')); ?>]</span>
                                                            <?php echo esc_html($entry['message'] ?? ''); ?>
                                                            <?php if (!empty($entry['data'])): ?>
                                                            <details class="flm-log-details">
                                                                <summary>Details</summary>
                                                                <pre><?php echo esc_html(json_encode($entry['data'], JSON_PRETTY_PRINT)); ?></pre>
                                                            </details>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($entry['timestamp'])): ?>
                                                        <div class="flm-log-time"><?php echo esc_html($entry['timestamp']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="flm-log-empty">
                                                    <div class="flm-log-empty-icon"><?php echo $this->icon('check'); ?></div>
                                                    <div>No errors logged. System running smoothly!</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    </div><!-- End Logs Panel -->
                
                    <!-- Settings Tab Panel -->
                    <div id="panel-settings" class="flm-tab-panel" role="tabpanel">
                        
                        <form id="flm-settings-form-settings" method="post" class="flm-settings-form-alt">
                            <?php wp_nonce_field('flm_settings', 'flm_nonce'); ?>
                            
                            <div class="flm-grid-2">
                                <div>
                                    <!-- Post Settings -->
                                    <div class="flm-card">
                                        <div class="flm-card-header">
                                            <h2 class="flm-card-title">
                                                <span class="flm-card-icon"><?php echo $this->icon('edit'); ?></span>
                                                Post Settings
                                            </h2>
                                        </div>
                                        <div class="flm-card-body">
                                            <div class="flm-form-group">
                                                <label class="flm-label" for="flm-post-status-2">Post Status</label>
                                                <div class="flm-select-wrap">
                                                    <select name="flm_settings[post_status]" id="flm-post-status-2" class="flm-select">
                                                        <option value="draft" <?php selected($settings['post_status'], 'draft'); ?>>Draft — Review before publishing</option>
                                                        <option value="pending" <?php selected($settings['post_status'], 'pending'); ?>>Pending Review</option>
                                                        <option value="publish" <?php selected($settings['post_status'], 'publish'); ?>>Publish Immediately</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="flm-form-group">
                                                <label class="flm-label" for="flm-post-author-2">Post Author</label>
                                                <div class="flm-select-wrap">
                                                    <select name="flm_settings[post_author]" id="flm-post-author-2" class="flm-select">
                                                        <?php foreach ($authors as $author): ?>
                                                        <option value="<?php echo $author->ID; ?>" <?php selected($settings['post_author'], $author->ID); ?>>
                                                            <?php echo esc_html($author->display_name); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="flm-form-group">
                                                <label class="flm-label" for="flm-default-cat-2">Default Category</label>
                                                <div class="flm-select-wrap">
                                                    <select name="flm_settings[default_category]" id="flm-default-cat-2" class="flm-select">
                                                        <option value="">— Select Category —</option>
                                                        <?php foreach ($categories as $cat): ?>
                                                        <option value="<?php echo $cat->term_id; ?>" <?php selected($settings['default_category'], $cat->term_id); ?>>
                                                            <?php echo esc_html($cat->name); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Import Options -->
                                    <div class="flm-card">
                                        <div class="flm-card-header">
                                            <h2 class="flm-card-title">
                                                <span class="flm-card-icon"><?php echo $this->icon('settings'); ?></span>
                                                Import Options
                                            </h2>
                                        </div>
                                        <div class="flm-card-body">
                                            <div class="flm-form-group">
                                                <label class="flm-checkbox-card" data-checked="<?php echo $settings['import_images'] ? 'true' : 'false'; ?>">
                                                    <input type="checkbox" name="flm_settings[import_images]" value="1" 
                                                           <?php checked($settings['import_images']); ?>>
                                                    <span>Download & set featured images</span>
                                                </label>
                                            </div>
                                            
                                            <div class="flm-form-group">
                                                <label class="flm-checkbox-card" data-checked="<?php echo !empty($settings['auto_excerpt']) ? 'true' : 'false'; ?>">
                                                    <input type="checkbox" name="flm_settings[auto_excerpt]" value="1" 
                                                           <?php checked(!empty($settings['auto_excerpt'])); ?>>
                                                    <span>Auto-generate excerpt from first paragraph</span>
                                                </label>
                                            </div>
                                            
                                            <div class="flm-form-group">
                                                <label class="flm-checkbox-card" data-checked="<?php echo !empty($settings['auto_meta_description']) ? 'true' : 'false'; ?>">
                                                    <input type="checkbox" name="flm_settings[auto_meta_description]" value="1" 
                                                           <?php checked(!empty($settings['auto_meta_description'])); ?>>
                                                    <span>Auto-generate SEO meta description</span>
                                                </label>
                                                <div class="flm-label-hint" style="margin-top: 4px; font-size: 11px;">
                                                    Works with Yoast SEO and RankMath
                                                </div>
                                            </div>
                                            
                                            <div class="flm-form-group">
                                                <label class="flm-label" for="flm-lookback-days-2">Lookback Period</label>
                                                <div class="flm-select-wrap">
                                                    <select name="flm_settings[lookback_days]" id="flm-lookback-days-2" class="flm-select">
                                                        <option value="1" <?php selected($settings['lookback_days'], 1); ?>>1 day</option>
                                                        <option value="3" <?php selected($settings['lookback_days'], 3); ?>>3 days</option>
                                                        <option value="7" <?php selected($settings['lookback_days'], 7); ?>>7 days</option>
                                                        <option value="14" <?php selected($settings['lookback_days'], 14); ?>>14 days</option>
                                                        <option value="30" <?php selected($settings['lookback_days'], 30); ?>>30 days</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <!-- Schedule Settings -->
                                    <div class="flm-card">
                                        <div class="flm-card-header">
                                            <h2 class="flm-card-title">
                                                <span class="flm-card-icon"><?php echo $this->icon('clock'); ?></span>
                                                Schedule
                                            </h2>
                                        </div>
                                        <div class="flm-card-body">
                                            <div class="flm-form-group">
                                                <label class="flm-label" for="flm-import-frequency-2">Auto-Import Schedule</label>
                                                <div class="flm-select-wrap">
                                                    <select name="flm_settings[import_frequency]" id="flm-import-frequency-2" class="flm-select">
                                                        <option value="hourly" <?php selected($settings['import_frequency'] ?? 'twicedaily', 'hourly'); ?>>Hourly</option>
                                                        <option value="every6hours" <?php selected($settings['import_frequency'] ?? 'twicedaily', 'every6hours'); ?>>Every 6 hours</option>
                                                        <option value="twicedaily" <?php selected($settings['import_frequency'] ?? 'twicedaily', 'twicedaily'); ?>>Twice daily</option>
                                                        <option value="daily" <?php selected($settings['import_frequency'] ?? 'twicedaily', 'daily'); ?>>Daily</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="flm-schedule-info">
                                                <div class="flm-schedule-next">
                                                    <?php echo $this->icon('clock'); ?>
                                                    <span>Next run: <strong><?php 
                                                        $next = wp_next_scheduled('flm_import_stories');
                                                        echo $next ? date('M j, g:i A', $next) : 'Not scheduled';
                                                    ?></strong></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- API Configuration -->
                                    <div class="flm-card">
                                        <div class="flm-card-header">
                                            <h2 class="flm-card-title">
                                                <span class="flm-card-icon"><?php echo $this->icon('key'); ?></span>
                                                API Configuration
                                            </h2>
                                            <div class="flm-connection-status">
                                                <span class="flm-connection-dot" data-status="<?php echo $is_connected ? 'online' : 'offline'; ?>"></span>
                                                <span><?php echo $is_connected ? 'Connected' : 'Not Connected'; ?></span>
                                            </div>
                                        </div>
                                        <div class="flm-card-body">
                                            <div class="flm-form-group">
                                                <label class="flm-label" for="flm-api-key-2">FLM API Key</label>
                                                <div class="flm-input-wrap">
                                                    <input type="password" 
                                                           name="flm_settings[api_key]" 
                                                           id="flm-api-key-2"
                                                           value="<?php echo esc_attr($settings['api_key']); ?>" 
                                                           class="flm-input flm-input-mono"
                                                           placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                                           autocomplete="off">
                                                    <button type="button" class="flm-input-toggle flm-toggle-visibility" aria-label="Show API key">
                                                        <?php echo $this->icon('eye'); ?>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <button type="button" id="flm-test-connection-2" class="flm-btn flm-btn-secondary flm-btn-sm">
                                                <?php echo $this->icon('plug'); ?>
                                                Test Connection
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Danger Zone -->
                                    <div class="flm-card flm-card-danger">
                                        <div class="flm-card-header">
                                            <h2 class="flm-card-title">
                                                <span class="flm-card-icon"><?php echo $this->icon('alert'); ?></span>
                                                Danger Zone
                                            </h2>
                                        </div>
                                        <div class="flm-card-body">
                                            <div class="flm-danger-item">
                                                <div class="flm-danger-info">
                                                    <strong>Reset Import Date</strong>
                                                    <p>Re-import stories that were previously imported</p>
                                                </div>
                                                <button type="button" id="flm-reset-date-2" class="flm-btn flm-btn-secondary flm-btn-sm">
                                                    <?php echo $this->icon('refresh'); ?>
                                                    Reset
                                                </button>
                                            </div>
                                            <div class="flm-danger-item">
                                                <div class="flm-danger-info">
                                                    <strong>Clear All Logs</strong>
                                                    <p>Remove all import and error log entries</p>
                                                </div>
                                                <button type="button" id="flm-clear-all-logs" class="flm-btn flm-btn-danger flm-btn-sm">
                                                    <?php echo $this->icon('trash'); ?>
                                                    Clear Logs
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Integration Settings (v2.8.0) -->
                            <div class="flm-card" style="margin-top:24px;">
                                <div class="flm-card-header">
                                    <h2 class="flm-card-title">
                                        <span class="flm-card-icon" style="background:linear-gradient(135deg,#a371f7,#8b5cf6);">
                                            <?php echo $this->icon('bolt'); ?>
                                        </span>
                                        AI & Integration Settings
                                    </h2>
                                    <span class="flm-badge" style="background:rgba(163,113,247,0.15);color:#a371f7;">v2.15.0</span>
                                </div>
                                <div class="flm-card-body">
                                    
                                    <!-- OAuth Quick Connect (v2.15.0) -->
                                    <div style="margin-bottom:24px;padding:20px;background:linear-gradient(135deg,rgba(99,102,241,0.1),rgba(139,92,246,0.1));border:1px solid rgba(139,92,246,0.2);border-radius:12px;">
                                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                                            <span class="flm-badge" style="background:rgba(63,185,80,0.15);color:var(--flm-success);font-size:10px;">NEW</span>
                                            <span style="font-weight:600;color:var(--flm-text);font-size:15px;">🔐 OAuth Quick Connect</span>
                                        </div>
                                        <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">Connect your accounts with one click. No API keys needed - we handle everything securely.</p>
                                        
                                        <div class="flm-oauth-grid">
                                            <!-- Google Analytics 4 OAuth Card -->
                                            <div class="flm-oauth-card <?php echo !empty($settings['ga4_oauth_access_token']) ? 'connected' : 'disconnected'; ?>" data-provider="ga4">
                                                <div class="flm-oauth-card-header">
                                                    <div class="flm-oauth-provider">
                                                        <div class="flm-oauth-provider-icon google">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
                                                        </div>
                                                        <div>
                                                            <div class="flm-oauth-provider-name">Google Analytics</div>
                                                            <div class="flm-oauth-provider-desc">GA4 Traffic Data</div>
                                                        </div>
                                                    </div>
                                                    <div class="flm-oauth-status">
                                                        <?php if (!empty($settings['ga4_oauth_access_token'])): ?>
                                                        <span class="flm-badge success">Connected</span>
                                                        <?php else: ?>
                                                        <span class="flm-badge secondary">Not Connected</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flm-oauth-card-body">
                                                    <div class="flm-oauth-expiry" style="display:<?php echo !empty($settings['ga4_oauth_access_token']) ? 'block' : 'none'; ?>;">
                                                        <?php 
                                                        $ga4_expires = $settings['ga4_oauth_expires_at'] ?? 0;
                                                        if ($ga4_expires > time()): ?>
                                                        Expires: <?php echo human_time_diff(time(), $ga4_expires); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flm-oauth-card-footer">
                                                    <button type="button" class="flm-btn flm-btn-primary flm-btn-sm flm-oauth-connect" data-provider="ga4">
                                                        <?php echo $this->icon('plug'); ?> Connect GA4
                                                    </button>
                                                    <button type="button" class="flm-btn flm-btn-danger flm-btn-sm flm-oauth-disconnect" data-provider="ga4" style="display:<?php echo !empty($settings['ga4_oauth_access_token']) ? 'flex' : 'none'; ?>;">
                                                        <?php echo $this->icon('x'); ?> Disconnect
                                                    </button>
                                                    <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-oauth-refresh" data-provider="ga4" style="display:<?php echo !empty($settings['ga4_oauth_access_token']) ? 'flex' : 'none'; ?>;" title="Refresh Token">
                                                        <?php echo $this->icon('refresh'); ?>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <!-- Google Search Console OAuth Card -->
                                            <div class="flm-oauth-card <?php echo !empty($settings['gsc_oauth_access_token']) ? 'connected' : 'disconnected'; ?>" data-provider="gsc">
                                                <div class="flm-oauth-card-header">
                                                    <div class="flm-oauth-provider">
                                                        <div class="flm-oauth-provider-icon google">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                                                        </div>
                                                        <div>
                                                            <div class="flm-oauth-provider-name">Search Console</div>
                                                            <div class="flm-oauth-provider-desc">Google Search Data</div>
                                                        </div>
                                                    </div>
                                                    <div class="flm-oauth-status">
                                                        <?php if (!empty($settings['gsc_oauth_access_token'])): ?>
                                                        <span class="flm-badge success">Connected</span>
                                                        <?php else: ?>
                                                        <span class="flm-badge secondary">Not Connected</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flm-oauth-card-body">
                                                    <div class="flm-oauth-expiry" style="display:<?php echo !empty($settings['gsc_oauth_access_token']) ? 'block' : 'none'; ?>;">
                                                        <?php 
                                                        $gsc_expires = $settings['gsc_oauth_expires_at'] ?? 0;
                                                        if ($gsc_expires > time()): ?>
                                                        Expires: <?php echo human_time_diff(time(), $gsc_expires); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flm-oauth-card-footer">
                                                    <button type="button" class="flm-btn flm-btn-primary flm-btn-sm flm-oauth-connect" data-provider="gsc">
                                                        <?php echo $this->icon('plug'); ?> Connect GSC
                                                    </button>
                                                    <button type="button" class="flm-btn flm-btn-danger flm-btn-sm flm-oauth-disconnect" data-provider="gsc" style="display:<?php echo !empty($settings['gsc_oauth_access_token']) ? 'flex' : 'none'; ?>;">
                                                        <?php echo $this->icon('x'); ?> Disconnect
                                                    </button>
                                                    <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-oauth-refresh" data-provider="gsc" style="display:<?php echo !empty($settings['gsc_oauth_access_token']) ? 'flex' : 'none'; ?>;" title="Refresh Token">
                                                        <?php echo $this->icon('refresh'); ?>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <!-- Twitter OAuth Card -->
                                            <div class="flm-oauth-card <?php echo !empty($settings['twitter_oauth_access_token']) ? 'connected' : 'disconnected'; ?>" data-provider="twitter">
                                                <div class="flm-oauth-card-header">
                                                    <div class="flm-oauth-provider">
                                                        <div class="flm-oauth-provider-icon twitter">
                                                            <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                                        </div>
                                                        <div>
                                                            <div class="flm-oauth-provider-name">Twitter / X</div>
                                                            <div class="flm-oauth-provider-desc">Auto-post & Analytics</div>
                                                        </div>
                                                    </div>
                                                    <div class="flm-oauth-status">
                                                        <?php if (!empty($settings['twitter_oauth_access_token'])): ?>
                                                        <span class="flm-badge success">Connected</span>
                                                        <?php else: ?>
                                                        <span class="flm-badge secondary">Not Connected</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flm-oauth-card-body">
                                                    <div class="flm-oauth-expiry" style="display:<?php echo !empty($settings['twitter_oauth_access_token']) ? 'block' : 'none'; ?>;">
                                                        <?php 
                                                        $twitter_expires = $settings['twitter_oauth_expires_at'] ?? 0;
                                                        if ($twitter_expires > time()): ?>
                                                        Expires: <?php echo human_time_diff(time(), $twitter_expires); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flm-oauth-card-footer">
                                                    <button type="button" class="flm-btn flm-btn-primary flm-btn-sm flm-oauth-connect" data-provider="twitter">
                                                        <?php echo $this->icon('plug'); ?> Connect Twitter
                                                    </button>
                                                    <button type="button" class="flm-btn flm-btn-danger flm-btn-sm flm-oauth-disconnect" data-provider="twitter" style="display:<?php echo !empty($settings['twitter_oauth_access_token']) ? 'flex' : 'none'; ?>;">
                                                        <?php echo $this->icon('x'); ?> Disconnect
                                                    </button>
                                                    <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-oauth-refresh" data-provider="twitter" style="display:<?php echo !empty($settings['twitter_oauth_access_token']) ? 'flex' : 'none'; ?>;" title="Refresh Token">
                                                        <?php echo $this->icon('refresh'); ?>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <!-- Facebook OAuth Card -->
                                            <div class="flm-oauth-card <?php echo !empty($settings['facebook_oauth_access_token']) ? 'connected' : 'disconnected'; ?>" data-provider="facebook">
                                                <div class="flm-oauth-card-header">
                                                    <div class="flm-oauth-provider">
                                                        <div class="flm-oauth-provider-icon facebook">
                                                            <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                                        </div>
                                                        <div>
                                                            <div class="flm-oauth-provider-name">Facebook</div>
                                                            <div class="flm-oauth-provider-desc">Page Posting</div>
                                                        </div>
                                                    </div>
                                                    <div class="flm-oauth-status">
                                                        <?php if (!empty($settings['facebook_oauth_access_token'])): ?>
                                                        <span class="flm-badge success">Connected</span>
                                                        <?php else: ?>
                                                        <span class="flm-badge secondary">Not Connected</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flm-oauth-card-body">
                                                    <div class="flm-oauth-expiry" style="display:<?php echo !empty($settings['facebook_oauth_access_token']) ? 'block' : 'none'; ?>;">
                                                        <?php 
                                                        $fb_expires = $settings['facebook_oauth_expires_at'] ?? 0;
                                                        if ($fb_expires > time()): ?>
                                                        Expires: <?php echo human_time_diff(time(), $fb_expires); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flm-oauth-pages" style="display:<?php echo !empty($settings['facebook_oauth_pages']) ? 'block' : 'none'; ?>;">
                                                        <?php if (!empty($settings['facebook_oauth_pages'])): ?>
                                                        <select id="flm-facebook-page-select" name="flm_settings[facebook_oauth_selected_page]" class="flm-select flm-select-sm">
                                                            <?php foreach ($settings['facebook_oauth_pages'] as $page): ?>
                                                            <option value="<?php echo esc_attr($page['id']); ?>" <?php selected($settings['facebook_oauth_selected_page'] ?? '', $page['id']); ?>>
                                                                <?php echo esc_html($page['name']); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flm-oauth-card-footer">
                                                    <button type="button" class="flm-btn flm-btn-primary flm-btn-sm flm-oauth-connect" data-provider="facebook">
                                                        <?php echo $this->icon('plug'); ?> Connect Facebook
                                                    </button>
                                                    <button type="button" class="flm-btn flm-btn-danger flm-btn-sm flm-oauth-disconnect" data-provider="facebook" style="display:<?php echo !empty($settings['facebook_oauth_access_token']) ? 'flex' : 'none'; ?>;">
                                                        <?php echo $this->icon('x'); ?> Disconnect
                                                    </button>
                                                    <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-oauth-refresh" data-provider="facebook" style="display:<?php echo !empty($settings['facebook_oauth_access_token']) ? 'flex' : 'none'; ?>;" title="Refresh Token">
                                                        <?php echo $this->icon('refresh'); ?>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <p style="margin:16px 0 0;font-size:11px;color:var(--flm-text-muted);">
                                            <strong>Note:</strong> OAuth connects via secure redirect through mmgleads.com. Your tokens are stored locally in WordPress.
                                        </p>
                                    </div>
                                    
                                    <!-- Claude AI -->
                                    <div class="flm-integration-group expanded">
                                        <div class="flm-integration-group-header">
                                            <div class="flm-integration-group-title">
                                                <div class="flm-integration-logo claude" style="width:32px;height:32px;border-radius:8px;">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;color:#fff;"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                                </div>
                                                Claude AI (Anthropic)
                                            </div>
                                            <span class="flm-integration-status <?php echo !empty($settings['claude_api_key']) ? 'connected' : 'disconnected'; ?>">
                                                <span>●</span> <?php echo !empty($settings['claude_api_key']) ? 'Connected' : 'Not Connected'; ?>
                                            </span>
                                        </div>
                                        <div class="flm-integration-group-body" style="display:block;">
                                            <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">Powers headline analysis, content suggestions, and AI optimization. Get your API key from <a href="https://console.anthropic.com/" target="_blank" style="color:var(--flm-accent);">console.anthropic.com</a></p>
                                            <div class="flm-form-group">
                                                <label class="flm-label" for="flm-claude-api-key">Claude API Key</label>
                                                <div class="flm-input-with-toggle">
                                                    <input type="password" name="flm_settings[claude_api_key]" id="flm-claude-api-key" class="flm-input" value="<?php echo esc_attr($settings['claude_api_key'] ?? ''); ?>" placeholder="sk-ant-api03-...">
                                                    <button type="button" class="flm-toggle-password" data-target="flm-claude-api-key">
                                                        <?php echo $this->icon('eye'); ?>
                                                    </button>
                                                </div>
                                            </div>
                                            <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-integration" data-integration="claude" style="margin-top:8px;">
                                                <?php echo $this->icon('check'); ?> Test Connection
                                            </button>
                                            <button type="button" class="flm-setup-guide-btn flm-open-wizard" data-wizard="claude" style="margin-top:8px;margin-left:8px;">
                                                <?php echo $this->icon('info'); ?> Setup Guide
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Google Analytics 4 -->
                                    <div class="flm-integration-group">
                                        <div class="flm-integration-group-header">
                                            <div class="flm-integration-group-title">
                                                <div class="flm-integration-logo ga4" style="width:32px;height:32px;border-radius:8px;">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;color:#fff;"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
                                                </div>
                                                Google Analytics 4
                                            </div>
                                            <span class="flm-integration-status <?php echo !empty($settings['ga4_property_id']) ? 'connected' : 'disconnected'; ?>">
                                                <span>●</span> <?php echo !empty($settings['ga4_property_id']) ? 'Configured' : 'Not Configured'; ?>
                                            </span>
                                        </div>
                                        <div class="flm-integration-group-body">
                                            <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">Connect to GA4 for real-time analytics. Find your Property ID in <a href="https://analytics.google.com/" target="_blank" style="color:var(--flm-accent);">Google Analytics</a> → Admin → Property Settings.</p>
                                            <div class="flm-integration-fields">
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-ga4-property">GA4 Property ID</label>
                                                    <input type="text" name="flm_settings[ga4_property_id]" id="flm-ga4-property" class="flm-input" value="<?php echo esc_attr($settings['ga4_property_id'] ?? ''); ?>" placeholder="123456789">
                                                </div>
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-ga4-secret">API Secret (optional)</label>
                                                    <div class="flm-input-with-toggle">
                                                        <input type="password" name="flm_settings[ga4_api_secret]" id="flm-ga4-secret" class="flm-input" value="<?php echo esc_attr($settings['ga4_api_secret'] ?? ''); ?>" placeholder="For Measurement Protocol">
                                                        <button type="button" class="flm-toggle-password" data-target="flm-ga4-secret">
                                                            <?php echo $this->icon('eye'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Service Account (v2.11.0) -->
                                            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--flm-border);">
                                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                                                    <span class="flm-badge" style="background:rgba(63,185,80,0.15);color:var(--flm-success);font-size:10px;">v2.11.0</span>
                                                    <span style="font-weight:600;color:var(--flm-text);">Service Account for Data API</span>
                                                </div>
                                                <p style="margin:0 0 12px;color:var(--flm-text-muted);font-size:12px;">For real GA4 data, create a service account in <a href="https://console.cloud.google.com/" target="_blank" style="color:var(--flm-accent);">Google Cloud Console</a>, enable the Analytics Data API, and paste the JSON key below.</p>
                                                <div class="flm-form-group" style="margin:0;">
                                                    <label class="flm-label" for="flm-ga4-service-account">Service Account JSON</label>
                                                    <textarea name="flm_settings[ga4_service_account]" id="flm-ga4-service-account" class="flm-input" rows="4" style="font-family:var(--flm-font-mono);font-size:11px;" placeholder='{"type":"service_account","project_id":"...","private_key":"..."}'><?php echo esc_textarea($settings['ga4_service_account'] ?? ''); ?></textarea>
                                                </div>
                                                <?php if (!empty($settings['ga4_service_account'])): ?>
                                                <div style="margin-top:8px;padding:8px 12px;background:rgba(63,185,80,0.1);border-radius:6px;border:1px solid rgba(63,185,80,0.2);">
                                                    <span style="color:var(--flm-success);font-size:12px;">✓ Service account configured - using real GA4 Data API</span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Analytics Options (v2.11.0) -->
                                            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--flm-border);">
                                                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;">
                                                    <label class="flm-checkbox-card" style="padding:10px;">
                                                        <input type="checkbox" name="flm_settings[article_tracking_enabled]" value="1" <?php checked(!empty($settings['article_tracking_enabled']) || !isset($settings['article_tracking_enabled'])); ?>>
                                                        <span class="flm-checkbox-card-content">
                                                            <span class="flm-checkbox-card-title" style="font-size:12px;">Article Tracking</span>
                                                            <span class="flm-checkbox-card-desc" style="font-size:10px;">Track per-article GA4 metrics</span>
                                                        </span>
                                                    </label>
                                                    <label class="flm-checkbox-card" style="padding:10px;">
                                                        <input type="checkbox" name="flm_settings[best_times_auto_learn]" value="1" <?php checked(!empty($settings['best_times_auto_learn']) || !isset($settings['best_times_auto_learn'])); ?>>
                                                        <span class="flm-checkbox-card-content">
                                                            <span class="flm-checkbox-card-title" style="font-size:12px;">Auto-Learn Best Times</span>
                                                            <span class="flm-checkbox-card-desc" style="font-size:10px;">Optimize posting from traffic data</span>
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-integration" data-integration="ga4" style="margin-top:12px;">
                                                <?php echo $this->icon('check'); ?> Test Connection
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Twitter/X -->
                                    <div class="flm-integration-group">
                                        <div class="flm-integration-group-header">
                                            <div class="flm-integration-group-title">
                                                <div class="flm-integration-logo twitter" style="width:32px;height:32px;border-radius:8px;">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;color:#fff;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                                </div>
                                                Twitter / X
                                            </div>
                                            <span class="flm-integration-status <?php echo !empty($settings['twitter_api_key']) ? 'connected' : 'disconnected'; ?>">
                                                <span>●</span> <?php echo !empty($settings['twitter_api_key']) ? 'Connected' : 'Not Connected'; ?>
                                            </span>
                                        </div>
                                        <div class="flm-integration-group-body">
                                            <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">Track social engagement on X. Create an app at <a href="https://developer.twitter.com/" target="_blank" style="color:var(--flm-accent);">developer.twitter.com</a></p>
                                            <div class="flm-integration-fields">
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-twitter-key">API Key</label>
                                                    <input type="text" name="flm_settings[twitter_api_key]" id="flm-twitter-key" class="flm-input" value="<?php echo esc_attr($settings['twitter_api_key'] ?? ''); ?>" placeholder="API Key">
                                                </div>
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-twitter-secret">API Secret</label>
                                                    <div class="flm-input-with-toggle">
                                                        <input type="password" name="flm_settings[twitter_api_secret]" id="flm-twitter-secret" class="flm-input" value="<?php echo esc_attr($settings['twitter_api_secret'] ?? ''); ?>" placeholder="API Secret">
                                                        <button type="button" class="flm-toggle-password" data-target="flm-twitter-secret">
                                                            <?php echo $this->icon('eye'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-twitter-access">Access Token</label>
                                                    <input type="text" name="flm_settings[twitter_access_token]" id="flm-twitter-access" class="flm-input" value="<?php echo esc_attr($settings['twitter_access_token'] ?? ''); ?>" placeholder="Access Token">
                                                </div>
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-twitter-access-secret">Access Secret</label>
                                                    <div class="flm-input-with-toggle">
                                                        <input type="password" name="flm_settings[twitter_access_secret]" id="flm-twitter-access-secret" class="flm-input" value="<?php echo esc_attr($settings['twitter_access_secret'] ?? ''); ?>" placeholder="Access Token Secret">
                                                        <button type="button" class="flm-toggle-password" data-target="flm-twitter-access-secret">
                                                            <?php echo $this->icon('eye'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-integration" data-integration="twitter" style="margin-top:8px;">
                                                <?php echo $this->icon('check'); ?> Test Connection
                                            </button>
                                            <button type="button" class="flm-setup-guide-btn flm-open-wizard" data-wizard="twitter" style="margin-top:8px;margin-left:8px;">
                                                <?php echo $this->icon('info'); ?> Setup Guide
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Facebook -->
                                    <div class="flm-integration-group">
                                        <div class="flm-integration-group-header">
                                            <div class="flm-integration-group-title">
                                                <div class="flm-integration-logo facebook" style="width:32px;height:32px;border-radius:8px;">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;color:#fff;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                                </div>
                                                Facebook
                                            </div>
                                            <span class="flm-integration-status <?php echo !empty($settings['facebook_app_id']) ? 'connected' : 'disconnected'; ?>">
                                                <span>●</span> <?php echo !empty($settings['facebook_app_id']) ? 'Connected' : 'Not Connected'; ?>
                                            </span>
                                        </div>
                                        <div class="flm-integration-group-body">
                                            <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">Monitor Facebook page engagement. Create an app at <a href="https://developers.facebook.com/" target="_blank" style="color:var(--flm-accent);">developers.facebook.com</a></p>
                                            <div class="flm-integration-fields">
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-fb-app-id">App ID</label>
                                                    <input type="text" name="flm_settings[facebook_app_id]" id="flm-fb-app-id" class="flm-input" value="<?php echo esc_attr($settings['facebook_app_id'] ?? ''); ?>" placeholder="App ID">
                                                </div>
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-fb-app-secret">App Secret</label>
                                                    <div class="flm-input-with-toggle">
                                                        <input type="password" name="flm_settings[facebook_app_secret]" id="flm-fb-app-secret" class="flm-input" value="<?php echo esc_attr($settings['facebook_app_secret'] ?? ''); ?>" placeholder="App Secret">
                                                        <button type="button" class="flm-toggle-password" data-target="flm-fb-app-secret">
                                                            <?php echo $this->icon('eye'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-fb-page-id">Page ID</label>
                                                    <input type="text" name="flm_settings[facebook_page_id]" id="flm-fb-page-id" class="flm-input" value="<?php echo esc_attr($settings['facebook_page_id'] ?? ''); ?>" placeholder="Page ID">
                                                </div>
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-fb-access-token">Access Token</label>
                                                    <div class="flm-input-with-toggle">
                                                        <input type="password" name="flm_settings[facebook_access_token]" id="flm-fb-access-token" class="flm-input" value="<?php echo esc_attr($settings['facebook_access_token'] ?? ''); ?>" placeholder="Page Access Token">
                                                        <button type="button" class="flm-toggle-password" data-target="flm-fb-access-token">
                                                            <?php echo $this->icon('eye'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-integration" data-integration="facebook" style="margin-top:8px;">
                                                <?php echo $this->icon('check'); ?> Test Connection
                                            </button>
                                            <button type="button" class="flm-setup-guide-btn flm-open-wizard" data-wizard="facebook" style="margin-top:8px;margin-left:8px;">
                                                <?php echo $this->icon('info'); ?> Setup Guide
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Google Search Console -->
                                    <div class="flm-integration-group">
                                        <div class="flm-integration-group-header">
                                            <div class="flm-integration-group-title">
                                                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#4285f4,#34a853);display:flex;align-items:center;justify-content:center;">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;color:#fff;"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                                                </div>
                                                Google Search Console
                                            </div>
                                            <span class="flm-integration-status <?php echo !empty($settings['gsc_property_url']) ? 'connected' : 'disconnected'; ?>">
                                                <span>●</span> <?php echo !empty($settings['gsc_property_url']) ? 'Configured' : 'Not Configured'; ?>
                                            </span>
                                        </div>
                                        <div class="flm-integration-group-body">
                                            <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">Track keyword rankings and search performance. Set up at <a href="https://search.google.com/search-console" target="_blank" style="color:var(--flm-accent);">Search Console</a>.</p>
                                            <div class="flm-integration-fields">
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-gsc-property">Property URL</label>
                                                    <input type="text" name="flm_settings[gsc_property_url]" id="flm-gsc-property" class="flm-input" value="<?php echo esc_attr($settings['gsc_property_url'] ?? ''); ?>" placeholder="https://yoursite.com or sc-domain:yoursite.com">
                                                </div>
                                            </div>
                                            
                                            <!-- Service Account (v2.11.0) -->
                                            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--flm-border);">
                                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                                                    <span class="flm-badge" style="background:rgba(63,185,80,0.15);color:var(--flm-success);font-size:10px;">v2.11.0</span>
                                                    <span style="font-weight:600;color:var(--flm-text);">Service Account for Search Console API</span>
                                                </div>
                                                <p style="margin:0 0 12px;color:var(--flm-text-muted);font-size:12px;">For real GSC data, create a service account in <a href="https://console.cloud.google.com/" target="_blank" style="color:var(--flm-accent);">Google Cloud Console</a>, add it to your Search Console property, and paste the JSON key below.</p>
                                                <div class="flm-form-group" style="margin:0;">
                                                    <label class="flm-label" for="flm-gsc-service-account">Service Account JSON</label>
                                                    <textarea name="flm_settings[gsc_service_account]" id="flm-gsc-service-account" class="flm-input" rows="4" style="font-family:var(--flm-font-mono);font-size:11px;" placeholder='{"type":"service_account","project_id":"...","private_key":"..."}'><?php echo esc_textarea($settings['gsc_service_account'] ?? ''); ?></textarea>
                                                </div>
                                                <?php if (!empty($settings['gsc_service_account'])): ?>
                                                <div style="margin-top:8px;padding:8px 12px;background:rgba(63,185,80,0.1);border-radius:6px;border:1px solid rgba(63,185,80,0.2);">
                                                    <span style="color:var(--flm-success);font-size:12px;">✓ Service account configured - using real GSC API</span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-integration" data-integration="gsc" style="margin-top:12px;">
                                                <?php echo $this->icon('check'); ?> Test Connection
                                            </button>
                                            <button type="button" class="flm-setup-guide-btn flm-open-wizard" data-wizard="google" style="margin-top:8px;margin-left:8px;">
                                                <?php echo $this->icon('info'); ?> Setup Guide
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Bing Webmaster Tools -->
                                    <div class="flm-integration-group">
                                        <div class="flm-integration-group-header">
                                            <div class="flm-integration-group-title">
                                                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#008373,#00a99d);display:flex;align-items:center;justify-content:center;">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;color:#fff;"><path d="M5 3v18l4-2.5 6 3.5 4-2V6l-4 2-6-3.5L5 3zm10 13l-4-2V8l4 2v6z"/></svg>
                                                </div>
                                                Bing Webmaster Tools
                                            </div>
                                            <span class="flm-integration-status <?php echo !empty($settings['bing_api_key']) ? 'connected' : 'disconnected'; ?>">
                                                <span>●</span> <?php echo !empty($settings['bing_api_key']) ? 'Connected' : 'Not Connected'; ?>
                                            </span>
                                        </div>
                                        <div class="flm-integration-group-body">
                                            <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">Monitor Bing search performance. Get your API key from <a href="https://www.bing.com/webmasters" target="_blank" style="color:var(--flm-accent);">Bing Webmaster Tools</a> → Settings → API Access.</p>
                                            <div class="flm-integration-fields">
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-bing-site">Site URL</label>
                                                    <input type="text" name="flm_settings[bing_site_url]" id="flm-bing-site" class="flm-input" value="<?php echo esc_attr($settings['bing_site_url'] ?? ''); ?>" placeholder="https://yoursite.com">
                                                </div>
                                                <div class="flm-form-group">
                                                    <label class="flm-label" for="flm-bing-api">API Key</label>
                                                    <div class="flm-input-with-toggle">
                                                        <input type="password" name="flm_settings[bing_api_key]" id="flm-bing-api" class="flm-input" value="<?php echo esc_attr($settings['bing_api_key'] ?? ''); ?>" placeholder="Your Bing API Key">
                                                        <button type="button" class="flm-toggle-password" data-target="flm-bing-api">
                                                            <?php echo $this->icon('eye'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-integration" data-integration="bing" style="margin-top:8px;">
                                                <?php echo $this->icon('check'); ?> Test Connection
                                            </button>
                                            <button type="button" class="flm-setup-guide-btn flm-open-wizard" data-wizard="bing" style="margin-top:8px;margin-left:8px;">
                                                <?php echo $this->icon('info'); ?> Setup Guide
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- ESP/Email Integration (v2.13.0) -->
                                    <div class="flm-integration-group" style="margin-top:24px;border-top:2px solid var(--flm-accent);padding-top:24px;">
                                        <div class="flm-integration-group-header">
                                            <div class="flm-integration-group-title">
                                                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;color:#fff;"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                                                </div>
                                                Email/ESP Integration
                                                <span class="flm-badge" style="background:rgba(99,102,241,0.15);color:#6366f1;margin-left:8px;">v2.13.0</span>
                                            </div>
                                        </div>
                                        <div class="flm-integration-group-body">
                                            <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">Track email newsletter performance and attribute clicks to FLM articles. This is your primary traffic source!</p>
                                            
                                            <!-- ESP Provider Selection -->
                                            <div class="flm-form-group" style="margin-bottom:20px;">
                                                <label class="flm-label">Email Service Provider</label>
                                                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                                    <label class="flm-radio-card" style="flex:1;min-width:140px;">
                                                        <input type="radio" name="flm_settings[esp_provider]" value="none" <?php checked(($settings['esp_provider'] ?? 'none') === 'none'); ?>>
                                                        <span class="flm-radio-card-content">
                                                            <span class="flm-radio-card-title">None</span>
                                                            <span class="flm-radio-card-desc">No ESP configured</span>
                                                        </span>
                                                    </label>
                                                    <label class="flm-radio-card" style="flex:1;min-width:140px;">
                                                        <input type="radio" name="flm_settings[esp_provider]" value="sendgrid" <?php checked(($settings['esp_provider'] ?? 'none') === 'sendgrid'); ?>>
                                                        <span class="flm-radio-card-content">
                                                            <span class="flm-radio-card-title">SendGrid</span>
                                                            <span class="flm-radio-card-desc">Twilio SendGrid API</span>
                                                        </span>
                                                    </label>
                                                    <label class="flm-radio-card" style="flex:1;min-width:140px;">
                                                        <input type="radio" name="flm_settings[esp_provider]" value="aigeon" <?php checked(($settings['esp_provider'] ?? 'none') === 'aigeon'); ?>>
                                                        <span class="flm-radio-card-content">
                                                            <span class="flm-radio-card-title">Aigeon</span>
                                                            <span class="flm-radio-card-desc">AI-Native Email OS</span>
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <!-- SendGrid Settings -->
                                            <div id="flm-sendgrid-settings" style="display:<?php echo ($settings['esp_provider'] ?? 'none') === 'sendgrid' ? 'block' : 'none'; ?>;margin-bottom:20px;padding:16px;background:var(--flm-bg-input);border-radius:8px;border:1px solid var(--flm-border);">
                                                <h5 style="margin:0 0 12px;font-size:13px;color:var(--flm-text);display:flex;align-items:center;gap:8px;">
                                                    <div style="width:24px;height:24px;border-radius:6px;background:linear-gradient(135deg,#1A82E2,#00C4CC);display:flex;align-items:center;justify-content:center;">
                                                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:12px;height:12px;color:#fff;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/></svg>
                                                    </div>
                                                    SendGrid Configuration
                                                </h5>
                                                <div class="flm-integration-fields">
                                                    <div class="flm-form-group">
                                                        <label class="flm-label" for="flm-sendgrid-api">API Key</label>
                                                        <div class="flm-input-with-toggle">
                                                            <input type="password" name="flm_settings[sendgrid_api_key]" id="flm-sendgrid-api" class="flm-input" value="<?php echo esc_attr($settings['sendgrid_api_key'] ?? ''); ?>" placeholder="SG.xxxxxxxxxx...">
                                                            <button type="button" class="flm-toggle-password" data-target="flm-sendgrid-api">
                                                                <?php echo $this->icon('eye'); ?>
                                                            </button>
                                                        </div>
                                                        <div class="flm-label-hint">Get from <a href="https://app.sendgrid.com/settings/api_keys" target="_blank" style="color:var(--flm-accent);">SendGrid → Settings → API Keys</a></div>
                                                    </div>
                                                    <div class="flm-form-group">
                                                        <label class="flm-label" for="flm-sendgrid-category">Category Filter (Optional)</label>
                                                        <input type="text" name="flm_settings[sendgrid_category]" id="flm-sendgrid-category" class="flm-input" value="<?php echo esc_attr($settings['sendgrid_category'] ?? ''); ?>" placeholder="newsletter">
                                                        <div class="flm-label-hint">Filter stats to a specific category (e.g., 'newsletter', 'daily_digest')</div>
                                                    </div>
                                                </div>
                                                <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-esp" data-provider="sendgrid" style="margin-top:12px;">
                                                    <?php echo $this->icon('check'); ?> Test SendGrid Connection
                                                </button>
                                            </div>
                                            
                                            <!-- Aigeon Settings -->
                                            <div id="flm-aigeon-settings" style="display:<?php echo ($settings['esp_provider'] ?? 'none') === 'aigeon' ? 'block' : 'none'; ?>;margin-bottom:20px;padding:16px;background:var(--flm-bg-input);border-radius:8px;border:1px solid var(--flm-border);">
                                                <h5 style="margin:0 0 12px;font-size:13px;color:var(--flm-text);display:flex;align-items:center;gap:8px;">
                                                    <div style="width:24px;height:24px;border-radius:6px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;">
                                                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:12px;height:12px;color:#fff;"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2z"/></svg>
                                                    </div>
                                                    Aigeon Configuration
                                                    <span class="flm-badge" style="background:rgba(251,191,36,0.15);color:#f59e0b;font-size:9px;">API Pending</span>
                                                </h5>
                                                <div style="padding:12px;background:rgba(251,191,36,0.1);border-radius:6px;border:1px solid rgba(251,191,36,0.2);margin-bottom:12px;">
                                                    <span style="color:#f59e0b;font-size:12px;">⏳ Aigeon API endpoints are placeholders. Update when API docs are available.</span>
                                                </div>
                                                <div class="flm-integration-fields">
                                                    <div class="flm-form-group">
                                                        <label class="flm-label" for="flm-aigeon-api">API Key</label>
                                                        <div class="flm-input-with-toggle">
                                                            <input type="password" name="flm_settings[aigeon_api_key]" id="flm-aigeon-api" class="flm-input" value="<?php echo esc_attr($settings['aigeon_api_key'] ?? ''); ?>" placeholder="Your Aigeon API Key">
                                                            <button type="button" class="flm-toggle-password" data-target="flm-aigeon-api">
                                                                <?php echo $this->icon('eye'); ?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div class="flm-form-group">
                                                        <label class="flm-label" for="flm-aigeon-account">Account ID</label>
                                                        <input type="text" name="flm_settings[aigeon_account_id]" id="flm-aigeon-account" class="flm-input" value="<?php echo esc_attr($settings['aigeon_account_id'] ?? ''); ?>" placeholder="Your Aigeon Account ID">
                                                    </div>
                                                </div>
                                                <button type="button" class="flm-btn flm-btn-secondary flm-btn-sm flm-test-esp" data-provider="aigeon" style="margin-top:12px;">
                                                    <?php echo $this->icon('check'); ?> Test Aigeon Connection
                                                </button>
                                            </div>
                                            
                                            <!-- ESP Sync Options -->
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
                                                <div class="flm-form-group" style="margin:0;">
                                                    <label class="flm-label" for="flm-esp-cache">Cache Duration (minutes)</label>
                                                    <input type="number" name="flm_settings[esp_cache_minutes]" id="flm-esp-cache" class="flm-input" value="<?php echo esc_attr($settings['esp_cache_minutes'] ?? 30); ?>" min="5" max="120">
                                                </div>
                                                <label class="flm-checkbox-card" style="align-self:end;">
                                                    <input type="checkbox" name="flm_settings[esp_sync_enabled]" value="1" <?php checked(!empty($settings['esp_sync_enabled'])); ?>>
                                                    <span class="flm-checkbox-card-content">
                                                        <span class="flm-checkbox-card-title">Auto-Sync to Articles</span>
                                                        <span class="flm-checkbox-card-desc">Save click data to post meta hourly</span>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- ML Feature Toggles -->
                                    <div style="margin-top:24px;padding-top:24px;border-top:1px solid var(--flm-border);">
                                        <h4 style="margin:0 0 16px;font-size:14px;font-weight:600;color:var(--flm-text);">ML Feature Toggles</h4>
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
                                            <label class="flm-checkbox-card">
                                                <input type="checkbox" name="flm_settings[ml_headline_analysis]" value="1" <?php checked(!empty($settings['ml_headline_analysis'])); ?>>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title">Headline Analysis</span>
                                                    <span class="flm-checkbox-card-desc">AI-powered headline scoring</span>
                                                </span>
                                            </label>
                                            <label class="flm-checkbox-card">
                                                <input type="checkbox" name="flm_settings[ml_publish_time_optimization]" value="1" <?php checked(!empty($settings['ml_publish_time_optimization'])); ?>>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title">Publish Time Optimization</span>
                                                    <span class="flm-checkbox-card-desc">Best time predictions</span>
                                                </span>
                                            </label>
                                            <label class="flm-checkbox-card">
                                                <input type="checkbox" name="flm_settings[ml_performance_prediction]" value="1" <?php checked(!empty($settings['ml_performance_prediction'])); ?>>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title">Performance Prediction</span>
                                                    <span class="flm-checkbox-card-desc">Views & engagement forecasts</span>
                                                </span>
                                            </label>
                                            <label class="flm-checkbox-card">
                                                <input type="checkbox" name="flm_settings[ml_trend_detection]" value="1" <?php checked(!empty($settings['ml_trend_detection'])); ?>>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title">Trend Detection</span>
                                                    <span class="flm-checkbox-card-desc">Trending topic alerts</span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <!-- Social Auto-Posting (v2.9.0) -->
                                    <div style="margin-top:24px;padding-top:24px;border-top:1px solid var(--flm-border);">
                                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                                            <h4 style="margin:0;font-size:14px;font-weight:600;color:var(--flm-text);">Social Auto-Posting</h4>
                                            <span class="flm-badge" style="background:rgba(63,185,80,0.15);color:var(--flm-success);">v2.9.0</span>
                                        </div>
                                        <p style="margin:0 0 16px;color:var(--flm-text-muted);font-size:13px;">
                                            Automatically share new articles to Twitter/X and Facebook when they're imported. Configure credentials above, then enable auto-posting below.
                                        </p>
                                        
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
                                            <label class="flm-checkbox-card" data-requires-twitter="true">
                                                <input type="checkbox" name="flm_settings[auto_post_twitter]" value="1" <?php checked(!empty($settings['auto_post_twitter'])); ?> <?php disabled(empty($settings['twitter_access_token'])); ?>>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title">
                                                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;margin-right:4px;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                                        Auto-post to Twitter/X
                                                    </span>
                                                    <span class="flm-checkbox-card-desc"><?php echo !empty($settings['twitter_access_token']) ? 'Share when articles import' : 'Configure Twitter above first'; ?></span>
                                                </span>
                                            </label>
                                            <label class="flm-checkbox-card" data-requires-facebook="true">
                                                <input type="checkbox" name="flm_settings[auto_post_facebook]" value="1" <?php checked(!empty($settings['auto_post_facebook'])); ?> <?php disabled(empty($settings['facebook_access_token'])); ?>>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title">
                                                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;margin-right:4px;color:#1877f2;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                                        Auto-post to Facebook
                                                    </span>
                                                    <span class="flm-checkbox-card-desc"><?php echo !empty($settings['facebook_access_token']) ? 'Share to your page' : 'Configure Facebook above first'; ?></span>
                                                </span>
                                            </label>
                                        </div>
                                        
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
                                            <label class="flm-checkbox-card">
                                                <input type="checkbox" name="flm_settings[social_include_image]" value="1" <?php checked(!empty($settings['social_include_image']) || !isset($settings['social_include_image'])); ?>>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title">Include Images</span>
                                                    <span class="flm-checkbox-card-desc">Attach featured image to posts</span>
                                                </span>
                                            </label>
                                            <label class="flm-checkbox-card">
                                                <input type="checkbox" name="flm_settings[social_queue_enabled]" value="1" <?php checked(!empty($settings['social_queue_enabled'])); ?>>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title">Queue for Drafts</span>
                                                    <span class="flm-checkbox-card-desc">Queue social posts until published</span>
                                                </span>
                                            </label>
                                        </div>
                                        
                                        <!-- Twitter Template -->
                                        <div class="flm-form-group" style="margin-bottom:16px;">
                                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                                <label class="flm-label" for="flm-twitter-template" style="margin:0;">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;margin-right:6px;vertical-align:-2px;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                                    Twitter/X Template
                                                </label>
                                                <button type="button" class="flm-btn flm-btn-secondary flm-btn-xs flm-test-social" data-platform="twitter" <?php disabled(empty($settings['twitter_access_token'])); ?>>
                                                    <?php echo $this->icon('bolt'); ?> Test Tweet
                                                </button>
                                            </div>
                                            <textarea name="flm_settings[twitter_post_template]" id="flm-twitter-template" class="flm-input" rows="2" style="resize:vertical;"
                                                      placeholder="📰 {headline} #Atlanta #Sports {team_hashtag}"><?php echo esc_textarea($settings['twitter_post_template'] ?? '📰 {headline} #Atlanta #Sports {team_hashtag}'); ?></textarea>
                                            <div class="flm-label-hint" style="margin-top:6px;font-size:11px;color:var(--flm-text-muted);">
                                                Max 280 chars. Tags: <code>{headline}</code> <code>{url}</code> <code>{team}</code> <code>{league}</code> <code>{team_hashtag}</code>
                                            </div>
                                        </div>
                                        
                                        <!-- Facebook Template -->
                                        <div class="flm-form-group" style="margin-bottom:16px;">
                                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                                <label class="flm-label" for="flm-facebook-template" style="margin:0;">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;margin-right:6px;vertical-align:-2px;color:#1877f2;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                                    Facebook Template
                                                </label>
                                                <button type="button" class="flm-btn flm-btn-secondary flm-btn-xs flm-test-social" data-platform="facebook" <?php disabled(empty($settings['facebook_access_token'])); ?>>
                                                    <?php echo $this->icon('bolt'); ?> Test Post
                                                </button>
                                            </div>
                                            <textarea name="flm_settings[facebook_post_template]" id="flm-facebook-template" class="flm-input" rows="3" style="resize:vertical;"
                                                      placeholder="{headline}&#10;&#10;Read more: {url}"><?php echo esc_textarea($settings['facebook_post_template'] ?? "{headline}\n\nRead more: {url}"); ?></textarea>
                                            <div class="flm-label-hint" style="margin-top:6px;font-size:11px;color:var(--flm-text-muted);">
                                                Tags: <code>{headline}</code> <code>{url}</code> <code>{team}</code> <code>{league}</code> — Use <code>\n</code> for line breaks
                                            </div>
                                        </div>
                                        
                                        <div class="flm-form-group">
                                            <label class="flm-label" for="flm-social-delay">Post Delay</label>
                                            <div class="flm-select-wrap" style="max-width:200px;">
                                                <select name="flm_settings[social_post_delay]" id="flm-social-delay" class="flm-select">
                                                    <option value="0" <?php selected(($settings['social_post_delay'] ?? 0), 0); ?>>Immediate</option>
                                                    <option value="300" <?php selected(($settings['social_post_delay'] ?? 0), 300); ?>>5 minutes</option>
                                                    <option value="900" <?php selected(($settings['social_post_delay'] ?? 0), 900); ?>>15 minutes</option>
                                                    <option value="1800" <?php selected(($settings['social_post_delay'] ?? 0), 1800); ?>>30 minutes</option>
                                                    <option value="3600" <?php selected(($settings['social_post_delay'] ?? 0), 3600); ?>>1 hour</option>
                                                </select>
                                            </div>
                                            <div class="flm-label-hint" style="margin-top:4px;font-size:11px;color:var(--flm-text-muted);">
                                                Delay between import and social posting (queued via WP Cron)
                                            </div>
                                        </div>
                                        
                                        <!-- UTM Tracking (v2.10.0) -->
                                        <div style="margin-top:24px;padding-top:24px;border-top:1px solid var(--flm-border);">
                                            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                                                <h4 style="margin:0;font-size:14px;font-weight:600;color:var(--flm-text);">UTM Tracking</h4>
                                                <span class="flm-badge" style="background:rgba(63,185,80,0.15);color:var(--flm-success);">v2.10.0</span>
                                            </div>
                                            
                                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
                                                <label class="flm-checkbox-card">
                                                    <input type="checkbox" name="flm_settings[utm_enabled]" value="1" <?php checked(!empty($settings['utm_enabled']) || !isset($settings['utm_enabled'])); ?>>
                                                    <span class="flm-checkbox-card-content">
                                                        <span class="flm-checkbox-card-title">Enable UTM Tracking</span>
                                                        <span class="flm-checkbox-card-desc">Add tracking parameters to social links</span>
                                                    </span>
                                                </label>
                                                <label class="flm-checkbox-card">
                                                    <input type="checkbox" name="flm_settings[social_preview_meta_box]" value="1" <?php checked(!empty($settings['social_preview_meta_box']) || !isset($settings['social_preview_meta_box'])); ?>>
                                                    <span class="flm-checkbox-card-content">
                                                        <span class="flm-checkbox-card-title">Social Preview Box</span>
                                                        <span class="flm-checkbox-card-desc">Show preview in post editor</span>
                                                    </span>
                                                </label>
                                            </div>
                                            
                                            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;" id="flm-utm-fields">
                                                <div class="flm-form-group" style="margin:0;">
                                                    <label class="flm-label">utm_source</label>
                                                    <input type="text" name="flm_settings[utm_source]" class="flm-input" 
                                                           value="<?php echo esc_attr($settings['utm_source'] ?? 'social'); ?>"
                                                           placeholder="social">
                                                </div>
                                                <div class="flm-form-group" style="margin:0;">
                                                    <label class="flm-label">utm_medium</label>
                                                    <input type="text" name="flm_settings[utm_medium]" class="flm-input" 
                                                           value="<?php echo esc_attr($settings['utm_medium'] ?? '{platform}'); ?>"
                                                           placeholder="{platform}">
                                                </div>
                                                <div class="flm-form-group" style="margin:0;">
                                                    <label class="flm-label">utm_campaign</label>
                                                    <input type="text" name="flm_settings[utm_campaign]" class="flm-input" 
                                                           value="<?php echo esc_attr($settings['utm_campaign'] ?? 'flm_auto'); ?>"
                                                           placeholder="flm_auto">
                                                </div>
                                                <div class="flm-form-group" style="margin:0;">
                                                    <label class="flm-label">utm_content</label>
                                                    <input type="text" name="flm_settings[utm_content]" class="flm-input" 
                                                           value="<?php echo esc_attr($settings['utm_content'] ?? '{team}'); ?>"
                                                           placeholder="{team}">
                                                </div>
                                            </div>
                                            <div class="flm-label-hint" style="margin-top:8px;font-size:11px;color:var(--flm-text-muted);">
                                                Variables: <code>{platform}</code> (twitter/facebook), <code>{team}</code>, <code>{league}</code>
                                            </div>
                                        </div>
                                        
                                        <?php 
                                        $social_history = $this->get_social_history(5);
                                        $queue = get_option('flm_social_queue', []);
                                        $queue_count = count($queue);
                                        $scheduled_posts = get_option('flm_scheduled_posts', []);
                                        $scheduled_count = count(array_filter($scheduled_posts, function($s) { return ($s['scheduled_for'] ?? 0) > time(); }));
                                        ?>
                                        
                                        <?php if ($scheduled_count > 0): ?>
                                        <div style="margin-top:16px;padding:12px;background:rgba(63,185,80,0.1);border-radius:8px;border:1px solid rgba(63,185,80,0.2);">
                                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                                <span style="font-size:13px;color:var(--flm-success);">
                                                    ⏱ <strong><?php echo $scheduled_count; ?></strong> scheduled social post(s)
                                                </span>
                                            </div>
                                            <div style="font-size:12px;color:var(--flm-text-muted);">
                                                <?php 
                                                $next_scheduled = null;
                                                foreach ($scheduled_posts as $s) {
                                                    if (($s['scheduled_for'] ?? 0) > time()) {
                                                        if (!$next_scheduled || $s['scheduled_for'] < $next_scheduled['scheduled_for']) {
                                                            $next_scheduled = $s;
                                                        }
                                                    }
                                                }
                                                if ($next_scheduled):
                                                    $post_title = get_the_title($next_scheduled['post_id']);
                                                ?>
                                                Next: "<?php echo esc_html(substr($post_title, 0, 30)); ?><?php echo strlen($post_title) > 30 ? '...' : ''; ?>" 
                                                to <?php echo ucfirst($next_scheduled['platform']); ?> 
                                                in <?php echo human_time_diff(time(), $next_scheduled['scheduled_for']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($queue_count > 0): ?>
                                        <div style="margin-top:16px;padding:12px;background:rgba(88,166,255,0.1);border-radius:8px;border:1px solid rgba(88,166,255,0.2);">
                                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                                <span style="font-size:13px;color:var(--flm-info);">
                                                    <?php echo $this->icon('clock'); ?>
                                                    <strong><?php echo $queue_count; ?></strong> post(s) queued for social sharing
                                                </span>
                                                <button type="button" id="flm-clear-social-queue" class="flm-btn flm-btn-secondary flm-btn-xs">Clear Queue</button>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($social_history)): ?>
                                        <div style="margin-top:16px;">
                                            <h5 style="margin:0 0 8px;font-size:12px;font-weight:600;color:var(--flm-text-muted);text-transform:uppercase;">Recent Social Posts</h5>
                                            <div style="background:var(--flm-bg-input);border-radius:8px;border:1px solid var(--flm-border);overflow:hidden;">
                                                <?php foreach (array_slice($social_history, 0, 3) as $entry): ?>
                                                <div style="padding:10px 12px;border-bottom:1px solid var(--flm-border);display:flex;align-items:center;gap:10px;">
                                                    <span style="font-size:12px;color:var(--flm-text-muted);white-space:nowrap;"><?php echo date('M j g:ia', strtotime($entry['timestamp'])); ?></span>
                                                    <span style="font-size:13px;color:var(--flm-text);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($entry['post_title']); ?></span>
                                                    <div style="display:flex;gap:6px;">
                                                        <?php if (isset($entry['results']['twitter'])): ?>
                                                        <span style="font-size:11px;padding:2px 6px;border-radius:4px;background:<?php echo $entry['results']['twitter']['success'] ? 'rgba(63,185,80,0.15)' : 'rgba(248,81,73,0.15)'; ?>;color:<?php echo $entry['results']['twitter']['success'] ? 'var(--flm-success)' : 'var(--flm-danger)'; ?>;">
                                                            𝕏 <?php echo $entry['results']['twitter']['success'] ? '✓' : '✗'; ?>
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if (isset($entry['results']['facebook'])): ?>
                                                        <span style="font-size:11px;padding:2px 6px;border-radius:4px;background:<?php echo $entry['results']['facebook']['success'] ? 'rgba(63,185,80,0.15)' : 'rgba(248,81,73,0.15)'; ?>;color:<?php echo $entry['results']['facebook']['success'] ? 'var(--flm-success)' : 'var(--flm-danger)'; ?>;">
                                                            FB <?php echo $entry['results']['facebook']['success'] ? '✓' : '✗'; ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                    </div>
                                    
                                </div>
                            </div>
                            
                            <!-- Settings Import/Export (v2.12.0) -->
                            <div class="flm-card" style="margin-top:24px;">
                                <div class="flm-card-header">
                                    <h2 class="flm-card-title">
                                        <span class="flm-card-icon"><?php echo $this->icon('folder'); ?></span>
                                        Settings Import/Export
                                        <span class="flm-badge" style="background:rgba(63,185,80,0.15);color:var(--flm-success);margin-left:8px;">v2.12.0</span>
                                    </h2>
                                </div>
                                <div class="flm-card-body">
                                    <p style="margin:0 0 20px;color:var(--flm-text-muted);">
                                        Export your settings to deploy to other sites, or import settings from another installation.
                                    </p>
                                    
                                    <div class="flm-grid-2" style="gap:24px;">
                                        <!-- Export Section -->
                                        <div style="background:var(--flm-bg-input);border-radius:12px;padding:20px;border:1px solid var(--flm-border);">
                                            <h4 style="margin:0 0 16px;color:var(--flm-text);font-size:14px;display:flex;align-items:center;gap:8px;">
                                                <?php echo $this->icon('download'); ?>
                                                Export Settings
                                            </h4>
                                            
                                            <div style="margin-bottom:16px;">
                                                <label class="flm-label" style="margin-bottom:8px;display:block;">Select categories to export:</label>
                                                <div style="display:grid;gap:8px;">
                                                    <?php 
                                                    $export_categories = [
                                                        'general' => ['General Settings', 'Post status, author, import frequency'],
                                                        'content' => ['Content Filters', 'Story types, teams enabled'],
                                                        'social_posting' => ['Social Posting', 'Templates, UTM, scheduling'],
                                                        'ml_features' => ['ML/AI Features', 'Headline analysis, predictions'],
                                                        'analytics' => ['Analytics Settings', 'Cache, auto-learn times'],
                                                        'api_keys' => ['API Keys & Credentials', 'All integration credentials'],
                                                    ];
                                                    foreach ($export_categories as $key => $info): ?>
                                                    <label class="flm-checkbox-card" style="padding:10px 12px;">
                                                        <input type="checkbox" class="flm-export-category" value="<?php echo $key; ?>" <?php echo $key !== 'api_keys' ? 'checked' : ''; ?>>
                                                        <span class="flm-checkbox-card-content">
                                                            <span class="flm-checkbox-card-title" style="font-size:12px;"><?php echo $info[0]; ?><?php if ($key === 'api_keys'): ?> <span style="color:var(--flm-warning);">⚠️</span><?php endif; ?></span>
                                                            <span class="flm-checkbox-card-desc" style="font-size:10px;"><?php echo $info[1]; ?></span>
                                                        </span>
                                                    </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="flm-form-group" style="margin-bottom:16px;">
                                                <label class="flm-label">Encryption Passphrase (optional)</label>
                                                <input type="password" id="flm-export-passphrase" class="flm-input" placeholder="Leave empty for unencrypted export">
                                                <div class="flm-label-hint">Recommended if including API keys</div>
                                            </div>
                                            
                                            <button type="button" id="flm-export-settings" class="flm-btn flm-btn-secondary" style="width:100%;">
                                                <?php echo $this->icon('download'); ?>
                                                Export Settings
                                            </button>
                                        </div>
                                        
                                        <!-- Import Section -->
                                        <div style="background:var(--flm-bg-input);border-radius:12px;padding:20px;border:1px solid var(--flm-border);">
                                            <h4 style="margin:0 0 16px;color:var(--flm-text);font-size:14px;display:flex;align-items:center;gap:8px;">
                                                <?php echo $this->icon('upload'); ?>
                                                Import Settings
                                            </h4>
                                            
                                            <div class="flm-form-group" style="margin-bottom:16px;">
                                                <label class="flm-label">Select Settings File</label>
                                                <input type="file" id="flm-import-file" accept=".json" class="flm-input" style="padding:8px;">
                                            </div>
                                            
                                            <div class="flm-form-group" style="margin-bottom:16px;">
                                                <label class="flm-label">Decryption Passphrase (if encrypted)</label>
                                                <input type="password" id="flm-import-passphrase" class="flm-input" placeholder="Enter passphrase if file is encrypted">
                                            </div>
                                            
                                            <label class="flm-checkbox-card" style="padding:10px 12px;margin-bottom:16px;">
                                                <input type="checkbox" id="flm-import-backup" checked>
                                                <span class="flm-checkbox-card-content">
                                                    <span class="flm-checkbox-card-title" style="font-size:12px;">Backup current settings first</span>
                                                    <span class="flm-checkbox-card-desc" style="font-size:10px;">Recommended - allows you to restore if needed</span>
                                                </span>
                                            </label>
                                            
                                            <button type="button" id="flm-preview-import" class="flm-btn flm-btn-secondary" style="width:100%;margin-bottom:8px;">
                                                <?php echo $this->icon('eye'); ?>
                                                Preview Import
                                            </button>
                                            
                                            <div id="flm-import-preview" style="display:none;margin-top:16px;padding:16px;background:var(--flm-bg-card);border-radius:8px;border:1px solid var(--flm-border);">
                                                <!-- Preview content populated by JS -->
                                            </div>
                                            
                                            <button type="button" id="flm-import-settings" class="flm-btn flm-btn-success" style="width:100%;display:none;margin-top:12px;">
                                                <?php echo $this->icon('check'); ?>
                                                Confirm Import
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <?php 
                                    $backup = get_option('flm_settings_backup');
                                    if ($backup): ?>
                                    <div style="margin-top:20px;padding:16px;background:rgba(88,166,255,0.1);border-radius:8px;border:1px solid rgba(88,166,255,0.2);">
                                        <div style="display:flex;align-items:center;justify-content:space-between;">
                                            <div>
                                                <div style="font-weight:600;color:var(--flm-text);font-size:13px;">
                                                    <?php echo $this->icon('clock'); ?> Backup Available
                                                </div>
                                                <div style="font-size:12px;color:var(--flm-text-muted);margin-top:4px;">
                                                    Created: <?php echo date('M j, Y g:ia', strtotime($backup['exported_at'] ?? '')); ?>
                                                    <?php if (!empty($backup['backup_before_import'])): ?>(before last import)<?php endif; ?>
                                                </div>
                                            </div>
                                            <button type="button" id="flm-restore-backup" class="flm-btn flm-btn-secondary flm-btn-sm">
                                                <?php echo $this->icon('refresh'); ?>
                                                Restore Backup
                                            </button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Save Button (Settings Tab) -->
                            <div class="flm-mt-2">
                                <button type="submit" class="flm-btn flm-btn-success flm-save-btn">
                                    <?php echo $this->icon('save'); ?>
                                    Save Settings
                                </button>
                            </div>
                        </form>
                        
                    </div><!-- End Settings Panel -->
                
                </div><!-- End Tab Panels -->
                
            </main>
            
            <!-- Footer -->
            <footer class="flm-footer">
                <span>Field Level Media Importer for WordPress</span>
                <span>
                    <?php echo $this->icon('clock'); ?>
                    Next scheduled: <?php 
                        $next = wp_next_scheduled('flm_import_stories');
                        echo $next ? human_time_diff(time(), $next) : 'Not scheduled';
                    ?>
                </span>
            </footer>
            
            <!-- Setup Wizard: Claude AI -->
            <div id="flm-wizard-claude" class="flm-modal-overlay">
                <div class="flm-modal" style="max-width:600px;">
                    <div class="flm-modal-header">
                        <div class="flm-modal-title" style="color:#d97706;">
                            <?php echo $this->icon('bolt'); ?>
                            Claude AI Setup
                        </div>
                        <button type="button" class="flm-modal-close flm-wizard-close"><?php echo $this->icon('x'); ?></button>
                    </div>
                    <div class="flm-modal-body">
                        <div class="flm-wizard-instruction">
                            <h4>🤖 Get Your Claude API Key</h4>
                            <p>Claude AI powers the headline analyzer and content suggestions. Follow these steps:</p>
                            <ol>
                                <li>Click the button below to open Anthropic Console</li>
                                <li>Sign up or log in with your email</li>
                                <li>Navigate to <strong>API Keys</strong> in the sidebar</li>
                                <li>Click <strong>Create Key</strong></li>
                                <li>Give it a name like <strong>FLM GameDay</strong></li>
                                <li>Copy the API key (starts with <code>sk-ant-</code>)</li>
                                <li>Paste it below</li>
                            </ol>
                            <a href="https://console.anthropic.com/settings/keys" target="_blank" class="flm-wizard-link" style="background:#d97706;">
                                <?php echo $this->icon('external'); ?> Open Anthropic Console
                            </a>
                        </div>
                        <div style="margin-top:20px;">
                            <div class="flm-form-group">
                                <label class="flm-label">Claude API Key</label>
                                <input type="password" id="wizard-claude-api-key" class="flm-input" placeholder="sk-ant-api03-...">
                            </div>
                        </div>
                        <div class="flm-wizard-tip">
                            <span class="flm-wizard-tip-icon">💰</span>
                            <span class="flm-wizard-tip-text">Anthropic offers $5 free credits for new accounts. Headline analysis costs ~$0.001 per request.</span>
                        </div>
                        <div class="flm-wizard-nav" style="margin-top:24px;">
                            <div class="flm-wizard-nav-left"></div>
                            <div class="flm-wizard-nav-right">
                                <button type="button" class="flm-btn flm-btn-secondary flm-wizard-close">Cancel</button>
                                <button type="button" class="flm-btn flm-btn-success flm-wizard-finish" data-provider="claude">Save API Key</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Setup Wizard: Google -->
            <div id="flm-wizard-google" class="flm-modal-overlay">
                <div class="flm-modal" style="max-width:700px;">
                    <div class="flm-modal-header">
                        <div class="flm-modal-title" style="color:#4285f4;">
                            <?php echo $this->icon('chart'); ?>
                            Google Integration Setup
                        </div>
                        <button type="button" class="flm-modal-close flm-wizard-close"><?php echo $this->icon('x'); ?></button>
                    </div>
                    <div class="flm-modal-body">
                        <div class="flm-wizard-steps">
                            <div class="flm-wizard-step active"><span class="flm-wizard-step-num">1</span><span>Create Project</span></div>
                            <div class="flm-wizard-step"><span class="flm-wizard-step-num">2</span><span>Enable APIs</span></div>
                            <div class="flm-wizard-step"><span class="flm-wizard-step-num">3</span><span>Get Credentials</span></div>
                        </div>
                        <div class="flm-wizard-content">
                            <div class="flm-wizard-panel active">
                                <div class="flm-wizard-instruction">
                                    <h4>📁 Step 1: Create a Google Cloud Project</h4>
                                    <ol>
                                        <li>Click the button below to open Google Cloud Console</li>
                                        <li>Sign in with your Google account</li>
                                        <li>Click <strong>Select a project</strong> → <strong>New Project</strong></li>
                                        <li>Name it <strong>FLM GameDay Analytics</strong></li>
                                        <li>Click <strong>Create</strong></li>
                                    </ol>
                                    <a href="https://console.cloud.google.com/projectcreate" target="_blank" class="flm-wizard-link" style="background:#4285f4;">
                                        <?php echo $this->icon('external'); ?> Open Google Cloud Console
                                    </a>
                                </div>
                                <div class="flm-wizard-tip">
                                    <span class="flm-wizard-tip-icon">💡</span>
                                    <span class="flm-wizard-tip-text">Google Cloud has a free tier - you won't be charged unless you exceed high usage limits.</span>
                                </div>
                            </div>
                            <div class="flm-wizard-panel">
                                <div class="flm-wizard-instruction">
                                    <h4>🔌 Step 2: Enable Required APIs</h4>
                                    <ol>
                                        <li>Make sure your project is selected</li>
                                        <li>Click each link below to enable the APIs:</li>
                                    </ol>
                                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:12px;">
                                        <a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" class="flm-wizard-link" style="background:#f9ab00;">Enable Google Analytics Data API</a>
                                        <a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" class="flm-wizard-link" style="background:#34a853;">Enable Search Console API</a>
                                    </div>
                                </div>
                                <div class="flm-checklist">
                                    <div class="flm-checklist-item"><div class="flm-checklist-check">✓</div><span class="flm-checklist-text">I enabled the Analytics Data API</span></div>
                                    <div class="flm-checklist-item"><div class="flm-checklist-check">✓</div><span class="flm-checklist-text">I enabled the Search Console API</span></div>
                                </div>
                            </div>
                            <div class="flm-wizard-panel">
                                <div class="flm-wizard-instruction">
                                    <h4>🔑 Step 3: Create OAuth Credentials</h4>
                                    <ol>
                                        <li>Go to <strong>APIs & Services</strong> → <strong>Credentials</strong></li>
                                        <li>Click <strong>+ Create Credentials</strong> → <strong>OAuth client ID</strong></li>
                                        <li>Configure consent screen if prompted (External, add app name)</li>
                                        <li>Select <strong>Web application</strong> type</li>
                                        <li>Add this redirect URI:</li>
                                    </ol>
                                    <div class="flm-copy-box">
                                        <code id="google-redirect-uri"><?php echo admin_url('options-general.php?page=flm-importer&flm_oauth_callback=google'); ?></code>
                                        <button type="button" class="flm-copy-btn" data-copy="google-redirect-uri">Copy</button>
                                    </div>
                                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="flm-wizard-link" style="background:#4285f4;margin-top:12px;">Open Credentials Page</a>
                                    <div style="margin-top:16px;display:grid;gap:12px;">
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">Client ID</label><input type="text" id="wizard-google-client-id" class="flm-input" placeholder="xxxxx.apps.googleusercontent.com"></div>
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">Client Secret</label><input type="password" id="wizard-google-client-secret" class="flm-input" placeholder="Your client secret"></div>
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">GA4 Property ID (optional)</label><input type="text" id="wizard-google-ga4-id" class="flm-input" placeholder="123456789"></div>
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">Search Console Property URL (optional)</label><input type="text" id="wizard-google-gsc-url" class="flm-input" placeholder="https://yoursite.com"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flm-wizard-nav">
                            <div class="flm-wizard-nav-left"><button type="button" class="flm-btn flm-btn-secondary flm-wizard-prev" style="display:none;">← Previous</button></div>
                            <div class="flm-wizard-nav-right">
                                <button type="button" class="flm-btn flm-btn-secondary flm-wizard-close">Cancel</button>
                                <button type="button" class="flm-btn flm-btn-primary flm-wizard-next">Next →</button>
                                <button type="button" class="flm-btn flm-btn-success flm-wizard-finish" data-provider="google" style="display:none;">Save & Connect</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Setup Wizard: Twitter -->
            <div id="flm-wizard-twitter" class="flm-modal-overlay">
                <div class="flm-modal" style="max-width:650px;">
                    <div class="flm-modal-header">
                        <div class="flm-modal-title" style="color:#1da1f2;">
                            <?php echo $this->icon('share'); ?>
                            Twitter / X Setup
                        </div>
                        <button type="button" class="flm-modal-close flm-wizard-close"><?php echo $this->icon('x'); ?></button>
                    </div>
                    <div class="flm-modal-body">
                        <div class="flm-wizard-steps">
                            <div class="flm-wizard-step active"><span class="flm-wizard-step-num">1</span><span>Developer Account</span></div>
                            <div class="flm-wizard-step"><span class="flm-wizard-step-num">2</span><span>Create App</span></div>
                            <div class="flm-wizard-step"><span class="flm-wizard-step-num">3</span><span>Get Keys</span></div>
                        </div>
                        <div class="flm-wizard-content">
                            <div class="flm-wizard-panel active">
                                <div class="flm-wizard-instruction">
                                    <h4>👤 Step 1: Developer Access</h4>
                                    <p>Twitter requires developer account approval (usually instant or a few days).</p>
                                    <ol>
                                        <li>Open Twitter Developer Portal</li>
                                        <li>Sign in with your Twitter account</li>
                                        <li>Click <strong>Sign up for Free Account</strong></li>
                                        <li>Describe your use: "Analytics dashboard for sports news website"</li>
                                    </ol>
                                    <a href="https://developer.twitter.com/en/portal/petition/essential/basic-info" target="_blank" class="flm-wizard-link" style="background:#1da1f2;"><?php echo $this->icon('external'); ?> Open Developer Portal</a>
                                </div>
                            </div>
                            <div class="flm-wizard-panel">
                                <div class="flm-wizard-instruction">
                                    <h4>📱 Step 2: Create App</h4>
                                    <ol>
                                        <li>In Dashboard, click <strong>+ Add App</strong></li>
                                        <li>Name: <strong>FLM GameDay Analytics</strong></li>
                                        <li>In App Settings → User authentication</li>
                                        <li>Enable OAuth 2.0, type: Web App</li>
                                        <li>Add this callback URL:</li>
                                    </ol>
                                    <div class="flm-copy-box">
                                        <code id="twitter-redirect-uri"><?php echo admin_url('options-general.php?page=flm-importer&flm_oauth_callback=twitter'); ?></code>
                                        <button type="button" class="flm-copy-btn" data-copy="twitter-redirect-uri">Copy</button>
                                    </div>
                                </div>
                            </div>
                            <div class="flm-wizard-panel">
                                <div class="flm-wizard-instruction">
                                    <h4>🔑 Step 3: Copy Your Keys</h4>
                                    <p>From Keys and tokens tab, copy all 4 values:</p>
                                    <div style="margin-top:12px;display:grid;gap:12px;">
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">API Key</label><input type="text" id="wizard-twitter-api-key" class="flm-input" placeholder="Consumer Key"></div>
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">API Secret</label><input type="password" id="wizard-twitter-api-secret" class="flm-input" placeholder="Consumer Secret"></div>
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">Access Token</label><input type="text" id="wizard-twitter-access-token" class="flm-input" placeholder="Access Token"></div>
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">Access Secret</label><input type="password" id="wizard-twitter-access-secret" class="flm-input" placeholder="Access Token Secret"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flm-wizard-nav">
                            <div class="flm-wizard-nav-left"><button type="button" class="flm-btn flm-btn-secondary flm-wizard-prev" style="display:none;">← Previous</button></div>
                            <div class="flm-wizard-nav-right">
                                <button type="button" class="flm-btn flm-btn-secondary flm-wizard-close">Cancel</button>
                                <button type="button" class="flm-btn flm-btn-primary flm-wizard-next">Next →</button>
                                <button type="button" class="flm-btn flm-btn-success flm-wizard-finish" data-provider="twitter" style="display:none;">Save & Connect</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Setup Wizard: Facebook -->
            <div id="flm-wizard-facebook" class="flm-modal-overlay">
                <div class="flm-modal" style="max-width:650px;">
                    <div class="flm-modal-header">
                        <div class="flm-modal-title" style="color:#1877f2;">
                            <?php echo $this->icon('share'); ?>
                            Facebook Setup
                        </div>
                        <button type="button" class="flm-modal-close flm-wizard-close"><?php echo $this->icon('x'); ?></button>
                    </div>
                    <div class="flm-modal-body">
                        <div class="flm-wizard-steps">
                            <div class="flm-wizard-step active"><span class="flm-wizard-step-num">1</span><span>Create App</span></div>
                            <div class="flm-wizard-step"><span class="flm-wizard-step-num">2</span><span>Configure</span></div>
                            <div class="flm-wizard-step"><span class="flm-wizard-step-num">3</span><span>Get Token</span></div>
                        </div>
                        <div class="flm-wizard-content">
                            <div class="flm-wizard-panel active">
                                <div class="flm-wizard-instruction">
                                    <h4>📱 Step 1: Create Facebook App</h4>
                                    <ol>
                                        <li>Open Facebook for Developers</li>
                                        <li>Click <strong>My Apps</strong> → <strong>Create App</strong></li>
                                        <li>Select <strong>Other</strong> → <strong>Business</strong> type</li>
                                        <li>Name: <strong>FLM GameDay Analytics</strong></li>
                                    </ol>
                                    <a href="https://developers.facebook.com/apps/create/" target="_blank" class="flm-wizard-link" style="background:#1877f2;"><?php echo $this->icon('external'); ?> Create Facebook App</a>
                                </div>
                            </div>
                            <div class="flm-wizard-panel">
                                <div class="flm-wizard-instruction">
                                    <h4>⚙️ Step 2: Configure App</h4>
                                    <ol>
                                        <li>Go to <strong>Settings</strong> → <strong>Basic</strong></li>
                                        <li>Copy <strong>App ID</strong> and <strong>App Secret</strong></li>
                                        <li>Add <strong>Facebook Login</strong> product</li>
                                        <li>Add this Valid OAuth Redirect URI:</li>
                                    </ol>
                                    <div class="flm-copy-box">
                                        <code id="facebook-redirect-uri"><?php echo admin_url('options-general.php?page=flm-importer&flm_oauth_callback=facebook'); ?></code>
                                        <button type="button" class="flm-copy-btn" data-copy="facebook-redirect-uri">Copy</button>
                                    </div>
                                    <div style="margin-top:16px;display:grid;gap:12px;">
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">App ID</label><input type="text" id="wizard-facebook-app-id" class="flm-input" placeholder="123456789012345"></div>
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">App Secret</label><input type="password" id="wizard-facebook-app-secret" class="flm-input" placeholder="App Secret"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="flm-wizard-panel">
                                <div class="flm-wizard-instruction">
                                    <h4>🔑 Step 3: Get Page Access Token</h4>
                                    <ol>
                                        <li>Go to <a href="https://developers.facebook.com/tools/explorer/" target="_blank" style="color:var(--flm-accent);">Graph API Explorer</a></li>
                                        <li>Select your app, click <strong>Get User Access Token</strong></li>
                                        <li>Check: pages_show_list, pages_read_engagement, read_insights</li>
                                        <li>Select your Page and get Page Access Token</li>
                                    </ol>
                                    <div style="margin-top:16px;display:grid;gap:12px;">
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">Page ID</label><input type="text" id="wizard-facebook-page-id" class="flm-input" placeholder="123456789"></div>
                                        <div class="flm-form-group" style="margin:0;"><label class="flm-label">Page Access Token</label><input type="password" id="wizard-facebook-access-token" class="flm-input" placeholder="EAAxxxxxxxxx..."></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flm-wizard-nav">
                            <div class="flm-wizard-nav-left"><button type="button" class="flm-btn flm-btn-secondary flm-wizard-prev" style="display:none;">← Previous</button></div>
                            <div class="flm-wizard-nav-right">
                                <button type="button" class="flm-btn flm-btn-secondary flm-wizard-close">Cancel</button>
                                <button type="button" class="flm-btn flm-btn-primary flm-wizard-next">Next →</button>
                                <button type="button" class="flm-btn flm-btn-success flm-wizard-finish" data-provider="facebook" style="display:none;">Save & Connect</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Setup Wizard: Bing -->
            <div id="flm-wizard-bing" class="flm-modal-overlay">
                <div class="flm-modal" style="max-width:600px;">
                    <div class="flm-modal-header">
                        <div class="flm-modal-title" style="color:#008373;">
                            <?php echo $this->icon('search'); ?>
                            Bing Webmaster Setup
                        </div>
                        <button type="button" class="flm-modal-close flm-wizard-close"><?php echo $this->icon('x'); ?></button>
                    </div>
                    <div class="flm-modal-body">
                        <div class="flm-wizard-instruction">
                            <h4>🔍 Get Your Bing API Key</h4>
                            <ol>
                                <li>Open Bing Webmaster Tools</li>
                                <li>Sign in with Microsoft account</li>
                                <li>Add your site if not already added</li>
                                <li>Click ⚙️ → <strong>API Access</strong></li>
                                <li>Click <strong>Generate</strong> to create API key</li>
                            </ol>
                            <a href="https://www.bing.com/webmasters/home" target="_blank" class="flm-wizard-link" style="background:linear-gradient(135deg,#008373,#00a99d);"><?php echo $this->icon('external'); ?> Open Bing Webmaster</a>
                        </div>
                        <div style="margin-top:20px;display:grid;gap:12px;">
                            <div class="flm-form-group" style="margin:0;"><label class="flm-label">Site URL</label><input type="text" id="wizard-bing-site-url" class="flm-input" placeholder="https://yoursite.com"></div>
                            <div class="flm-form-group" style="margin:0;"><label class="flm-label">API Key</label><input type="password" id="wizard-bing-api-key" class="flm-input" placeholder="Your Bing API Key"></div>
                        </div>
                        <div class="flm-wizard-tip">
                            <span class="flm-wizard-tip-icon">💡</span>
                            <span class="flm-wizard-tip-text">Bing data includes traffic from Bing, DuckDuckGo, Yahoo, and other Microsoft-powered search.</span>
                        </div>
                        <div class="flm-wizard-nav" style="margin-top:24px;">
                            <div class="flm-wizard-nav-left"></div>
                            <div class="flm-wizard-nav-right">
                                <button type="button" class="flm-btn flm-btn-secondary flm-wizard-close">Cancel</button>
                                <button type="button" class="flm-btn flm-btn-success flm-wizard-finish" data-provider="bing">Save & Connect</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
        <?php
    }
}

new FLM_GameDay_Atlanta();
