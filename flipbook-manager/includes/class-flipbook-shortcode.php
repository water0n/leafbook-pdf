<?php
/**
 * class-flipbook-shortcode.php
 * Shortcode [leafbook id="X"] — lee la config de apariencia por PDF
 * y renderiza solo los botones/info que corresponda.
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
        wp_register_script( 'pdfjs-lib',  'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',               array(), '3.11.174', true );
        // Worker LOCAL — evita bloqueos cross-origin en iframes
        wp_register_script( 'pdfjs-worker', FBM_PLUGIN_URL . 'assets/js/pdf.worker.min.js', array(), '3.11.174', true );
        wp_register_script( 'stpageflip', 'https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.js',       array(), '2.0.7',    true );
        wp_register_script( 'fbm-visor',  FBM_PLUGIN_URL . 'assets/js/visor.js', array('pdfjs-lib','stpageflip'), FBM_VERSION, true );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0, 'ancho' => '', 'alto' => '' ), $atts, 'leafbook' );
        $pid  = intval( $atts['id'] );
        if ( $pid <= 0 ) return '<p style="color:red;">⚠️ LeafBook: falta el id. Uso: [leafbook id="42"]</p>';

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

        // Llama fbmIniciarTodos() al final del footer — garantiza que el DOM y los
        // datos fbmData_X ya existen, independientemente del orden de carga de scripts.
        // add_inline_script es idempotente por handle: WordPress acumula los fragmentos
        // pero el script solo se registra una vez por handle, así que llamar esto
        // múltiples veces (varios shortcodes en la misma página) no duplica la llamada.
        wp_add_inline_script( 'fbm-visor', 'document.addEventListener("DOMContentLoaded",function(){ if(typeof window.fbmIniciarTodos==="function") window.fbmIniciarTodos(); });', 'after' );

        $tema = $cfg['tema_botones'] ?? 'oscuro';
        wp_localize_script( 'fbm-visor', 'fbmData_' . $pid, array(
            'pdfUrl'    => esc_url( $proxy_url ),
            'pdfDireto' => esc_url( $pdf_url ),
            'ancho'     => $ancho,
            'alto'      => $alto,
            'autoplay'  => $autoplay,
            'workerSrc' => FBM_PLUGIN_URL . 'assets/js/pdf.worker.min.js?v=' . FBM_VERSION,
            'buscar'    => $cfg['btn_buscar'],
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

            <?php // ── Barra de controles ── ?>
            <div class="fbm-controles" id="fbm-controles-<?php echo $pid; ?>">

                <?php // Búsqueda (se muestra a la izquierda si activa) ?>
                <?php if ($cfg['btn_buscar'] === '1'): ?>
                <div class="fbm-ctrl-grupo fbm-ctrl-buscar" id="fbm-ctrl-buscar-<?php echo $pid; ?>">
                    <div class="fbm-search-wrap" id="fbm-search-wrap-<?php echo $pid; ?>">
                        <input type="text" class="fbm-search-input" id="fbm-search-input-<?php echo $pid; ?>"
                               placeholder="Buscar en PDF…" data-id="<?php echo $pid; ?>">
                        <span class="fbm-search-info" id="fbm-search-info-<?php echo $pid; ?>"></span>
                        <button class="fbm-btn fbm-btn-search-clear" onclick="fbmLimpiarBusqueda(<?php echo $pid; ?>)" title="Limpiar">✕</button>
                    </div>
                </div>
                <?php endif; ?>

                <?php // Grupo centro: navegación ?>
                <div class="fbm-ctrl-grupo">
                    <?php if ($cfg['btn_primera'] === '1'): ?>
                    <button class="fbm-btn" data-accion="primera" data-id="<?php echo $pid; ?>" title="Primera página">⏮</button>
                    <?php endif; ?>
                    <?php if ($cfg['btn_anterior'] === '1'): ?>
                    <button class="fbm-btn" data-accion="anterior" data-id="<?php echo $pid; ?>" title="Anterior">◀</button>
                    <?php endif; ?>
                    <span class="fbm-pagina-info" id="fbm-info-<?php echo $pid; ?>">Cargando…</span>
                    <?php if ($cfg['btn_anterior'] === '1'): ?>
                    <button class="fbm-btn" data-accion="siguiente" data-id="<?php echo $pid; ?>" title="Siguiente">▶</button>
                    <?php endif; ?>
                    <?php if ($cfg['btn_ultima'] === '1'): ?>
                    <button class="fbm-btn" data-accion="ultima" data-id="<?php echo $pid; ?>" title="Última página">⏭</button>
                    <?php endif; ?>
                </div>

                <?php // Grupo derecho: herramientas ?>
                <div class="fbm-ctrl-grupo">
                    <?php if ($cfg['btn_zoom'] === '1'): ?>
                    <button class="fbm-btn" data-accion="zoom-menos" data-id="<?php echo $pid; ?>" title="Alejar">−</button>
                    <span class="fbm-zoom-nivel" id="fbm-zoom-<?php echo $pid; ?>">100%</span>
                    <button class="fbm-btn" data-accion="zoom-mas"   data-id="<?php echo $pid; ?>" title="Acercar">+</button>
                    <?php endif; ?>

                    <button class="fbm-btn" data-accion="miniaturas" data-id="<?php echo $pid; ?>" title="Miniaturas" id="fbm-btn-min-<?php echo $pid; ?>">⊞</button>

                    <?php if ($cfg['btn_compartir'] === '1'): ?>
                    <button class="fbm-btn" data-accion="compartir" data-id="<?php echo $pid; ?>"
                            data-url="<?php echo esc_attr(get_permalink($pid)); ?>"
                            data-titulo="<?php echo esc_attr(get_the_title($pid)); ?>"
                            title="Compartir">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                        </svg>
                    </button>
                    <?php endif; ?>

                    <?php if ($cfg['btn_imprimir'] === '1'): ?>
                    <button class="fbm-btn" data-accion="imprimir" data-id="<?php echo $pid; ?>"
                            data-url="<?php echo esc_attr(add_query_arg('lbpdf_proxy',$pid,home_url('/'))); ?>"
                            title="Imprimir">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                            <rect x="6" y="14" width="12" height="8"/>
                        </svg>
                    </button>
                    <?php endif; ?>

                    <?php if ($cfg['btn_descarga'] === '1'): ?>
                    <a class="fbm-btn" href="<?php echo esc_url(add_query_arg('lbpdf_proxy',$pid,home_url('/'))); ?>" download="<?php echo esc_attr(sanitize_file_name(get_the_title($pid)).'.pdf'); ?>" title="Descargar PDF" target="_blank">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                    </a>
                    <?php endif; ?>

                    <?php if ($cfg['btn_fullscreen'] === '1'): ?>
                    <button class="fbm-btn fbm-btn-fs" data-accion="fullscreen" data-id="<?php echo $pid; ?>"
                            id="fbm-btn-fs-<?php echo $pid; ?>" title="Pantalla completa (F)">
                        <svg class="icon-expand" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/>
                            <line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>
                        </svg>
                        <svg class="icon-compress" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/>
                            <line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/>
                        </svg>
                    </button>
                    <?php endif; ?>
                </div>

            </div><!-- .fbm-controles -->

            <?php // ── Área principal ── ?>
            <div class="fbm-area-principal">
                <div class="fbm-panel-miniaturas" id="fbm-miniaturas-<?php echo $pid; ?>" aria-hidden="true">
                    <div class="fbm-miniaturas-titulo">Páginas</div>
                    <div class="fbm-miniaturas-grid" id="fbm-min-grid-<?php echo $pid; ?>"></div>
                </div>
                <div class="fbm-visor-wrap" id="fbm-visor-wrap-<?php echo $pid; ?>">
                    <div id="fbm-visor-<?php echo $pid; ?>" class="fbm-visor"
                         data-id="<?php echo $pid; ?>"
                         data-pdf="<?php echo esc_url($pdf_url); ?>"
                         data-ancho="<?php echo $ancho; ?>"
                         data-alto="<?php echo $alto; ?>"
                         style="width:100%;height:<?php echo $alto; ?>px;" tabindex="0">
                        <div class="fbm-cargando" id="fbm-cargando-<?php echo $pid; ?>">
                            <div class="fbm-spinner"></div>
                            <p class="fbm-cargando-texto">Preparando PDF…</p>
                            <div class="fbm-progreso-barra">
                                <div class="fbm-progreso-fill" id="fbm-progreso-<?php echo $pid; ?>"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php // ── Panel de búsqueda de resultados ── ?>
            <?php if ($cfg['btn_buscar'] === '1'): ?>
            <div class="fbm-search-resultados" id="fbm-search-res-<?php echo $pid; ?>" style="display:none">
                <div class="fbm-search-res-lista" id="fbm-search-res-lista-<?php echo $pid; ?>"></div>
            </div>
            <?php endif; ?>

        </div><!-- .fbm-contenedor-externo -->
        <?php
        return ob_get_clean();
    }
}
