<?php

if ( ! class_exists( 'TCPMenu' ) ) {
    class TCPMenu {
        function __construct() {
            add_action( 'admin_menu', array( $this, 'admin_menu' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        }

        function enqueue_scripts() {
                wp_enqueue_style( 'tcp-menu', plugin_dir_url( __FILE__ ) . 'assets/css/menu.css' );
        }

        function admin_menu() {
                add_menu_page(
                        'TheCartPress',
                        'TheCartPress',
                        'manage_options',
                        'thecartpress',
                        array( &$this, 'plugins_content' ),
                        plugin_dir_url( __FILE__ )  . 'assets/images/tcp-icon.svg',
                        26
                );
                add_submenu_page( 'thecartpress', 'TCP Plugins', 'TCP Plugins', 'manage_options', 'thecartpress' );
        }

        function plugins_content() {
                ?>
                <div class="tcp_plugins_page wrap">
                    <h1><?php echo esc_html( "TheCartPress" ); ?></h1>
                    <div class="tcp_plugins">
                        <h2 class="title"><?php echo esc_html( "TCP Plugins" ); ?></h2>
                            <?php
                            if ( false === ( $plugins_arr = get_transient( 'tcp_plugins' ) )) {
                                    $url = 'https://app.thecartpress.com/notice/?view=tcp_plugin_list';
                                    $response = wp_remote_get($url);
                                    if ( ! is_wp_error( $response ) ) {
                                        $plugins_arr = array();
                                        $plugins     = json_decode(wp_remote_retrieve_body($response), true);
                                        if ( isset( $plugins["plugins"] ) && ( count( $plugins["plugins"]  ) > 0 ) ) {
                                            foreach ( $plugins["plugins"] as $pl ) {
                                                $plugins_arr[] = array(
                                                    'slug'            => $pl["slug"],
                                                    'name'            => $pl["name"],
                                                    'short_description' => $pl["short_description"],
                                                    'version'      => $pl["version"],
                                                    'active_installs'   => $pl["active_installs"],
                                                    'icon'=> $pl["icons"]["1x"],
                                                    'download_page'=> $pl["download_link"]
                                                );
                                            }
                                        }

                                        if ( isset( $plugins["promote"] ) && ( count( $plugins["promote"]  ) > 0 ) ) {
                                                foreach ( $plugins["promote"] as $plpromote ) {
                                                    $plugins_promote_arr[] = array(
                                                        'promote_image'            => $plpromote["promote_image"],
                                                        'promote_link'            => $plpromote["promote_link"]
                                                    );
                                                }
                                        }
                                            
                                        set_transient( 'tcp_plugins', $plugins_arr, 24 * HOUR_IN_SECONDS );
                                    }
                            }
                            
                            // promote banner
                             if (( $plugins_promote_arr = get_transient( 'tcp_plugins_promote' ) ) && is_array( $plugins_promote_arr ) && ( count( $plugins_promote_arr ) > 0 ) ) {
                                $i = 1;
                                
                                foreach ( $plugins_promote_arr as $plpromote ) {
                                        echo '<div>';
                                        echo '<a href="'. $plpromote['promote_link'] . '"><img src="'. esc_url($plpromote['promote_image']).'" ></a>';
                                        $i ++;
                                        echo '</div>';
                                }
                              
                            } 
                            // wordpress plugins
                            if (( $plugins_arr = get_transient( 'tcp_plugins' ) ) && is_array( $plugins_arr ) && ( count( $plugins_arr ) > 0 ) ) {
                                echo '<div class="'.esc_attr("tcp_plugins_cards").'">';
                                foreach ( $plugins_arr as $pl ) {
                                    echo '<a class="'.esc_attr("tcp_plugins_card_container").'" href="'. $pl['download_page'] . '/"><table class="'.esc_attr("tcp_plugins_card").'"><tr><td class="'.esc_attr("tcp_plugin_icon").'"><img class="'.esc_attr("tcp_plugin_icon").'" src='.esc_url($pl['icon']).' alt="img.png"/></td><td align=left><strong>' . esc_html($pl['name']) . '</strong><br>' . esc_html($pl['short_description']) . '</td></tr></table></a>';
                                }
                                echo '</div>';
                            } 
                            
                            ?>
                    </div>

                    <div class="card">
                        <h2><?php echo esc_html( "Contact" ); ?></h2>
                        <p><?php echo esc_html( "Feel free to contact us via" ); ?>
                            <a href="<?php echo esc_url( "https://www.thecartpress.com/contact/?utm_source=contact&utm_medium=menu&utm_campaign=wporg" ); ?>" target="_blank">
                                <?php echo esc_html( " contact page" ); ?></a><br/>
                             <?php echo esc_html( "Website:" ); ?> <a href="<?php echo esc_url( "https://www.thecartpress.com/?utm_source=visit&utm_medium=menu&utm_campaign=wporg" ); ?>"
                                        target="_blank">https://www.thecartpress.com/</a>
                        </p>
                    </div>
                </div>
                <?php
        }
    }

    new TCPMenu();
}