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
    }
}
