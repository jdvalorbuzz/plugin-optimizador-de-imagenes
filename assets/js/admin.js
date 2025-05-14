// Archivo: assets/js/admin.js
jQuery(document).ready(function($) {
    // Actualizar valor del slider de compresión
    $('#compression-slider').on('input', function() {
        $('#compression-value').text($(this).val() + '%');
    });
    
    // Variables para el proceso de optimización
    let isOptimizing = false;
    let totalImages = 0;
    let optimizedImages = 0;
    let currentOffset = 0;
    
    // Iniciar la optimización
    $('#start-optimization').on('click', function() {
        if (isOptimizing) {
            return;
        }
        
        isOptimizing = true;
        $(this).attr('disabled', true);
        $('#stop-optimization').attr('disabled', false);
        
        // Resetear contador y log
        optimizedImages = 0;
        currentOffset = 0;
        $('#optimization-log').empty();
        
        // Obtener total de imágenes
        $.ajax({
            url: wp_image_optimizer.ajax_url,
            type: 'POST',
            data: {
                action: 'get_total_images',
                nonce: wp_image_optimizer.nonce
            },
            success: function(response) {
                if (response.success) {
                    totalImages = response.data.total;
                    $('#total-images').text(totalImages);
                    $('#optimized-count').text('0');
                    
                    // Iniciar el primer lote
                    processBatch();
                }
            }
        });
    });
    
    // Detener la optimización
    $('#stop-optimization').on('click', function() {
        isOptimizing = false;
        $(this).attr('disabled', true);
        $('#start-optimization').attr('disabled', false);
        logMessage('Proceso detenido por el usuario', 'warning');
    });
    
    // Procesar un lote de imágenes
    function processBatch() {
        if (!isOptimizing) {
            return;
        }
        
        $.ajax({
            url: wp_image_optimizer.ajax_url,
            type: 'POST',
            data: {
                action: 'process_images_batch',
                nonce: wp_image_optimizer.nonce,
                offset: currentOffset
            },
            success: function(response) {
                if (response.success) {
                    // Actualizar estadísticas
                    const stats = response.data.stats;
                    $('#stat-total-images').text(stats.total_images);
                    $('#stat-optimized-images').text(stats.optimized_images);
                    $('#stat-saved-space').text(stats.saved_space);
                    $('#stat-average-reduction').text(stats.average_reduction);
                    
                    // Procesar resultados
                    if (response.data.results) {
                        response.data.results.forEach(function(result) {
                            if (result.success) {
                                optimizedImages++;
                                logMessage(`Imagen optimizada: ${result.percent_saved} de ahorro`, 'success');
                            } else {
                                logMessage(`Error: ${result.message}`, 'error');
                            }
                        });
                    }
                    
                    // Actualizar progreso
                    currentOffset = response.data.offset;
                    $('#optimized-count').text(optimizedImages);
                    updateProgressBar();
                    
                    // Continuar o finalizar
                    if (response.data.done) {
                        isOptimizing = false;
                        $('#stop-optimization').attr('disabled', true);
                        $('#start-optimization').attr('disabled', false);
                        logMessage('¡Proceso completado!', 'success');
                    } else {
                        // Continuar con el siguiente lote
                        setTimeout(processBatch, 1000);
                    }
                } else {
                    logMessage(`Error: ${response.data.message}`, 'error');
                    isOptimizing = false;
                    $('#stop-optimization').attr('disabled', true);
                    $('#start-optimization').attr('disabled', false);
                }
            },
            error: function() {
                logMessage('Error de conexión al servidor', 'error');
                isOptimizing = false;
                $('#stop-optimization').attr('disabled', true);
                $('#start-optimization').attr('disabled', false);
            }
        });
    }
    
    // Actualizar barra de progreso
    function updateProgressBar() {
        const progress = (optimizedImages / totalImages) * 100;
        $('#optimization-progress').css('width', progress + '%');
    }
    
    // Agregar mensaje al log
    function logMessage(message, type) {
        const timestamp = new Date().toLocaleTimeString();
        const cssClass = type ? `log-${type}` : '';
        $('#optimization-log').prepend(`<div class="log-entry ${cssClass}"><span class="log-time">[${timestamp}]</span> ${message}</div>`);
        
        // Limitar a 100 mensajes para evitar sobrecarga
        if ($('#optimization-log .log-entry').length > 100) {
            $('#optimization-log .log-entry').last().remove();
        }
    }
    
    // Comprobar proceso en segundo plano
    function checkBackgroundProcess() {
        $.ajax({
            url: wp_image_optimizer.ajax_url,
            type: 'POST',
            data: {
                action: 'get_background_status',
                nonce: wp_image_optimizer.nonce
            },
            success: function(response) {
                if (response.success) {
                    const status = response.data.status;
                    const stats = response.data.stats;
                    
                    // Actualizar estadísticas
                    $('#stat-total-images').text(stats.total_images);
                    $('#stat-optimized-images').text(stats.optimized_images);
                    $('#stat-saved-space').text(stats.saved_space);
                    $('#stat-average-reduction').text(stats.average_reduction);
                    
                    // Mostrar últimos logs
                    if (response.data.log && response.data.log.length > 0) {
                        response.data.log.forEach(function(entry) {
                            const date = new Date(entry.time * 1000);
                            const time = date.toLocaleTimeString();
                            const type = entry.success ? 'success' : 'error';
                            const message = entry.success 
                                ? `Imagen ${entry.image_id} optimizada: ${entry.percent_saved} de ahorro` 
                                : `Error en imagen ${entry.image_id}: ${entry.message}`;
                            
                            // Solo agregar si no existe ya
                            const existingLogs = $('.log-entry').map(function() {
                                return $(this).text();
                            }).get();
                            
                            const logText = `[${time}] ${message}`;
                            if (existingLogs.indexOf(logText) === -1) {
                                logMessage(message, type);
                            }
                        });
                    }
                }
                
                // Comprobar de nuevo después de 30 segundos
                setTimeout(checkBackgroundProcess, 30000);
            }
        });
    }
    
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
                const stats = response.data;
                $('#stat-total-images').text(stats.total_images);
                $('#stat-optimized-images').text(stats.optimized_images);
                $('#stat-saved-space').text(stats.saved_space);
                $('#stat-average-reduction').text(stats.average_reduction);
                
                // Iniciar comprobación del proceso en segundo plano
                checkBackgroundProcess();
            }
        }
    });
});