<?php
/**
 * class-flipbook-admin.php
 * Pantalla de edición de un PDF registrado.
 * Concepto: asignas un nombre, seleccionas el PDF de Medios,
 * configuras opciones → obtienes un ID único para incrustar.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Flipbook_Admin {

    public function register() {
        add_action( 'add_meta_boxes',                      array( $this, 'meta_boxes'          ) );
        add_action( 'save_post_flipbook',                  array( $this, 'guardar'             ) );
        add_action( 'admin_enqueue_scripts',               array( $this, 'assets_admin'        ) );
        add_action( 'admin_head',                          array( $this, 'ocultar_cosas_wp'    ) );
        add_filter( 'manage_flipbook_posts_columns',       array( $this, 'columnas'            ) );
        add_action( 'manage_flipbook_posts_custom_column', array( $this, 'render_col'          ), 10, 2 );
        add_filter( 'enter_title_here',                    array( $this, 'placeholder_titulo'  ) );
        add_filter( 'post_updated_messages',               array( $this, 'mensajes'            ) );
    }

    // ── Personaliza el placeholder del título ─────────────────
    public function placeholder_titulo( $text ) {
        if ( get_post_type() === 'flipbook' ) return 'Nombre del PDF (ej: Revista Marzo 2026)';
        return $text;
    }

    // ── Oculta bloques de WP innecesarios ────────────────────
    public function ocultar_cosas_wp() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'flipbook' ) return;
        echo '<style>
            #postdivrich, #postdiv, #slugdiv, #authordiv,
            #commentstatusdiv, #commentsdiv, #trackbacksdiv,
            #revisionsdiv, .misc-pub-section.misc-pub-post-status { display:none !important; }
            #titlediv { margin-bottom:0; }
            #title    { font-size:16px; padding:8px 11px; }
            .lbpdf-id-badge { display:inline-flex; align-items:center; gap:8px; background:#f0f4ff;
                border:1px solid #c7d7fa; border-radius:6px; padding:6px 12px; margin:8px 0 0;
                font-size:13px; color:#2563eb; font-family:monospace; }
            .lbpdf-id-badge strong { font-size:16px; }
        </style>';
    }

    // ── Meta boxes ────────────────────────────────────────────
    public function meta_boxes() {
        add_meta_box( 'lbpdf_pdf',     '📂 Archivo PDF',        array($this,'box_pdf'),     'flipbook','normal','high' );
        add_meta_box( 'lbpdf_opciones','⚙️ Opciones del visor', array($this,'box_opciones'), 'flipbook','normal','default' );
        add_meta_box( 'lbpdf_incrustar','🔗 Cómo incrustar',    array($this,'box_incrustar'),'flipbook','side','default' );
    }

    // ─────────────────────────────────────────────────────────
    // BOX 1: SELECTOR DE PDF
    // ─────────────────────────────────────────────────────────
    public function box_pdf( $post ) {
        wp_nonce_field( 'lbpdf_guardar', 'lbpdf_nonce' );
        $pdf_url = get_post_meta( $post->ID, '_fbm_pdf_url',           true );
        $pdf_id  = get_post_meta( $post->ID, '_fbm_pdf_attachment_id', true );
        $pdf_nom = $pdf_id ? get_the_title($pdf_id) : ( $pdf_url ? basename($pdf_url) : '' );
        $pdf_tam = $pdf_id ? $this->tamano_legible( filesize( get_attached_file($pdf_id) ?: '' ) ) : '';
        ?>
        <style>
        .lbpdf-zona { border:2px dashed #c3c4c7; border-radius:8px; background:#fafafa; transition:.2s; }
        .lbpdf-zona.vacia  { cursor:pointer; padding:24px 20px; }
        .lbpdf-zona.vacia:hover { border-color:#2271b1; background:#f0f6ff; }
        .lbpdf-zona.llena  { border-style:solid; border-color:#00a32a; background:#f0fdf4; padding:16px 18px; }
        .lbpdf-vacio { display:flex; flex-direction:column; align-items:center; gap:10px; text-align:center; color:#787c82; }
        .lbpdf-vacio-ico { font-size:40px; }
        .lbpdf-vacio p { margin:0; font-size:13px; }
        .lbpdf-llena { display:none; align-items:center; gap:14px; flex-wrap:wrap; }
        .lbpdf-llena.vis { display:flex; }
        .lbpdf-pdf-ico { font-size:42px; flex-shrink:0; }
        .lbpdf-pdf-info { flex:1; min-width:0; }
        .lbpdf-pdf-nom { font-weight:700; font-size:15px; color:#1d2327; word-break:break-word; }
        .lbpdf-pdf-meta { font-size:11px; color:#787c82; margin-top:3px; word-break:break-all; }
        .lbpdf-pdf-bts  { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }
        .lbpdf-bt { padding:5px 11px; font-size:12px; border-radius:5px; border:1px solid; cursor:pointer; background:#fff; line-height:1.4; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
        .lbpdf-bt-cambiar { border-color:#c3c4c7; color:#2271b1; } .lbpdf-bt-cambiar:hover { background:#f0f6ff; border-color:#2271b1; }
        .lbpdf-bt-ver     { border-color:#374151; color:#111827; } .lbpdf-bt-ver:hover     { background:#f3f4f6; }
        .lbpdf-bt-quitar  { border-color:#f0a500; color:#b45309; } .lbpdf-bt-quitar:hover  { background:#fff8e6; }
        .lbpdf-cors { background:#fffbe6; border-left:4px solid #f0b429; padding:8px 12px; border-radius:4px; font-size:12px; color:#7a5c00; margin-top:10px; }
        </style>

        <input type="hidden" id="lbpdf_url" name="lbpdf_pdf_url"    value="<?php echo esc_attr($pdf_url); ?>">
        <input type="hidden" id="lbpdf_aid" name="lbpdf_pdf_att_id" value="<?php echo esc_attr($pdf_id);  ?>">

        <div class="lbpdf-zona <?php echo $pdf_url ? 'llena':'vacia'; ?>" id="lbpdf-zona"
             <?php if(!$pdf_url): ?>onclick="lbpdfAbrir()"<?php endif; ?>>

            <div class="lbpdf-vacio" id="lbpdf-vacio" <?php echo $pdf_url ? 'style="display:none"':''; ?>>
                <div class="lbpdf-vacio-ico">📂</div>
                <p><strong>Haz clic para seleccionar un PDF de tu biblioteca</strong></p>
                <p style="font-size:12px;">También puedes subir uno nuevo directamente desde aquí.</p>
                <button type="button" class="button button-primary" onclick="event.stopPropagation();lbpdfAbrir()">
                    Seleccionar / Subir PDF
                </button>
            </div>

            <div class="lbpdf-llena <?php echo $pdf_url?'vis':''; ?>" id="lbpdf-llena">
                <div class="lbpdf-pdf-ico">📄</div>
                <div class="lbpdf-pdf-info">
                    <div class="lbpdf-pdf-nom" id="lbpdf-nom"><?php echo esc_html($pdf_nom); ?></div>
                    <div class="lbpdf-pdf-meta">
                        <span id="lbpdf-tam"><?php echo esc_html($pdf_tam); ?></span>
                        <span id="lbpdf-url-txt" style="display:block;margin-top:2px;"><?php echo esc_html($pdf_url); ?></span>
                    </div>
                    <div class="lbpdf-pdf-bts">
                        <button type="button" class="lbpdf-bt lbpdf-bt-cambiar" onclick="lbpdfAbrir()">🔄 Cambiar PDF</button>
                        <a id="lbpdf-ver" class="lbpdf-bt lbpdf-bt-ver" href="<?php echo esc_url($pdf_url); ?>" target="_blank">👁 Previsualizar</a>
                        <button type="button" class="lbpdf-bt lbpdf-bt-quitar" onclick="lbpdfQuitar()">✕ Quitar</button>
                    </div>
                </div>
            </div>
        </div>

        <p class="lbpdf-cors">⚠️ El PDF debe estar subido en <strong>este mismo sitio</strong>. Archivos de Google Drive, Dropbox u otros dominios no funcionan (restricción CORS del navegador).</p>

        <script>
        (function(){
            var up = null;
            window.lbpdfAbrir = function(){
                if (up){ up.open(); return; }
                up = wp.media({ title:'Seleccionar o subir PDF', button:{text:'Usar este PDF'}, library:{type:'application/pdf'}, multiple:false });
                up.on('select', function(){
                    var a = up.state().get('selection').first().toJSON();
                    lbpdfSet(a.id, a.url, a.title||a.filename, a.filesizeHumanReadable||'');
                });
                up.open();
            };
            function lbpdfSet(id,url,nom,tam){
                document.getElementById('lbpdf_url').value = url;
                document.getElementById('lbpdf_aid').value = id;
                document.getElementById('lbpdf-nom').textContent = nom;
                document.getElementById('lbpdf-url-txt').textContent = url;
                document.getElementById('lbpdf-tam').textContent = tam;
                document.getElementById('lbpdf-ver').href = url;
                document.getElementById('lbpdf-vacio').style.display = 'none';
                document.getElementById('lbpdf-llena').classList.add('vis');
                var z = document.getElementById('lbpdf-zona');
                z.classList.remove('vacia'); z.classList.add('llena'); z.onclick=null;
            }
            window.lbpdfQuitar = function(){
                document.getElementById('lbpdf_url').value = '';
                document.getElementById('lbpdf_aid').value = '';
                document.getElementById('lbpdf-vacio').style.display='';
                document.getElementById('lbpdf-llena').classList.remove('vis');
                var z = document.getElementById('lbpdf-zona');
                z.classList.remove('llena'); z.classList.add('vacia');
                z.onclick = function(){ lbpdfAbrir(); };
                up=null;
            };
        })();
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // BOX 2: OPCIONES
    // ─────────────────────────────────────────────────────────
    public function box_opciones( $post ) {
        $ancho    = get_post_meta($post->ID,'_fbm_ancho',true) ?: '900';
        $alto     = get_post_meta($post->ID,'_fbm_alto', true) ?: '600';
        $autoplay = get_post_meta($post->ID,'_fbm_autoplay',true);
        $descarga = get_post_meta($post->ID,'_fbm_permitir_descarga',true);
        if ($descarga==='') $descarga='1';
        ?>
        <style>
        .lbpdf-ops  { display:grid; grid-template-columns:1fr 1fr; gap:14px 20px; padding:4px 0; }
        @media(max-width:600px){ .lbpdf-ops{ grid-template-columns:1fr; } }
        .lbpdf-op   { display:flex; flex-direction:column; gap:5px; }
        .lbpdf-op label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#1d2327; }
        .lbpdf-op input[type=number]{ padding:6px 9px; border:1px solid #c3c4c7; border-radius:5px; font-size:14px; width:100%; }
        .lbpdf-op input:focus{ border-color:#2271b1; outline:none; box-shadow:0 0 0 2px rgba(34,113,177,.15); }
        .lbpdf-hint{ color:#787c82; font-size:11px; }
        .lbpdf-toggle { display:flex; align-items:flex-start; gap:9px; padding:9px 11px; border:1px solid #e2e4e7; border-radius:6px; cursor:pointer; transition:.15s; }
        .lbpdf-toggle:hover{ border-color:#2271b1; background:#f8faff; }
        .lbpdf-toggle input{ flex-shrink:0; margin-top:2px; }
        .lbpdf-toggle strong{ font-size:13px; display:block; }
        .lbpdf-toggle span  { font-size:11px; color:#787c82; }
        </style>
        <div class="lbpdf-ops">
            <div class="lbpdf-op">
                <label for="lbpdf_ancho">↔ Ancho del visor (px)</label>
                <input type="number" id="lbpdf_ancho" name="lbpdf_ancho" value="<?php echo esc_attr($ancho); ?>" min="300" max="1600" step="10">
                <span class="lbpdf-hint">Recomendado: 900 px</span>
            </div>
            <div class="lbpdf-op">
                <label for="lbpdf_alto">↕ Alto del visor (px)</label>
                <input type="number" id="lbpdf_alto" name="lbpdf_alto" value="<?php echo esc_attr($alto); ?>" min="200" max="1200" step="10">
                <span class="lbpdf-hint">Recomendado: 600 px</span>
            </div>
            <div class="lbpdf-op">
                <label style="margin-bottom:4px;">Comportamiento</label>
                <label class="lbpdf-toggle">
                    <input type="checkbox" name="lbpdf_autoplay" value="1" <?php checked($autoplay,'1'); ?>>
                    <div><strong>▶ Autoplay</strong><span>Pasa páginas cada 4 segundos automáticamente.</span></div>
                </label>
            </div>
            <div class="lbpdf-op">
                <label style="margin-bottom:4px;">Descarga</label>
                <label class="lbpdf-toggle">
                    <input type="checkbox" name="lbpdf_descarga" value="1" <?php checked($descarga,'1'); ?>>
                    <div><strong>⬇ Permitir descarga</strong><span>Muestra el botón de descarga en el visor.</span></div>
                </label>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // BOX 3: CÓMO INCRUSTAR (panel lateral)
    // ─────────────────────────────────────────────────────────
    public function box_incrustar( $post ) {
        $id = $post->ID;
        ?>
        <style>
        .lbpdf-sc   { background:#1d2327; color:#a8d8ff; padding:9px 12px; border-radius:6px; font-family:monospace; font-size:12px; word-break:break-all; margin-bottom:8px; }
        .lbpdf-copy { width:100%; padding:7px; font-size:12px; background:#2271b1; color:#fff; border:none; border-radius:5px; cursor:pointer; }
        .lbpdf-copy:hover{ background:#135e96; }
        .lbpdf-sec  { margin-bottom:16px; }
        .lbpdf-sec-t{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#1d2327; margin:0 0 6px; }
        .lbpdf-id-badge { display:flex; align-items:center; justify-content:center; background:#f0f4ff; border:1px solid #c7d7fa; border-radius:6px; padding:8px 12px; margin-bottom:14px; text-align:center; }
        .lbpdf-id-num { font-size:26px; font-weight:800; color:#2271b1; font-family:monospace; }
        .lbpdf-id-lbl { font-size:10px; color:#787c82; text-transform:uppercase; letter-spacing:.06em; margin-top:2px; }
        </style>

        <!-- ID único -->
        <div class="lbpdf-id-badge">
            <div>
                <div class="lbpdf-id-num"><?php echo $id; ?></div>
                <div class="lbpdf-id-lbl">ID único de este PDF</div>
            </div>
        </div>

        <!-- Shortcode -->
        <div class="lbpdf-sec">
            <p class="lbpdf-sec-t">Shortcode (WordPress)</p>
            <div class="lbpdf-sc">[leafbook id="<?php echo $id; ?>"]</div>
            <button class="lbpdf-copy" onclick="navigator.clipboard.writeText('[leafbook id=\'<?php echo $id; ?>\']');this.textContent='✅ ¡Copiado!';setTimeout(()=>this.textContent='📋 Copiar shortcode',1800)">
                📋 Copiar shortcode
            </button>
        </div>

        <!-- iFrame -->
        <div class="lbpdf-sec">
            <p class="lbpdf-sec-t">Código iframe (cualquier sitio)</p>
            <?php
            $ancho = get_post_meta($id,'_fbm_ancho',true) ?: 900;
            $alto  = get_post_meta($id,'_fbm_alto', true) ?: 600;
            // La URL embed incluye headers especiales para permitir iframes
            $url   = add_query_arg('lbpdf_embed', $id, home_url('/'));
            $iframe = '<iframe' . "
"
                . '  src="'.$url.'"' . "
"
                . '  width="'.$ancho.'"' . "
"
                . '  height="'.$alto.'"' . "
"
                . '  frameborder="0"' . "
"
                . '  allowfullscreen' . "
"
                . '  loading="lazy"' . "
"
                . '  style="border:none; display:block; max-width:100%;">' . "
"
                . '</iframe>';
            ?>
            <div class="lbpdf-sc"><?php echo esc_html($iframe); ?></div>
            <button class="lbpdf-copy" onclick="navigator.clipboard.writeText(<?php echo json_encode($iframe); ?>);this.textContent='✅ ¡Copiado!';setTimeout(()=>this.textContent='📋 Copiar iframe',1800)">
                📋 Copiar iframe
            </button>
            <p style="font-size:11px;color:#9ca3af;margin:8px 0 0;">
                💡 Esta URL incluye un proxy integrado que resuelve automáticamente los errores de CORS.
                Funciona en cualquier sitio externo.
            </p>
        </div>

        <p style="font-size:11px;color:#9ca3af;margin:0;">
            💡 También disponible como bloque Gutenberg — busca <strong>"Leafbook"</strong> en el editor.
        </p>
        <?php
    }

    // ── Guardar ───────────────────────────────────────────────
    public function guardar( $pid ) {
        if (!isset($_POST['lbpdf_nonce']) || !wp_verify_nonce($_POST['lbpdf_nonce'],'lbpdf_guardar')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post',$pid)) return;

        if (isset($_POST['lbpdf_pdf_url']))    update_post_meta($pid,'_fbm_pdf_url',          esc_url_raw($_POST['lbpdf_pdf_url']));
        if (isset($_POST['lbpdf_pdf_att_id'])) update_post_meta($pid,'_fbm_pdf_attachment_id',absint($_POST['lbpdf_pdf_att_id']));
        if (isset($_POST['lbpdf_ancho']))       update_post_meta($pid,'_fbm_ancho',            absint($_POST['lbpdf_ancho']));
        if (isset($_POST['lbpdf_alto']))        update_post_meta($pid,'_fbm_alto',             absint($_POST['lbpdf_alto']));
        update_post_meta($pid,'_fbm_autoplay',         isset($_POST['lbpdf_autoplay']) ?'1':'0');
        update_post_meta($pid,'_fbm_permitir_descarga',isset($_POST['lbpdf_descarga']) ?'1':'0');
    }

    // ── Assets ────────────────────────────────────────────────
    public function assets_admin( $hook ) {
        global $post;
        if (in_array($hook,array('post.php','post-new.php'))
            && isset($post->post_type) && $post->post_type==='flipbook')
            wp_enqueue_media();
    }

    // ── Mensajes personalizados ───────────────────────────────
    public function mensajes( $msgs ) {
        global $post;
        $msgs['flipbook'] = array(
            0  => '',
            1  => 'PDF actualizado. <a href="' . esc_url(get_permalink($post->ID)) . '">Ver</a>',
            4  => 'PDF actualizado.',
            6  => 'PDF guardado.',
            7  => 'PDF guardado.',
        );
        return $msgs;
    }

    // ── Columnas en la lista ──────────────────────────────────
    public function columnas( $cols ) {
        return array(
            'cb'              => $cols['cb'],
            'title'           => '📄 Nombre del PDF',
            'lbpdf_grupo'     => '🗂 Grupo',
            'lbpdf_id'        => '🆔 ID',
            'lbpdf_shortcode' => '🔗 Shortcode',
            'lbpdf_pdf'       => '📎 Archivo',
            'date'            => 'Agregado',
        );
    }

    public function render_col( $col, $pid ) {
        switch ($col) {
            case 'lbpdf_id':
                echo '<strong style="font-size:18px;color:#2271b1;font-family:monospace;">' . $pid . '</strong>';
                break;
            case 'lbpdf_shortcode':
                echo '<code style="background:#f0f4ff;color:#2563eb;padding:3px 7px;border-radius:4px;font-size:11px;">[leafbook id="'.$pid.'"]</code>';
                break;
            case 'lbpdf_pdf':
                $url = get_post_meta($pid,'_fbm_pdf_url',true);
                if ($url) {
                    $nom = basename($url);
                    echo '<a href="'.esc_url($url).'" target="_blank" style="font-size:12px;color:#2271b1;">'.esc_html(substr($nom,0,24).(strlen($nom)>24?'…':'')).'</a>';
                } else {
                    echo '<span style="color:#f0a500;font-size:12px;">⚠ Sin PDF</span>';
                }
                break;
        }
    }

    // ── Helper tamaño de archivo ──────────────────────────────
    private function tamano_legible( $bytes ) {
        if (!$bytes) return '';
        if ($bytes >= 1048576) return round($bytes/1048576,1) . ' MB';
        if ($bytes >= 1024)    return round($bytes/1024,1)    . ' KB';
        return $bytes . ' B';
    }
}
