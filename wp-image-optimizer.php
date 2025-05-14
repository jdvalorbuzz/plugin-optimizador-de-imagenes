<?php
/**
 * Plugin Name: Buzz Costa Rica - Plugin de Optimización de Imágenes
 * Plugin URI: https://buzz.cr
 * Description: Optimiza imágenes convirtiéndolas a WebP, reduciendo su tamaño en aproximadamente 80% y procesando por lotes. Desarrollado por Buzz Costa Rica (https://buzz.cr).
 * Version: 1.1.3
 * Author: Buzz Costa Rica
 * Author URI: https://buzz.cr
 * License: GPL v2 or later
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class WP_Image_Optimizer {
    // Opciones por defecto
    private $default_options = [
        'compression_level' => 80, // Nivel de compresión (0-100)
        'convert_to_webp' => true, // Convertir a WebP
        'keep_original' => true, // Mantener original como respaldo
        'batch_size' => 20, // Número de imágenes por lote
        'resize_images' => true, // Redimensionar imágenes grandes
        'max_width' => 1920, // Ancho máximo en píxeles
        'max_height' => 1920, // Alto máximo en píxeles
        'use_background_processing' => true, // Usar procesamiento en segundo plano
        'debug_mode' => false, // Modo de depuración
        'auto_convert_to_webp' => true, // Servir automáticamente las versiones WebP
        'safety_mode' => true, // Omitir imágenes problemáticas para evitar errores
    ];
    
    // Formatos de imagen soportados
    private $supported_mime_types = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif'
    ];
    
    // Lista de imágenes problemáticas (para no reintentar indefinidamente)
    private $problem_images = [];

    // Constructor
    public function __construct() {
        // Establecer límites de tiempo y memoria seguros
        @set_time_limit(120);
        @ini_set('memory_limit', '256M');
        
        // Inicializar plugin
        add_action('init', [$this, 'init']);
        
        // Agregar menú de administración
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Registrar AJAX para procesamiento por lotes
        add_action('wp_ajax_process_images_batch', [$this, 'process_images_batch']);
        add_action('wp_ajax_get_optimization_stats', [$this, 'ajax_get_optimization_stats']);
        add_action('wp_ajax_get_total_images', [$this, 'ajax_get_total_images']);
        add_action('wp_ajax_get_background_status', [$this, 'ajax_get_background_status']);
        add_action('wp_ajax_reset_problem_images', [$this, 'ajax_reset_problem_images']);
        
        // Agregar scripts y estilos
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Registrar hook de activación y desactivación
        register_activation_hook(__FILE__, [$this, 'plugin_activation']);
        register_deactivation_hook(__FILE__, [$this, 'plugin_deactivation']);
        
        // Agregar acción para cron de procesamiento en segundo plano
        add_action('wp_image_optimizer_cron_task', [$this, 'process_background_optimization']);
        
        // Modificar media upload para redimensionar imágenes
        add_filter('wp_handle_upload', [$this, 'handle_image_upload'], 10);
        
        // Registrar las opciones
        add_action('admin_init', [$this, 'register_settings']);
        
        // Funcionalidad de entrega WebP
        add_action('wp_head', [$this, 'add_webp_support_detection']);
        add_filter('the_content', [$this, 'replace_images_with_webp']);
        add_filter('wp_get_attachment_image_src', [$this, 'filter_attachment_image_src'], 10, 4);
        add_filter('wp_calculate_image_srcset', [$this, 'filter_srcset_for_webp'], 10, 5);
        
        // Soporte para Elementor
        add_filter('elementor/frontend/builder_content_data', [$this, 'process_elementor_images'], 10, 2);
        
        // Cargar lista de imágenes problemáticas
        $this->problem_images = get_option('wp_image_optimizer_problem_images', []);
    }

    // Inicializar plugin
    public function init() {
        // Comprobar si existen las opciones, si no, crear con valores por defecto
        $options = get_option('wp_image_optimizer_options');
        if (!$options) {
            update_option('wp_image_optimizer_options', $this->default_options);
        } else {
            // Asegurar que todas las opciones existan
            $merged_options = array_merge($this->default_options, $options);
            if ($merged_options !== $options) {
                update_option('wp_image_optimizer_options', $merged_options);
            }
        }
        
        // Agregar intervalo personalizado para el cron
        add_filter('cron_schedules', function($schedules) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display' => __('Cada 5 minutos')
            ];
            return $schedules;
        });
    }
    
    // Registrar configuraciones
    public function register_settings() {
        register_setting('wp_image_optimizer_options', 'wp_image_optimizer_options');
    }

    // Hook de activación
    public function plugin_activation() {
        // Verificar dependencias
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('Este plugin requiere la extensión GD o Imagick de PHP. Por favor, contacte a su administrador de hosting para habilitarlas.');
        }
        
        // Crear directorio de caché si no existe
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/wp-image-optimizer-cache';
        
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            
            // Crear archivo .htaccess para proteger la carpeta
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents($cache_dir . '/.htaccess', $htaccess_content);
        }
        
        // Configurar WP Cron para procesamiento en segundo plano
        if (!wp_next_scheduled('wp_image_optimizer_cron_task')) {
            wp_schedule_event(time(), 'five_minutes', 'wp_image_optimizer_cron_task');
        }
    }
    
    // Función para desactivación
    public function plugin_deactivation() {
        // Limpiar evento cron
        $timestamp = wp_next_scheduled('wp_image_optimizer_cron_task');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wp_image_optimizer_cron_task');
        }
    }

    // Agregar menú de administración
    public function add_admin_menu() {
        add_media_page(
            'Optimizador de Imágenes',
            'Optimizador de Imágenes',
            'manage_options',
            'wp-image-optimizer',
            [$this, 'render_admin_page']
        );
    }

    // Encolar scripts y estilos
    public function enqueue_scripts($hook) {
        if ('media_page_wp-image-optimizer' !== $hook) {
            return;
        }
        
        wp_enqueue_script('wp-image-optimizer-js', plugin_dir_url(__FILE__) . 'assets/js/admin.js', ['jquery'], '1.1.3', true);
        wp_localize_script('wp-image-optimizer-js', 'wp_image_optimizer', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp-image-optimizer-nonce'),
        ]);
        
        wp_enqueue_style('wp-image-optimizer-css', plugin_dir_url(__FILE__) . 'assets/css/admin.css', [], '1.1.3');
    }

    // Renderizar página de administración
    public function render_admin_page() {
        $options = get_option('wp_image_optimizer_options');
        $problem_images_count = is_array($this->problem_images) ? count($this->problem_images) : 0;
        ?>
        <div class="wrap buzzcr-optimizer-wrap">
            <div style="display:flex;align-items:center;gap:18px;margin-bottom:18px;">
                <img src="https://buzz.cr/wp-content/uploads/2024/07/logo.png" alt="Buzz Costa Rica" style="height:48px;width:auto;vertical-align:middle;">
                <h1 style="margin:0;">Buzz Costa Rica - Plugin de Optimización de Imágenes <a href="https://buzz.cr" target="_blank">buzz.cr</a></h1>
            </div>
            <div class="buzzcr-columns">
                <div class="buzzcr-col">
                    <?php if ($problem_images_count > 0): ?>
                    <div class="notice notice-warning">
                        <p><strong>Atención:</strong> Se han detectado <?php echo $problem_images_count; ?> imágenes problemáticas que se han omitido para evitar errores.</p>
                        <p><button id="reset-problem-images" class="button">Reiniciar lista de imágenes problemáticas</button></p>
                    </div>
                    <?php endif; ?>
                    <div class="card">
                        <h2>Configuración</h2>
                        <form method="post" action="options.php" id="optimizer-settings">
                            <?php settings_fields('wp_image_optimizer_options'); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Nivel de compresión</th>
                                    <td>
                                        <input type="range" name="wp_image_optimizer_options[compression_level]" 
                                            min="0" max="100" value="<?php echo esc_attr($options['compression_level']); ?>" 
                                            class="compression-slider" id="compression-slider">
                                        <span id="compression-value"><?php echo esc_html($options['compression_level']); ?>%</span>
                                        <p class="description">Mayor valor = mejor calidad pero archivos más grandes</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Convertir a WebP</th>
                                    <td>
                                        <input type="checkbox" name="wp_image_optimizer_options[convert_to_webp]" 
                                            <?php checked($options['convert_to_webp']); ?> value="1">
                                        <p class="description">Crear versiones WebP para todas las imágenes</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Servir WebP automáticamente</th>
                                    <td>
                                        <input type="checkbox" name="wp_image_optimizer_options[auto_convert_to_webp]" 
                                            <?php checked(isset($options['auto_convert_to_webp']) ? $options['auto_convert_to_webp'] : true); ?> value="1">
                                        <p class="description">Reemplazar automáticamente todas las imágenes JPG/PNG por WebP cuando el navegador lo soporte</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Mantener originales</th>
                                    <td>
                                        <input type="checkbox" name="wp_image_optimizer_options[keep_original]" 
                                            <?php checked($options['keep_original']); ?> value="1">
                                        <p class="description">Mantener las imágenes originales como respaldo</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Modo de seguridad</th>
                                    <td>
                                        <input type="checkbox" name="wp_image_optimizer_options[safety_mode]" 
                                            <?php checked(isset($options['safety_mode']) ? $options['safety_mode'] : true); ?> value="1">
                                        <p class="description"><strong>Recomendado:</strong> Omitir automáticamente imágenes problemáticas para evitar errores</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Imágenes por lote</th>
                                    <td>
                                        <input type="number" name="wp_image_optimizer_options[batch_size]" 
                                            min="1" max="50" value="<?php echo esc_attr($options['batch_size']); ?>">
                                        <p class="description">Número de imágenes a procesar por lote (recomendado: 10-20)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Redimensionar imágenes</th>
                                    <td>
                                        <input type="checkbox" name="wp_image_optimizer_options[resize_images]" 
                                            <?php checked($options['resize_images']); ?> value="1">
                                        <p class="description">Redimensionar automáticamente imágenes grandes</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Dimensiones máximas</th>
                                    <td>
                                        <label>
                                            Ancho: <input type="number" name="wp_image_optimizer_options[max_width]" 
                                            min="100" max="5000" value="<?php echo esc_attr($options['max_width']); ?>" style="width: 100px;">
                                        </label>
                                        &nbsp;&nbsp;
                                        <label>
                                            Alto: <input type="number" name="wp_image_optimizer_options[max_height]" 
                                            min="100" max="5000" value="<?php echo esc_attr($options['max_height']); ?>" style="width: 100px;">
                                        </label>
                                        <p class="description">Las imágenes más grandes que estas dimensiones serán redimensionadas proporcionalmente</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Procesamiento en segundo plano</th>
                                    <td>
                                        <input type="checkbox" name="wp_image_optimizer_options[use_background_processing]" 
                                            <?php checked($options['use_background_processing']); ?> value="1">
                                        <p class="description">Utiliza WP Cron para procesar imágenes en segundo plano (recomendado para sitios con muchas imágenes)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Modo depuración</th>
                                    <td>
                                        <input type="checkbox" name="wp_image_optimizer_options[debug_mode]" 
                                            <?php checked(isset($options['debug_mode']) ? $options['debug_mode'] : false); ?> value="1">
                                        <p class="description">Registrar información detallada en el log de errores</p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button('Guardar cambios'); ?>
                        </form>
                    </div>
                    <div class="card">
                        <h2>Optimización por lotes</h2>
                        <div class="optimization-controls">
                            <button id="start-optimization" class="button button-primary">Iniciar optimización</button>
                            <button id="stop-optimization" class="button" disabled>Detener</button>
                            <div class="progress-bar-container">
                                <div class="progress-bar" id="optimization-progress"></div>
                            </div>
                            <div class="progress-text">
                                <span id="optimized-count">0</span> de <span id="total-images">0</span> imágenes optimizadas
                            </div>
                        </div>
                        <div id="optimization-log"></div>
                    </div>
                </div>
                <div class="buzzcr-col">
                    <div class="card">
                        <h2>Estadísticas</h2>
                        <div id="optimization-stats">
                            <p>Total de imágenes: <span id="stat-total-images">0</span></p>
                            <p>Imágenes optimizadas: <span id="stat-optimized-images">0</span></p>
                            <p>Espacio ahorrado: <span id="stat-saved-space">0 MB</span></p>
                            <p>Reducción promedio: <span id="stat-average-reduction">0%</span></p>
                            <?php if ($problem_images_count > 0): ?>
                            <p>Imágenes omitidas: <span><?php echo $problem_images_count; ?></span></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (isset($options['debug_mode']) && $options['debug_mode']): ?>
                    <div class="card">
                        <h2>Información de depuración</h2>
                        <div>
                            <p><strong>Imagick disponible:</strong> <?php echo extension_loaded('imagick') ? 'Sí' : 'No'; ?></p>
                            <p><strong>GD disponible:</strong> <?php echo extension_loaded('gd') ? 'Sí' : 'No'; ?></p>
                            <p><strong>WebP soportado (GD):</strong> <?php echo function_exists('imagewebp') ? 'Sí' : 'No'; ?></p>
                            <p><strong>Directorio uploads escribible:</strong> <?php 
                                $upload_dir = wp_upload_dir();
                                echo is_writable($upload_dir['basedir']) ? 'Sí' : 'No'; 
                            ?></p>
                            
                            <?php if ($problem_images_count > 0): ?>
                            <h3>Imágenes problemáticas (máx. 10):</h3>
                            <ul>
                                <?php 
                                $counter = 0;
                                foreach ($this->problem_images as $image_id => $data) {
                                    if ($counter++ >= 10) break;
                                    echo '<li>ID: ' . esc_html($image_id) . ' - ' . esc_html($data['file']) . '</li>';
                                }
                                ?>
                            </ul>
                            <?php endif; ?>
                            
                            <h3>Formatos soportados por el plugin:</h3>
                            <ul>
                                <li>JPEG (image/jpeg, image/jpg)</li>
                                <li>PNG (image/png) - <em>Nota: Los PNG de paleta indexada son detectados y omitidos para WebP</em></li>
                                <li>GIF (image/gif)</li>
                            </ul>
                            <h3>Todos los tipos MIME detectados:</h3>
                            <pre><?php 
                                $mime_types = get_allowed_mime_types();
                                $image_types = array_filter($mime_types, function($mime) {
                                    return strpos($mime, 'image/') === 0;
                                });
                                print_r($image_types);
                            ?></pre>
                            <h3>Nota sobre formatos no soportados:</h3>
                            <p>SVG, AVIF, WEBP y otros formatos vectoriales o ya optimizados no se procesan porque no necesitan conversión.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="buzzcr-fullwidth-doc">
                <div class="card">
                    <h2>¿Cómo funciona este plugin?</h2>
                    <div style="font-size:15px;line-height:1.7;">
                        <strong>Buzz Image Optimizer</strong> es un plugin desarrollado por <a href="https://buzz.cr" target="_blank">Buzz Costa Rica</a> para optimizar imágenes en WordPress de forma eficiente y segura.<br><br>
                        <strong>Características principales:</strong>
                        <ul>
                            <li>Convierte imágenes JPG, PNG y GIF a <b>WebP</b> automáticamente.</li>
                            <li>Reduce el peso de las imágenes hasta un 80% sin perder calidad visual.</li>
                            <li>Procesa imágenes en lotes pequeños para evitar errores de servidor.</li>
                            <li>Permite redimensionar imágenes grandes y mantener copias originales.</li>
                            <li>Entrega WebP automáticamente en navegadores compatibles.</li>
                            <li>Incluye estadísticas y log de optimización en tiempo real.</li>
                        </ul>
                        <strong>¿Cómo usarlo?</strong>
                        <ol>
                            <li>Configura las opciones según tus necesidades en la sección "Configuración".</li>
                            <li>Haz clic en <b>Iniciar optimización</b> para procesar todas las imágenes.</li>
                            <li>Monitorea el progreso y revisa las estadísticas y el log.</li>
                            <li>Para sitios grandes, realiza la optimización en varias sesiones.</li>
                        </ol>
                        <strong>Recomendaciones:</strong>
                        <ul>
                            <li>Activa el modo de seguridad para evitar errores masivos.</li>
                            <li>Haz una copia de seguridad antes de optimizar muchas imágenes.</li>
                            <li>Si tienes dudas o necesitas soporte, visita <a href="https://buzz.cr" target="_blank">buzz.cr</a>.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            // Actualizar valor del slider de compresión
            $('#compression-slider').on('input', function() {
                $('#compression-value').text($(this).val() + '%');
            });
            
            // Cargar estadísticas iniciales
            $.ajax({
                url: wp_image_optimizer.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_optimization_stats',
                    nonce: wp_image_optimizer.nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateStats(response.data);
                    }
                }
            });
            
            // Cargar total de imágenes
            $.ajax({
                url: wp_image_optimizer.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_total_images',
                    nonce: wp_image_optimizer.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#total-images').text(response.data.total);
                    }
                }
            });
            
            // Inicio de optimización
            var isOptimizing = false;
            var currentOffset = 0;
            
            $('#start-optimization').on('click', function() {
                if (isOptimizing) return;
                
                isOptimizing = true;
                currentOffset = 0;
                $('#stop-optimization').prop('disabled', false);
                $(this).prop('disabled', true);
                $('#optimization-log').empty();
                $('#optimization-progress').css('width', '0%');
                $('#optimized-count').text('0');
                
                processNextBatch();
            });
            
            $('#stop-optimization').on('click', function() {
                isOptimizing = false;
                $(this).prop('disabled', true);
                $('#start-optimization').prop('disabled', false);
                appendToLog('Optimización detenida por el usuario');
            });
            
            $('#reset-problem-images').on('click', function() {
                if (confirm('¿Está seguro de que desea reiniciar la lista de imágenes problemáticas? Esto permitirá que el plugin intente procesarlas nuevamente.')) {
                    $.ajax({
                        url: wp_image_optimizer.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'reset_problem_images',
                            nonce: wp_image_optimizer.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                window.location.reload();
                            }
                        }
                    });
                }
            });
            
            function processNextBatch() {
                if (!isOptimizing) return;
                
                $.ajax({
                    url: wp_image_optimizer.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'process_images_batch',
                        nonce: wp_image_optimizer.nonce,
                        offset: currentOffset
                    },
                    success: function(response) {
                        if (!isOptimizing) return;
                        
                        if (response.success) {
                            // Actualizar estadísticas
                            updateStats(response.data.stats);
                            
                            // Actualizar progreso
                            var total = parseInt($('#total-images').text());
                            var optimized = parseInt($('#stat-optimized-images').text());
                            var progress = total > 0 ? (optimized / total) * 100 : 0;
                            $('#optimization-progress').css('width', progress + '%');
                            $('#optimized-count').text(optimized);
                            
                            // Registrar resultados
                            appendToLog(response.data.message);
                            
                            if (response.data.results && response.data.results.length > 0) {
                                response.data.results.forEach(function(result) {
                                    var status = result.success ? '✅' : '❌';
                                    appendToLog(`${status} ID: ${result.image_id} - ${result.message}`);
                                });
                            }
                            
                            // Continuar o finalizar
                            if (response.data.done) {
                                isOptimizing = false;
                                $('#stop-optimization').prop('disabled', true);
                                $('#start-optimization').prop('disabled', false);
                                appendToLog('✅ Optimización completa');
                            } else {
                                currentOffset = response.data.offset;
                                setTimeout(processNextBatch, 1000); // Esperar 1 segundo entre lotes
                            }
                        } else {
                            appendToLog('❌ Error: ' + (response.data ? response.data.message : 'Error desconocido'));
                            isOptimizing = false;
                            $('#stop-optimization').prop('disabled', true);
                            $('#start-optimization').prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        appendToLog('❌ Error AJAX: ' + error);
                        isOptimizing = false;
                        $('#stop-optimization').prop('disabled', true);
                        $('#start-optimization').prop('disabled', false);
                    }
                });
            }
            
            function updateStats(stats) {
                $('#stat-total-images').text(stats.total_images);
                $('#stat-optimized-images').text(stats.optimized_images);
                $('#stat-saved-space').text(stats.saved_space);
                $('#stat-average-reduction').text(stats.average_reduction);
            }
            
            function appendToLog(message) {
                var now = new Date();
                var timestamp = now.getHours().toString().padStart(2, '0') + ':' +
                                now.getMinutes().toString().padStart(2, '0') + ':' +
                                now.getSeconds().toString().padStart(2, '0');
                $('#optimization-log').append('<div>[' + timestamp + '] ' + message + '</div>');
                $('#optimization-log').scrollTop($('#optimization-log')[0].scrollHeight);
            }
        });
        </script>
        <?php
    }

    // Detectar soporte para WebP en navegador
    public function add_webp_support_detection() {
        $options = get_option('wp_image_optimizer_options');
        if (!isset($options['auto_convert_to_webp']) || !$options['auto_convert_to_webp']) {
            return;
        }
        
        echo '<script>
            document.documentElement.className += (window.safari === undefined && 
            "HTMLPictureElement" in window && 
            document.createElement("canvas").toDataURL("image/webp").indexOf("data:image/webp") === 0) ? " webp" : " no-webp";
        </script>';
    }

    // Reemplazar imágenes en el contenido
    public function replace_images_with_webp($content) {
        // Evitar reemplazo en admin o feeds
        if (is_admin() || is_feed()) {
            return $content;
        }
        
        $options = get_option('wp_image_optimizer_options');
        if (!isset($options['auto_convert_to_webp']) || !$options['auto_convert_to_webp']) {
            return $content;
        }
        
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        // Patrón para encontrar etiquetas de imagen
        $pattern = '/<img[^>]*src=[\'"]([^\'"]+)\.(jpe?g|png)[\'"][^>]*>/i';
        
        // Reemplazar con picture/source/img
        $content = preg_replace_callback($pattern, function($matches) use ($debug_mode) {
            $src = $matches[1] . '.' . $matches[2];
            $webp_src = $matches[1] . '.webp';
            
            // Verificar si el archivo WebP existe
            $upload_dir = wp_upload_dir();
            $webp_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $webp_src);
            
            if (strpos($webp_path, $upload_dir['basedir']) !== 0) {
                // No es una imagen de la biblioteca de medios
                return $matches[0];
            }
            
            if (!file_exists($webp_path)) {
                if ($debug_mode) {
                    error_log('WP Image Optimizer - WebP no encontrado: ' . $webp_path);
                }
                return $matches[0];
            }
            
            if ($debug_mode) {
                error_log('WP Image Optimizer - WebP encontrado, reemplazando: ' . $src . ' -> ' . $webp_src);
            }
            
            // Mantener todos los atributos originales de la imagen
            $img_tag = $matches[0];
            
            // Extraer atributos como clase, alt, etc.
            $attributes = [];
            preg_match_all('/(\w+)=[\'"]([^\'"]*)[\'"]/', $img_tag, $attr_matches, PREG_SET_ORDER);
            foreach ($attr_matches as $attr_match) {
                if ($attr_match[1] != 'src' && $attr_match[1] != 'srcset') {
                    $attributes[$attr_match[1]] = $attr_match[2];
                }
            }
            
            // Reconstruir atributos
            $attr_html = '';
            foreach ($attributes as $name => $value) {
                $attr_html .= " $name=\"$value\"";
            }
            
            // Extraer srcset si existe
            $srcset = '';
            if (preg_match('/srcset=[\'"]([^\'"]+)[\'"]/', $img_tag, $srcset_match)) {
                $original_srcset = $srcset_match[1];
                $webp_srcset = $this->convert_srcset_to_webp($original_srcset);
                $srcset = " srcset=\"$webp_srcset\"";
            }
            
            // Construir elemento picture con source WebP y fallback a la imagen original
            return "<picture>
                <source type=\"image/webp\" src=\"$webp_src\"$srcset>
                $img_tag
            </picture>";
        }, $content);
        
        return $content;
    }

    // Convertir srcset a WebP
    private function convert_srcset_to_webp($srcset) {
        $srcset_urls = explode(',', $srcset);
        $webp_srcset_urls = [];
        
        $options = get_option('wp_image_optimizer_options');
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        foreach ($srcset_urls as $url_data) {
            // Encontrar URL y descriptor (como 300w, 2x, etc.)
            if (preg_match('/([^\s]+)\s+([^\s]+)/', trim($url_data), $parts)) {
                $url = $parts[1];
                $descriptor = $parts[2];
                
                // Convertir a WebP si es JPG o PNG
                if (preg_match('/\.(jpe?g|png)$/i', $url)) {
                    $webp_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $url);
                    
                    // Verificar si el archivo WebP existe
                    $upload_dir = wp_upload_dir();
                    $webp_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $webp_url);
                    
                    if (file_exists($webp_path)) {
                        $webp_srcset_urls[] = "$webp_url $descriptor";
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Srcset WebP encontrado: ' . $webp_url);
                        }
                    } else {
                        $webp_srcset_urls[] = "$url $descriptor";
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Srcset WebP no encontrado: ' . $webp_path);
                        }
                    }
                } else {
                    $webp_srcset_urls[] = "$url $descriptor";
                }
            } else {
                // Si no podemos analizar, mantener original
                $webp_srcset_urls[] = $url_data;
            }
        }
        
        return implode(', ', $webp_srcset_urls);
    }

    // Filtrar src de imágenes adjuntas
    public function filter_attachment_image_src($image, $attachment_id, $size, $icon) {
        if (!$image) {
            return $image;
        }
        
        $options = get_option('wp_image_optimizer_options');
        if (!isset($options['auto_convert_to_webp']) || !$options['auto_convert_to_webp']) {
            return $image;
        }
        
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        // Comprobar si el navegador admite WebP
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false) {
            // Obtener URL de WebP
            $webp_url = get_post_meta($attachment_id, '_wp_image_optimizer_webp_url', true);
            
            if ($webp_url && file_exists(get_post_meta($attachment_id, '_wp_image_optimizer_webp_path', true))) {
                if ($debug_mode) {
                    error_log('WP Image Optimizer - Reemplazando imagen adjunta: ' . $image[0] . ' -> ' . $webp_url);
                }
                $image[0] = $webp_url;
            }
        }
        
        return $image;
    }

    // Filtrar srcset para WebP
    public function filter_srcset_for_webp($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        $options = get_option('wp_image_optimizer_options');
        if (!isset($options['auto_convert_to_webp']) || !$options['auto_convert_to_webp']) {
            return $sources;
        }
        
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        // Comprobar si el navegador admite WebP
        if (!isset($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') === false) {
            return $sources;
        }
        
        foreach ($sources as &$source) {
            $url = $source['url'];
            if (preg_match('/\.(jpe?g|png)$/i', $url)) {
                $webp_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $url);
                
                // Verificar si el archivo WebP existe
                $upload_dir = wp_upload_dir();
                $webp_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $webp_url);
                
                if (file_exists($webp_path)) {
                    if ($debug_mode) {
                        error_log('WP Image Optimizer - Reemplazando srcset: ' . $url . ' -> ' . $webp_url);
                    }
                    $source['url'] = $webp_url;
                }
            }
        }
        
        return $sources;
    }
    
    // Procesar imágenes de Elementor
    public function process_elementor_images($data, $post_id) {
        $options = get_option('wp_image_optimizer_options');
        if (!isset($options['auto_convert_to_webp']) || !$options['auto_convert_to_webp']) {
            return $data;
        }
        
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Procesando contenido de Elementor para ID: ' . $post_id);
        }
        
        // Esta función solamente registra imágenes usadas por Elementor
        // para asegurarse de que sean optimizadas
        $this->extract_elementor_images($data);
        
        return $data;
    }
    
    // Extraer imágenes de Elementor
    private function extract_elementor_images($data) {
        if (empty($data) || !is_array($data)) {
            return;
        }
        
        $options = get_option('wp_image_optimizer_options');
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        foreach ($data as $element) {
            if (empty($element) || !is_array($element)) {
                continue;
            }
            
            // Procesar imágenes en este elemento
            if (!empty($element['settings'])) {
                $settings = $element['settings'];
                
                // Buscar campos de imágenes comunes en Elementor
                $image_fields = ['image', 'background_image', 'hover_image', 'logo', 'gallery'];
                
                foreach ($image_fields as $field) {
                    if (!empty($settings[$field]) && !empty($settings[$field]['url'])) {
                        $image_url = $settings[$field]['url'];
                        $attachment_id = isset($settings[$field]['id']) ? $settings[$field]['id'] : 0;
                        
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Encontrada imagen de Elementor: ' . $image_url . ' (ID: ' . $attachment_id . ')');
                        }
                        
                        if ($attachment_id && preg_match('/\.(jpe?g|png)$/i', $image_url)) {
                            // Verificar si ya está optimizada
                            $optimized = get_post_meta($attachment_id, '_wp_image_optimizer_optimized', true);
                            
                            if (!$optimized && $debug_mode) {
                                error_log('WP Image Optimizer - Imagen de Elementor no optimizada: ' . $attachment_id);
                            }
                        }
                    }
                }
            }
            
            // Procesar elementos hijos recursivamente
            if (!empty($element['elements'])) {
                $this->extract_elementor_images($element['elements']);
            }
        }
    }

    // Manejo de subida de imágenes para redimensionamiento
    public function handle_image_upload($file) {
        $options = get_option('wp_image_optimizer_options');
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Procesando archivo subido: ' . $file['file']);
        }
        
        // Verificar si está habilitado el redimensionamiento
        if (!isset($options['resize_images']) || !$options['resize_images']) {
            return $file;
        }
        
        $max_width = isset($options['max_width']) ? intval($options['max_width']) : 1920;
        $max_height = isset($options['max_height']) ? intval($options['max_height']) : 1920;
        
        // Verificar si es una imagen
        $file_type = wp_check_filetype($file['file']);
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Tipo de archivo: ' . $file_type['type']);
        }
        
        // Solo procesar formatos soportados
        if (!in_array($file_type['type'], $this->supported_mime_types)) {
            if ($debug_mode) {
                error_log('WP Image Optimizer - Formato no soportado: ' . $file_type['type']);
            }
            return $file;
        }
        
        // Obtener dimensiones de la imagen
        $image_size = @getimagesize($file['file']);
        if (!$image_size) {
            if ($debug_mode) {
                error_log('WP Image Optimizer - No se pudieron obtener las dimensiones de: ' . $file['file']);
            }
            return $file;
        }
        
        list($width, $height) = $image_size;
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Dimensiones de imagen: ' . $width . 'x' . $height);
        }
        
        // Verificar si necesita redimensionamiento
        if ($width <= $max_width && $height <= $max_height) {
            return $file;
        }
        
        // Calcular nuevas dimensiones manteniendo proporción
        $ratio = min($max_width / $width, $max_height / $height);
        $new_width = round($width * $ratio);
        $new_height = round($height * $ratio);
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Nuevas dimensiones: ' . $new_width . 'x' . $new_height);
        }
        
        // Crear nueva imagen
        $image = wp_get_image_editor($file['file']);
        
        if (!is_wp_error($image)) {
            // Hacer backup si está habilitado
            if (isset($options['keep_original']) && $options['keep_original']) {
                copy($file['file'], $file['file'] . '.original');
                if ($debug_mode) {
                    error_log('WP Image Optimizer - Backup creado: ' . $file['file'] . '.original');
                }
            }
            
            $image->resize($new_width, $new_height, false);
            $result = $image->save($file['file']);
            
            if ($debug_mode) {
                if (is_wp_error($result)) {
                    error_log('WP Image Optimizer - Error al guardar imagen redimensionada: ' . $result->get_error_message());
                } else {
                    error_log('WP Image Optimizer - Imagen redimensionada guardada correctamente');
                }
            }
            
            // Registrar que ha sido redimensionada
            update_option('wp_image_optimizer_last_resize', [
                'file' => $file['file'],
                'original_dimensions' => $width . 'x' . $height,
                'new_dimensions' => $new_width . 'x' . $new_height,
                'time' => time(),
            ]);
        } else if ($debug_mode) {
            error_log('WP Image Optimizer - Error al crear editor de imagen: ' . $image->get_error_message());
        }
        
        return $file;
    }

    // Procesar lote de imágenes (AJAX)
    public function process_images_batch() {
        // Verificar nonce
        check_ajax_referer('wp-image-optimizer-nonce', 'nonce');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
            return;
        }
        
        $options = get_option('wp_image_optimizer_options');
        $batch_size = isset($options['batch_size']) ? intval($options['batch_size']) : 20;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        // Establecer límites de tiempo y memoria para este proceso
        @set_time_limit(120);
        @ini_set('memory_limit', '256M');
        
        try {
            // Obtener imágenes no optimizadas
            $images = $this->get_unoptimized_images($batch_size, $offset);
            
            if (empty($images)) {
                wp_send_json_success([
                    'message' => 'Todas las imágenes han sido optimizadas',
                    'done' => true,
                    'stats' => $this->get_optimization_stats()
                ]);
                return;
            }
            
            $results = [];
            $success_count = 0;
            $skipped_count = 0;
            
            foreach ($images as $image) {
                // Verificar si está en la lista de problemas
                if (isset($this->problem_images[$image['id']])) {
                    if ($debug_mode) {
                        error_log('WP Image Optimizer - Omitiendo imagen problemática: ' . $image['file_path']);
                    }
                    $results[] = [
                        'success' => false,
                        'image_id' => $image['id'],
                        'message' => 'Imagen omitida (problema conocido)',
                    ];
                    $skipped_count++;
                    continue;
                }
                
                try {
                    $result = $this->optimize_image($image, $options);
                    
                    if ($result['success']) {
                        $success_count++;
                    } else if (isset($options['safety_mode']) && $options['safety_mode']) {
                        // Añadir a la lista de problemas después de varios intentos fallidos
                        $retries = get_post_meta($image['id'], '_wp_image_optimizer_retries', true);
                        $retries = $retries ? intval($retries) : 0;
                        $retries++;
                        
                        update_post_meta($image['id'], '_wp_image_optimizer_retries', $retries);
                        
                        if ($retries >= 3) { // Máximo 3 intentos
                            $this->problem_images[$image['id']] = [
                                'file' => $image['file_path'],
                                'reason' => $result['message']
                            ];
                            
                            update_option('wp_image_optimizer_problem_images', $this->problem_images);
                            
                            $result['message'] = 'Imagen marcada como problemática después de ' . $retries . ' intentos fallidos.';
                            
                            if ($debug_mode) {
                                error_log('WP Image Optimizer - Imagen marcada como problemática: ' . $image['file_path']);
                            }
                        }
                    }
                    
                    $results[] = $result;
                } catch (Exception $e) {
                    if ($debug_mode) {
                        error_log('WP Image Optimizer - Error al procesar imagen: ' . $e->getMessage());
                    }
                    
                    $results[] = [
                        'success' => false,
                        'image_id' => $image['id'],
                        'message' => 'Error: ' . $e->getMessage(),
                    ];
                    
                    // Si estamos en modo seguridad, marcar como problemática
                    if (isset($options['safety_mode']) && $options['safety_mode']) {
                        $this->problem_images[$image['id']] = [
                            'file' => $image['file_path'],
                            'reason' => 'Error: ' . $e->getMessage()
                        ];
                        
                        update_option('wp_image_optimizer_problem_images', $this->problem_images);
                    }
                }
            }
            
            $message = "Procesadas: $success_count de " . count($images) . " imágenes";
            if ($skipped_count > 0) {
                $message .= " ($skipped_count omitidas)";
            }
            
            wp_send_json_success([
                'message' => $message,
                'done' => false,
                'results' => $results,
                'offset' => $offset + count($images),
                'stats' => $this->get_optimization_stats()
            ]);
        } catch (Exception $e) {
            if ($debug_mode) {
                error_log('WP Image Optimizer - Error general en proceso por lotes: ' . $e->getMessage());
            }
            
            wp_send_json_error([
                'message' => 'Error en proceso por lotes: ' . $e->getMessage()
            ]);
        }
    }
    
    // Resetear lista de imágenes problemáticas
    public function ajax_reset_problem_images() {
        // Verificar nonce
        check_ajax_referer('wp-image-optimizer-nonce', 'nonce');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
            return;
        }
        
        // Resetear intentos de todas las imágenes
        global $wpdb;
        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_wp_image_optimizer_retries']);
        
        // Limpiar lista de imágenes problemáticas
        update_option('wp_image_optimizer_problem_images', []);
        $this->problem_images = [];
        
        wp_send_json_success(['message' => 'Lista de imágenes problemáticas reiniciada']);
    }
    
    // Procesamiento en segundo plano con WP Cron
    public function process_background_optimization() {
        $options = get_option('wp_image_optimizer_options');
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Iniciando procesamiento en segundo plano');
        }
        
        // Verificar si está habilitado el procesamiento en segundo plano
        if (!isset($options['use_background_processing']) || !$options['use_background_processing']) {
            if ($debug_mode) {
                error_log('WP Image Optimizer - Procesamiento en segundo plano desactivado');
            }
            return;
        }
        
        // Obtener estado del procesamiento en segundo plano
        $background_status = get_option('wp_image_optimizer_background_status', [
            'is_running' => false,
            'last_run' => 0,
            'offset' => 0,
            'total_processed' => 0,
            'batch_size' => isset($options['batch_size']) ? intval($options['batch_size']) : 20,
        ]);
        
        // Si ya está en ejecución y no ha pasado mucho tiempo, salir
        if ($background_status['is_running'] && (time() - $background_status['last_run'] < 300)) {
            if ($debug_mode) {
                error_log('WP Image Optimizer - Procesamiento en segundo plano ya en ejecución');
            }
            return;
        }
        
        // Actualizar estado
        $background_status['is_running'] = true;
        $background_status['last_run'] = time();
        update_option('wp_image_optimizer_background_status', $background_status);
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Buscando imágenes no optimizadas, offset: ' . $background_status['offset']);
        }
        
        // Establecer límites para este proceso
        @set_time_limit(120);
        @ini_set('memory_limit', '256M');
        
        // Obtener imágenes no optimizadas
        $images = $this->get_unoptimized_images($background_status['batch_size'], $background_status['offset']);
        
        if (empty($images)) {
            // No hay más imágenes para procesar, reiniciar
            $background_status['is_running'] = false;
            $background_status['offset'] = 0;
            update_option('wp_image_optimizer_background_status', $background_status);
            
            if ($debug_mode) {
                error_log('WP Image Optimizer - No se encontraron más imágenes para optimizar');
            }
            return;
        }
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Encontradas ' . count($images) . ' imágenes para procesar');
        }
        
        $success_count = 0;
        $skipped_count = 0;
        
        foreach ($images as $image) {
            // Verificar si está en la lista de problemas
            if (isset($this->problem_images[$image['id']])) {
                if ($debug_mode) {
                    error_log('WP Image Optimizer - Omitiendo imagen problemática: ' . $image['file_path']);
                }
                $skipped_count++;
                continue;
            }
            
            try {
                $result = $this->optimize_image($image, $options);
                
                if ($result['success']) {
                    $success_count++;
                    $background_status['total_processed']++;
                } else if (isset($options['safety_mode']) && $options['safety_mode']) {
                    // Añadir a la lista de problemas después de varios intentos fallidos
                    $retries = get_post_meta($image['id'], '_wp_image_optimizer_retries', true);
                    $retries = $retries ? intval($retries) : 0;
                    $retries++;
                    
                    update_post_meta($image['id'], '_wp_image_optimizer_retries', $retries);
                    
                    if ($retries >= 3) {
                        $this->problem_images[$image['id']] = [
                            'file' => $image['file_path'],
                            'reason' => $result['message']
                        ];
                        
                        update_option('wp_image_optimizer_problem_images', $this->problem_images);
                        
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Imagen marcada como problemática: ' . $image['file_path']);
                        }
                    }
                }
                
                // Guardar log de procesamiento
                $this->log_optimization_result($result);
            } catch (Exception $e) {
                if ($debug_mode) {
                    error_log('WP Image Optimizer - Error al procesar imagen: ' . $e->getMessage());
                }
                
                // Si estamos en modo seguridad, marcar como problemática
                if (isset($options['safety_mode']) && $options['safety_mode']) {
                    $this->problem_images[$image['id']] = [
                        'file' => $image['file_path'],
                        'reason' => 'Error: ' . $e->getMessage()
                    ];
                    
                    update_option('wp_image_optimizer_problem_images', $this->problem_images);
                }
                
                // Guardar log de error
                $this->log_optimization_result([
                    'success' => false,
                    'image_id' => $image['id'],
                    'message' => 'Error: ' . $e->getMessage(),
                ]);
            }
        }
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Procesadas con éxito: ' . $success_count . ' imágenes, omitidas: ' . $skipped_count);
        }
        
        // Actualizar estado
        $background_status['offset'] += count($images);
        $background_status['is_running'] = false;
        $background_status['last_run'] = time();
        update_option('wp_image_optimizer_background_status', $background_status);
    }
    
    // Función para registrar resultados en el log
    private function log_optimization_result($result) {
        $log = get_option('wp_image_optimizer_log', []);
        
        // Limitar el tamaño del log a 1000 entradas
        if (count($log) > 1000) {
            $log = array_slice($log, -999);
        }
        
        $log[] = [
            'time' => time(),
            'image_id' => $result['image_id'],
            'success' => $result['success'],
            'message' => $result['message'],
            'saved' => isset($result['saved']) ? $result['saved'] : '',
            'percent_saved' => isset($result['percent_saved']) ? $result['percent_saved'] : '',
        ];
        
        update_option('wp_image_optimizer_log', $log);
    }
    
    // Obtener estado actual del procesamiento en segundo plano (AJAX)
    public function ajax_get_background_status() {
        // Verificar nonce
        check_ajax_referer('wp-image-optimizer-nonce', 'nonce');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
            return;
        }
        
        $background_status = get_option('wp_image_optimizer_background_status', [
            'is_running' => false,
            'last_run' => 0,
            'offset' => 0,
            'total_processed' => 0,
        ]);
        
        // Obtener últimas entradas del log
        $log = get_option('wp_image_optimizer_log', []);
        $recent_log = array_slice($log, -20);
        
        wp_send_json_success([
            'status' => $background_status,
            'log' => $recent_log,
            'stats' => $this->get_optimization_stats(),
        ]);
    }
    
    // Obtener total de imágenes (AJAX)
    public function ajax_get_total_images() {
        // Verificar nonce
        check_ajax_referer('wp-image-optimizer-nonce', 'nonce');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
            return;
        }
        
        global $wpdb;
        
        // Solo contar formatos de imagen soportados
        $mime_types = "'" . implode("','", $this->supported_mime_types) . "'";
        
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type IN ($mime_types)"
        );
        
        wp_send_json_success(['total' => intval($total)]);
    }
    
    // Obtener estadísticas de optimización (AJAX)
    public function ajax_get_optimization_stats() {
        // Verificar nonce
        check_ajax_referer('wp-image-optimizer-nonce', 'nonce');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
            return;
        }
        
        wp_send_json_success($this->get_optimization_stats());
    }

    // Obtener imágenes no optimizadas
    private function get_unoptimized_images($limit = 20, $offset = 0) {
        global $wpdb;
        
        $options = get_option('wp_image_optimizer_options');
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        
        // Solo incluir formatos que podemos optimizar
        $mime_types = "'" . implode("','", $this->supported_mime_types) . "'";
        
        // Preparar lista de IDs de imágenes problemáticas
        $excluded_ids = [];
        if (is_array($this->problem_images)) {
            foreach ($this->problem_images as $id => $data) {
                $excluded_ids[] = intval($id);
            }
        }
        
        $excluded_condition = '';
        if (!empty($excluded_ids)) {
            $excluded_condition = "AND p.ID NOT IN (" . implode(',', $excluded_ids) . ")";
        }
        
        $query = $wpdb->prepare(
            "SELECT p.ID, p.guid, p.post_mime_type, pm.meta_value as file_path
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->postmeta} opt ON p.ID = opt.post_id AND opt.meta_key = '_wp_image_optimizer_optimized'
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type IN ($mime_types)
            $excluded_condition
            AND pm.meta_value NOT LIKE '%.zip'
            AND pm.meta_value NOT LIKE '%.pdf'
            AND pm.meta_value NOT LIKE '%.mp4'
            AND pm.meta_value NOT LIKE '%.webp'
            AND (opt.meta_value IS NULL OR opt.meta_value = '0')
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Query: ' . $query);
        }
        
        $results = $wpdb->get_results($query);
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Resultados encontrados: ' . count($results));
        }
        
        $images = [];
        foreach ($results as $result) {
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/' . $result->file_path;
            
            if (file_exists($file_path)) {
                // Para archivos grandes, verificar si podemos procesarlos
                $filesize = filesize($file_path);
                $memory_limit = $this->get_memory_limit_bytes();
                
                // Reservar 4 veces el tamaño del archivo para procesamiento
                // Si es más del 50% del límite de memoria, mejor omitirlo
                if ($filesize * 4 > $memory_limit * 0.5) {
                    if ($debug_mode) {
                        error_log('WP Image Optimizer - Imagen demasiado grande para procesar: ' . $file_path . ' (' . size_format($filesize) . ')');
                    }
                    
                    // Marcar como procesada para evitar reintento
                    update_post_meta($result->ID, '_wp_image_optimizer_optimized', '1');
                    update_post_meta($result->ID, '_wp_image_optimizer_skip_reason', 'size_too_large');
                    
                    // Agregar a imágenes problemáticas
                    $this->problem_images[$result->ID] = [
                        'file' => $file_path,
                        'reason' => 'Imagen demasiado grande para procesar: ' . size_format($filesize)
                    ];
                    
                    update_option('wp_image_optimizer_problem_images', $this->problem_images);
                    continue;
                }
                
                $images[] = [
                    'id' => $result->ID,
                    'url' => $result->guid,
                    'file_path' => $file_path,
                    'mime_type' => $result->post_mime_type
                ];
                
                if ($debug_mode) {
                    error_log('WP Image Optimizer - Imagen encontrada: ' . $file_path . ' (Tipo: ' . $result->post_mime_type . ')');
                }
            } else if ($debug_mode) {
                error_log('WP Image Optimizer - Imagen no encontrada en el sistema de archivos: ' . $file_path);
            }
        }
        
        return $images;
    }

    // Optimizar una imagen individual
    private function optimize_image($image, $options) {
        $debug_mode = isset($options['debug_mode']) && $options['debug_mode'];
        $compression_level = isset($options['compression_level']) ? intval($options['compression_level']) : 80;
        $convert_to_webp = isset($options['convert_to_webp']) ? (bool)$options['convert_to_webp'] : true;
        $keep_original = isset($options['keep_original']) ? (bool)$options['keep_original'] : true;
        $resize_images = isset($options['resize_images']) ? (bool)$options['resize_images'] : true;
        $max_width = isset($options['max_width']) ? intval($options['max_width']) : 1920;
        $max_height = isset($options['max_height']) ? intval($options['max_height']) : 1920;
        $safety_mode = isset($options['safety_mode']) ? (bool)$options['safety_mode'] : true;
        
        $file_path = $image['file_path'];
        $image_id = $image['id'];
        $mime_type = isset($image['mime_type']) ? $image['mime_type'] : wp_check_filetype($file_path)['type'];
        
        // Verificar que el tipo MIME es soportado
        if (!in_array($mime_type, $this->supported_mime_types)) {
            if ($debug_mode) {
                error_log('WP Image Optimizer - Tipo MIME no soportado: ' . $mime_type);
            }
            return [
                'success' => false,
                'image_id' => $image_id,
                'message' => 'Formato ' . $mime_type . ' no soportado por el plugin.',
            ];
        }
        
        if ($debug_mode) {
            error_log('WP Image Optimizer - Optimizando imagen: ' . $file_path . ' (ID: ' . $image_id . ', Tipo: ' . $mime_type . ')');
            
            if (extension_loaded('imagick')) {
                error_log('WP Image Optimizer - Usando Imagick para: ' . $file_path);
            } else if (function_exists('imagecreatefrompng') && function_exists('imagewebp')) {
                error_log('WP Image Optimizer - Usando GD para: ' . $file_path);
            } else {
                error_log('WP Image Optimizer - No hay librería de procesamiento de imágenes disponible');
            }
        }
        
        // Comprobar permisos de archivo y corregir si es posible
        if (!is_readable($file_path) || !is_writable($file_path)) {
            // Intentar corregir permisos
            @chmod($file_path, 0644);
            
            // Verificar de nuevo
            if (!is_writable($file_path)) {
                if ($debug_mode) {
                    error_log('WP Image Optimizer - Error de permisos en: ' . $file_path);
                }
                return [
                    'success' => false,
                    'image_id' => $image_id,
                    'message' => 'Error de permisos: No se puede escribir en el archivo (' . $mime_type . ')',
                ];
            }
        }
        
        try {
            $file_info = pathinfo($file_path);
            $original_size = filesize($file_path);
            
            if ($debug_mode) {
                error_log('WP Image Optimizer - Tamaño original: ' . size_format($original_size));
            }
            
            // Verificar si es un PNG con paleta (indexado)
            $is_palette_png = false;
            if ($mime_type == 'image/png') {
                $info = @getimagesize($file_path);
                if ($info && isset($info['bits']) && $info['bits'] <= 8) {
                    // Es muy probable que sea un PNG indexado (paleta)
                    if ($debug_mode) {
                        error_log('WP Image Optimizer - Detectado PNG indexado (paleta), omitiendo WebP: ' . $file_path);
                    }
                    $is_palette_png = true;
                }
            }
            
            // Crear objetos de imagen
            if (extension_loaded('imagick')) {
                // Usar Imagick si está disponible
                try {
                    $image_data = new Imagick($file_path);
                    
                    // Redimensionar si es necesario
                    if ($resize_images) {
                        $dimensions = $image_data->getImageGeometry();
                        $width = $dimensions['width'];
                        $height = $dimensions['height'];
                        
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Dimensiones originales (Imagick): ' . $width . 'x' . $height);
                        }
                        
                        if ($width > $max_width || $height > $max_height) {
                            // Calcular nuevas dimensiones manteniendo proporción
                            $ratio = min($max_width / $width, $max_height / $height);
                            $new_width = round($width * $ratio);
                            $new_height = round($height * $ratio);
                            
                            if ($debug_mode) {
                                error_log('WP Image Optimizer - Nuevas dimensiones (Imagick): ' . $new_width . 'x' . $new_height);
                            }
                            
                            // Hacer backup si está habilitado
                            if ($keep_original && !file_exists($file_path . '.original')) {
                                copy($file_path, $file_path . '.original');
                                if ($debug_mode) {
                                    error_log('WP Image Optimizer - Backup creado en: ' . $file_path . '.original');
                                }
                            }
                            
                            // Redimensionar la imagen
                            $image_data->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, 1);
                            if ($debug_mode) {
                                error_log('WP Image Optimizer - Imagen redimensionada con Imagick');
                            }
                        }
                    }
                    
                    // Comprimir y guardar la imagen original
                    $image_data->setImageCompressionQuality($compression_level);
                    
                    if (!$keep_original) {
                        $backup_path = $file_path . '.bak';
                        copy($file_path, $backup_path);
                        $image_data->writeImage($file_path);
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Imagen comprimida guardada (Imagick)');
                        }
                    }
                    
                    // Convertir a WebP si está habilitado y no es un PNG indexado
                    if ($convert_to_webp && !$is_palette_png) {
                        $webp_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.webp';
                        $image_data->setImageFormat('webp');
                        $image_data->setImageCompressionQuality($compression_level);
                        $image_data->writeImage($webp_path);
                        
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Versión WebP creada en: ' . $webp_path);
                        }
                        
                        // Actualizar metadatos de WordPress para WebP
                        $this->update_attachment_webp_metadata($image_id, $webp_path);
                    } else if ($is_palette_png && $debug_mode) {
                        error_log('WP Image Optimizer - Omitiendo conversión WebP para PNG indexado');
                    }
                    
                    $image_data->clear();
                    $image_data->destroy();
                } catch (Exception $e) {
                    if ($debug_mode) {
                        error_log('WP Image Optimizer - Error en Imagick: ' . $e->getMessage());
                    }
                    // Si falla Imagick, intentamos con GD
                    throw new Exception('Fallback a GD: ' . $e->getMessage());
                }
            } else {
                // Usar GD como alternativa
                if ($debug_mode) {
                    error_log('WP Image Optimizer - Usando GD para: ' . $file_path);
                }
                
                $src_img = null;
                
                // Intentar diferentes funciones de carga según el tipo MIME
                switch ($mime_type) {
                    case 'image/jpeg':
                    case 'image/jpg':
                        if (function_exists('imagecreatefromjpeg')) {
                            $src_img = @imagecreatefromjpeg($file_path);
                        }
                        break;
                    case 'image/png':
                        if (function_exists('imagecreatefrompng')) {
                            $src_img = @imagecreatefrompng($file_path);
                        }
                        break;
                    case 'image/gif':
                        if (function_exists('imagecreatefromgif')) {
                            $src_img = @imagecreatefromgif($file_path);
                        }
                        break;
                }
                
                if (!$src_img) {
                    if ($debug_mode) {
                        error_log('WP Image Optimizer - Error al crear la imagen con GD');
                    }
                    return [
                        'success' => false,
                        'image_id' => $image_id,
                        'message' => 'Error al crear la imagen. Formato ' . $mime_type . ' no soportado o archivo corrupto.',
                    ];
                }
                
                // Redimensionar si es necesario
                if ($resize_images) {
                    $width = imagesx($src_img);
                    $height = imagesy($src_img);
                    
                    if ($debug_mode) {
                        error_log('WP Image Optimizer - Dimensiones originales (GD): ' . $width . 'x' . $height);
                    }
                    
                    if ($width > $max_width || $height > $max_height) {
                        // Calcular nuevas dimensiones manteniendo proporción
                        $ratio = min($max_width / $width, $max_height / $height);
                        $new_width = round($width * $ratio);
                        $new_height = round($height * $ratio);
                        
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Nuevas dimensiones (GD): ' . $new_width . 'x' . $new_height);
                        }
                        
                        // Hacer backup si está habilitado
                        if ($keep_original && !file_exists($file_path . '.original')) {
                            copy($file_path, $file_path . '.original');
                            if ($debug_mode) {
                                error_log('WP Image Optimizer - Backup creado en: ' . $file_path . '.original');
                            }
                        }
                        
                        // Crear nueva imagen redimensionada
                        $new_img = imagecreatetruecolor($new_width, $new_height);
                        
                        // Mantener transparencia para PNG y GIF
                        if ($mime_type == 'image/png' || $mime_type == 'image/gif') {
                            imagealphablending($new_img, false);
                            imagesavealpha($new_img, true);
                            $transparent = imagecolorallocatealpha($new_img, 255, 255, 255, 127);
                            imagefilledrectangle($new_img, 0, 0, $new_width, $new_height, $transparent);
                        }
                        
                        // Redimensionar
                        imagecopyresampled(
                            $new_img, $src_img,
                            0, 0, 0, 0,
                            $new_width, $new_height, $width, $height
                        );
                        
                        if ($debug_mode) {
                            error_log('WP Image Optimizer - Imagen redimensionada con GD');
                        }
                        
                        // Liberar memoria de la imagen original
                        imagedestroy($src_img);
                        $src_img = $new_img;
                    }
                }
                
                // Mantener transparencia para PNG y GIF
                if ($mime_type == 'image/png' || $mime_type == 'image/gif') {
                    imagealphablending($src_img, false);
                    imagesavealpha($src_img, true);
                }
                
                // Guardar copia de respaldo si es necesario
                if (!$keep_original) {
                    $backup_path = $file_path . '.bak';
                    copy($file_path, $backup_path);
                    
                    // Guardar imagen comprimida
                    $save_success = false;
                    
                    switch ($mime_type) {
                        case 'image/jpeg':
                        case 'image/jpg':
                            if (function_exists('imagejpeg')) {
                                $save_success = imagejpeg($src_img, $file_path, $compression_level);
                            }
                            break;
                        case 'image/png':
                            if (function_exists('imagepng')) {
                                // Para PNG, la escala de compresión es diferente (0-9)
                                $png_compression = 9 - round(($compression_level / 100) * 9);
                                $save_success = imagepng($src_img, $file_path, $png_compression);
                            }
                            break;
                        case 'image/gif':
                            if (function_exists('imagegif')) {
                                $save_success = imagegif($src_img, $file_path);
                            }
                            break;
                    }
                    
                    if ($debug_mode) {
                        if ($save_success) {
                            error_log('WP Image Optimizer - Imagen comprimida guardada (GD)');
                        } else {
                            error_log('WP Image Optimizer - Error al guardar imagen comprimida (GD)');
                        }
                    }
                }
                
                // Convertir a WebP si está habilitado y no es un PNG indexado
                if ($convert_to_webp && function_exists('imagewebp') && !$is_palette_png) {
                    $webp_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.webp';
                    $webp_success = imagewebp($src_img, $webp_path, $compression_level);
                    
                    if ($debug_mode) {
                        if ($webp_success) {
                            error_log('WP Image Optimizer - Versión WebP creada en: ' . $webp_path);
                        } else {
                            error_log('WP Image Optimizer - Error al crear versión WebP');
                        }
                    }
                    
                    if ($webp_success) {
                        // Actualizar metadatos de WordPress para WebP
                        $this->update_attachment_webp_metadata($image_id, $webp_path);
                    }
                } else if ($debug_mode) {
                    if ($is_palette_png) {
                        error_log('WP Image Optimizer - Omitiendo conversión WebP para PNG indexado');
                    } else if (!function_exists('imagewebp')) {
                        error_log('WP Image Optimizer - La función imagewebp no está disponible');
                    }
                }
                
                imagedestroy($src_img);
            }
            
            // Calcular estadísticas
            clearstatcache(); // Limpiar caché para obtener el tamaño real actualizado
            $new_size = filesize($file_path);
            $webp_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.webp';
            $webp_size = file_exists($webp_path) ? filesize($webp_path) : 0;
            
            // Calcular ahorro
            $size_reduction = $original_size - ($keep_original ? $webp_size : $new_size);
            $percentage_saved = $original_size > 0 ? round(($size_reduction / $original_size) * 100, 2) : 0;
            
            if ($debug_mode) {
                error_log('WP Image Optimizer - Tamaño final: ' . size_format($new_size));
                if ($webp_size > 0) {
                    error_log('WP Image Optimizer - Tamaño WebP: ' . size_format($webp_size));
                }
                error_log('WP Image Optimizer - Reducción: ' . size_format($size_reduction) . ' (' . $percentage_saved . '%)');
            }
            
            // Actualizar metadatos
            update_post_meta($image_id, '_wp_image_optimizer_optimized', '1');
            update_post_meta($image_id, '_wp_image_optimizer_original_size', $original_size);
            update_post_meta($image_id, '_wp_image_optimizer_new_size', $new_size);
            
            
            if ($webp_size > 0) {
                update_post_meta($image_id, '_wp_image_optimizer_webp_size', $webp_size);
            }
            
            update_post_meta($image_id, '_wp_image_optimizer_saved', $size_reduction);
            update_post_meta($image_id, '_wp_image_optimizer_percent_saved', $percentage_saved);
            
            $message = 'Imagen optimizada correctamente';
            if ($is_palette_png) {
                $message .= ' (PNG indexado: WebP omitido)';
            }
            
            return [
                'success' => true,
                'image_id' => $image_id,
                'original_size' => size_format($original_size),
                'new_size' => size_format($new_size),
                'webp_size' => $webp_size > 0 ? size_format($webp_size) : 'N/A',
                'saved' => size_format($size_reduction),
                'percent_saved' => $percentage_saved . '%',
                'message' => $message,
            ];
        } catch (Exception $e) {
            if ($debug_mode) {
                error_log('WP Image Optimizer - Error general: ' . $e->getMessage());
            }
            
            // Si estamos en modo seguridad, marcar como procesada para evitar reintentos
            if ($safety_mode) {
                update_post_meta($image_id, '_wp_image_optimizer_optimized', '1');
                update_post_meta($image_id, '_wp_image_optimizer_error', $e->getMessage());
            }
            
            return [
                'success' => false,
                'image_id' => $image_id,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    // Actualizar metadatos WebP
    private function update_attachment_webp_metadata($attachment_id, $webp_path) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $webp_url = str_replace($base_dir, $upload_dir['baseurl'], $webp_path);
        
        update_post_meta($attachment_id, '_wp_image_optimizer_webp_path', $webp_path);
        update_post_meta($attachment_id, '_wp_image_optimizer_webp_url', $webp_url);
    }

    // Obtener estadísticas de optimización
    private function get_optimization_stats() {
        global $wpdb;
        
        try {
            // Solo formatos soportados
            $mime_types = "'" . implode("','", $this->supported_mime_types) . "'";
            
            $total_images = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                AND post_mime_type IN ($mime_types)"
            );
            
            $optimized_images = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'attachment'
                AND p.post_mime_type IN ($mime_types)
                AND pm.meta_key = '_wp_image_optimizer_optimized'
                AND pm.meta_value = '1'"
            );
            
            $total_saved = $wpdb->get_var(
                "SELECT SUM(meta_value) FROM {$wpdb->postmeta}
                WHERE meta_key = '_wp_image_optimizer_saved'"
            );
            
            $average_reduction = $wpdb->get_var(
                "SELECT AVG(meta_value) FROM {$wpdb->postmeta}
                WHERE meta_key = '_wp_image_optimizer_percent_saved'"
            );
            
            // Asegurar que no hay valores nulos
            $total_images = intval($total_images);
            $optimized_images = intval($optimized_images);
            $total_saved = floatval($total_saved);
            $average_reduction = floatval($average_reduction);
            
            if ($total_images < 0) $total_images = 0;
            if ($optimized_images < 0) $optimized_images = 0;
            if ($total_saved < 0) $total_saved = 0;
            
            return [
                'total_images' => $total_images,
                'optimized_images' => $optimized_images,
                'saved_space' => size_format($total_saved),
                'average_reduction' => round($average_reduction, 2) . '%',
                'problem_images' => is_array($this->problem_images) ? count($this->problem_images) : 0,
            ];
        } catch (Exception $e) {
            return [
                'total_images' => 0,
                'optimized_images' => 0,
                'saved_space' => '0 B',
                'average_reduction' => '0%',
                'problem_images' => is_array($this->problem_images) ? count($this->problem_images) : 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Obtener límite de memoria en bytes
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        
        // Si no hay límite
        if ($memory_limit === '-1') {
            return PHP_INT_MAX;
        }
        
        // Convertir a bytes
        $unit = strtolower(substr($memory_limit, -1));
        $value = intval($memory_limit);
        
        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}

// Inicializar plugin
$wp_image_optimizer = new WP_Image_Optimizer();

// Agregar función para el CSS en caso de que falte
if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 2) {
        $size = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
    }
}

// Generar CSS y JS si no existen los directorios
register_activation_hook(__FILE__, 'wp_image_optimizer_create_assets');

function wp_image_optimizer_create_assets() {
    // Crear directorios de assets si no existen
    $plugin_dir = plugin_dir_path(__FILE__);
    $js_dir = $plugin_dir . 'assets/js';
    $css_dir = $plugin_dir . 'assets/css';
    
    if (!file_exists($js_dir)) {
        wp_mkdir_p($js_dir);
    }
    
    if (!file_exists($css_dir)) {
        wp_mkdir_p($css_dir);
    }
    
    // Crear archivo JS si no existe
    $js_file = $js_dir . '/admin.js';
    if (!file_exists($js_file)) {
        $js_content = "jQuery(document).ready(function($) {
    // Ya se ha implementado el código JS en la página de administración
    // Este archivo es solo un placeholder para evitar errores 404
});";
        file_put_contents($js_file, $js_content);
    }
    
    // Crear archivo CSS si no existe
    $css_file = $css_dir . '/admin.css';
    if (!file_exists($css_file)) {
        $css_content = ".card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-top: 20px;
    padding: 20px;
    border-radius: 3px;
}

.optimization-controls {
    margin: 20px 0;
}

.progress-bar-container {
    height: 20px;
    background-color: #f5f5f5;
    border-radius: 4px;
    margin: 15px 0;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background-color: #0073aa;
    border-radius: 4px;
    width: 0%;
    transition: width 0.3s ease-in-out;
}

.progress-text {
    margin-bottom: 15px;
}

#optimization-log {
    background: #f5f5f5;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-family: monospace;
    font-size: 12px;
    line-height: 1.5;
    max-height: 300px;
    overflow-y: auto;
    margin-top: 10px;
}

.compression-slider {
    width: 300px;
    vertical-align: middle;
}

#compression-value {
    display: inline-block;
    width: 50px;
    text-align: center;
    font-weight: bold;
}

.buzzcr-columns {
    display: flex;
    flex-wrap: wrap;
    margin: 0 -10px;
}

.buzzcr-col {
    flex: 1;
    min-width: 300px;
    padding: 0 10px;
}

.buzzcr-optimizer-wrap h1 {
    display: flex;
    align-items: center;
    font-size: 24px;
    margin-bottom: 20px;
}

.buzzcr-optimizer-wrap h1 a {
    color: #0073aa;
    text-decoration: none;
    margin-left: 10px;
}

.buzzcr-optimizer-wrap h1 a:hover {
    text-decoration: underline;
}";
        file_put_contents($css_file, $css_content);
    }
}