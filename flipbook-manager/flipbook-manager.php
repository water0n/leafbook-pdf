<?php
/**
 * Plugin Name:       LeafBook PDF
 * Plugin URI:        https://kaabapp.com
 * Description:       Gestiona PDFs con visor de volteo de pagina. Inserta con [leafbook id="X"] o iframe.
 * Version:           1.4.15
 * Author:            Daniel Zermeno
 * Author URI:        https://kaabapp.com
 * License:           GPL v2 or later
 * Text Domain:       leafbook-pdf
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      6.7
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FBM_VERSION',    '1.4.15' );
define( 'FBM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FBM_PLUGIN_URL', plugin_dir_url( __FILE__ )  );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>LeafBook PDF</strong> requiere PHP 7.4+. Actual: ' . PHP_VERSION . '</p></div>';
    });
    return;
}

// -- Modulos --------------------------------------------------
require_once FBM_PLUGIN_DIR . 'includes/class-flipbook-settings.php';
require_once FBM_PLUGIN_DIR . 'includes/class-flipbook-taxonomy.php';
require_once FBM_PLUGIN_DIR . 'includes/class-flipbook-cpt.php';
require_once FBM_PLUGIN_DIR . 'includes/class-flipbook-admin.php';
require_once FBM_PLUGIN_DIR . 'includes/class-flipbook-apariencia.php';
require_once FBM_PLUGIN_DIR . 'includes/class-flipbook-shortcode.php';
require_once FBM_PLUGIN_DIR . 'includes/class-flipbook-block.php';

// -- Inicializar ----------------------------------------------
add_action( 'plugins_loaded', function() {
    ( new Flipbook_Settings()   )->register();
    ( new Flipbook_Taxonomy()   )->register();
    ( new Flipbook_CPT()        )->register();
    ( new Flipbook_Admin()      )->register();
    ( new Flipbook_Apariencia() )->register();
    ( new Flipbook_Shortcode()  )->register();
    ( new Flipbook_Block()      )->register();
});

// ================================================================
// PROXY DE PDF  /?lbpdf_proxy=ID
// Sirve el PDF desde disco con headers CORS + HTTPS correctos.
// Bypasea caches (SiteGround, WP Super Cache, etc.) usando prioridad 1.
// ================================================================
add_action( 'template_redirect', 'lbpdf_proxy_handler', 1 );
function lbpdf_proxy_handler() {
    if ( ! isset( $_GET['lbpdf_proxy'] ) ) return;

    // Desactivar cache de SiteGround y otros plugins de cache
    if ( ! defined('DONOTCACHEPAGE') ) define( 'DONOTCACHEPAGE', true );
    if ( ! defined('DONOTCACHEDB')   ) define( 'DONOTCACHEDB',   true );

    $pid = intval( $_GET['lbpdf_proxy'] );
    if ( $pid <= 0 ) { http_response_code(400); exit; }

    $post = get_post($pid);
    if ( ! $post || $post->post_type !== 'flipbook' || $post->post_status !== 'publish' ) {
        http_response_code(404); exit;
    }

    $pdf_url = get_post_meta($pid, '_fbm_pdf_url', true);
    if ( ! $pdf_url ) { http_response_code(404); exit; }

    $att_id   = (int) get_post_meta($pid, '_fbm_pdf_attachment_id', true);
    $pdf_path = $att_id ? get_attached_file($att_id) : '';

    // Limpiar cualquier output previo que WordPress haya iniciado
    while ( ob_get_level() > 0 ) ob_end_clean();

    if ( $pdf_path && file_exists($pdf_path) ) {
        $size = filesize($pdf_path);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline; filename="' . basename($pdf_path) . '"');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=86400');
        header('X-Robots-Tag: noindex');
        header('X-LB-Source: disk');
        readfile($pdf_path);
    } else {
        // Fallback: fetch remoto (cuando el attachment_id no esta o el path no existe)
        $resp = wp_remote_get( $pdf_url, array(
            'timeout'   => 45,
            'sslverify' => false,
            'stream'    => false,
        ));
        if ( is_wp_error($resp) ) { http_response_code(502); exit; }

        $body = wp_remote_retrieve_body($resp);
        if ( empty($body) ) { http_response_code(502); exit; }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($body));
        header('Content-Disposition: inline; filename="leafbook.pdf"');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=86400');
        header('X-Robots-Tag: noindex');
        header('X-LB-Source: remote');
        echo $body;
    }
    exit;
}

// ================================================================
// EMBED STANDALONE  /?lbpdf_embed=ID
// Pagina HTML limpia para el <iframe> - sin tema de WP
// ================================================================
add_action( 'template_redirect', 'lbpdf_embed_handler', 2 );
function lbpdf_embed_handler() {
    if ( ! isset( $_GET['lbpdf_embed'] ) ) return;

    // Permitir que esta URL viva dentro de iframes
    header_remove('X-Frame-Options');
    header('X-Frame-Options: ALLOWALL');
    header('Content-Security-Policy: frame-ancestors *');
    if ( ! defined('DONOTCACHEPAGE') ) define('DONOTCACHEPAGE', true);

    $pid = intval( $_GET['lbpdf_embed'] );
    if ( $pid <= 0 ) wp_die('ID invalido.');

    $post = get_post($pid);
    if ( ! $post || $post->post_type !== 'flipbook' || $post->post_status !== 'publish' )
        wp_die('PDF no encontrado.');

    $pdf_url_orig = get_post_meta($pid, '_fbm_pdf_url', true);
    if ( ! $pdf_url_orig ) wp_die('Sin PDF configurado.');

    $ancho      = intval( get_post_meta($pid,'_fbm_ancho',true) ?: 900 );
    $alto       = intval( get_post_meta($pid,'_fbm_alto', true) ?: 600 );
    $cfg        = Flipbook_Apariencia::get($pid);
    $proxy_url  = add_query_arg('lbpdf_proxy', $pid, home_url('/'));

    $css_url    = FBM_PLUGIN_URL . 'assets/css/visor.css?v='        . FBM_VERSION;
    $pdfjs_url  = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    $flip_url   = 'https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.js';
    $worker_url = FBM_PLUGIN_URL . 'assets/js/pdf.worker.min.js?v=' . FBM_VERSION;
    $visor_url  = FBM_PLUGIN_URL . 'assets/js/visor.js?v='          . FBM_VERSION;

    // Shortcode genera el HTML con la config exacta del PDF (mismos botones)
    $sc   = new Flipbook_Shortcode();
    $sc->register();
    $html = $sc->render_shortcode( array('id'=>$pid,'ancho'=>$ancho,'alto'=>$alto) );

    $css_ap = Flipbook_Apariencia::css_inline($pid, $cfg);
    $embed_bg = ( ($cfg['fondo_tipo'] ?? 'color') === 'sin_fondo' ) ? 'transparent' : '#0f172a';

    $datos_js = wp_json_encode(array(
        'pdfUrl'    => esc_url($proxy_url),
        'ancho'     => $ancho,
        'alto'      => $alto,
        'autoplay'  => get_post_meta($pid,'_fbm_autoplay',true),
        'buscar'    => $cfg['btn_buscar'] ?? '1',
        'workerSrc' => $worker_url,
        'calidad'   => floatval($cfg['calidad'] ?? 0.85),
        'escala'    => floatval($cfg['escala'] ?? 1.5),
    ));

    while ( ob_get_level() > 0 ) ob_end_clean();
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html(get_the_title($pid)); ?></title>
<link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
<style>*{margin:0;padding:0;box-sizing:border-box;}html,body{background:<?php echo esc_attr($embed_bg); ?>;min-height:100%;}body{display:flex;align-items:flex-start;justify-content:center;min-height:100vh;padding:0;overflow:hidden;}.fbm-contenedor-externo{margin:0!important;border-radius:0!important;max-width:100%!important;width:100%;}</style>
<?php echo $css_ap; ?>
</head>
<body>
<?php echo $html; ?>
<script src="<?php echo esc_url($pdfjs_url); ?>"></script>
<script src="<?php echo esc_url($flip_url); ?>"></script>
<script>window['fbmData_<?php echo $pid; ?>'] = <?php echo $datos_js; ?>;</script>
<script src="<?php echo esc_url($visor_url); ?>"></script>
</body>
</html>
    <?php
    exit;
}

// -- Quitar X-Frame-Options en la pagina embed ----------------
add_action('send_headers', function() {
    if ( isset($_GET['lbpdf_embed']) ) {
        header_remove('X-Frame-Options');
        header('X-Frame-Options: ALLOWALL');
        header('Content-Security-Policy: frame-ancestors *');
    }
});

// -- Activacion / Desactivacion -------------------------------
register_activation_hook(   __FILE__, function(){ ( new Flipbook_CPT() )->registrar_tipo(); flush_rewrite_rules(); });
register_deactivation_hook( __FILE__, function(){ flush_rewrite_rules(); });
