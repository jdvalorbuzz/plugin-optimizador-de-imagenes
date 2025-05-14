# Buzz Costa Rica - Plugin de Optimización de Imágenes

Plugin de WordPress desarrollado por [Buzz Costa Rica](https://buzz.cr) para optimizar imágenes, convertirlas a WebP y reducir el tamaño de los archivos sin pérdida significativa de calidad.

## Descripción técnica

Este plugin optimiza imágenes JPEG, PNG y GIF en sitios WordPress, convirtiéndolas a WebP, redimensionando imágenes grandes y comprimiendo archivos para reducir su tamaño hasta en un 80%. El plugin está diseñado para funcionar de forma eficiente y segura en sitios con grandes volúmenes de imágenes.

### Características técnicas

- Conversión automática a WebP (manteniendo originales como respaldo)
- Compresión ajustable (0-100%)
- Redimensionamiento automático de imágenes grandes
- Procesamiento por lotes para evitar timeouts
- Entrega WebP automática en navegadores compatibles
- Estadísticas en tiempo real y modo depuración
- Compatible con Elementor y WP Cron
- Interfaz de administración en dos columnas, con branding y enlace a [buzz.cr](https://buzz.cr)

### Funcionamiento

- El plugin añade una página en Medios > Optimización de Imágenes.
- Permite configurar compresión, WebP, redimensionamiento y procesamiento en segundo plano.
- El proceso de optimización puede ejecutarse manualmente o en segundo plano vía WP Cron.
- El plugin detecta y omite imágenes problemáticas para evitar errores.
- Proporciona estadísticas detalladas y logs de depuración.

### Instalación y uso

1. Instala el plugin desde el ZIP o sube la carpeta a `/wp-content/plugins/`.
2. Actívalo desde el panel de WordPress.
3. Ve a Medios > Optimización de Imágenes para configurar y optimizar.

### Requisitos

- WordPress 5.0+
- PHP 7.2+
- Extensión GD o Imagick
- Permisos de escritura en uploads

### Soporte y contacto

Desarrollado por [Buzz Costa Rica](https://buzz.cr). Para soporte, visita [buzz.cr](https://buzz.cr).

## Licencia

GPL v2 o posterior