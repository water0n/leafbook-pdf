<?php
/**
 * Archivo: includes/class-flipbook-block.php
 *
 * ¿Qué hace?
 * Registra el bloque Gutenberg con WordPress y define cómo se renderiza
 * en el frontend (PHP dinámico).
 *
 * ¿Por qué PHP y no solo JavaScript?
 * Porque el bloque guarda solo los atributos (ID, ancho, alto) en la DB.
 * El HTML final se genera en PHP en cada carga de página, igual que
 * el shortcode. Esto se llama "bloque dinámico" (dynamic block).
 *
 * Ventaja: Si cambias el diseño del visor, se actualiza en todas las
 * páginas automáticamente sin tener que re-guardar cada una.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Flipbook_Block {

    public function register() {
        add_action( 'init', array( $this, 'registrar_bloque' ) );
    }

    // --------------------------------------------------------
    // REGISTRAR: Le dice a WordPress que existe este bloque
    // --------------------------------------------------------
    public function registrar_bloque() {

        // Verifica que la función exista (WordPress 5.8+)
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        register_block_type(
            FBM_PLUGIN_DIR . 'blocks/flipbook-block', // Ruta a la carpeta con block.json
            array(
                // render_callback = la función PHP que genera el HTML del frontend
                'render_callback' => array( $this, 'render_bloque' ),
            )
        );
    }

    // --------------------------------------------------------
    // RENDER: Genera el HTML cuando Gutenberg muestra el bloque
    // --------------------------------------------------------
    public function render_bloque( $atributos ) {

        $post_id = isset( $atributos['flipbookId'] ) ? intval( $atributos['flipbookId'] ) : 0;
        $ancho   = isset( $atributos['ancho'] )      ? intval( $atributos['ancho'] )      : 900;
        $alto    = isset( $atributos['alto'] )        ? intval( $atributos['alto'] )       : 600;

        // Si no eligieron ningún flipbook, no mostrar nada en el frontend
        if ( $post_id <= 0 ) {
            // Solo muestra mensaje si el usuario tiene permisos de edición
            if ( current_user_can( 'edit_posts' ) ) {
                return '<p style="color:orange; border:1px dashed orange; padding:10px; border-radius:6px;">'
                    . '⚠️ <strong>Flipbook:</strong> No hay ninguna publicación seleccionada. Edita la página y elige una.'
                    . '</p>';
            }
            return '';
        }

        // Reutiliza exactamente el mismo shortcode que ya funciona
        // Así no duplicamos código — el bloque es simplemente un
        // "envoltorio visual" para el shortcode
        $shortcode = new Flipbook_Shortcode();

        return $shortcode->render_shortcode( array(
            'id'    => $post_id,
            'ancho' => $ancho,
            'alto'  => $alto,
        ) );
    }
}
