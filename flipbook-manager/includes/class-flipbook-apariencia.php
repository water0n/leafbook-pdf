<?php
/**
 * class-flipbook-apariencia.php
 *
 * Sistema de apariencia por PDF + presets guardados.
 *
 * Qué hace:
 *  1. Meta box en cada PDF con todos los toggles de botones e info
 *  2. Sección de colores/fondo individual por PDF
 *  3. Presets guardados (guardar / aplicar / borrar) vía AJAX
 *  4. Fallback: si un PDF no tiene config propia, usa los globales de Ajustes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Flipbook_Apariencia {

    const META_KEY    = '_lbpdf_apariencia';   // Meta por PDF
    const PRESETS_KEY = 'lbpdf_presets';        // wp_options → array de presets

    // ── Valores por defecto ────────────────────────────────────
    public static function defaults() {
        return array(
            // Botones
            'btn_primera'    => '1',
            'btn_ultima'     => '1',
            'btn_anterior'   => '1',
            'btn_zoom'       => '1',
            'btn_miniaturas' => '1',
            'btn_buscar'     => '1',
            'btn_compartir'  => '1',
            'btn_imprimir'   => '1',
            'btn_descarga'   => '1',
            'btn_fullscreen' => '1',
            // Info a mostrar
            'mostrar_titulo'    => '0',
            'mostrar_categoria' => '0',
            'mostrar_autor'     => '0',
            // Tema de botones
            'tema_botones'     => 'oscuro',   // oscuro | claro | azul | verde | rojo | transparente
            // Apariencia visual
            'fondo_tipo'       => 'color',
            'fondo_color'      => '#1a2234',
            'fondo_color2'     => '#111827',
            'fondo_imagen_url' => '',
            'color_barra'      => '#0f172a',
            'color_botones'    => '#2a3547',
            'color_btn_texto'  => '#d1d5db',
            'radio_bordes'     => '12',
            'sombra'           => '1',
        );
    }

    // ── Obtiene la config de un PDF (merge defaults) ───────────
    public static function get( $post_id ) {
        $globales = get_option( class_exists('Flipbook_Settings') ? Flipbook_Settings::OPCION : 'lbpdf_ajustes', array() );
        $guardado = get_post_meta( $post_id, self::META_KEY, true );
        if ( ! is_array($globales) ) $globales = array();
        if ( ! is_array($guardado) ) $guardado = array();
        return array_merge( self::defaults(), $globales, $guardado );
    }

    public function register() {
        add_action( 'add_meta_boxes',         array( $this, 'meta_box'   ) );
        add_action( 'save_post_flipbook',      array( $this, 'guardar'    ) );
        add_action( 'wp_ajax_lbpdf_presets',   array( $this, 'ajax_presets' ) );
    }

    // ── Meta box ───────────────────────────────────────────────
    public function meta_box() {
        add_meta_box(
            'lbpdf_apariencia',
            '🎨 Apariencia y botones',
            array( $this, 'render' ),
            'flipbook', 'normal', 'default'
        );
    }

    // ── Render del meta box ────────────────────────────────────
    public function render( $post ) {
        wp_nonce_field( 'lbpdf_apariencia_guardar', 'lbpdf_ap_nonce' );
        $cfg      = self::get( $post->ID );
        $presets  = get_option( self::PRESETS_KEY, array() );

        // Definición de todos los toggles
        $toggles_botones = array(
            'btn_primera'    => array( '⏮', 'Primera página'   ),
            'btn_anterior'   => array( '◀', 'Anterior / Siguiente' ),
            'btn_ultima'     => array( '⏭', 'Última página'    ),
            'btn_zoom'       => array( '🔍', 'Zoom + / −'       ),
            'btn_miniaturas' => array( '⊞', 'Miniaturas'       ),
            'btn_buscar'     => array( '🔎', 'Buscar en PDF'    ),
            'btn_compartir'  => array( '↗', 'Compartir'        ),
            'btn_imprimir'   => array( '🖨', 'Imprimir'         ),
            'btn_descarga'   => array( '⬇', 'Descarga'         ),
            'btn_fullscreen' => array( '⛶', 'Pantalla completa'),
        );

        $toggles_info = array(
            'mostrar_titulo'    => 'Mostrar título del PDF',
            'mostrar_categoria' => 'Mostrar grupo / categoría',
            'mostrar_autor'     => 'Mostrar autor',
        );
        ?>
        <style>
        .lap-wrap        { padding:2px 0 6px; }
        .lap-section     { margin-bottom:20px; }
        .lap-sec-title   { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
                           color:#1d2327; margin:0 0 10px; padding-bottom:6px; border-bottom:2px solid #f0f0f0; }

        /* ── Preset bar ── */
        .lap-preset-bar  { display:flex; gap:8px; align-items:center; flex-wrap:wrap;
                           padding:10px 14px; background:#f6f7f7; border-radius:8px; margin-bottom:18px; }
        .lap-preset-bar label { font-size:12px; font-weight:600; color:#1d2327; white-space:nowrap; }
        .lap-preset-sel  { flex:1; min-width:140px; padding:6px 9px; border:1px solid #c3c4c7; border-radius:5px; font-size:13px; }
        .lap-btn         { padding:6px 12px; font-size:12px; border-radius:5px; border:1px solid; cursor:pointer; white-space:nowrap; }
        .lap-btn-apply   { background:#2271b1; color:#fff; border-color:#2271b1; }
        .lap-btn-apply:hover { background:#135e96; }
        .lap-btn-save    { background:#fff; color:#2271b1; border-color:#2271b1; }
        .lap-btn-save:hover  { background:#f0f6ff; }
        .lap-btn-del     { background:#fff; color:#cc1818; border-color:#cc1818; }
        .lap-btn-del:hover   { background:#fff5f5; }

        /* ── Toggles grid ── */
        .lap-toggles     { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:8px; }
        .lap-toggle      { display:flex; align-items:center; gap:10px; padding:9px 11px;
                           border:1px solid #e2e4e7; border-radius:7px; cursor:pointer;
                           transition:border-color .15s, background .15s; user-select:none; }
        .lap-toggle:hover{ border-color:#2271b1; background:#f8faff; }
        .lap-toggle input{ display:none; }
        .lap-sw          { width:36px; height:20px; background:#c3c4c7; border-radius:10px; flex-shrink:0;
                           position:relative; transition:background .2s; }
        .lap-sw::after   { content:''; position:absolute; top:3px; left:3px; width:14px; height:14px;
                           background:#fff; border-radius:50%; transition:left .2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
        .lap-toggle input:checked ~ .lap-sw              { background:#2271b1; }
        .lap-toggle input:checked ~ .lap-sw::after       { left:19px; }
        .lap-toggle-ico  { font-size:16px; flex-shrink:0; width:20px; text-align:center; }
        .lap-toggle-lbl  { font-size:13px; color:#1d2327; }

        /* ── Colores ── */
        .lap-colores     { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; }
        .lap-color-item  { display:flex; flex-direction:column; gap:4px; }
        .lap-color-item label { font-size:11px; font-weight:600; color:#50575e; }
        .lap-color-row   { display:flex; align-items:center; gap:8px; }
        .lap-color-row input[type=color] { width:36px; height:32px; padding:2px; border:1px solid #c3c4c7; border-radius:5px; cursor:pointer; }
        .lap-color-row input[type=text]  { flex:1; padding:5px 8px; border:1px solid #c3c4c7; border-radius:5px; font-size:12px; font-family:monospace; }

        .lap-range-item  { display:flex; flex-direction:column; gap:4px; }
        .lap-range-item label { font-size:11px; font-weight:600; color:#50575e; }
        .lap-range-row   { display:flex; align-items:center; gap:8px; }
        .lap-range-row input[type=range] { flex:1; }
        .lap-range-val   { font-size:12px; color:#1d2327; min-width:28px; }

        /* Fondo tipo select */
        .lap-fondo-tipo  { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
        .lap-radio-opt   { flex:1; min-width:80px; }
        .lap-radio-opt input { display:none; }
        .lap-radio-opt label { display:flex; flex-direction:column; align-items:center; gap:4px;
                               padding:10px 8px; border:2px solid #e2e4e7; border-radius:7px;
                               cursor:pointer; font-size:12px; text-align:center; transition:.15s; }
        .lap-radio-opt label:hover { border-color:#93c5fd; background:#f0f6ff; }
        .lap-radio-opt input:checked + label { border-color:#2271b1; background:#eff6ff; color:#1d4ed8; font-weight:600; }
        .lap-radio-ico   { font-size:20px; }

        .lap-fila-condicional { display:none; }
        .lap-fila-condicional.vis { display:block; }

        /* Modal preset */
        .lap-modal-bg    { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:99999; align-items:center; justify-content:center; }
        .lap-modal-bg.vis{ display:flex; }
        .lap-modal       { background:#fff; border-radius:10px; padding:24px 28px; width:340px; max-width:95vw; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .lap-modal h3    { margin:0 0 14px; font-size:16px; }
        .lap-modal input[type=text] { width:100%; padding:8px 10px; border:1px solid #c3c4c7; border-radius:5px; font-size:14px; margin-bottom:12px; }
        .lap-modal-bts   { display:flex; gap:8px; justify-content:flex-end; }
        .lap-modal-cancel{ padding:7px 14px; font-size:13px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:5px; cursor:pointer; }
        .lap-modal-ok    { padding:7px 14px; font-size:13px; background:#2271b1; color:#fff; border:none; border-radius:5px; cursor:pointer; }
        </style>

        <div class="lap-wrap">

            <!-- ══ PRESETS ══════════════════════════════════════ -->
            <div class="lap-preset-bar">
                <label>Preset guardado:</label>
                <select class="lap-preset-sel" id="lap-preset-sel">
                    <option value="">— Ninguno (config individual) —</option>
                    <?php foreach ( $presets as $p ) :
                        $sel = isset($cfg['_preset_id']) && $cfg['_preset_id'] === $p['id'];
                    ?>
                        <option value="<?php echo esc_attr($p['id']); ?>" <?php selected($sel); ?>>
                            <?php echo esc_html($p['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="lap-btn lap-btn-apply" onclick="lapAplicarPreset()">
                    ✅ Aplicar
                </button>
                <button type="button" class="lap-btn lap-btn-save" onclick="lapAbrirModal()">
                    💾 Guardar como preset
                </button>
                <button type="button" class="lap-btn lap-btn-del" onclick="lapBorrarPreset()" id="lap-btn-del" style="display:none">
                    🗑 Borrar preset
                </button>
                <input type="hidden" name="lbpdf_ap[_preset_id]" id="lap-preset-id-field" value="<?php echo esc_attr($cfg['_preset_id'] ?? ''); ?>">
            </div>

            <!-- ══ BOTONES VISIBLES ═══════════════════════════════ -->
            <div class="lap-section">
                <p class="lap-sec-title">Botones visibles en el visor</p>
                <div class="lap-toggles">
                    <?php foreach ( $toggles_botones as $key => list($ico, $label) ) : ?>
                    <label class="lap-toggle">
                        <input type="checkbox" name="lbpdf_ap[<?php echo $key; ?>]" value="1"
                               id="lap-<?php echo $key; ?>"
                               <?php checked( $cfg[$key], '1' ); ?>>
                        <span class="lap-sw"></span>
                        <span class="lap-toggle-ico"><?php echo $ico; ?></span>
                        <span class="lap-toggle-lbl"><?php echo $label; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ══ INFORMACIÓN A MOSTRAR ════════════════════════ -->
            <div class="lap-section">
                <p class="lap-sec-title">Información a mostrar sobre el visor</p>
                <div class="lap-toggles">
                    <?php foreach ( $toggles_info as $key => $label ) : ?>
                    <label class="lap-toggle">
                        <input type="checkbox" name="lbpdf_ap[<?php echo $key; ?>]" value="1"
                               <?php checked( $cfg[$key], '1' ); ?>>
                        <span class="lap-sw"></span>
                        <span class="lap-toggle-ico">ℹ</span>
                        <span class="lap-toggle-lbl"><?php echo $label; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ══ APARIENCIA VISUAL ════════════════════════════ -->
            <div class="lap-section">
                <p class="lap-sec-title">Apariencia visual</p>

                <!-- ── Tema de botones ── -->
                <div style="margin-bottom:18px;">
                    <p style="font-size:11px;font-weight:700;color:#50575e;margin:0 0 10px;text-transform:uppercase;letter-spacing:.05em;">Tema de botones</p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;" id="lap-temas-wrap">
                        <?php
                        $temas = array(
                            'oscuro'       => array('#0f172a', '#d1d5db', 'Oscuro'),
                            'claro'        => array('#f1f5f9', '#374151', 'Claro'),
                            'azul'         => array('#1e3a5f', '#bfdbfe', 'Azul'),
                            'verde'        => array('#14532d', '#bbf7d0', 'Verde'),
                            'rojo'         => array('#7f1d1d', '#fecaca', 'Rojo'),
                            'transparente' => array('transparent', '#e2e8f0', 'Mínimo'),
                        );
                        $tema_actual = $cfg['tema_botones'] ?? 'oscuro';
                        foreach ($temas as $slug => list($bg, $txt, $nombre)): ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="lbpdf_ap[tema_botones]" value="<?php echo $slug; ?>"
                                   <?php checked($tema_actual, $slug); ?>
                                   style="display:none;" onchange="lapAplicarTema(this)">
                            <span class="lap-tema-opt <?php echo $tema_actual===$slug ? 'activo' : ''; ?>"
                                  style="display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:7px;border:2px solid <?php echo $tema_actual===$slug ? '#2271b1' : '#e2e4e7'; ?>;background:<?php echo $bg; ?>;color:<?php echo $txt; ?>;font-size:12px;font-weight:600;transition:.15s;user-select:none;">
                                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo $txt; ?>;"></span>
                                <?php echo $nombre; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size:11px;color:#787c82;margin:6px 0 0;">El tema aplica colores predefinidos a la barra y los botones. Puedes sobreescribir los colores abajo.</p>
                </div>

                <!-- Tipo de fondo -->
                <div style="margin-bottom:10px;">
                    <p style="font-size:11px;font-weight:600;color:#50575e;margin:0 0 8px;text-transform:uppercase;letter-spacing:.05em;">Tipo de fondo</p>
                    <div class="lap-fondo-tipo">
                        <?php
                        $tipos = array(
                            'color'     => array('🟦', 'Color sólido'),
                            'degradado' => array('🌈', 'Degradado'),
                            'imagen'    => array('🖼', 'Imagen'),
                            'sin_fondo' => array('⬜', 'Sin fondo'),
                        );
                        foreach ( $tipos as $val => list($ico, $lbl) ) : ?>
                        <div class="lap-radio-opt">
                            <input type="radio" name="lbpdf_ap[fondo_tipo]"
                                   id="lap-ft-<?php echo $val; ?>"
                                   value="<?php echo $val; ?>"
                                   <?php checked($cfg['fondo_tipo'], $val); ?>
                                   onchange="lapToggleFondo()">
                            <label for="lap-ft-<?php echo $val; ?>">
                                <span class="lap-radio-ico"><?php echo $ico; ?></span>
                                <?php echo $lbl; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="lap-colores">
                    <!-- Color principal -->
                    <div class="lap-color-item lap-fila-condicional <?php echo $cfg['fondo_tipo']!=='sin_fondo'?'vis':''; ?>" id="lap-fila-c1">
                        <label>Color del visor</label>
                        <div class="lap-color-row">
                            <input type="color" id="lap-c-fondo" value="<?php echo esc_attr($cfg['fondo_color']); ?>"
                                   oninput="document.getElementById('lap-t-fondo').value=this.value">
                            <input type="text"  id="lap-t-fondo" name="lbpdf_ap[fondo_color]"
                                   value="<?php echo esc_attr($cfg['fondo_color']); ?>"
                                   oninput="document.getElementById('lap-c-fondo').value=this.value" maxlength="7">
                        </div>
                    </div>

                    <!-- Color 2 (degradado) -->
                    <div class="lap-color-item lap-fila-condicional <?php echo $cfg['fondo_tipo']==='degradado'?'vis':''; ?>" id="lap-fila-c2">
                        <label>Color 2 (degradado)</label>
                        <div class="lap-color-row">
                            <input type="color" id="lap-c-fondo2" value="<?php echo esc_attr($cfg['fondo_color2']); ?>"
                                   oninput="document.getElementById('lap-t-fondo2').value=this.value">
                            <input type="text"  id="lap-t-fondo2" name="lbpdf_ap[fondo_color2]"
                                   value="<?php echo esc_attr($cfg['fondo_color2']); ?>"
                                   oninput="document.getElementById('lap-c-fondo2').value=this.value" maxlength="7">
                        </div>
                    </div>

                    <!-- Color barra -->
                    <div class="lap-color-item">
                        <label>Color barra de controles</label>
                        <div class="lap-color-row">
                            <input type="color" id="lap-c-barra" value="<?php echo esc_attr($cfg['color_barra']); ?>"
                                   oninput="document.getElementById('lap-t-barra').value=this.value">
                            <input type="text"  id="lap-t-barra" name="lbpdf_ap[color_barra]"
                                   value="<?php echo esc_attr($cfg['color_barra']); ?>"
                                   oninput="document.getElementById('lap-c-barra').value=this.value" maxlength="7">
                        </div>
                    </div>

                    <!-- Color botones -->
                    <div class="lap-color-item">
                        <label>Fondo de botones</label>
                        <div class="lap-color-row">
                            <input type="color" id="lap-c-btn" value="<?php echo esc_attr($cfg['color_botones']); ?>"
                                   oninput="document.getElementById('lap-t-btn').value=this.value">
                            <input type="text"  id="lap-t-btn" name="lbpdf_ap[color_botones]"
                                   value="<?php echo esc_attr($cfg['color_botones']); ?>"
                                   oninput="document.getElementById('lap-c-btn').value=this.value" maxlength="7">
                        </div>
                    </div>

                    <!-- Color texto botones -->
                    <div class="lap-color-item">
                        <label>Texto de botones</label>
                        <div class="lap-color-row">
                            <input type="color" id="lap-c-btntx" value="<?php echo esc_attr($cfg['color_btn_texto']); ?>"
                                   oninput="document.getElementById('lap-t-btntx').value=this.value">
                            <input type="text"  id="lap-t-btntx" name="lbpdf_ap[color_btn_texto]"
                                   value="<?php echo esc_attr($cfg['color_btn_texto']); ?>"
                                   oninput="document.getElementById('lap-c-btntx').value=this.value" maxlength="7">
                        </div>
                    </div>

                    <!-- Bordes -->
                    <div class="lap-range-item">
                        <label>Redondez de bordes</label>
                        <div class="lap-range-row">
                            <input type="range" name="lbpdf_ap[radio_bordes]"
                                   value="<?php echo esc_attr($cfg['radio_bordes']); ?>"
                                   min="0" max="30" step="1"
                                   oninput="this.nextElementSibling.textContent=this.value+'px'">
                            <span class="lap-range-val"><?php echo esc_attr($cfg['radio_bordes']); ?>px</span>
                        </div>
                    </div>
                </div>

                <!-- URL imagen de fondo -->
                <div class="lap-fila-condicional <?php echo $cfg['fondo_tipo']==='imagen'?'vis':''; ?>" id="lap-fila-img" style="margin-top:12px;">
                    <label style="font-size:11px;font-weight:600;color:#50575e;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">URL imagen de fondo</label>
                    <input type="url" name="lbpdf_ap[fondo_imagen_url]"
                           value="<?php echo esc_attr($cfg['fondo_imagen_url']); ?>"
                           placeholder="https://tusitio.com/wp-content/uploads/fondo.jpg"
                           style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:5px;font-size:13px;">
                </div>

                <!-- Sombra -->
                <div style="margin-top:14px;">
                    <label class="lap-toggle" style="max-width:220px;">
                        <input type="checkbox" name="lbpdf_ap[sombra]" value="1" <?php checked($cfg['sombra'],'1'); ?>>
                        <span class="lap-sw"></span>
                        <span class="lap-toggle-ico">🌑</span>
                        <span class="lap-toggle-lbl">Sombra exterior</span>
                    </label>
                </div>
            </div>

        </div><!-- .lap-wrap -->

        <!-- ══ MODAL: Guardar preset ════════════════════════════ -->
        <div class="lap-modal-bg" id="lap-modal">
            <div class="lap-modal">
                <h3>💾 Guardar como preset</h3>
                <p style="font-size:13px;color:#787c82;margin:0 0 12px;">
                    Dale un nombre para identificarlo (ej: "Revista oscura", "Catálogo claro").
                </p>
                <input type="text" id="lap-modal-nombre" placeholder="Nombre del preset…" maxlength="60">
                <div class="lap-modal-bts">
                    <button type="button" class="lap-modal-cancel" onclick="lapCerrarModal()">Cancelar</button>
                    <button type="button" class="lap-modal-ok"     onclick="lapGuardarPreset()">Guardar</button>
                </div>
            </div>
        </div>

        <script>
        (function(){

            var nonce = '<?php echo wp_create_nonce('lbpdf_presets_nonce'); ?>';
            var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

            // ── Toggle filas de fondo ──────────────────────────
            // ── Aplicar tema: cambia los colores según el tema elegido ──
            window.lapAplicarTema = function(radio) {
                var temas = {
                    oscuro:       { barra:'#0f172a', botones:'#1e293b', texto:'#d1d5db' },
                    claro:        { barra:'#f1f5f9', botones:'#e2e8f0', texto:'#374151' },
                    azul:         { barra:'#1e3a5f', botones:'#1d4ed8', texto:'#bfdbfe' },
                    verde:        { barra:'#14532d', botones:'#15803d', texto:'#bbf7d0' },
                    rojo:         { barra:'#7f1d1d', botones:'#b91c1c', texto:'#fecaca' },
                    transparente: { barra:'transparent', botones:'rgba(255,255,255,0.06)', texto:'#e2e8f0' },
                };
                var t = temas[radio.value];
                if (!t) return;

                // Actualiza los pickers de color
                var setColor = function(cId, tId, val) {
                    var c = document.getElementById(cId), tx = document.getElementById(tId);
                    if(c) c.value = val.indexOf('#')===0 ? val : '#000000';
                    if(tx) tx.value = val;
                };
                setColor('lap-c-barra',  'lap-t-barra',  t.barra);
                setColor('lap-c-btn',    'lap-t-btn',    t.botones);
                setColor('lap-c-btntx',  'lap-t-btntx',  t.texto);

                // Actualiza estilo visual de las opciones de tema
                document.querySelectorAll('#lap-temas-wrap .lap-tema-opt').forEach(function(el){
                    el.style.border = '2px solid #e2e4e7';
                });
                var span = radio.nextElementSibling;
                if (span) span.style.border = '2px solid #2271b1';
            };

            window.lapToggleFondo = function() {
                var tipo = document.querySelector('input[name="lbpdf_ap[fondo_tipo]"]:checked');
                if (!tipo) return;
                document.getElementById('lap-fila-c1').classList.toggle('vis', tipo.value !== 'sin_fondo');
                document.getElementById('lap-fila-c2').classList.toggle('vis', tipo.value === 'degradado');
                document.getElementById('lap-fila-img').classList.toggle('vis', tipo.value === 'imagen');
            };

            // ── Mostrar/ocultar botón borrar según preset ──────
            var sel = document.getElementById('lap-preset-sel');
            if (sel) sel.addEventListener('change', function(){
                document.getElementById('lap-btn-del').style.display = this.value ? '' : 'none';
            });
            // Estado inicial
            if (sel && sel.value) document.getElementById('lap-btn-del').style.display = '';

            // ── Aplicar preset: carga sus valores en el form ──
            window.lapAplicarPreset = function() {
                var id = document.getElementById('lap-preset-sel').value;
                if (!id) { alert('Elige un preset de la lista.'); return; }

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=lbpdf_presets&op=get&id=' + encodeURIComponent(id) + '&_nonce=' + nonce
                })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d.success) { alert(d.data || 'Error'); return; }
                    lapCargarEnForm(d.data.settings);
                    document.getElementById('lap-preset-id-field').value = id;
                    alert('✅ Preset "' + d.data.nombre + '" aplicado. Guarda el PDF para confirmar.');
                });
            };

            // ── Llena el formulario con un objeto de settings ─
            function lapCargarEnForm(s) {
                var map = {
                    'fondo_color':      ['lap-c-fondo',  'lap-t-fondo'],
                    'fondo_color2':     ['lap-c-fondo2', 'lap-t-fondo2'],
                    'color_barra':      ['lap-c-barra',  'lap-t-barra'],
                    'color_botones':    ['lap-c-btn',    'lap-t-btn'],
                    'color_btn_texto':  ['lap-c-btntx',  'lap-t-btntx'],
                };
                Object.keys(s).forEach(function(k){
                    var v = s[k];
                    // Colores
                    if (map[k]) {
                        var c = document.getElementById(map[k][0]);
                        var t = document.getElementById(map[k][1]);
                        if (c) c.value = v;
                        if (t) t.value = v;
                    }
                    // Checkboxes / toggles
                    var cb = document.getElementById('lap-' + k);
                    if (cb && cb.type === 'checkbox') cb.checked = (v === '1');
                    // Radio fondo_tipo
                    if (k === 'fondo_tipo') {
                        var r = document.getElementById('lap-ft-' + v);
                        if (r) { r.checked = true; lapToggleFondo(); }
                    }
                    // Tema de botones
                    if (k === 'tema_botones') {
                        var tema = document.querySelector('input[name="lbpdf_ap[tema_botones]"][value="' + v + '"]');
                        if (tema) { tema.checked = true; lapAplicarTema(tema); }
                    }
                    // Range radio_bordes
                    if (k === 'radio_bordes') {
                        var rng = document.querySelector('input[name="lbpdf_ap[radio_bordes]"]');
                        if (rng) { rng.value = v; rng.nextElementSibling.textContent = v + 'px'; }
                    }
                    // URL imagen
                    if (k === 'fondo_imagen_url') {
                        var u = document.querySelector('input[name="lbpdf_ap[fondo_imagen_url]"]');
                        if (u) u.value = v;
                    }
                    // Sombra
                    if (k === 'sombra') {
                        var sc = document.querySelector('input[name="lbpdf_ap[sombra]"]');
                        if (sc) sc.checked = (v === '1');
                    }
                });
            }

            // ── Modal ─────────────────────────────────────────
            window.lapAbrirModal  = function() { document.getElementById('lap-modal').classList.add('vis'); document.getElementById('lap-modal-nombre').focus(); };
            window.lapCerrarModal = function() { document.getElementById('lap-modal').classList.remove('vis'); };

            // Cerrar con Esc
            document.addEventListener('keydown', function(e){ if (e.key==='Escape') lapCerrarModal(); });

            // ── Guardar preset (AJAX) ─────────────────────────
            window.lapGuardarPreset = function() {
                var nombre = document.getElementById('lap-modal-nombre').value.trim();
                if (!nombre) { alert('Escribe un nombre.'); return; }

                // Recolecta todos los valores actuales del form
                var settings = lapRecolectarSettings();

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=lbpdf_presets&op=save&nombre=' + encodeURIComponent(nombre)
                        + '&settings=' + encodeURIComponent(JSON.stringify(settings))
                        + '&_nonce=' + nonce
                })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d.success) { alert(d.data || 'Error'); return; }
                    lapCerrarModal();
                    // Agrega al select
                    var opt = document.createElement('option');
                    opt.value = d.data.id; opt.textContent = nombre;
                    opt.selected = true;
                    document.getElementById('lap-preset-sel').appendChild(opt);
                    document.getElementById('lap-preset-id-field').value = d.data.id;
                    document.getElementById('lap-btn-del').style.display = '';
                    alert('✅ Preset "' + nombre + '" guardado correctamente.');
                });
            };

            // ── Borrar preset ─────────────────────────────────
            window.lapBorrarPreset = function() {
                var id = document.getElementById('lap-preset-sel').value;
                if (!id) return;
                if (!confirm('¿Borrar este preset? Los PDFs que lo usan no se verán afectados.')) return;

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=lbpdf_presets&op=delete&id=' + encodeURIComponent(id) + '&_nonce=' + nonce
                })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d.success) { alert(d.data || 'Error'); return; }
                    var sel = document.getElementById('lap-preset-sel');
                    sel.querySelector('option[value="' + id + '"]').remove();
                    sel.value = '';
                    document.getElementById('lap-btn-del').style.display = 'none';
                    document.getElementById('lap-preset-id-field').value = '';
                    alert('🗑 Preset borrado.');
                });
            };

            // ── Recolecta todos los valores del form ──────────
            function lapRecolectarSettings() {
                var s = {};
                document.querySelectorAll('[name^="lbpdf_ap["]').forEach(function(el){
                    var m = el.name.match(/\[([^\]]+)\]/);
                    if (!m) return;
                    var k = m[1];
                    if (k === '_preset_id') return;
                    if (el.type === 'checkbox')  s[k] = el.checked ? '1' : '0';
                    else if (el.type === 'radio') { if (el.checked) s[k] = el.value; }
                    else s[k] = el.value;
                });
                return s;
            }

        })();
        </script>
        <?php
    }

    // ── Guardar meta ───────────────────────────────────────────
    public function guardar( $post_id ) {
        if ( ! isset($_POST['lbpdf_ap_nonce']) || ! wp_verify_nonce($_POST['lbpdf_ap_nonce'], 'lbpdf_apariencia_guardar') ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;

        if ( ! isset($_POST['lbpdf_ap']) || ! is_array($_POST['lbpdf_ap']) ) return;

        $raw = $_POST['lbpdf_ap'];
        $def = self::defaults();
        $clean = array();

        // Colores hex
        foreach ( array('fondo_color','fondo_color2','color_barra','color_botones','color_btn_texto') as $k ) {
            $clean[$k] = isset($raw[$k]) ? sanitize_hex_color($raw[$k]) ?? $def[$k] : $def[$k];
        }
        // Textos simples
        $clean['fondo_tipo'] = isset($raw['fondo_tipo']) && in_array($raw['fondo_tipo'], array('color', 'degradado', 'imagen', 'sin_fondo'), true ) ? $raw['fondo_tipo'] : 'color';
        $clean['tema_botones'] = isset($raw['tema_botones']) && in_array($raw['tema_botones'], array('oscuro', 'claro', 'azul', 'verde', 'rojo', 'transparente'), true ) ? $raw['tema_botones'] : $def['tema_botones'];
        // URL
        $clean['fondo_imagen_url'] = isset($raw['fondo_imagen_url']) ? esc_url_raw($raw['fondo_imagen_url']) : '';
        // Número
        $clean['radio_bordes'] = isset($raw['radio_bordes']) ? min(30, max(0, intval($raw['radio_bordes']))) : 12;
        // Preset
        $clean['_preset_id'] = isset($raw['_preset_id']) ? sanitize_text_field($raw['_preset_id']) : '';

        // Checkboxes (todos los toggles)
        $toggles = array('btn_primera','btn_ultima','btn_anterior','btn_zoom','btn_miniaturas',
                         'btn_buscar','btn_compartir','btn_imprimir','btn_descarga','btn_fullscreen',
                         'mostrar_titulo','mostrar_categoria','mostrar_autor','sombra');
        foreach ( $toggles as $k ) {
            $clean[$k] = isset($raw[$k]) && $raw[$k] === '1' ? '1' : '0';
        }

        update_post_meta( $post_id, self::META_KEY, $clean );
    }

    // ── AJAX: Gestión de presets ───────────────────────────────
    public function ajax_presets() {
        if ( ! check_ajax_referer('lbpdf_presets_nonce', '_nonce', false) ) {
            wp_send_json_error('Seguridad: token inválido.'); return;
        }
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('Sin permisos.'); return;
        }

        $op = sanitize_text_field($_POST['op'] ?? '');

        switch ( $op ) {
            case 'get':
                $id      = sanitize_text_field($_POST['id'] ?? '');
                $presets = get_option(self::PRESETS_KEY, array());
                $preset  = null;
                foreach ($presets as $p) { if ($p['id'] === $id) { $preset = $p; break; } }
                if (!$preset) { wp_send_json_error('Preset no encontrado.'); return; }
                wp_send_json_success($preset);
                break;

            case 'save':
                $nombre   = sanitize_text_field($_POST['nombre'] ?? '');
                $settings = json_decode(stripslashes($_POST['settings'] ?? '{}'), true);
                if (!$nombre || !is_array($settings)) { wp_send_json_error('Datos inválidos.'); return; }
                $presets = get_option(self::PRESETS_KEY, array());
                $id = 'preset_' . time() . '_' . substr(md5($nombre), 0, 6);
                $presets[] = array('id' => $id, 'nombre' => $nombre, 'settings' => $settings);
                update_option(self::PRESETS_KEY, $presets);
                wp_send_json_success(array('id' => $id));
                break;

            case 'delete':
                $id      = sanitize_text_field($_POST['id'] ?? '');
                $presets = get_option(self::PRESETS_KEY, array());
                $presets = array_values(array_filter($presets, function($p) use($id){ return $p['id'] !== $id; }));
                update_option(self::PRESETS_KEY, $presets);
                wp_send_json_success();
                break;

            default:
                wp_send_json_error('Operación desconocida.');
        }
    }

    // ── Paletas de temas predefinidos ────────────────────────────
    public static function temas() {
        return array(
            'oscuro'       => array('barra'=>'#0f172a', 'botones'=>'#1e293b', 'texto'=>'#d1d5db', 'hover'=>'rgba(255,255,255,0.15)'),
            'claro'        => array('barra'=>'#f1f5f9', 'botones'=>'#e2e8f0', 'texto'=>'#374151', 'hover'=>'rgba(0,0,0,0.08)'),
            'azul'         => array('barra'=>'#1e3a5f', 'botones'=>'#1d4ed8', 'texto'=>'#bfdbfe', 'hover'=>'rgba(191,219,254,0.2)'),
            'verde'        => array('barra'=>'#14532d', 'botones'=>'#15803d', 'texto'=>'#bbf7d0', 'hover'=>'rgba(187,247,208,0.2)'),
            'rojo'         => array('barra'=>'#7f1d1d', 'botones'=>'#b91c1c', 'texto'=>'#fecaca', 'hover'=>'rgba(254,202,202,0.2)'),
            'transparente' => array('barra'=>'rgba(0,0,0,0.4)', 'botones'=>'rgba(255,255,255,0.06)', 'texto'=>'#e2e8f0', 'hover'=>'rgba(255,255,255,0.12)'),
        );
    }

    // ── Genera CSS inline para un PDF específico ───────────────
    public static function css_inline( $post_id, $cfg ) {
        $tipo  = $cfg['fondo_tipo'];
        $c1    = $cfg['fondo_color'];
        $c2    = $cfg['fondo_color2'];
        $img   = $cfg['fondo_imagen_url'];
        $rad   = intval($cfg['radio_bordes']);
        $sombra = ($cfg['sombra'] === '1');

        // Aplica colores del tema primero, luego sobreescribe con los individuales
        $tema_slug = $cfg['tema_botones'] ?? 'oscuro';
        $temas     = self::temas();
        $paleta    = $temas[$tema_slug] ?? $temas['oscuro'];

        $defaults  = self::defaults();
        // Si el usuario dejó el color igual al default, usa el tema
        $barra = ($cfg['color_barra']     !== $defaults['color_barra'])     ? $cfg['color_barra']     : $paleta['barra'];
        $btn   = ($cfg['color_botones']   !== $defaults['color_botones'])   ? $cfg['color_botones']   : $paleta['botones'];
        $btntx = ($cfg['color_btn_texto'] !== $defaults['color_btn_texto']) ? $cfg['color_btn_texto'] : $paleta['texto'];
        $hover = $paleta['hover'];

        // Tema transparente: el CSS de visor.css maneja todo el glassmorphism via clase
        // lbpdf-tema-transparente. El CSS inline sobreescribiría con colores sólidos.
        if ( $tema_slug === 'transparente' ) {
            return '<style>'
                . '#fbm-wrap-' . $post_id . '{border-radius:' . $rad . 'px;background:transparent!important;box-shadow:none!important;}'
                . '#fbm-wrap-' . $post_id . '.fbm-contenedor-externo{background:transparent!important;}'
                . '#fbm-wrap-' . $post_id . ' .fbm-visor-wrap,'
                . '#fbm-wrap-' . $post_id . ' .fbm-visor,'
                . '#fbm-wrap-' . $post_id . ' .fbm-cargando{background:transparent!important;}'
                . '</style>';
        }

        if      ($tipo === 'degradado')          $fondo = 'background:linear-gradient(135deg,' . $c1 . ',' . $c2 . ')';
        elseif  ($tipo === 'imagen' && $img)     $fondo = 'background:url(' . esc_url($img) . ') center/cover no-repeat;background-color:' . $c1;
        elseif  ($tipo === 'sin_fondo')          $fondo = 'background:transparent';
        else                                     $fondo = 'background:' . $c1;

        $transparencia_css = $tipo === 'sin_fondo'
            ? '#fbm-wrap-' . $post_id . '{background:transparent!important;}'
                . '#fbm-wrap-' . $post_id . ' .fbm-area-principal,'
                . '#fbm-wrap-' . $post_id . ' .fbm-visor-wrap,'
                . '#fbm-wrap-' . $post_id . ' .fbm-visor,'
                . '#fbm-wrap-' . $post_id . ' .fbm-cargando{background:transparent!important;}'
                . '#fbm-wrap-' . $post_id . ' .fbm-controles,'
                . '#fbm-wrap-' . $post_id . ' .fbm-info-bar{background:transparent!important;border:none!important;box-shadow:none!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important;}'
            : '';

        $sombra_css = $sombra ? 'box-shadow:0 16px 48px rgba(0,0,0,.35)' : 'box-shadow:none';

        return '<style>'
            . '#fbm-wrap-' . $post_id . '{border-radius:' . $rad . 'px;' . $sombra_css . '}'
            . $transparencia_css
            . '#fbm-wrap-' . $post_id . ' .fbm-visor,'
            . '#fbm-wrap-' . $post_id . ' .fbm-cargando{' . $fondo . '}'
            . '#fbm-wrap-' . $post_id . ' .fbm-controles{background:' . $barra . '}'
            . '#fbm-wrap-' . $post_id . ' .fbm-btn{background:' . $btn . '!important;color:' . $btntx . '!important;}'
            . '#fbm-wrap-' . $post_id . ' .fbm-btn:hover{background:' . $hover . '!important;}'
            . '</style>';
    }
}
