<?php
/**
 * class-flipbook-shortcode.php
 * Shortcode [leafbook id="X"] o [leafbook grupo="slug"].
 *
 * Renderiza un lector PDF simple basado en PDF.js. La lectura, el zoom y
 * pantalla completa viven en assets/js/visor.js.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Flipbook_Shortcode {

    public function register() {
        add_shortcode( 'leafbook', array( $this, 'render_shortcode' ) );
        add_shortcode( 'flipbook', array( $this, 'render_shortcode' ) ); // retrocompatibilidad
        add_action( 'wp_enqueue_scripts', array( $this, 'registrar_assets' ) );
    }

    public function registrar_assets() {
        wp_register_style( 'fbm-visor', FBM_PLUGIN_URL . 'assets/css/visor.css', array(), FBM_VERSION );
        wp_register_script( 'pdfjs-lib',  FBM_PLUGIN_URL . 'assets/js/pdf.min.js', array(), '3.11.174', true );
        wp_register_script( 'pdfjs-worker', FBM_PLUGIN_URL . 'assets/js/pdf.worker.min.js', array(), '3.11.174', true );
        wp_register_script( 'fbm-visor',  FBM_PLUGIN_URL . 'assets/js/visor.js', array('pdfjs-lib'), FBM_VERSION, true );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id'          => 0,
            'grupo'       => '',
            'categoria'   => '',
            'category'    => '',
            'lbpdf_grupo' => '',
            'ancho'       => '',
            'alto'        => '',
        ), $atts, 'leafbook' );

        $pid = $this->resolver_pdf_id( $atts );
        if ( is_wp_error( $pid ) ) {
            return '<p style="color:orange;">⚠️ ' . esc_html( $pid->get_error_message() ) . '</p>';
        }

        if ( $pid <= 0 ) {
            return '<p style="color:red;">⚠️ LeafBook: falta el id o grupo. Uso: [leafbook id="42"] o [leafbook grupo="revistas"]</p>';
        }

        $post = get_post($pid);
        if ( !$post || $post->post_type !== 'flipbook' || $post->post_status !== 'publish' )
            return '<p style="color:red;">⚠️ PDF #' . $pid . ' no encontrado o no publicado.</p>';

        $pdf_url = get_post_meta($pid,'_fbm_pdf_url',true);
        if ( !$pdf_url ) return '<p style="color:orange;">⚠️ PDF #' . $pid . ' no tiene archivo configurado. <a href="' . get_edit_post_link($pid) . '">Configúralo aquí →</a></p>';

        $ancho = intval( $atts['ancho'] ?: get_post_meta($pid,'_fbm_ancho',true) ?: 900 );
        $alto  = intval( $atts['alto']  ?: get_post_meta($pid,'_fbm_alto', true) ?: 600 );

        // ── Configuración de apariencia del PDF ──
        $cfg      = Flipbook_Apariencia::get($pid);
        $autoplay = get_post_meta($pid,'_fbm_autoplay',true);

        // ── URL del PDF: siempre a través del proxy local ──
        // Razón: el PDF puede estar guardado como http:// pero el sitio es https://
        // El navegador bloquea recursos HTTP en páginas HTTPS (Mixed Content).
        // El proxy sirve el PDF desde el servidor con HTTPS y header CORS correcto.
        $proxy_url = add_query_arg( 'lbpdf_proxy', $pid, home_url('/') );

        // ── Enqueue assets ──
        wp_enqueue_style( 'fbm-visor' );
        wp_enqueue_script( 'fbm-visor' );

        $tema = $cfg['tema_botones'] ?? 'oscuro';
        wp_localize_script( 'fbm-visor', 'fbmData_' . $pid, array(
            'pdfUrl'    => esc_url( $proxy_url ),
            'pdfDirect' => esc_url( $pdf_url ),
            'ancho'     => $ancho,
            'alto'      => $alto,
            'autoplay'  => $autoplay,
            'workerSrc' => FBM_PLUGIN_URL . 'assets/js/pdf.worker.min.js?v=' . FBM_VERSION,
            'tema'      => $tema,
        ));

        // ── CSS inline con apariencia individual ──
        $css_inline = Flipbook_Apariencia::css_inline($pid, $cfg);

        // ── Info: título, categoría, autor ──
        $titulo     = $cfg['mostrar_titulo']    === '1' ? get_the_title($pid) : '';
        $grupos     = $cfg['mostrar_categoria'] === '1' ? wp_get_post_terms($pid, 'lbpdf_grupo') : array();
        $autor      = $cfg['mostrar_autor']     === '1' ? get_the_author_meta('display_name', get_post_field('post_author', $pid)) : '';

        $mostrar_info = $titulo || (!empty($grupos) && !is_wp_error($grupos)) || $autor;

        ob_start();
        echo $css_inline;
        ?>
        <div class="fbm-contenedor-externo lbpdf-tema-<?php echo esc_attr($tema); ?>" id="fbm-wrap-<?php echo $pid; ?>" style="max-width:<?php echo $ancho; ?>px;margin:0 auto;">

            <?php // ── Barra de info (opcional) ── ?>
            <?php if ($mostrar_info): ?>
            <div class="fbm-info-bar">
                <?php if ($titulo): ?><span class="fbm-info-titulo"><?php echo esc_html($titulo); ?></span><?php endif; ?>
                <?php if (!empty($grupos) && !is_wp_error($grupos)):
                    $nombres = array_map(function($g){ return esc_html($g->name); }, $grupos);
                    echo '<span class="fbm-info-cat">' . implode(', ', $nombres) . '</span>';
                endif; ?>
                <?php if ($autor): ?><span class="fbm-info-autor">por <?php echo esc_html($autor); ?></span><?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="fbm-visor-wrap" id="fbm-visor-wrap-<?php echo $pid; ?>">
                <div id="fbm-visor-<?php echo $pid; ?>" class="fbm-visor"
                     data-id="<?php echo $pid; ?>"
                     data-pdf="<?php echo esc_url($proxy_url); ?>"
                     data-ancho="<?php echo $ancho; ?>"
                     data-alto="<?php echo $alto; ?>"
                     style="--fbm-height:<?php echo $alto; ?>px;" tabindex="0">
                    <div class="fbm-stage" id="fbm-stage-<?php echo $pid; ?>">
                        <button class="fbm-page-hotspot fbm-page-hotspot-prev" data-accion="anterior" data-id="<?php echo $pid; ?>" aria-label="Pagina anterior"></button>
                        <div class="fbm-page-shell" id="fbm-page-shell-<?php echo $pid; ?>">
                            <canvas class="fbm-page-canvas" id="fbm-canvas-<?php echo $pid; ?>"></canvas>
                            <div class="fbm-link-layer" id="fbm-links-<?php echo $pid; ?>"></div>
                        </div>
                        <button class="fbm-page-hotspot fbm-page-hotspot-next" data-accion="siguiente" data-id="<?php echo $pid; ?>" aria-label="Pagina siguiente"></button>
                    </div>

                    <div class="fbm-cargando" id="fbm-cargando-<?php echo $pid; ?>">
                        <div class="fbm-spinner"></div>
                        <p class="fbm-cargando-texto">Preparando PDF...</p>
                    </div>

                    <div class="fbm-controles" id="fbm-controles-<?php echo $pid; ?>">
                        <button class="fbm-btn" data-accion="anterior" data-id="<?php echo $pid; ?>" title="Pagina anterior" aria-label="Pagina anterior">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>
                        <span class="fbm-pagina-info" id="fbm-info-<?php echo $pid; ?>">...</span>
                        <button class="fbm-btn" data-accion="siguiente" data-id="<?php echo $pid; ?>" title="Pagina siguiente" aria-label="Pagina siguiente">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <button class="fbm-btn fbm-btn-fs" data-accion="fullscreen" data-id="<?php echo $pid; ?>" title="Pantalla completa" aria-label="Pantalla completa">
                            <svg class="icon-expand" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H3v5M16 3h5v5M21 16v5h-5M3 16v5h5"/></svg>
                            <svg class="icon-compress" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3v6H3M15 3v6h6M15 21v-6h6M9 21v-6H3"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div><!-- .fbm-contenedor-externo -->
        <?php
        return ob_get_clean();
    }

    private function resolver_pdf_id( $atts ) {
        $pid = intval( $atts['id'] );
        if ( $pid > 0 ) {
            return $pid;
        }

        $grupo = $this->obtener_atributo_grupo( $atts );
        if ( $grupo === '' ) {
            return 0;
        }

        return $this->obtener_ultimo_pdf_por_grupo( $grupo );
    }

    private function obtener_atributo_grupo( $atts ) {
        foreach ( array( 'grupo', 'categoria', 'category', 'lbpdf_grupo' ) as $key ) {
            if ( isset( $atts[$key] ) && trim( (string) $atts[$key] ) !== '' ) {
                return sanitize_text_field( wp_unslash( $atts[$key] ) );
            }
        }

        return '';
    }

    private function obtener_ultimo_pdf_por_grupo( $grupo ) {
        $taxonomy = Flipbook_Taxonomy::SLUG;
        $term     = false;

        if ( is_numeric( $grupo ) ) {
            $term = get_term( absint( $grupo ), $taxonomy );
        }

        if ( ! $term || is_wp_error( $term ) ) {
            $term = get_term_by( 'slug', sanitize_title( $grupo ), $taxonomy );
        }

        if ( ! $term ) {
            $term = get_term_by( 'name', $grupo, $taxonomy );
        }

        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error(
                'lbpdf_grupo_no_encontrado',
                sprintf( 'LeafBook: el grupo "%s" no existe.', $grupo )
            );
        }

        $query = new WP_Query( array(
            'post_type'           => 'flipbook',
            'post_status'         => 'publish',
            'posts_per_page'      => 1,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'tax_query'           => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => array( $term->term_id ),
                ),
            ),
            'meta_query'          => array(
                array(
                    'key'     => '_fbm_pdf_url',
                    'value'   => '',
                    'compare' => '!=',
                ),
            ),
        ) );

        if ( empty( $query->posts ) ) {
            return new WP_Error(
                'lbpdf_grupo_sin_pdfs',
                sprintf( 'LeafBook: no hay PDFs publicados con archivo configurado en el grupo "%s".', $term->name )
            );
        }

        return (int) $query->posts[0]->ID;
    }
}
