<?php
/**
 * Archivo: includes/class-flipbook-taxonomy.php
 * Taxonomía "Grupos" para organizar publicaciones: Revistas, Libros, etc.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Flipbook_Taxonomy {

    const SLUG = 'lbpdf_grupo';

    public function register() {
        add_action( 'init',                                array( $this, 'registrar_taxonomia' ) );
        add_action( 'restrict_manage_posts',               array( $this, 'filtro_en_lista'     ) );
        add_filter( 'manage_flipbook_posts_columns',       array( $this, 'agregar_columna'     ) );
        add_action( 'manage_flipbook_posts_custom_column', array( $this, 'render_columna'      ), 10, 2 );
    }

    // ── Registro ─────────────────────────────────────────────────
    public function registrar_taxonomia() {
        register_taxonomy( self::SLUG, 'flipbook', array(
            'labels' => array(
                'name'          => 'Grupos',
                'singular_name' => 'Grupo',
                'all_items'     => 'Todos los grupos',
                'edit_item'     => 'Editar grupo',
                'add_new_item'  => 'Agregar nuevo grupo',
                'new_item_name' => 'Nombre del nuevo grupo',
                'menu_name'     => '🗂 Grupos',
                'no_terms'      => 'Sin grupo asignado',
            ),
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_nav_menus' => false,
            'show_in_rest'      => true,
            'show_admin_column' => false,
            'rewrite'           => array( 'slug' => 'leafbook-grupo' ),
            'meta_box_cb'       => array( $this, 'render_meta_box_grupo' ),
        ) );
    }

    // ── Meta box personalizado en la edición ─────────────────────
    public function render_meta_box_grupo( $post ) {
        $todos    = get_terms( array( 'taxonomy' => self::SLUG, 'hide_empty' => false ) );
        $actuales = wp_get_post_terms( $post->ID, self::SLUG, array( 'fields' => 'ids' ) );
        if ( is_wp_error($actuales) ) $actuales = array();
        ?>
        <style>
        .lbg-lista{max-height:160px;overflow-y:auto;border:1px solid #e2e4e7;border-radius:6px;padding:6px 8px;background:#fff;margin-bottom:10px;}
        .lbg-lista label{display:flex;align-items:center;gap:8px;padding:5px 4px;font-size:13px;cursor:pointer;border-radius:4px;}
        .lbg-lista label:hover{background:#f0f6ff;}
        .lbg-vacio{color:#9ca3af;font-size:12px;padding:6px;}
        .lbg-nuevo p{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#1d2327;margin:0 0 6px;}
        .lbg-fila{display:flex;gap:6px;margin-bottom:6px;}
        .lbg-fila input{flex:1;padding:6px 9px;border:1px solid #c3c4c7;border-radius:5px;font-size:13px;}
        .lbg-fila button{padding:6px 12px;font-size:12px;white-space:nowrap;}
        .lbg-padre select{width:100%;padding:5px 8px;border:1px solid #c3c4c7;border-radius:5px;font-size:12px;}
        </style>

        <div class="lbg-lista" id="lbg-lista">
            <?php if ( empty($todos) || is_wp_error($todos) ) : ?>
                <p class="lbg-vacio">Sin grupos aún — crea el primero abajo.</p>
            <?php else : ?>
                <?php foreach ( $todos as $g ) : ?>
                    <label>
                        <input type="checkbox"
                               name="tax_input[<?php echo self::SLUG; ?>][]"
                               value="<?php echo $g->term_id; ?>"
                               <?php checked( in_array( $g->term_id, $actuales ) ); ?>>
                        <?php echo esc_html($g->name); ?>
                        <span style="color:#9ca3af;font-size:11px;">(<?php echo $g->count; ?>)</span>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="lbg-nuevo">
            <p>+ Nuevo grupo</p>
            <div class="lbg-fila">
                <input type="text" id="lbg-nombre" placeholder="Ej: Revistas 2026">
                <button type="button" class="button" onclick="lbgCrear()">Agregar</button>
            </div>
            <?php if ( !empty($todos) && !is_wp_error($todos) ) : ?>
            <div class="lbg-padre">
                <select id="lbg-padre">
                    <option value="0">Sin grupo padre</option>
                    <?php foreach($todos as $g): ?>
                        <option value="<?php echo $g->term_id; ?>"><?php echo esc_html($g->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function lbgCrear() {
            var nombre = document.getElementById('lbg-nombre').value.trim();
            if (!nombre) { alert('Escribe un nombre para el grupo.'); return; }
            var padreEl = document.getElementById('lbg-padre');
            var padre   = padreEl ? parseInt(padreEl.value) : 0;

            fetch('<?php echo esc_js(rest_url('wp/v2/' . self::SLUG)); ?>', {
                method: 'POST',
                headers: { 'Content-Type':'application/json', 'X-WP-Nonce':'<?php echo wp_create_nonce('wp_rest'); ?>' },
                body: JSON.stringify({ name: nombre, parent: padre })
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.id) {
                    var lista = document.getElementById('lbg-lista');
                    var vacio = lista.querySelector('.lbg-vacio');
                    if (vacio) vacio.remove();

                    var lbl = document.createElement('label');
                    lbl.innerHTML = '<input type="checkbox" name="tax_input[lbpdf_grupo][]" value="'+d.id+'" checked> '
                        + d.name + ' <span style="color:#9ca3af;font-size:11px;">(0)</span>';
                    lista.appendChild(lbl);
                    document.getElementById('lbg-nombre').value = '';

                    if (padreEl) {
                        var opt = document.createElement('option');
                        opt.value = d.id; opt.textContent = d.name;
                        padreEl.appendChild(opt);
                    }
                } else {
                    alert('Error: ' + (d.message || 'desconocido'));
                }
            })
            .catch(function(){ alert('Error de conexión.'); });
        }
        </script>
        <?php
    }

    // ── Filtro por grupo en la lista ─────────────────────────────
    public function filtro_en_lista( $post_type ) {
        if ( $post_type !== 'flipbook' ) return;
        $grupos = get_terms( array('taxonomy' => self::SLUG, 'hide_empty' => false) );
        if ( empty($grupos) || is_wp_error($grupos) ) return;
        $sel = isset($_GET[self::SLUG]) ? sanitize_text_field($_GET[self::SLUG]) : '';
        echo '<select name="'.self::SLUG.'">';
        echo '<option value="">Todos los grupos</option>';
        foreach ($grupos as $g) {
            printf('<option value="%s"%s>%s (%d)</option>', esc_attr($g->slug), selected($sel,$g->slug,false), esc_html($g->name), $g->count);
        }
        echo '</select>';
    }

    // ── Columna en la lista ───────────────────────────────────────
    public function agregar_columna( $cols ) {
        $nuevo = array();
        foreach ($cols as $k => $v) {
            $nuevo[$k] = $v;
            if ($k === 'title') $nuevo['lbpdf_grupo'] = '🗂 Grupo';
        }
        return $nuevo;
    }

    public function render_columna( $col, $pid ) {
        if ($col !== 'lbpdf_grupo') return;
        $grupos = wp_get_post_terms($pid, self::SLUG);
        if (empty($grupos) || is_wp_error($grupos)) { echo '<span style="color:#9ca3af;font-size:12px;">—</span>'; return; }
        $links = array();
        foreach ($grupos as $g) {
            $url = add_query_arg(array('post_type'=>'flipbook', self::SLUG=>$g->slug), admin_url('edit.php'));
            $links[] = '<a href="'.esc_url($url).'" style="font-size:12px;color:#2271b1;">'.esc_html($g->name).'</a>';
        }
        echo implode(', ', $links);
    }
}
