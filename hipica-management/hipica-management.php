<?php
/*
Plugin Name: Hipica Management
Description: Gestor simple de hípicas con roles de alumnos y profesores.
Version: 0.2
Author: Codex
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Hipica_Management {
    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'init', array( $this, 'register_post_types' ) );
        add_action( 'init', array( $this, 'register_taxonomies' ) );
        add_action( 'init', array( $this, 'register_roles' ) );
        add_action( 'show_user_profile', array( $this, 'nivel_field' ) );
        add_action( 'edit_user_profile', array( $this, 'nivel_field' ) );
        add_action( 'personal_options_update', array( $this, 'save_nivel_field' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_nivel_field' ) );
        add_shortcode( 'hipica_clases', array( $this, 'clases_shortcode' ) );
    }

    public function activate() {
        $this->register_post_types();
        $this->register_taxonomies();
        $this->register_roles();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Roles y capacidades básicas
     */
    public function register_roles() {
        add_role( 'alumno', 'Alumno', array( 'read' => true ) );

        add_role( 'profesor', 'Profesor', array(
            'read'                     => true,
            'edit_clases'              => true,
            'edit_caballos'            => true,
            'edit_pistas'              => true,
            'edit_alumnos'             => true,
            'publish_clases'           => true,
            'publish_caballos'         => true,
            'publish_pistas'           => true,
            'publish_alumnos'          => true,
            'delete_posts'             => true,
        ) );
    }

    /**
     * Tipos de contenido
     */
    public function register_post_types() {
        $common = array(
            'public'       => true,
            'show_in_menu' => true,
            'supports'     => array( 'title' ),
            'map_meta_cap' => true,
        );

        register_post_type( 'caballo', array_merge( $common, array(
            'labels'         => array(
                'name' => 'Caballos',
                'singular_name' => 'Caballo',
            ),
            'capability_type' => array( 'caballo', 'caballos' ),
        ) ) );

        register_post_type( 'pista', array_merge( $common, array(
            'labels'         => array(
                'name' => 'Pistas',
                'singular_name' => 'Pista',
            ),
            'capability_type' => array( 'pista', 'pistas' ),
        ) ) );

        register_post_type( 'alumno_ficha', array_merge( $common, array(
            'labels'         => array(
                'name' => 'Alumnos',
                'singular_name' => 'Alumno',
            ),
            'capability_type' => array( 'alumno', 'alumnos' ),
        ) ) );

        register_post_type( 'clase', array_merge( $common, array(
            'labels'         => array(
                'name' => 'Clases',
                'singular_name' => 'Clase',
            ),
            'has_archive'    => true,
            'capability_type'=> array( 'clase', 'clases' ),
            'supports'       => array( 'title', 'custom-fields' ),
        ) ) );

        register_post_type( 'reserva', array_merge( $common, array(
            'labels'         => array(
                'name' => 'Reservas',
                'singular_name' => 'Reserva',
            ),
            'public'         => false,
            'show_ui'        => true,
            'capability_type'=> array( 'reserva', 'reservas' ),
        ) ) );
    }

    /**
     * Taxonomía para estado del caballo
     */
    public function register_taxonomies() {
        register_taxonomy( 'estado_caballo', 'caballo', array(
            'labels' => array( 'name' => 'Estado Caballo' ),
            'public' => true,
            'show_in_quick_edit' => true,
        ) );
    }

    /**
     * Campo de nivel en el perfil de usuario
     */
    public function nivel_field( $user ) {
        ?>
        <h3>Nivel / Color</h3>
        <table class="form-table">
            <tr>
                <th><label for="nivel_color">Color</label></th>
                <td>
                    <input type="text" name="nivel_color" id="nivel_color" value="<?php echo esc_attr( get_user_meta( $user->ID, 'nivel_color', true ) ); ?>" class="regular-text" />
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_nivel_field( $user_id ) {
        if ( current_user_can( 'edit_user', $user_id ) ) {
            update_user_meta( $user_id, 'nivel_color', sanitize_text_field( $_POST['nivel_color'] ) );
        }
    }

    /**
     * Shortcode para mostrar clases y permitir reservas
     */
    public function clases_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<p>Debes iniciar sesión para ver las clases.</p>';
        }
        $user_id = get_current_user_id();
        $color   = get_user_meta( $user_id, 'nivel_color', true );

        $args = array(
            'post_type'  => 'clase',
            'meta_query' => array(
                array(
                    'key'     => 'nivel_color',
                    'value'   => $color,
                    'compare' => '=',
                )
            )
        );
        $query = new WP_Query( $args );
        ob_start();
        if ( $query->have_posts() ) {
            echo '<ul class="hipica-clases">';
            while ( $query->have_posts() ) {
                $query->the_post();
                $max    = (int) get_post_meta( get_the_ID(), 'max_alumnos', true );
                $current = (int) $this->reservas_count( get_the_ID() );
                if ( $max > 0 && $current >= $max ) {
                    continue;
                }
                echo '<li>' . esc_html( get_the_title() );
                echo ' <a href="' . esc_url( add_query_arg( array( 'book_clase' => get_the_ID() ) ) ) . '">Reservar</a>';
                echo '</li>';
            }
            echo '</ul>';
            wp_reset_postdata();
        } else {
            echo '<p>No hay clases disponibles.</p>';
        }
        return ob_get_clean();
    }

    private function reservas_count( $clase_id ) {
        $q = new WP_Query( array(
            'post_type'  => 'reserva',
            'meta_key'   => 'clase_id',
            'meta_value' => $clase_id,
            'fields'     => 'ids',
        ) );
        return $q->found_posts;
    }
}

new Hipica_Management();

/**
 * Procesar reservas simples
 */
add_action( 'init', function() {
    if ( isset( $_GET['book_clase'] ) && is_user_logged_in() ) {
        $class_id = intval( $_GET['book_clase'] );
        $user_id  = get_current_user_id();
        $title    = 'Reserva ' . $class_id . ' - ' . $user_id;
        wp_insert_post( array(
            'post_type'  => 'reserva',
            'post_title' => $title,
            'post_status'=> 'publish',
            'meta_input' => array(
                'clase_id' => $class_id,
                'user_id'  => $user_id,
            )
        ) );
        wp_safe_redirect( remove_query_arg( 'book_clase' ) );
        exit;
    }
} );
