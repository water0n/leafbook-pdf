<?php
/**
 * class-flipbook-cpt.php
 * Tipo de contenido: "PDF" — no es una publicación editorial,
 * es un archivo PDF registrado con ID único para incrustarlo
 * donde se necesite (shortcode, iframe, Elementor, etc.)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Flipbook_CPT {

    public function register() {
        add_action( 'init', array( $this, 'registrar_tipo' ) );
    }

    public function registrar_tipo() {
        register_post_type( 'flipbook', array(
            'labels' => array(
                'name'               => 'Mis PDFs',
                'singular_name'      => 'PDF',
                'add_new'            => 'Agregar PDF',
                'add_new_item'       => 'Agregar PDF',
                'edit_item'          => 'Editar PDF',
                'new_item'           => 'Nuevo PDF',
                'all_items'          => 'Todos mis PDFs',
                'view_item'          => 'Ver PDF',
                'search_items'       => 'Buscar PDFs',
                'not_found'          => 'No se encontraron PDFs',
                'not_found_in_trash' => 'No hay PDFs en la papelera',
                'menu_name'          => 'LeafBook PDF',
            ),
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => 'leafbook-pdf',   // se anida bajo el menú raíz
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'leafbook' ),
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            // Solo título — sin editor de texto, sin imágenes destacadas
            'supports'           => array( 'title' ),
            'show_in_rest'       => true,
        ) );

        $this->registrar_meta_rest();
    }

    private function registrar_meta_rest() {
        $auth_callback = function( $allowed, $meta_key, $post_id ) {
            if ( $post_id ) {
                return current_user_can( 'edit_post', $post_id );
            }
            return current_user_can( 'edit_posts' );
        };

        $meta = array(
            '_fbm_pdf_url' => array(
                'type'              => 'string',
                'single'            => true,
                'sanitize_callback' => 'esc_url_raw',
            ),
            '_fbm_pdf_attachment_id' => array(
                'type'              => 'integer',
                'single'            => true,
                'sanitize_callback' => 'absint',
            ),
            '_fbm_ancho' => array(
                'type'              => 'integer',
                'single'            => true,
                'sanitize_callback' => 'absint',
            ),
            '_fbm_alto' => array(
                'type'              => 'integer',
                'single'            => true,
                'sanitize_callback' => 'absint',
            ),
            '_fbm_autoplay' => array(
                'type'              => 'string',
                'single'            => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            '_fbm_permitir_descarga' => array(
                'type'              => 'string',
                'single'            => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );

        foreach ( $meta as $key => $args ) {
            register_post_meta( 'flipbook', $key, array_merge( $args, array(
                'show_in_rest'  => true,
                'auth_callback' => $auth_callback,
            ) ) );
        }
    }
}
