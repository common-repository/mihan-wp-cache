<?php
/**
 * Plugin Name: Mihan WP Cache
 * Plugin URI: https://mihanwebdesign.com
 * Description: Simple and fast cache plugin
 * Author: Mihan Web Design
 * Version: 1.1.1
 */

require_once 'vendor/autoload.php';
use MatthiasMullie\Minify;
use PHPHtmlParser\Dom;

defined('ABSPATH') || die('No direct script access allowed!');
$cache = new MihanWPCache();

class MihanWPCache
{
    protected $cacheBasePath = '';
    protected $cachePath = '';
    protected $cssLogFile = '';
    protected $jsLogFile = '';
    protected $homeUrl = '';

    public function __construct()
    {
        $this->cacheBasePath = ABSPATH . 'wp-content/cache/';
        $this->cachePath = $this->cacheBasePath . 'mihan-wp-cache/';
        $this->cssLogFile = $this->cachePath.'css.log';
        $this->jsLogFile = $this->cachePath.'js.log';
        $this->homeUrl = get_home_url().'/';

        if (!file_exists($this->cacheBasePath)){
            mkdir($this->cacheBasePath);
        }

        if (!file_exists($this->cachePath)){
            mkdir($this->cachePath);
        }

        add_action('wp_loaded', array($this, 'processPost'));
        add_action('shutdown', array($this, 'getOutput'), 0);
        add_filter('final_output', array($this, 'createCache'));
        add_action('admin_post_clear_contents_caches', array($this, 'clearContentsCaches'));
        add_action('admin_post_clear_all_caches', array($this, 'clearAllCaches'));
        add_action('admin_init', array($this, 'cacheSettingsInit'));
        add_action('admin_menu', array($this, 'optionsPage'));
        add_action('post_updated', array($this,'postUpdated'));
        register_activation_hook( __FILE__, array($this,'activate'));
        register_deactivation_hook( __FILE__, array($this,'deactivate'));
    }


    //************************************ state **************************************
    function activate() {
        $startTag = '#MihanWPCacheStart' .PHP_EOL;
        $endTag = '#MihanWPCacheEnd';
        $gzipCode = '<IfModule mod_deflate.c>
    # Compress HTML, CSS, JavaScript, Text, XML and fonts
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE application/rss+xml
    AddOutputFilterByType DEFLATE application/vnd.ms-fontobject
    AddOutputFilterByType DEFLATE application/x-font
    AddOutputFilterByType DEFLATE application/x-font-opentype
    AddOutputFilterByType DEFLATE application/x-font-otf
    AddOutputFilterByType DEFLATE application/x-font-truetype
    AddOutputFilterByType DEFLATE application/x-font-ttf
    AddOutputFilterByType DEFLATE application/x-javascript
    AddOutputFilterByType DEFLATE application/xhtml+xml
    AddOutputFilterByType DEFLATE application/xml
    AddOutputFilterByType DEFLATE font/opentype
    AddOutputFilterByType DEFLATE font/otf
    AddOutputFilterByType DEFLATE font/ttf
    AddOutputFilterByType DEFLATE image/svg+xml
    AddOutputFilterByType DEFLATE image/x-icon
    AddOutputFilterByType DEFLATE text/css
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/javascript
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/xml
    # Remove browser bugs (only needed for really old browsers)
    BrowserMatch ^Mozilla/4 gzip-only-text/html
    BrowserMatch ^Mozilla/4\.0[678] no-gzip
    BrowserMatch \bMSIE !no-gzip !gzip-only-text/html
    Header append Vary User-Agent
</IfModule>' .PHP_EOL;
        $expirationCode = '<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access 1 year"
    ExpiresByType image/jpeg "access 1 year"
    ExpiresByType image/gif "access 1 year"
    ExpiresByType image/png "access 1 year"
    ExpiresByType text/css "access 1 month"
    ExpiresByType application/pdf "access 1 month"
    ExpiresByType application/javascript "access 1 month"
    ExpiresByType application/x-javascript "access 1 month"
    ExpiresByType application/x-shockwave-flash "access 1 month"
    ExpiresByType image/x-icon "access 1 year"
    ExpiresDefault "access 2 days"
</IfModule>' .PHP_EOL;

        if(file_exists(ABSPATH.'.htaccess')){
            $oldContent = file_get_contents(ABSPATH.'.htaccess');
            $hasGzip = strpos($oldContent,'<IfModule mod_deflate.c>') !== false ? true:false;
            $hasExpiration = strpos($oldContent,'<IfModule mod_expires.c>') !== false ? true:false;

            if ($hasGzip && $hasExpiration){
                return;
            }else{
                $content = $startTag;

                if (!$hasGzip){
                    $content.= $gzipCode;
                }

                if (!$hasExpiration){
                    $content.= $expirationCode;
                }

                file_put_contents(ABSPATH.'.htaccess',$oldContent.$content.$endTag);
            }
        }
    }

    function deactivate() {
        $this->clearAllCaches();
        if(file_exists($this->cachePath)){
            $this->removeDirectory($this->cachePath);
        }

        if(file_exists(ABSPATH.'.htaccess')){
            $oldContent = file_get_contents(ABSPATH.'.htaccess');
            $oldContent = preg_replace('/(?<=\#MihanWPCacheStart)((.|\n)*?)(?=\#MihanWPCacheEnd)/', '', $oldContent);
            $oldContent = str_replace('#MihanWPCacheStart#MihanWPCacheEnd', '', $oldContent);
            file_put_contents(ABSPATH.'.htaccess',$oldContent);
        }
    }

    function removeDirectory($dir) {
        $files = array_diff(scandir($dir), array('.','..'));

        foreach ($files as $file) {
            (is_dir($dir.'/'.$file)) ? removeDirectory($dir.'/'.$file) : unlink($dir.'/'.$file);
        }

        return rmdir($dir);
    }

    //************************************* cache *************************************
    function processPost()
    {
        if (!(substr($_SERVER['REQUEST_URI'], 0, 9) == '/wp-admin') && !is_user_logged_in()) {
            $file = $this->cachePath . 'html/' . md5($_SERVER['REQUEST_URI']) . '.html';

            if (file_exists($file)) {
                echo file_get_contents($file);
                die();
            }
        }

        ob_start();
    }

    function getOutput()
    {
        $final = '';
        $levels = ob_get_level();

        for ($i = 0; $i < $levels; $i++) {
            $final .= ob_get_clean();
        }

        echo apply_filters('final_output', $final);
    }

    function createCache($output)
    {
        if (!(substr($_SERVER['REQUEST_URI'], 0, 9) == '/wp-admin')) {
            $file = $this->cachePath . 'html/' . md5($_SERVER['REQUEST_URI']) . '.html';

            if (!file_exists($this->cacheBasePath)) {
                mkdir($this->cacheBasePath);
            }

            if (!file_exists($this->cachePath)) {
                mkdir($this->cachePath);
            }

            if (!file_exists($this->cachePath . 'html')) {
                mkdir($this->cachePath . 'html');
            }

            $option = get_option('mihan_wp_cache_options');

            if (isset($option['mihan_wp_cache_field_minify'])) {
                $dom = new Dom;

                $dom->loadStr($output, array(
                    'removeScripts' => false,
                    'removeStyles' => false,
                    'preserveLineBreaks' => true,
                ));

                try{
                    $cssElements = $dom->find('link[href*="\.css"]');
                    $jsElements = $dom->find('script[src*="\.js"]');
                    $cssLinks = array();
                    $jsLinks = array();
                    $cssFiles = array();
                    $jsFiles = array();

                    foreach ($cssElements as $element){
                        $cssLinks[] = $element->href;
                    }

                    foreach ($jsElements as $element){
                        $jsLinks[] = $element->src;
                    }

                    if (count($cssLinks) > 0) {
                        $cssLinks = $this->filterInternalAssets($cssLinks);
                        $cssFiles = $this->minify($cssLinks, 'css');
                    }

                    if (count($jsLinks) > 0) {
                        $jsLinks = $this->filterInternalAssets($jsLinks);
                        $jsFiles = $this->minify($jsLinks, 'js');
                    }

                    $output = str_replace(array_merge($cssLinks ,$jsLinks), array_merge($cssFiles, $jsFiles), $output);
                }catch (\Exception $exception){
                    //
                }
            }

            if (!is_user_logged_in() && !file_exists($file)){
                file_put_contents($file, $output);
            }
        }

        return $output;
    }

    //************************************* minify *************************************
    function minify($assets, $type)
    {
        if (!in_array($type, ['css', 'js'])) {
            return array();
        }

        $resultAssets = array();

        foreach ($assets as $asset) {
            $tempAsset = explode('?',$asset)[0];

            if (strpos($tempAsset, $this->homeUrl) !== false) {
                $parsedUrl = parse_url($tempAsset);
                $tempAsset = substr($parsedUrl['path'], 1);
            } elseif (substr($asset, 0, 1) == '/') {
                $tempAsset = substr($tempAsset, 1);
            }

            $fileName = $this->getNewFileName($tempAsset,$type);
            $assetPath = $this->getAssetPath($tempAsset);

            if (file_exists(ABSPATH.$assetPath.$fileName)){
                $resultAssets[] = $this->homeUrl.$assetPath.$fileName;
            }else{
                try{
                    if ($type == 'css') {
                        $minifier = new Minify\CSS();
                        $logFile = $this->cssLogFile;
                        //$minifier->setImportExtensions(array());
                    } elseif ($type == 'js') {
                        $minifier = new Minify\JS();
                        $logFile = $this->jsLogFile;
                    }

                    $minifier->add(ABSPATH.$tempAsset);
                    $minifier->minify(ABSPATH.$assetPath.$fileName);
                    file_put_contents($logFile, ABSPATH.$assetPath.$fileName.PHP_EOL, FILE_APPEND | LOCK_EX);
                    $resultAssets[] = $this->homeUrl.$assetPath.$fileName;
                }catch (\Exception $exception){
                    $resultAssets[] = $this->homeUrl.$tempAsset;
                }
            }
        }

        return $resultAssets;
    }

    function filterInternalAssets($assets){
        $internalAssets = array();

        foreach ($assets as $asset){
            if (strpos($asset, $this->homeUrl) !== false || substr($asset, 0, 4) != 'http') {
                $internalAssets[] = $asset;
            }
        }

        return $internalAssets;
    }

    function getNewFileName($asset,$type){
        return md5($asset).'.'.$type;
    }

    function getAssetPath($asset){
        $path = explode('/',$asset);
        unset($path[count($path)-1]);
        return implode('/',$path).'/';
    }

    //************************************* clear cache *************************************
    function clearCache($type)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!in_array($type,array('html', 'js', 'css'))){
            return;
        }

        if ($type == 'html' && file_exists($this->cachePath . 'html')) {
            $this->removeDirectory($this->cachePath . 'html');
        }else{
            $logFile = $type == 'css' ? $this->cssLogFile : $this->jsLogFile;

            if (file_exists($logFile)){
                $assets = explode(PHP_EOL, file_get_contents($logFile));

                foreach ($assets as $asset){
                    if ($asset != '' && file_exists($asset)){
                        unlink($asset);
                    }
                }

                unlink($logFile);
            }
        }
    }

    function clearContentsCaches(){
        $this->clearCache('html');
        wp_redirect('/wp-admin/admin.php?page=mihan_wp_cache&settings-updated=clear_contents_caches');
    }

    function clearAllCaches(){
        $this->clearCache('html');
        $this->clearCache('css');
        $this->clearCache('js');
        wp_redirect('/wp-admin/admin.php?page=mihan_wp_cache&settings-updated=clear_all_caches');
    }

    function postUpdated(){
        $this->clearCache('html');
    }

    //************************************* setting *************************************
    function cacheSettingsInit()
    {
        register_setting('mihan_wp_cache', 'mihan_wp_cache_options');

        add_settings_section(
            'mihan_wp_cache_section_minify',
            __('<img src="'.$this->homeUrl . 'wp-content/plugins/mihan-wp-cache/image/logo.png">','mihan_wp_cache'),
            array($this, 'sectionHtml'),
            'mihan_wp_cache'
        );

        add_settings_field(
            'mihan_wp_cache_field_minify',
            __('Minify CSS and JS Files', 'mihan_wp_cache'),
            array($this, 'fieldsHtml'),
            'mihan_wp_cache',
            'mihan_wp_cache_section_minify',
            [
                'label_for' => 'mihan_wp_cache_field_minify',
                'class' => 'mihan_wp_cache_row',
            ]
        );
    }

    function sectionHtml($args)
    {
        ?>
        <p id="<?php echo esc_attr($args['id']); ?>"><?php esc_html_e('it is recommended to minify your css and js files.', 'mihan_wp_cache'); ?></p>
        <?php
    }

    function fieldsHtml($args)
    {
        $options = get_option('mihan_wp_cache_options');
        ?>

        <input type="checkbox" id="<?php echo esc_attr($args['label_for']); ?>"
               name="mihan_wp_cache_options[<?php echo esc_attr($args['label_for']); ?>]"
               value="1" <?php echo isset($options[$args['label_for']]) ? (checked($options[$args['label_for']], 1, true)) : (''); ?>
        >
        <?php
    }

    function optionsPage()
    {
        add_menu_page(
            '',
            'Mihan WP Cache',
            'manage_options',
            'mihan_wp_cache',
            array($this, 'optionsPageHtml')
        );
    }

    function optionsPageHtml()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            if ($_GET['settings-updated'] == 'true'){
                add_settings_error('mihan_wp_cache_messages', 'mihan_wp_cache_message', __('Settings Saved', 'mihan_wp_cache'), 'updated');
            }elseif($_GET['settings-updated'] == 'clear_contents_caches'){
                add_settings_error('mihan_wp_cache_messages', 'mihan_wp_cache_message', __('Contents Caches have been Deleted', 'mihan_wp_cache'), 'updated');
            }elseif($_GET['settings-updated'] == 'clear_all_caches'){
                add_settings_error('mihan_wp_cache_messages', 'mihan_wp_cache_message', __('All Caches have been Deleted', 'mihan_wp_cache'), 'updated');
            }
        }

        settings_errors('mihan_wp_cache_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('mihan_wp_cache');
                do_settings_sections('mihan_wp_cache');
                submit_button('Save Settings');
                ?>
            </form>
        </div>

        <div class="wrap">
            <h1><?php echo esc_html('Clear Caches'); ?></h1>

            <form action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block">
                <input type="hidden" name="action" value="clear_contents_caches">
                <?php submit_button('Clear Contents Caches'); ?>
            </form>

            <form action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block">
                <input type="hidden" name="action" value="clear_all_caches">
                <?php submit_button('Clear All Caches'); ?>
            </form>
        </div>
        <?php
    }

}