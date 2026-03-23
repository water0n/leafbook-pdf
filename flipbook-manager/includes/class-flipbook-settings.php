<?php
/**
 * Archivo: includes/class-flipbook-settings.php
 *
 * Crea el menú principal "LeafBook PDF" en la barra lateral,
 * con sus submenús y la página de Ajustes.
 *
 * ORDEN CRÍTICO: Este archivo se carga PRIMERO en flipbook-manager.php
 * para que el menú raíz exista cuando el CPT intenta anidarse en él.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Flipbook_Settings {

    const OPCION = 'lbpdf_ajustes';

    // Ícono SVG inline de una hoja/documento PDF
    // Base64 para usarlo como data URI en add_menu_page()
    private function icono_svg() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
             . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
             . '<polyline points="14 2 14 8 20 8"/>'
             . '<line x1="9" y1="13" x2="15" y2="13"/>'
             . '<line x1="9" y1="17" x2="13" y2="17"/>'
             . '<polyline points="9 9 10 9"/>'
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    public function register() {
        // admin_menu se ejecuta en el hook 'admin_menu', prioridad 9
        // (antes de la prioridad 10 por defecto del CPT)
        add_action( 'admin_menu', array( $this, 'registrar_menu' ), 9 );
        add_action( 'admin_init', array( $this, 'registrar_campos' ) );
        add_action( 'wp_head',    array( $this, 'imprimir_estilos' ) );
    }

    // ============================================================
    // MENÚ LATERAL
    // ============================================================
    public function registrar_menu() {

        // ── Menú RAÍZ ────────────────────────────────────────────
        add_menu_page(
            'LeafBook PDF',             // Título en <title>
            'LeafBook PDF',             // Texto en el menú
            'edit_posts',               // Quién puede verlo
            'leafbook-pdf',             // Slug único ← el CPT usa este valor en show_in_menu
            array( $this, 'render_pagina' ),
            $this->icono_svg(),         // Ícono SVG como data URI
            26                          // Posición (26 = debajo de Comentarios)
        );

        // ── Submenús explícitos ──────────────────────────────────
        // WordPress agrega "Todas las publicaciones" y "Nueva publicación"
        // automáticamente desde el CPT. Solo necesitamos agregar "Ajustes".

        // Renombrar el primer submenú (que WordPress crea igual al menú padre)
        add_submenu_page( 'leafbook-pdf', 'LeafBook PDF', '📊 Inicio', 'edit_posts', 'leafbook-pdf', array( $this, 'render_pagina' ) );

        // Ajustes
        add_submenu_page(
            'leafbook-pdf',
            'Ajustes — LeafBook PDF',
            '⚙️ Ajustes',
            'manage_options',
            'lbpdf-ajustes',
            array( $this, 'render_pagina_ajustes' )
        );
    }

    // ============================================================
    // RENDER: Página de inicio del plugin
    // ============================================================
    public function render_pagina() {
        if ( ! current_user_can('edit_posts') ) return;

        $total = wp_count_posts('flipbook');
        $publicados = isset($total->publish) ? $total->publish : 0;

        $flipbooks = get_posts( array( 'post_type' => 'flipbook', 'post_status' => 'publish', 'numberposts' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
        ?>
        <div class="wrap">
        <style>
            .lbpdf-inicio { max-width:920px; }
            .lbpdf-hero   { background:linear-gradient(135deg,#0f172a,#1e3a5f); border-radius:12px;
                            padding:22px 24px; color:#fff; display:flex; align-items:center; gap:20px; margin-bottom:20px; flex-wrap:wrap; }
            .lbpdf-hero-icono { font-size:44px; line-height:1; flex-shrink:0; }
            .lbpdf-hero h1    { font-size:20px; margin:0 0 4px; color:#fff; }
            .lbpdf-hero p     { margin:0; color:#94a3b8; font-size:13px; }
            .lbpdf-cards  { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin-bottom:20px; }
            .lbpdf-card   { background:#fff; border:1px solid #e2e4e7; border-radius:10px; padding:18px 20px; }
            .lbpdf-card-num { font-size:28px; font-weight:700; color:#1d2327; word-break:break-word; }
            .lbpdf-card-label { font-size:12px; color:#787c82; margin-top:2px; }
            .lbpdf-card-accion { display:inline-block; margin-top:10px; font-size:12px; color:#2271b1; text-decoration:none; font-weight:600; }
            .lbpdf-recientes { background:#fff; border:1px solid #e2e4e7; border-radius:10px; padding:18px 20px; overflow-x:auto; }
            .lbpdf-recientes h2 { font-size:14px; margin:0 0 12px; color:#1d2327; }
            .lbpdf-recientes table { width:100%; border-collapse:collapse; min-width:400px; }
            .lbpdf-recientes th { font-size:11px; color:#787c82; text-transform:uppercase; letter-spacing:.05em; padding:0 8px 8px 0; text-align:left; border-bottom:1px solid #f0f0f0; }
            .lbpdf-recientes td { padding:9px 8px 9px 0; border-bottom:1px solid #f9f9f9; font-size:13px; vertical-align:middle; }
            .lbpdf-recientes tr:last-child td { border-bottom:none; }
            .lbpdf-sc { background:#f0f4ff; color:#2563eb; padding:3px 8px; border-radius:4px; font-family:monospace; font-size:12px; }
            .lbpdf-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; background:#e0f2fe; color:#0369a1; }
            .lbpdf-footer { margin-top:18px; font-size:12px; color:#b0b8c8; text-align:right; }
        </style>

        <div class="lbpdf-inicio">

            <div class="lbpdf-hero">
                <div class="lbpdf-hero-icono">📄</div>
                <div>
                    <h1>LeafBook PDF</h1>
                    <p>by <strong style="color:#cbd5e1">KaabApp.com</strong> · Daniel Zermeño &nbsp;·&nbsp; Versión <?php echo FBM_VERSION; ?></p>
                    <p style="margin-top:6px;">Gestiona tus PDFs y obten un ID único para incrustarlos donde quieras. <a href="<?php echo admin_url('post-new.php?post_type=flipbook'); ?>" style="color:#60a5fa;">+ Agregar PDF →</a></p>
                </div>
            </div>

            <div class="lbpdf-cards">
                <div class="lbpdf-card">
                    <div class="lbpdf-card-num"><?php echo $publicados; ?></div>
                    <div class="lbpdf-card-label">PDFs registrados</div>
                    <a class="lbpdf-card-accion" href="<?php echo admin_url('edit.php?post_type=flipbook'); ?>">Ver todas →</a>
                </div>
                <div class="lbpdf-card">
                    <div class="lbpdf-card-num" style="font-size:22px;">[leafbook id="X"]</div>
                    <div class="lbpdf-card-label">Incrustar en cualquier sitio</div>
                    <a class="lbpdf-card-accion" href="<?php echo admin_url('admin.php?page=lbpdf-ajustes#iframe'); ?>">Generar iframe →</a>
                </div>
                <div class="lbpdf-card">
                    <div class="lbpdf-card-num">✅</div>
                    <div class="lbpdf-card-label">PDF.js + StPageFlip</div>
                    <a class="lbpdf-card-accion" href="<?php echo admin_url('admin.php?page=lbpdf-ajustes'); ?>">Personalizar →</a>
                </div>
            </div>

            <?php if ( $flipbooks ) : ?>
            <div class="lbpdf-recientes">
                <h2>📋 PDFs registrados recientemente</h2>
                <table>
                    <tr>
                        <th>Título</th>
                        <th>Grupo</th>
                        <th>Shortcode</th>
                        <th>Acciones</th>
                    </tr>
                    <?php foreach ( $flipbooks as $fb ) :
                        $grupos = wp_get_post_terms($fb->ID, Flipbook_Taxonomy::SLUG);
                        $grupo_html = '';
                        if (!empty($grupos) && !is_wp_error($grupos)) {
                            foreach ($grupos as $g) $grupo_html .= '<span class="lbpdf-badge">' . esc_html($g->name) . '</span> ';
                        } else {
                            $grupo_html = '<span style="color:#9ca3af;font-size:11px;">—</span>';
                        }
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?php echo esc_html( $fb->post_title ); ?></td>
                        <td><?php echo $grupo_html; ?></td>
                        <td><span class="lbpdf-sc">[leafbook id="<?php echo $fb->ID; ?>"]</span></td>
                        <td style="white-space:nowrap;">
                            <a href="<?php echo get_edit_post_link( $fb->ID ); ?>" style="font-size:12px; color:#2271b1;">Editar</a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo get_permalink( $fb->ID ); ?>" target="_blank" style="font-size:12px; color:#2271b1;">Ver</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php else : ?>
            <div style="background:#f6f7f7; border:2px dashed #ddd; border-radius:8px; padding:28px; text-align:center; color:#787c82;">
                <p style="font-size:15px; margin:0 0 10px;">📭 Aún no tienes PDFs registrados.</p>
                <a href="<?php echo admin_url('post-new.php?post_type=flipbook'); ?>" class="button button-primary">+ Agregar mi primer PDF</a>
            </div>
            <?php endif; ?>

            <div class="lbpdf-footer">LeafBook PDF · KaabApp.com · by Daniel Zermeño</div>
        </div>
        </div>
        <?php
    }

    // ============================================================
    // RENDER: Página de Ajustes
    // ============================================================
    public function render_pagina_ajustes() {
        if ( ! current_user_can('manage_options') ) return;
        $saved = get_option( self::OPCION, array() );
        ?>
        <div class="wrap" id="lbpdf-ajustes-wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">⚙️ Ajustes — LeafBook PDF</h1>

        <style>
            #lbpdf-ajustes-wrap  { max-width:860px; }
            .lbpdf-tabs          { display:flex; gap:4px; margin:16px 0 0; border-bottom:2px solid #e2e4e7; }
            .lbpdf-tab           { padding:9px 18px; cursor:pointer; border:1px solid transparent; border-bottom:none; border-radius:6px 6px 0 0; font-size:13px; background:#f6f7f7; color:#50575e; text-decoration:none; }
            .lbpdf-tab.activa    { background:#fff; border-color:#e2e4e7; color:#1d2327; font-weight:600; margin-bottom:-2px; }
            .lbpdf-panel         { display:none; background:#fff; border:1px solid #e2e4e7; border-top:none; border-radius:0 0 8px 8px; padding:24px 28px; }
            .lbpdf-panel.activo  { display:block; }
            .lbpdf-tabla         { border-collapse:collapse; width:100%; }
            .lbpdf-tabla th      { text-align:left; padding:12px 16px 12px 0; font-size:13px; color:#1d2327; font-weight:600; width:220px; vertical-align:top; }
            .lbpdf-tabla td      { padding:8px 0; vertical-align:top; }
            .lbpdf-tabla tr      { border-bottom:1px solid #f0f0f0; }
            .lbpdf-tabla tr:last-child { border-bottom:none; }
            .lbpdf-hint          { color:#787c82; font-size:12px; margin:4px 0 0; }
            .lbpdf-sec-titulo    { font-size:15px; font-weight:700; color:#1d2327; margin:0 0 16px; padding-bottom:10px; border-bottom:2px solid #f0f0f0; }
            .lbpdf-iframe-caja   { background:#1d2327; color:#a8d8ff; padding:14px 16px; border-radius:6px; font-family:monospace; font-size:12px; word-break:break-all; white-space:pre-wrap; }
            .lbpdf-btn-copy      { padding:7px 14px; background:#2271b1; color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:12px; margin-top:10px; }
            .lbpdf-btn-copy:hover { background:#135e96; }
        </style>

        <div class="lbpdf-tabs">
            <a href="#" class="lbpdf-tab activa" data-panel="apariencia">🎨 Apariencia</a>
            <a href="#" class="lbpdf-tab"        data-panel="carga"     >⚡ Rendimiento</a>
            <a href="#" class="lbpdf-tab"        data-panel="iframe"    >🔗 Iframe / Embed</a>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('lbpdf_grupo'); ?>

            <!-- ── PANEL APARIENCIA ── -->
            <div class="lbpdf-panel activo" id="lbpdf-panel-apariencia">
                <p class="lbpdf-sec-titulo">Personalización visual</p>
                <table class="lbpdf-tabla">
                    <tr>
                        <th>Tipo de fondo del visor</th>
                        <td>
                            <?php $tipo = $this->val('fondo_tipo','color'); ?>
                            <select name="<?php echo self::OPCION; ?>[fondo_tipo]" id="lbpdf_fondo_tipo" onchange="lbpdfToggleFondo()">
                                <option value="color"     <?php selected($tipo,'color');     ?>>Color sólido</option>
                                <option value="degradado" <?php selected($tipo,'degradado'); ?>>Degradado</option>
                                <option value="imagen"    <?php selected($tipo,'imagen');    ?>>Imagen (URL)</option>
                                <option value="sin_fondo" <?php selected($tipo,'sin_fondo'); ?>>Sin fondo</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="lbpdf-fila-c1" style="<?php echo $tipo === 'sin_fondo' ? 'display:none' : ''; ?>">
                        <th>Color principal</th>
                        <td><input type="color" name="<?php echo self::OPCION; ?>[fondo_color]" value="<?php echo esc_attr($this->val('fondo_color','#1a2234')); ?>"></td>
                    </tr>
                    <tr id="lbpdf-fila-c2" style="<?php echo $tipo !== 'degradado' ? 'display:none' : ''; ?>">
                        <th>Color secundario</th>
                        <td><input type="color" name="<?php echo self::OPCION; ?>[fondo_color2]" value="<?php echo esc_attr($this->val('fondo_color2','#111827')); ?>"></td>
                    </tr>
                    <tr id="lbpdf-fila-img" style="<?php echo $tipo !== 'imagen' ? 'display:none' : ''; ?>">
                        <th>URL de imagen de fondo</th>
                        <td>
                            <input type="url" name="<?php echo self::OPCION; ?>[fondo_imagen_url]" value="<?php echo esc_attr($this->val('fondo_imagen_url','')); ?>" style="width:400px" placeholder="https://tusitio.com/wp-content/uploads/fondo.jpg">
                            <p class="lbpdf-hint">Sube la imagen en Medios y pega la URL aquí.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Color de barra de controles</th>
                        <td><input type="color" name="<?php echo self::OPCION; ?>[color_barra]" value="<?php echo esc_attr($this->val('color_barra','#0f172a')); ?>"></td>
                    </tr>
                    <tr>
                        <th>Fondo de botones</th>
                        <td><input type="color" name="<?php echo self::OPCION; ?>[color_botones]" value="<?php echo esc_attr($this->val('color_botones','#2a3547')); ?>"></td>
                    </tr>
                    <tr>
                        <th>Texto de botones</th>
                        <td><input type="color" name="<?php echo self::OPCION; ?>[color_btn_texto]" value="<?php echo esc_attr($this->val('color_btn_texto','#d1d5db')); ?>"></td>
                    </tr>
                    <tr>
                        <th>Redondez de bordes</th>
                        <td>
                            <input type="number" name="<?php echo self::OPCION; ?>[radio_bordes]" value="<?php echo esc_attr($this->val('radio_bordes','12')); ?>" min="0" max="40" style="width:70px"> px
                            <p class="lbpdf-hint">0 = cuadrado · 20 = muy redondeado</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Sombra exterior</th>
                        <td><label><input type="checkbox" name="<?php echo self::OPCION; ?>[sombra]" value="1" <?php checked('1',$this->val('sombra','1')); ?>> Activar sombra</label></td>
                    </tr>
                </table>
            </div>

            <!-- ── PANEL RENDIMIENTO ── -->
            <div class="lbpdf-panel" id="lbpdf-panel-carga">
                <p class="lbpdf-sec-titulo">Rendimiento al procesar el PDF</p>
                <p style="color:#646970;font-size:13px;margin:0 0 18px;">Aplica a todas las publicaciones del sitio. Mayor calidad = más tiempo de carga.</p>
                <table class="lbpdf-tabla">
                    <tr>
                        <th>Calidad de imagen</th>
                        <td>
                            <?php $cal = $this->val('calidad','0.85'); ?>
                            <input type="range" name="<?php echo self::OPCION; ?>[calidad]" value="<?php echo esc_attr($cal); ?>" min="0.5" max="1" step="0.05" oninput="document.getElementById('lbv_cal').textContent=this.value" style="width:200px;vertical-align:middle">
                            &nbsp;<span id="lbv_cal"><?php echo esc_html($cal); ?></span>
                            <p class="lbpdf-hint">0.5 = rápido / 1.0 = máxima calidad</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Escala de renderizado</th>
                        <td>
                            <?php $esc = $this->val('escala','1.5'); ?>
                            <input type="range" name="<?php echo self::OPCION; ?>[escala]" value="<?php echo esc_attr($esc); ?>" min="1" max="3" step="0.25" oninput="document.getElementById('lbv_esc').textContent=this.value" style="width:200px;vertical-align:middle">
                            &nbsp;<span id="lbv_esc"><?php echo esc_html($esc); ?></span>
                            <p class="lbpdf-hint">Recomendado: 1.5</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div id="lbpdf-boton-guardar" style="padding:18px 0 4px;">
                <?php submit_button('💾 Guardar ajustes','primary','submit',false); ?>
            </div>
        </form>

        <!-- ── PANEL IFRAME (sin form) ── -->
        <div class="lbpdf-panel" id="lbpdf-panel-iframe">
            <p class="lbpdf-sec-titulo">Incrustar en otro sitio (iframe)</p>
            <?php
            $flipbooks = get_posts( array('post_type'=>'flipbook','post_status'=>'publish','numberposts'=>100) );
            if ( empty($flipbooks) ) : ?>
                <p style="color:#787c82;">Aún no tienes PDFs registrados. <a href="<?php echo admin_url('post-new.php?post_type=flipbook'); ?>">Agregar el primero →</a></p>
            <?php else : ?>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
                    <label style="font-weight:600;font-size:13px;">Publicación:</label>
                    <select id="lbpdf-iframe-sel" onchange="lbpdfIframe()" style="padding:5px 8px;border:1px solid #c3c4c7;border-radius:4px;font-size:13px;">
                        <?php foreach($flipbooks as $fb) : ?>
                            <option value="<?php echo $fb->ID; ?>"><?php echo esc_html($fb->post_title); ?> — ID <?php echo $fb->ID; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label style="font-weight:600;font-size:13px;">Ancho:</label>
                    <input type="number" id="lbpdf-iframe-w" value="900" min="300" max="1600" step="10" oninput="lbpdfIframe()" style="width:75px;padding:5px 8px;border:1px solid #c3c4c7;border-radius:4px;"> px
                    <label style="font-weight:600;font-size:13px;">Alto:</label>
                    <input type="number" id="lbpdf-iframe-h" value="600" min="200" max="1000" step="10" oninput="lbpdfIframe()" style="width:75px;padding:5px 8px;border:1px solid #c3c4c7;border-radius:4px;"> px
                </div>
                <div class="lbpdf-iframe-caja" id="lbpdf-iframe-cod"></div>
                <button class="lbpdf-btn-copy" onclick="lbpdfCopiar()">📋 Copiar código</button>
                <span id="lbpdf-copiado" style="display:none;color:#00a32a;font-size:12px;margin-left:8px;">✅ ¡Copiado!</span>

                <hr style="margin:22px 0;border-color:#f0f0f0;">
                <p style="font-weight:600;font-size:13px;margin:0 0 10px;">Shortcodes disponibles:</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php foreach($flipbooks as $fb) : ?>
                        <code style="background:#1d2327;color:#a8d8ff;padding:5px 10px;border-radius:4px;font-size:12px;">[leafbook id="<?php echo $fb->ID; ?>"]</code>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <script>
        // ── Pestañas
        document.querySelectorAll('.lbpdf-tab').forEach(function(t) {
            t.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelectorAll('.lbpdf-tab').forEach(function(x)  { x.classList.remove('activa'); });
                document.querySelectorAll('.lbpdf-panel').forEach(function(x){ x.classList.remove('activo'); });
                t.classList.add('activa');
                document.getElementById('lbpdf-panel-' + t.dataset.panel).classList.add('activo');
                document.getElementById('lbpdf-boton-guardar').style.display = t.dataset.panel === 'iframe' ? 'none' : 'block';
            });
        });
        // ── Toggle filas de fondo
        function lbpdfToggleFondo() {
            var v = document.getElementById('lbpdf_fondo_tipo').value;
            document.getElementById('lbpdf-fila-c1').style.display  = v === 'sin_fondo'  ? 'none' : '';
            document.getElementById('lbpdf-fila-c2').style.display  = v === 'degradado' ? '' : 'none';
            document.getElementById('lbpdf-fila-img').style.display = v === 'imagen'    ? '' : 'none';
        }
        // ── Iframe
        function lbpdfIframe() {
            var id = document.getElementById('lbpdf-iframe-sel').value;
            var w  = document.getElementById('lbpdf-iframe-w').value || 900;
            var h  = document.getElementById('lbpdf-iframe-h').value || 600;
            var url = '<?php echo esc_js(home_url('/')); ?>?lbpdf_embed=' + id;
            document.getElementById('lbpdf-iframe-cod').textContent =
                '<iframe\n  src="' + url + '"\n  width="' + w + '"\n  height="' + h + '"\n  frameborder="0"\n  allowfullscreen\n  loading="lazy"\n  style="border:none; border-radius:12px;">\n</iframe>';
        }
        function lbpdfCopiar() {
            var txt = document.getElementById('lbpdf-iframe-cod').textContent;
            navigator.clipboard.writeText(txt).then(function() {
                var el = document.getElementById('lbpdf-copiado');
                el.style.display = 'inline';
                setTimeout(function(){ el.style.display='none'; }, 2500);
            });
        }
        if (document.getElementById('lbpdf-iframe-sel')) lbpdfIframe();
        </script>
        </div><!-- .wrap -->
        <?php
    }

    // ============================================================
    // REGISTRAR CAMPOS
    // ============================================================
    public function registrar_campos() {
        register_setting( 'lbpdf_grupo', self::OPCION, array($this,'sanitizar') );
    }

    // ============================================================
    // CSS PERSONALIZADO en el <head> del frontend
    // ============================================================
    public function imprimir_estilos() {
        $o = get_option( self::OPCION, array() );
        if ( empty($o) ) return;

        $tipo  = $this->val('fondo_tipo',  'color');
        $c1    = $this->val('fondo_color', '#1a2234');
        $c2    = $this->val('fondo_color2','#111827');
        $img   = $this->val('fondo_imagen_url','');
        $barra = $this->val('color_barra', '#0f172a');
        $btn   = $this->val('color_botones','#2a3547');
        $btntx = $this->val('color_btn_texto','#d1d5db');
        $rad   = intval($this->val('radio_bordes','12'));
        $sombra = $this->val('sombra','1') === '1';

        if      ($tipo === 'degradado')         $fondo = 'background:linear-gradient(135deg,' . $c1 . ',' . $c2 . ');';
        elseif  ($tipo === 'imagen' && $img)    $fondo = 'background:url(' . esc_url($img) . ') center/cover no-repeat; background-color:' . $c1 . ';';
        elseif  ($tipo === 'sin_fondo')         $fondo = 'background:transparent;';
        else                                    $fondo = 'background:' . $c1 . ';';

        $transparencia_css = $tipo === 'sin_fondo'
            ? '.fbm-contenedor-externo,.fbm-area-principal,.fbm-visor-wrap,.fbm-visor,.fbm-cargando{background:transparent!important;}'
            : '';

        $sombra_css = $sombra ? 'box-shadow:0 20px 60px rgba(0,0,0,.45);' : 'box-shadow:none;';

        echo '<style id="lbpdf-estilos">
.fbm-contenedor-externo{border-radius:' . $rad . 'px!important;' . $sombra_css . '}
' . $transparencia_css . '
.fbm-visor,.fbm-cargando{' . $fondo . '}
.fbm-controles{background:' . $barra . '!important;}
.fbm-btn{background:' . $btn . '!important;color:' . $btntx . '!important;}
</style>' . "\n";
    }

    // ============================================================
    // SANITIZAR
    // ============================================================
    public function sanitizar($i) {
        $fondo_tipo = isset($i['fondo_tipo']) && in_array($i['fondo_tipo'], array('color', 'degradado', 'imagen', 'sin_fondo'), true)
            ? $i['fondo_tipo']
            : 'color';

        return array(
            'fondo_tipo'       => $fondo_tipo,
            'fondo_color'      => sanitize_hex_color(   $i['fondo_color']      ?? '#1a2234' ),
            'fondo_color2'     => sanitize_hex_color(   $i['fondo_color2']     ?? '#111827' ),
            'fondo_imagen_url' => esc_url_raw(          $i['fondo_imagen_url'] ?? ''        ),
            'color_barra'      => sanitize_hex_color(   $i['color_barra']      ?? '#0f172a' ),
            'color_botones'    => sanitize_hex_color(   $i['color_botones']    ?? '#2a3547' ),
            'color_btn_texto'  => sanitize_hex_color(   $i['color_btn_texto']  ?? '#d1d5db' ),
            'radio_bordes'     => absint(               $i['radio_bordes']     ?? 12        ),
            'sombra'           => isset($i['sombra']) && $i['sombra'] === '1' ? '1' : '0',
            'calidad'          => min(1, max(0.5, floatval($i['calidad'] ?? 0.85))),
            'escala'           => min(3, max(1,   floatval($i['escala']  ?? 1.5 ))),
        );
    }

    private function val($k, $def = '') {
        $o = get_option(self::OPCION, array());
        return isset($o[$k]) ? $o[$k] : $def;
    }
}

// Note: WordPress automatically adds the taxonomy management page
// under the CPT menu when show_in_menu is true.
// We just need to make sure the slug matches.
