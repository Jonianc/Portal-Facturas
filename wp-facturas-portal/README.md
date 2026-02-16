# WP Facturas Portal (Drive)

Plugin de WordPress para gestionar facturas en un portal protegido por contraseña, con carga de PDFs en Google Drive y estados de seguimiento.

## Versión

`1.0.3`

## Instalación

1. Copia la carpeta `wp-facturas-portal` en `wp-content/plugins/`.
2. Activa el plugin desde el panel de WordPress.
3. Crea una página y agrega el shortcode:

```txt
[wp_facturas_portal]
```

## Uso

- Menú admin: **Portal Facturas**.
- Vistas de estado: pendiente, asignado, duda y cargada.
- El cliente puede dejar observación en el portal y la factura pasa automáticamente a estado **asignado**.

## Generar ZIP de entrega

Se define y recomienda este comando (desde la raíz del repositorio):

```bash
./wp-facturas-portal/scripts/build-zip.sh
```

Salida esperada:

- `dist/wp-facturas-portal-<version>.zip`

## Changelog rápido

Ver `CHANGELOG.md` para detalle completo de cambios por versión.
