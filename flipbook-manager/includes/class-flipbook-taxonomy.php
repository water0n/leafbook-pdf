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
        add_action( 'admin_notices',                       array( $this, 'render_ayuda_shortcodes' ) );
        add_action( self::SLUG . '_edit_form_fields',      array( $this, 'render_shortcode_edicion' ), 10, 2 );
        add_filter( 'manage_edit-' . self::SLUG . '_columns', array( $this, 'agregar_columna_shortcode_terms' ) );
        add_filter( 'manage_' . self::SLUG . '_custom_column', array( $this, 'render_columna_shortcode_terms' ), 10, 3 );
        add_action( 'admin_head-edit-tags.php',            array( $this, 'estilos_admin_grupos' ) );
        add_action( 'admin_head-term.php',                 array( $this, 'estilos_admin_grupos' ) );
        add_action( 'admin_footer-edit-tags.php',          array( $this, 'scripts_admin_grupos' ) );
        add_action( 'admin_footer-term.php',               array( $this, 'scripts_admin_grupos' ) );
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

    // ── Ayuda y shortcodes por grupo ────────────────────────────
    public function render_ayuda_shortcodes() {
        if ( ! current_user_can( 'edit_posts' ) ) return;
        if ( ! $this->es_lista_grupos() ) return;

        $grupos = get_terms( array(
            'taxonomy'   => self::SLUG,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        ?>
        <div class="lbpdf-grupos-ayuda">
            <div class="lbpdf-grupos-ayuda-head">
                <div>
                    <h2>Shortcodes por categoria/grupo</h2>
                    <p>En LeafBook las categorias se administran como <strong>Grupos</strong>. Usa el slug del grupo para mostrar automaticamente el ultimo PDF publicado dentro de esa categoria.</p>
                </div>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=flipbook' ) ); ?>">Agregar PDF</a>
            </div>

            <div class="lbpdf-grupos-ejemplos">
                <div>
                    <span>Shortcode recomendado</span>
                    <code>[leafbook grupo="slug-del-grupo"]</code>
                </div>
                <div>
                    <span>Alias disponible</span>
                    <code>[leafbook categoria="slug-del-grupo"]</code>
                </div>
            </div>

            <?php if ( ! empty( $grupos ) && ! is_wp_error( $grupos ) ) : ?>
                <table class="widefat striped lbpdf-grupos-tabla">
                    <thead>
                        <tr>
                            <th>Grupo</th>
                            <th>Slug</th>
                            <th>Ultimo PDF</th>
                            <th>Shortcode</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $grupos as $grupo ) :
                            $ultimo    = $this->ultimo_flipbook_por_grupo( $grupo->term_id );
                            $shortcode = $this->shortcode_grupo( $grupo );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $grupo->name ); ?></strong></td>
                                <td><code><?php echo esc_html( $grupo->slug ); ?></code></td>
                                <td>
                                    <?php if ( $ultimo ) : ?>
                                        <a href="<?php echo esc_url( get_edit_post_link( $ultimo->ID ) ); ?>"><?php echo esc_html( get_the_title( $ultimo->ID ) ); ?></a>
                                    <?php else : ?>
                                        <span class="lbpdf-muted">Sin PDFs publicados con archivo.</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="lbpdf-term-sc"><?php echo esc_html( $shortcode ); ?></code>
                                    <button type="button" class="button button-small lbpdf-copy-code" data-shortcode="<?php echo esc_attr( $shortcode ); ?>">Copiar</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="lbpdf-grupos-vacio">Aun no hay grupos. Crea uno abajo; despues podras usar su shortcode aqui mismo.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_shortcode_edicion( $term ) {
        if ( ! $term || is_wp_error( $term ) ) return;

        $shortcode = $this->shortcode_grupo( $term );
        ?>
        <tr class="form-field">
            <th scope="row">Shortcode del grupo</th>
            <td>
                <code class="lbpdf-term-sc"><?php echo esc_html( $shortcode ); ?></code>
                <button type="button" class="button button-small lbpdf-copy-code" data-shortcode="<?php echo esc_attr( $shortcode ); ?>">Copiar</button>
                <p class="description">Muestra automaticamente el ultimo PDF publicado que pertenezca a este grupo.</p>
            </td>
        </tr>
        <?php
    }

    public function agregar_columna_shortcode_terms( $columns ) {
        $nuevo = array();
        foreach ( $columns as $key => $label ) {
            $nuevo[$key] = $label;
            if ( $key === 'name' ) {
                $nuevo['lbpdf_shortcode'] = 'Shortcode';
            }
        }

        if ( ! isset( $nuevo['lbpdf_shortcode'] ) ) {
            $nuevo['lbpdf_shortcode'] = 'Shortcode';
        }

        return $nuevo;
    }

    public function render_columna_shortcode_terms( $content, $column_name, $term_id ) {
        if ( $column_name !== 'lbpdf_shortcode' ) {
            return $content;
        }

        $term = get_term( $term_id, self::SLUG );
        if ( ! $term || is_wp_error( $term ) ) {
            return $content;
        }

        $shortcode = $this->shortcode_grupo( $term );
        return '<code class="lbpdf-term-sc">' . esc_html( $shortcode ) . '</code> '
            . '<button type="button" class="button button-small lbpdf-copy-code" data-shortcode="' . esc_attr( $shortcode ) . '">Copiar</button>';
    }

    public function estilos_admin_grupos() {
        if ( ! $this->es_pantalla_grupos() ) return;
        ?>
        <style>
            .lbpdf-grupos-ayuda { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:18px 20px; margin:14px 0 18px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
            .lbpdf-grupos-ayuda-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:14px; }
            .lbpdf-grupos-ayuda h2 { margin:0 0 6px; font-size:18px; line-height:1.3; }
            .lbpdf-grupos-ayuda p { margin:0; color:#50575e; max-width:760px; }
            .lbpdf-grupos-ejemplos { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:10px; margin:14px 0; }
            .lbpdf-grupos-ejemplos > div { background:#f6f7f7; border:1px solid #e2e4e7; border-radius:6px; padding:10px 12px; }
            .lbpdf-grupos-ejemplos span { display:block; color:#646970; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px; }
            .lbpdf-grupos-ejemplos code,
            .lbpdf-term-sc { display:inline-block; background:#1d2327; color:#a8d8ff; padding:5px 8px; border-radius:4px; font-size:12px; line-height:1.4; }
            .lbpdf-grupos-tabla { margin-top:12px; }
            .lbpdf-grupos-tabla td { vertical-align:middle; }
            .lbpdf-muted,
            .lbpdf-grupos-vacio { color:#787c82; }
            .column-lbpdf_shortcode { width:280px; }
            .lbpdf-copy-code { margin-left:6px !important; vertical-align:middle !important; }
            @media (max-width: 782px) {
                .lbpdf-grupos-ayuda-head { display:block; }
                .lbpdf-grupos-ayuda-head .button { margin-top:12px; }
                .column-lbpdf_shortcode { width:auto; }
            }
        </style>
        <?php
    }

    public function scripts_admin_grupos() {
        if ( ! $this->es_pantalla_grupos() ) return;
        ?>
        <script>
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.lbpdf-copy-code');
            if (!btn) return;

            e.preventDefault();
            var text = btn.getAttribute('data-shortcode') || '';
            var label = btn.textContent;
            var done = function() {
                btn.textContent = 'Copiado';
                setTimeout(function(){ btn.textContent = label; }, 1800);
            };
            var fallback = function() {
                var area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.left = '-9999px';
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                document.body.removeChild(area);
                done();
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(fallback);
            } else {
                fallback();
            }
        });
        </script>
        <?php
    }

    private function shortcode_grupo( $term ) {
        return '[leafbook grupo="' . $term->slug . '"]';
    }

    private function ultimo_flipbook_por_grupo( $term_id ) {
        $posts = get_posts( array(
            'post_type'      => 'flipbook',
            'post_status'    => 'publish',
            'numberposts'    => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => array(
                array(
                    'taxonomy' => self::SLUG,
                    'field'    => 'term_id',
                    'terms'    => array( absint( $term_id ) ),
                ),
            ),
            'meta_query'     => array(
                array(
                    'key'     => '_fbm_pdf_url',
                    'value'   => '',
                    'compare' => '!=',
                ),
            ),
        ) );

        return ! empty( $posts ) ? $posts[0] : null;
    }

    private function es_pantalla_grupos() {
        if ( ! function_exists( 'get_current_screen' ) ) return false;

        $screen = get_current_screen();
        return $screen && isset( $screen->taxonomy ) && $screen->taxonomy === self::SLUG;
    }

    private function es_lista_grupos() {
        if ( ! $this->es_pantalla_grupos() ) return false;

        $screen = get_current_screen();
        return $screen && isset( $screen->base ) && $screen->base === 'edit-tags';
    }
}
