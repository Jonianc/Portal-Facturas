<?php
/**
 * Plugin Name: WP Facturas Portal (Drive)
 * Description: Portal público protegido por clave para gestionar facturas (PDF en Google Drive). El cliente solo escribe observación y la factura pasa a "Asignado" automáticamente.
 * Version: 1.1.0
 * Author: Rocket Solutions
 */

if (!defined('ABSPATH')) exit;

class WPFPP_Facturas_Portal {
    const VERSION = '1.1.0';
    const OPTION_SETTINGS = 'wpfp_settings';
    const OPTION_PLAIN_PASS = 'wpfp_password_plain';
    const COOKIE_NAME = 'wpfp_auth';

    public static function init() {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        add_action('template_redirect', [__CLASS__, 'maybe_render_standalone_portal']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);

        add_action('wp_ajax_nopriv_wpfp_update_factura', [__CLASS__, 'ajax_update_factura']);
        add_action('wp_ajax_wpfp_update_factura', [__CLASS__, 'ajax_update_factura']);

        add_action('wp_ajax_wpfp_logout', [__CLASS__, 'ajax_logout']);
        add_action('wp_ajax_nopriv_wpfp_logout', [__CLASS__, 'ajax_logout']);
    }

    public static function activate() {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            proveedor VARCHAR(190) NOT NULL DEFAULT '',
            folio VARCHAR(80) NOT NULL DEFAULT '',
            fecha_factura DATE NULL,
            monto DECIMAL(14,2) NULL,
            moneda VARCHAR(10) NOT NULL DEFAULT 'CLP',
            pdf_url TEXT NULL,
            observacion TEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            assigned_at DATETIME NULL,
            assigned_by VARCHAR(190) NULL,
            loaded_at DATETIME NULL,
            loaded_by VARCHAR(190) NULL,
            PRIMARY KEY (id),
            KEY estado (estado),
            KEY proveedor (proveedor),
            KEY folio (folio),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);

        $settings = get_option(self::OPTION_SETTINGS, []);
        if (empty($settings['password_hash'])) {
            // Genera una clave inicial y guárdala (en claro solo hasta que la cambies en Ajustes).
            $plain = self::generate_password();
            $settings['password_hash'] = wp_hash_password($plain);
            $settings['session_hours'] = isset($settings['session_hours']) ? (int)$settings['session_hours'] : 12;
            $settings['default_view'] = isset($settings['default_view']) ? sanitize_text_field($settings['default_view']) : 'pendiente';
            update_option(self::OPTION_SETTINGS, $settings, false);
            update_option(self::OPTION_PLAIN_PASS, $plain, false);
        }
    }

    private static function generate_password() {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#';
        $out = '';
        for ($i=0; $i<14; $i++) $out .= $chars[random_int(0, strlen($chars)-1)];
        return $out;
    }

    private static function settings() {
        $defaults = [
            'password_hash' => '',
            'session_hours' => 12,
            'default_view' => 'pendiente',
        ];
        $s = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($s)) $s = [];
        return array_merge($defaults, $s);
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wpfp_facturas';
    }

    public static function admin_menu() {
        add_menu_page(
            'Portal Facturas',
            'Portal Facturas',
            'manage_options',
            'wpfp_facturas',
            [__CLASS__, 'admin_page_list'],
            'dashicons-media-document',
            58
        );
        add_submenu_page('wpfp_facturas', 'Agregar factura', 'Agregar', 'manage_options', 'wpfp_facturas_add', [__CLASS__, 'admin_page_add']);
        add_submenu_page('wpfp_facturas', 'Ajustes', 'Ajustes', 'manage_options', 'wpfp_facturas_settings', [__CLASS__, 'admin_page_settings']);
        add_submenu_page(null, 'Editar factura', 'Editar factura', 'manage_options', 'wpfp_facturas_edit', [__CLASS__, 'admin_page_edit']);
    }

    public static function register_settings() {
        register_setting('wpfp_settings_group', self::OPTION_SETTINGS, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings']
        ]);
    }

    public static function sanitize_settings($input) {
        $current = self::settings();
        $out = $current;

        $out['session_hours'] = isset($input['session_hours']) ? max(1, min(72, (int)$input['session_hours'])) : $current['session_hours'];
        $out['default_view']  = isset($input['default_view']) ? sanitize_text_field($input['default_view']) : $current['default_view'];

        // Cambio de clave
        if (!empty($input['new_password'])) {
            $new = (string)$input['new_password'];
            $out['password_hash'] = wp_hash_password($new);
            delete_option(self::OPTION_PLAIN_PASS);
        }

        // Regenerar clave (solo admin)
        if (!empty($input['regenerate_password']) && current_user_can('manage_options')) {
            $plain = self::generate_password();
            $out['password_hash'] = wp_hash_password($plain);
            update_option(self::OPTION_PLAIN_PASS, $plain, false);
        }

        return $out;
    }

    public static function admin_assets($hook) {
        if (strpos($hook, 'wpfp_facturas') === false) return;
        wp_enqueue_style('wpfp-admin', plugins_url('assets/admin.css', __FILE__), [], self::VERSION);
    }

    /* ------------------------------
     * Admin: Listado
     * ------------------------------ */
    public static function admin_page_list() {
        if (!current_user_can('manage_options')) return;

        // Acciones (marcar cargada / borrar)
        if (!empty($_POST['wpfp_action']) && check_admin_referer('wpfp_admin_action', 'wpfp_nonce')) {
            $action = sanitize_text_field($_POST['wpfp_action']);
            $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
            if ($ids) {
                if ($action === 'mark_loaded') self::mark_loaded($ids);
                if ($action === 'mark_pending') self::mark_pending($ids);
                if ($action === 'delete') self::delete_facturas($ids);
            }
        }

        $estado = isset($_GET['estado']) ? sanitize_text_field($_GET['estado']) : '';
        $proveedor = isset($_GET['proveedor']) ? sanitize_text_field($_GET['proveedor']) : '';
        $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $rows = self::get_facturas([
            'estado' => $estado,
            'proveedor' => $proveedor,
            'q' => $q,
            'limit' => 500
        ]);

        $proveedores = self::get_proveedores();

        echo '<div class="wrap"><h1>Portal Facturas</h1>';

        echo '<form method="get" class="wpfp-filters">';
        echo '<input type="hidden" name="page" value="wpfp_facturas" />';
        echo '<select name="estado">';
        echo '<option value="">— Todos los estados —</option>';
        foreach (['pendiente'=>'Pendiente','asignado'=>'Asignado','duda'=>'Duda','cargada'=>'Cargada'] as $k=>$label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($estado, $k, false), esc_html($label));
        }
        echo '</select>';

        echo '<select name="proveedor">';
        echo '<option value="">— Todos los proveedores —</option>';
        foreach ($proveedores as $p) {
            printf('<option value="%s"%s>%s</option>', esc_attr($p), selected($proveedor, $p, false), esc_html($p));
        }
        echo '</select>';

        printf('<input type="search" name="q" value="%s" placeholder="Buscar folio/proveedor" />', esc_attr($q));
        echo '<button class="button">Filtrar</button>';
        echo '</form>';

        echo '<form method="post">';
        wp_nonce_field('wpfp_admin_action', 'wpfp_nonce');
        echo '<div class="wpfp-bulk">';
        echo '<select name="wpfp_action" required>';
        echo '<option value="">— Acción masiva —</option>';
        echo '<option value="mark_loaded">Marcar como Cargada</option>';
        echo '<option value="mark_pending">Marcar como Pendiente</option>';
        echo '<option value="delete">Eliminar</option>';
        echo '</select> ';
        echo '<button class="button action">Aplicar</button>';
        echo '</div>';

        echo '<table class="widefat fixed striped wpfp-table">';
        echo '<thead><tr>
            <th style="width:28px;"><input type="checkbox" id="wpfp-checkall" /></th>
            <th>ID</th>
            <th>Proveedor</th>
            <th>Folio</th>
            <th>Fecha</th>
            <th>Monto</th>
            <th>Estado</th>
            <th>Observación</th>
            <th>PDF</th>
            <th>Asignado</th>
            <th>Cargada</th>
            <th>Acciones</th>
        </tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="12">Sin resultados.</td></tr>';
        } else {
            foreach ($rows as $r) {
                $pdf = $r->pdf_url ? '<a href="'.esc_url($r->pdf_url).'" target="_blank" rel="noopener">Abrir</a>' : '—';
                $monto = is_null($r->monto) ? '—' : number_format((float)$r->monto, 0, ',', '.').' '.esc_html($r->moneda);
                echo '<tr>';
                echo '<td><input type="checkbox" name="ids[]" value="'.(int)$r->id.'"></td>';
                echo '<td>'.(int)$r->id.'</td>';
                echo '<td>'.esc_html($r->proveedor).'</td>';
                echo '<td>'.esc_html($r->folio).'</td>';
                echo '<td>'.esc_html($r->fecha_factura ? $r->fecha_factura : '—').'</td>';
                echo '<td>'.$monto.'</td>';
                echo '<td><span class="wpfp-badge wpfp-'.$r->estado.'">'.esc_html(ucfirst($r->estado)).'</span></td>';
                echo '<td>'.esc_html(wp_trim_words((string)$r->observacion, 15)).'</td>';
                echo '<td>'.$pdf.'</td>';
                echo '<td>'.esc_html($r->assigned_at ? $r->assigned_at : '—').'</td>';
                echo '<td>'.esc_html($r->loaded_at ? $r->loaded_at : '—').'</td>';
                $edit_url = admin_url('admin.php?page=wpfp_facturas_edit&id='.(int)$r->id);
                echo '<td><a class="button button-small" href="'.esc_url($edit_url).'">Editar</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</form>';

        $portal_url = add_query_arg('wpfp_portal', '1', home_url('/'));
        echo '<p style="margin-top:14px;">URL portal standalone: <code>'.esc_html($portal_url).'</code></p>';
        echo '</div>';

        // Checkall script small
        echo "<script>
            document.addEventListener('DOMContentLoaded', function(){
                var all = document.getElementById('wpfp-checkall');
                if(!all) return;
                all.addEventListener('change', function(){
                    document.querySelectorAll('.wpfp-table tbody input[type=checkbox]').forEach(function(cb){ cb.checked = all.checked; });
                });
            });
        </script>";
    }

    public static function admin_page_add() {
        if (!current_user_can('manage_options')) return;

        $msg = '';
        if (!empty($_POST['wpfp_add']) && check_admin_referer('wpfp_add_factura', 'wpfp_nonce')) {
            $data = [
                'proveedor' => sanitize_text_field($_POST['proveedor'] ?? ''),
                'folio' => sanitize_text_field($_POST['folio'] ?? ''),
                'fecha_factura' => sanitize_text_field($_POST['fecha_factura'] ?? ''),
                'monto' => sanitize_text_field($_POST['monto'] ?? ''),
                'moneda' => sanitize_text_field($_POST['moneda'] ?? 'CLP'),
                'pdf_url' => esc_url_raw($_POST['pdf_url'] ?? ''),
                'estado' => 'pendiente',
            ];
            $id = self::insert_factura($data);
            $msg = $id ? 'Factura agregada.' : 'No se pudo agregar.';
        }

        echo '<div class="wrap"><h1>Agregar factura</h1>';
        if ($msg) echo '<div class="notice notice-success"><p>'.esc_html($msg).'</p></div>';

        echo '<form method="post" class="wpfp-form">';
        wp_nonce_field('wpfp_add_factura', 'wpfp_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label>Proveedor</label></th><td><input name="proveedor" required class="regular-text" /></td></tr>';
        echo '<tr><th><label>Folio</label></th><td><input name="folio" class="regular-text" /></td></tr>';
        echo '<tr><th><label>Fecha factura</label></th><td><input name="fecha_factura" type="date" /></td></tr>';
        echo '<tr><th><label>Monto</label></th><td><input name="monto" type="number" step="0.01" /></td></tr>';
        echo '<tr><th><label>Moneda</label></th><td><input name="moneda" value="CLP" class="small-text" /></td></tr>';
        echo '<tr><th><label>Link PDF (Drive)</label></th><td><input name="pdf_url" type="url" class="large-text" placeholder="https://drive.google.com/..." /></td></tr>';
        echo '</tbody></table>';
        echo '<p><button class="button button-primary" name="wpfp_add" value="1">Guardar</button></p>';
        echo '</form></div>';
    }

    public static function admin_page_edit() {
        if (!current_user_can('manage_options')) return;

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) {
            echo '<div class="wrap"><h1>Editar factura</h1><div class="notice notice-error"><p>ID inválido.</p></div></div>';
            return;
        }

        $row = self::get_factura($id);
        if (!$row) {
            echo '<div class="wrap"><h1>Editar factura</h1><div class="notice notice-error"><p>No existe la factura.</p></div></div>';
            return;
        }

        $msg = '';
        $err = '';

        if (!empty($_POST['wpfp_save']) && check_admin_referer('wpfp_edit_factura', 'wpfp_nonce')) {
            $data = [
                'proveedor' => sanitize_text_field($_POST['proveedor'] ?? ''),
                'folio' => sanitize_text_field($_POST['folio'] ?? ''),
                'fecha_factura' => sanitize_text_field($_POST['fecha_factura'] ?? ''),
                'monto' => sanitize_text_field($_POST['monto'] ?? ''),
                'moneda' => sanitize_text_field($_POST['moneda'] ?? 'CLP'),
                'pdf_url' => esc_url_raw($_POST['pdf_url'] ?? ''),
                'observacion' => sanitize_textarea_field($_POST['observacion'] ?? ''),
                'estado' => sanitize_text_field($_POST['estado'] ?? 'pendiente'),
            ];

            $ok = self::update_factura($id, $data);
            if ($ok === false) {
                $err = 'No se pudo guardar.';
            } else {
                $msg = 'Cambios guardados.';
                $row = self::get_factura($id); // recargar
            }
        }

        $back = admin_url('admin.php?page=wpfp_facturas');

        echo '<div class="wrap"><h1>Editar factura #'.(int)$row->id.'</h1>';
        echo '<p><a href="'.esc_url($back).'">← Volver al listado</a></p>';

        if ($msg) echo '<div class="notice notice-success"><p>'.esc_html($msg).'</p></div>';
        if ($err) echo '<div class="notice notice-error"><p>'.esc_html($err).'</p></div>';

        $monto_val = is_null($row->monto) ? '' : (string)$row->monto;

        echo '<form method="post" class="wpfp-form">';
        wp_nonce_field('wpfp_edit_factura', 'wpfp_nonce');

        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label>Proveedor</label></th><td><input name="proveedor" required class="regular-text" value="'.esc_attr($row->proveedor).'" /></td></tr>';
        echo '<tr><th><label>Folio</label></th><td><input name="folio" class="regular-text" value="'.esc_attr($row->folio).'" /></td></tr>';
        echo '<tr><th><label>Fecha factura</label></th><td><input name="fecha_factura" type="date" value="'.esc_attr($row->fecha_factura ? $row->fecha_factura : '').'" /></td></tr>';
        echo '<tr><th><label>Monto</label></th><td><input name="monto" type="number" step="0.01" value="'.esc_attr($monto_val).'" /></td></tr>';
        echo '<tr><th><label>Moneda</label></th><td><input name="moneda" value="'.esc_attr($row->moneda).'" class="small-text" /></td></tr>';
        echo '<tr><th><label>Link PDF (Drive)</label></th><td><input name="pdf_url" type="url" class="large-text" value="'.esc_attr($row->pdf_url).'" placeholder="https://drive.google.com/..." /></td></tr>';

        echo '<tr><th><label>Observación</label></th><td><textarea name="observacion" rows="4" class="large-text" placeholder="Observación / asignación">'.esc_textarea((string)$row->observacion).'</textarea></td></tr>';

        echo '<tr><th><label>Estado</label></th><td><select name="estado">';
        $states = ['pendiente'=>'Pendiente','asignado'=>'Asignado','duda'=>'Duda','cargada'=>'Cargada'];
        foreach ($states as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($row->estado, $k, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label>Asignado</label></th><td>';
        echo esc_html($row->assigned_at ? $row->assigned_at : '—');
        echo '</td></tr>';

        echo '<tr><th><label>Cargada</label></th><td>';
        echo esc_html($row->loaded_at ? $row->loaded_at : '—');
        echo '</td></tr>';

        echo '</tbody></table>';

        echo '<p><button class="button button-primary" name="wpfp_save" value="1">Guardar cambios</button></p>';
        echo '</form></div>';
    }


    public static function admin_page_settings() {
        if (!current_user_can('manage_options')) return;

        $settings = self::settings();
        $plain = get_option(self::OPTION_PLAIN_PASS, '');

        echo '<div class="wrap"><h1>Ajustes — Portal Facturas</h1>';

        if ($plain) {
            echo '<div class="notice notice-warning"><p><strong>Clave actual (temporal):</strong> <code>'.esc_html($plain).'</code> — Cámbiala y este aviso desaparecerá.</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>Si no recuerdas la clave, puedes regenerarla abajo.</p></div>';
        }

        echo '<form method="post" action="options.php" class="wpfp-form">';
        settings_fields('wpfp_settings_group');
        $opt = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($opt)) $opt = [];
        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label>Duración sesión (horas)</label></th><td>';
        printf('<input name="%s[session_hours]" type="number" min="1" max="72" value="%s" />', esc_attr(self::OPTION_SETTINGS), esc_attr($settings['session_hours']));
        echo '<p class="description">Tiempo que el cliente queda “logueado” en el portal.</p></td></tr>';

        echo '<tr><th><label>Vista por defecto</label></th><td>';
        echo '<select name="'.esc_attr(self::OPTION_SETTINGS).'[default_view]">';
        foreach (['pendiente'=>'Pendiente','asignado'=>'Asignado','duda'=>'Duda','cargada'=>'Cargada','todas'=>'Todas'] as $k=>$label) {
            $val = ($k==='todas') ? '' : $k;
            $sel = selected($settings['default_view'], ($k==='todas'?'':$k), false);
            echo '<option value="'.esc_attr($val).'" '.$sel.'>'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label>Nueva clave</label></th><td>';
        printf('<input name="%s[new_password]" type="text" class="regular-text" placeholder="Deja vacío para mantener" />', esc_attr(self::OPTION_SETTINGS));
        echo '<p class="description">Al guardar una nueva clave, se elimina la clave temporal almacenada.</p></td></tr>';

        echo '<tr><th><label>Regenerar clave</label></th><td>';
        printf('<label><input name="%s[regenerate_password]" type="checkbox" value="1" /> Generar una clave nueva aleatoria</label>', esc_attr(self::OPTION_SETTINGS));
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button('Guardar ajustes');
        echo '</form>';

        echo '<hr /><h2>Recomendación de seguridad</h2>';
        echo '<ol><li>Crea una URL no obvia para la página del portal (slug largo).</li><li>Activa HTTPS.</li><li>Ideal: agrega una capa extra (Cloudflare Access o Basic Auth).</li></ol>';

        echo '</div>';
    }

    /* ------------------------------
     * Frontend standalone (sin theme)
     * ------------------------------ */
    public static function maybe_render_standalone_portal() {
        if (is_admin()) return;
        if (!isset($_GET['wpfp_portal'])) return;

        show_admin_bar(false);

        $body = self::render_portal_frontend();
        self::render_blank_page('Portal Facturas', $body);
        exit;
    }

    private static function render_blank_page($title, $body_html) {
        $css = plugins_url('assets/portal.css', __FILE__);
        $js  = plugins_url('assets/portal.js', __FILE__);
        $jquery = includes_url('js/jquery/jquery.min.js');

        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('wpfp_portal_nonce');

        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        ?>
        <!doctype html>
        <html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
        <head>
            <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title><?php echo esc_html($title ? $title : 'Portal Facturas'); ?></title>
            <link rel="stylesheet" href="<?php echo esc_url($css); ?>">
            <style>
                html,body{height:100%;margin:0;background:#f5f6f8}
                /* evita estilos del theme */
                body *{box-sizing:border-box}
            </style>
        </head>
        <body>
            <?php echo $body_html; ?>
            <script>window.WPFPP = <?php echo wp_json_encode(['ajax_url'=>$ajax,'nonce'=>$nonce]); ?>;</script>
            <script src="<?php echo esc_url($jquery); ?>"></script>
            <script src="<?php echo esc_url($js); ?>"></script>
        </body>
        </html>
        <?php
    }

    /* ------------------------------
     * Frontend portal
     * ------------------------------ */
    private static function render_portal_frontend() {
        $settings = self::settings();

        // Logout via query param
        if (isset($_GET['wpfp_logout'])) {
            self::clear_cookie();
        }

        if (!self::is_portal_authed()) {
            return self::render_login();
        }

        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'list';
        $view = strtolower(trim((string)$view));
        if (!in_array($view, ['list','monthly'], true)) $view = 'list';

        $estado = isset($_GET['estado']) ? sanitize_text_field($_GET['estado']) : $settings['default_view'];
        $proveedor = isset($_GET['proveedor']) ? sanitize_text_field($_GET['proveedor']) : '';
        $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $date_from = null;
        $date_to = null;
        $ym = null;
        $include_no_date = true;

        if ($view === 'monthly') {
            $ym = isset($_GET['ym']) ? sanitize_text_field($_GET['ym']) : '';
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                $ym = wp_date('Y-m', current_time('timestamp'));
            }

            try {
                $dt = new DateTime($ym . '-01');
            } catch (Exception $e) {
                $dt = new DateTime(wp_date('Y-m-01', current_time('timestamp')));
            }

            $date_from = $dt->format('Y-m-01');
            $dt2 = clone $dt;
            $dt2->modify('first day of next month');
            $date_to = $dt2->format('Y-m-d');
        }

        $order = ($view === 'monthly')
            ? 'proveedor ASC, fecha_factura DESC, created_at DESC'
            : 'proveedor ASC, created_at DESC';

        $rows = self::get_facturas([
            'estado' => $estado,
            'proveedor' => $proveedor,
            'q' => $q,
            'limit' => 800,
            'order' => $order,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'include_no_date' => $include_no_date,
        ]);

        $proveedores = self::get_proveedores($estado, $date_from, $date_to, $include_no_date);

        // Totales de la vista actual
        $total_count = is_array($rows) ? count($rows) : 0;
        $total_monto = 0.0;
        if ($rows) {
            foreach ($rows as $r) {
                if (!is_null($r->monto)) $total_monto += (float)$r->monto;
            }
        }

        // Prev/next mes (solo monthly)
        $prev_ym = $next_ym = '';
        if ($view === 'monthly' && $ym) {
            $dtm = new DateTime($ym . '-01');
            $p = clone $dtm; $p->modify('-1 month');
            $n = clone $dtm; $n->modify('+1 month');
            $prev_ym = $p->format('Y-m');
            $next_ym = $n->format('Y-m');
        }

        ob_start();
        ?>
        <div class="wpfp-portal">
            <div class="wpfp-topbar">
                <div class="wpfp-title">
                    <?php echo ($view === 'monthly') ? 'Facturas — Vista mensual' : 'Bandeja de Facturas'; ?>
                </div>
                <div class="wpfp-actions">
                    <a class="wpfp-link" href="<?php echo esc_url(add_query_arg('wpfp_logout','1')); ?>">Salir</a>
                </div>
            </div>

            <form class="wpfp-filters" method="get">
                <?php
                    // Preserve page vars
                    foreach ($_GET as $k=>$v) {
                        if (in_array($k, ['estado','proveedor','q','ym','wpfp_logout'])) continue;
                        printf('<input type="hidden" name="%s" value="%s" />', esc_attr($k), esc_attr($v));
                    }
                ?>

                <?php if ($view === 'monthly'): ?>
                    <div class="wpfp-monthbar">
                        <a class="wpfp-monthbtn" href="<?php echo esc_url(add_query_arg(['ym'=>$prev_ym])); ?>">◀</a>
                        <input type="month" name="ym" value="<?php echo esc_attr($ym); ?>" />
                        <a class="wpfp-monthbtn" href="<?php echo esc_url(add_query_arg(['ym'=>$next_ym])); ?>">▶</a>
                    </div>
                <?php endif; ?>

                <select name="estado">
                    <option value="">— Todos —</option>
                    <?php
                    $states = ['pendiente'=>'Pendiente','asignado'=>'Asignado','duda'=>'Duda','cargada'=>'Cargada'];
                    foreach ($states as $k=>$label) {
                        printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($estado, $k, false), esc_html($label));
                    }
                    ?>
                </select>

                <select name="proveedor">
                    <option value="">— Proveedor —</option>
                    <?php foreach ($proveedores as $p): ?>
                        <option value="<?php echo esc_attr($p); ?>" <?php selected($proveedor, $p); ?>><?php echo esc_html($p); ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Buscar folio/proveedor" />
                <button type="submit">Filtrar</button>
            </form>

            <div class="wpfp-summary">
                <div><strong><?php echo (int)$total_count; ?></strong> facturas en vista</div>
                <div><strong><?php echo number_format($total_monto, 0, ',', '.'); ?></strong> CLP (suma vista)</div>
            </div>

            <div class="wpfp-hint">
                Escribe una observación y presiona <strong>Guardar</strong>. Al guardar, la factura pasa a <strong>Asignado</strong> automáticamente.
            </div>

            <div class="wpfp-tablewrap">
                <table class="wpfp-table">
                    <thead>
                        <tr>
                            <th>Proveedor</th>
                            <th>Folio</th>
                            <th>Fecha</th>
                            <th>Monto</th>
                            <th>PDF</th>
                            <th>Observación</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="wpfp-empty">Sin facturas.</td></tr>
                    <?php else:
                        $lastProv = null;
                        foreach ($rows as $r):
                            if ($lastProv !== $r->proveedor) {
                                $lastProv = $r->proveedor;
                                echo '<tr class="wpfp-group"><td colspan="8">'.esc_html($lastProv).'</td></tr>';
                            }
                            $pdf = $r->pdf_url ? '<a href="'.esc_url($r->pdf_url).'" target="_blank" rel="noopener">Abrir PDF</a>' : '—';
                            $monto = is_null($r->monto) ? '—' : number_format((float)$r->monto, 0, ',', '.').' '.esc_html($r->moneda);
                            ?>
                            <tr data-id="<?php echo (int)$r->id; ?>">
                                <td class="wpfp-cell-proveedor"><?php echo esc_html($r->proveedor); ?></td>
                                <td><?php echo esc_html($r->folio); ?></td>
                                <td><?php echo esc_html($r->fecha_factura ? $r->fecha_factura : '—'); ?></td>
                                <td><?php echo esc_html($monto); ?></td>
                                <td><?php echo $pdf; ?></td>
                                <td>
                                    <input type="text" class="wpfp-obs" value="<?php echo esc_attr((string)$r->observacion); ?>" placeholder="Escribe aquí..." />
                                </td>
                                <td><span class="wpfp-badge wpfp-<?php echo esc_attr($r->estado); ?>"><?php echo esc_html(ucfirst($r->estado)); ?></span></td>
                                <td>
                                    <button type="button" class="wpfp-save">Guardar</button>
                                </td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="wpfp-toast" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
private static function render_login() {
        $err = '';
        if (!empty($_POST['wpfp_pass']) && isset($_POST['wpfp_login']) && wp_verify_nonce($_POST['wpfp_login'], 'wpfp_login')) {
            $pass = (string)$_POST['wpfp_pass'];
            if (self::check_password($pass)) {
                self::set_cookie();
                // redirect to avoid resubmission
                wp_safe_redirect(self::current_url_no_post());
                exit;
            } else {
                $err = 'Clave incorrecta.';
            }
        }

        ob_start(); ?>
        <div class="wpfp-portal wpfp-login">
            <div class="wpfp-login-card">
                <div class="wpfp-title">Acceso Portal Facturas</div>
                <div class="wpfp-sub">Ingresa la clave para ver y asignar facturas.</div>
                <?php if ($err): ?><div class="wpfp-error"><?php echo esc_html($err); ?></div><?php endif; ?>
                <form method="post">
                    <?php wp_nonce_field('wpfp_login', 'wpfp_login'); ?>
                    <input type="password" name="wpfp_pass" placeholder="Clave" required />
                    <button type="submit">Entrar</button>
                </form>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    private static function current_url_no_post() {
        $url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        // remove wpnonce / post vars not in query
        return $url;
    }

    /* ------------------------------
     * Auth cookie
     * ------------------------------ */
    private static function set_cookie() {
        $settings = self::settings();
        $hours = (int)$settings['session_hours'];
        $exp = time() + ($hours * 3600);
        $payload = ['exp' => $exp, 'v' => 1];
        $b64 = self::b64url_encode(wp_json_encode($payload));
        $sig = hash_hmac('sha256', $b64, wp_salt('auth'));
        $token = $b64 . '.' . $sig;

        setcookie(self::COOKIE_NAME, $token, [
            'expires' => $exp,
            'path' => COOKIEPATH ? COOKIEPATH : '/',
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $token;
    }

    private static function clear_cookie() {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => COOKIEPATH ? COOKIEPATH : '/',
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    public static function ajax_logout() {
        self::clear_cookie();
        wp_send_json_success(['ok'=>1]);
    }

    private static function is_portal_authed() {
        if (empty($_COOKIE[self::COOKIE_NAME])) return false;
        $token = (string)$_COOKIE[self::COOKIE_NAME];
        $parts = explode('.', $token);
        if (count($parts) !== 2) return false;
        [$b64, $sig] = $parts;
        $calc = hash_hmac('sha256', $b64, wp_salt('auth'));
        if (!hash_equals($calc, $sig)) return false;
        $payload_json = self::b64url_decode($b64);
        $payload = json_decode($payload_json, true);
        if (!is_array($payload) || empty($payload['exp'])) return false;
        if ((int)$payload['exp'] < time()) return false;
        return true;
    }

    private static function b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) $data .= str_repeat('=', 4 - $remainder);
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private static function check_password($pass) {
        $settings = self::settings();
        if (empty($settings['password_hash'])) return false;
        return wp_check_password($pass, $settings['password_hash']);
    }

    /* ------------------------------
     * AJAX: update observacion -> auto estado asignado
     * ------------------------------ */
    public static function ajax_update_factura() {
        if (!self::is_portal_authed()) wp_send_json_error(['message'=>'No autorizado'], 403);
        if (!check_ajax_referer('wpfp_portal_nonce', 'nonce', false)) wp_send_json_error(['message'=>'Nonce inválido'], 403);

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $obs = isset($_POST['observacion']) ? sanitize_text_field($_POST['observacion']) : '';

        if (!$id) wp_send_json_error(['message'=>'ID inválido'], 400);

        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
        if (!$row) wp_send_json_error(['message'=>'No existe'], 404);

        // Si está cargada, no permitir cambios
        if ($row->estado === 'cargada') {
            wp_send_json_error(['message'=>'Esta factura está marcada como Cargada.'], 409);
        }

        $now = current_time('mysql');

        $new_estado = trim($obs) !== '' ? 'asignado' : 'pendiente';
        $assigned_at = trim($obs) !== '' ? $now : null;
        $assigned_by = trim($obs) !== '' ? 'Cliente' : null;

        $updated = $wpdb->update($table, [
            'observacion' => $obs,
            'estado' => $new_estado,
            'assigned_at' => $assigned_at,
            'assigned_by' => $assigned_by,
        ], ['id' => $id], ['%s','%s','%s','%s'], ['%d']);

        if ($updated === false) wp_send_json_error(['message'=>'No se pudo guardar'], 500);

        wp_send_json_success([
            'id' => $id,
            'estado' => $new_estado
        ]);
    }

    /* ------------------------------
     * DB helpers
     * ------------------------------ */
    private static function insert_factura($data) {
        global $wpdb;
        $table = self::table_name();

        $proveedor = $data['proveedor'] ?? '';
        $folio = $data['folio'] ?? '';
        $fecha_factura = $data['fecha_factura'] ?? null;
        $monto = $data['monto'] !== '' ? (float)$data['monto'] : null;
        $moneda = $data['moneda'] ?? 'CLP';
        $pdf_url = $data['pdf_url'] ?? '';
        $estado = $data['estado'] ?? 'pendiente';

        $ok = $wpdb->insert($table, [
            'created_at' => current_time('mysql'),
            'proveedor' => $proveedor,
            'folio' => $folio,
            'fecha_factura' => $fecha_factura ?: null,
            'monto' => $monto,
            'moneda' => $moneda,
            'pdf_url' => $pdf_url,
            'observacion' => '',
            'estado' => $estado,
        ], ['%s','%s','%s','%s','%f','%s','%s','%s','%s']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    private static function get_factura($id) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", (int)$id));
    }

    private static function update_factura($id, $data) {
        global $wpdb;
        $table = self::table_name();
        $id = (int)$id;
        if (!$id) return false;

        $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
        if (!$current) return false;

        $allowed_states = ['pendiente','asignado','duda','cargada'];

        $proveedor = sanitize_text_field($data['proveedor'] ?? $current->proveedor);
        $folio = sanitize_text_field($data['folio'] ?? $current->folio);

        $fecha_factura = sanitize_text_field($data['fecha_factura'] ?? '');
        $fecha_factura = $fecha_factura !== '' ? $fecha_factura : null;

        $monto_raw = isset($data['monto']) ? trim((string)$data['monto']) : '';
        $monto = $monto_raw !== '' ? (float)$monto_raw : null;

        $moneda = sanitize_text_field($data['moneda'] ?? $current->moneda);
        $pdf_url = esc_url_raw($data['pdf_url'] ?? $current->pdf_url);

        $observacion = isset($data['observacion']) ? sanitize_textarea_field($data['observacion']) : (string)$current->observacion;

        $estado_in = sanitize_text_field($data['estado'] ?? $current->estado);
        $estado = in_array($estado_in, $allowed_states, true) ? $estado_in : (string)$current->estado;

        $now = current_time('mysql');

        // Timestamps según estado (admin puede forzar estados)
        $assigned_at = $current->assigned_at;
        $assigned_by = $current->assigned_by;
        $loaded_at = $current->loaded_at;
        $loaded_by = $current->loaded_by;

        if ($estado === 'asignado') {
            if (!$assigned_at) $assigned_at = $now;
            if (!$assigned_by) $assigned_by = 'Admin';
        } elseif ($estado === 'pendiente' || $estado === 'duda') {
            // Reabrir
            $assigned_at = null;
            $assigned_by = null;
            if ($estado !== 'cargada') {
                $loaded_at = null;
                $loaded_by = null;
            }
        }

        if ($estado === 'cargada') {
            if (!$loaded_at) $loaded_at = $now;
            if (!$loaded_by) $loaded_by = 'Admin';
        } else {
            // Si sale de cargada, limpia cargada
            if ($current->estado === 'cargada') {
                $loaded_at = null;
                $loaded_by = null;
            }
        }

        $updated = $wpdb->update($table, [
            'proveedor' => $proveedor,
            'folio' => $folio,
            'fecha_factura' => $fecha_factura,
            'monto' => $monto,
            'moneda' => $moneda,
            'pdf_url' => $pdf_url,
            'observacion' => $observacion,
            'estado' => $estado,
            'assigned_at' => $assigned_at,
            'assigned_by' => $assigned_by,
            'loaded_at' => $loaded_at,
            'loaded_by' => $loaded_by,
        ], ['id' => $id],
        ['%s','%s','%s','%f','%s','%s','%s','%s','%s','%s','%s','%s'],
        ['%d']);

        if ($updated === false) return false;
        return true;
    }


    private static function get_facturas($args=[]) {
        global $wpdb;
        $table = self::table_name();

        $where = "WHERE 1=1";
        $params = [];

        if (!empty($args['estado'])) {
            $where .= " AND estado=%s";
            $params[] = $args['estado'];
        }

        if (!empty($args['proveedor'])) {
            $where .= " AND proveedor=%s";
            $params[] = $args['proveedor'];
        }

        if (!empty($args['q'])) {
            $q = '%' . $wpdb->esc_like($args['q']) . '%';
            $where .= " AND (proveedor LIKE %s OR folio LIKE %s)";
            $params[] = $q;
            $params[] = $q;
        }

        // Filtro por rango de fechas (fecha_factura). Útil para vista mensual.
        if (!empty($args['date_from']) && !empty($args['date_to'])) {
            $df = sanitize_text_field($args['date_from']);
            $dt = sanitize_text_field($args['date_to']);
            $include_no_date = isset($args['include_no_date']) ? (bool)$args['include_no_date'] : false;

            if ($include_no_date) {
                $where .= " AND ((fecha_factura >= %s AND fecha_factura < %s) OR fecha_factura IS NULL)";
            } else {
                $where .= " AND (fecha_factura >= %s AND fecha_factura < %s)";
            }
            $params[] = $df;
            $params[] = $dt;
        }


        $order = !empty($args['order']) ? $args['order'] : 'created_at DESC';
        $limit = !empty($args['limit']) ? (int)$args['limit'] : 200;
        $limit = max(1, min(2000, $limit));

        $sql = "SELECT * FROM {$table} {$where} ORDER BY {$order} LIMIT {$limit}";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return $wpdb->get_results($sql);
    }

    private static function get_proveedores($estado='', $date_from=null, $date_to=null, $include_no_date=true) {
        global $wpdb;
        $table = self::table_name();

        $where = "WHERE proveedor<>''";
        $params = [];

        if ($estado) {
            $where .= " AND estado=%s";
            $params[] = $estado;
        }

        if ($date_from && $date_to) {
            if ($include_no_date) {
                $where .= " AND ((fecha_factura >= %s AND fecha_factura < %s) OR fecha_factura IS NULL)";
            } else {
                $where .= " AND (fecha_factura >= %s AND fecha_factura < %s)";
            }
            $params[] = $date_from;
            $params[] = $date_to;
        }

        $sql = "SELECT DISTINCT proveedor FROM {$table} {$where} ORDER BY proveedor ASC";
        if ($params) $sql = $wpdb->prepare($sql, $params);
        return $wpdb->get_col($sql);
    }

    private static function mark_loaded($ids) {
        global $wpdb;
        $table = self::table_name();
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) return;
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET estado='cargada', loaded_at=%s, loaded_by=%s WHERE id IN ($in)", array_merge([$now, 'Admin'], $ids)));
    }

    private static function mark_pending($ids) {
        global $wpdb;
        $table = self::table_name();
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) return;
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET estado='pendiente' WHERE id IN ($in)", $ids));
    }

    private static function delete_facturas($ids) {
        global $wpdb;
        $table = self::table_name();
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) return;
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($in)", $ids));
    }
}

WPFPP_Facturas_Portal::init();
