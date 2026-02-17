# Changelog

Todos los cambios relevantes de este plugin se documentan en este archivo.

## [1.7.1] - 2026-02-17
### Fixed
- Se corrige la compatibilidad de acceso legacy: cuando no hay `portal_users` configurados, el login vuelve a aceptar la clave global existente (`password_hash`) para evitar bloqueo en instalaciones previas.

### Added
- Se agrega acción de asignación masiva global en **Listado admin** para asignar todas las facturas a un usuario portal seleccionado.
- Se agrega acción de asignación masiva en **Ajustes** para asignar todas las facturas a un usuario portal seleccionado.

### Changed
- En sesiones legacy, el frontend mantiene comportamiento sin segmentación por `usuario_portal`, preservando compatibilidad hasta migrar a usuarios individuales.
- Se actualiza la versión del plugin de `1.7.0` a `1.7.1`.

## [1.7.0] - 2026-02-16
### Added
- Se agrega soporte de usuarios de portal con clave individual gestionados desde Ajustes (`usuario:clave`, una línea por usuario).
- Se incorpora el campo `usuario_portal` en facturas, con migración automática de esquema e índice para segmentación por usuario.

### Changed
- El login del portal ahora autentica por clave de usuario (sin selector en login) y la sesión queda asociada al usuario autenticado.
- El frontend (listado, mensual y guardado AJAX) queda restringido a facturas del usuario autenticado.
- En admin se añade asignación/filtro por `usuario_portal` en listado, alta y edición de facturas.
- Se actualiza la versión del plugin de `1.6.0` a `1.7.0`.

## [1.6.0] - 2026-02-16
### Changed
- Se simplifica el plugin para trabajar solo con dos estados: `pendiente` y `asignado`.
- Se eliminan `duda` y `cargada` de filtros, formularios de edición, opciones de ajustes, resumen mensual y badges del portal.
- Se ajustan transiciones y acciones masivas para el nuevo modelo de estados (sin operaciones de cargado).
- Se actualiza la versión del plugin de `1.5.0` a `1.6.0`.

## [1.5.0] - 2026-02-16
### Changed
- Se normaliza la lógica de transiciones de estado (`pendiente`, `asignado`, `duda`, `cargada`) para mantener consistentes `assigned_*` y `loaded_*` en edición admin y en portal.
- En el portal, al guardar observación una factura en estado `duda` conserva ese estado (si la observación no queda vacía) y solo vuelve a `pendiente` cuando se limpia.
- Las acciones masivas ahora sincronizan metadatos de estado: `Marcar como Cargada` completa también asignación faltante y `Marcar como Pendiente` limpia asignación/carga.
- Se actualiza la versión del plugin de `1.4.0` a `1.5.0`.

## [1.4.0] - 2026-02-16
### Added
- La vista mensual incorpora un bloque de resumen por estado (Pendiente, Asignado, Duda y Cargada) para lectura rápida del mes filtrado.
- Se agrega acceso rápido a **Mes actual** y etiqueta textual del mes activo en los controles de navegación mensual.

### Changed
- En vista mensual se excluyen facturas sin `fecha_factura` para que el corte mensual sea consistente.
- Se mejora la UX del selector mensual con autoenvío al cambiar el input de mes.
- Se actualiza la versión del plugin de `1.3.0` a `1.4.0`.

## [1.3.0] - 2026-02-16
### Added
- Se agrega un selector visible en el portal para alternar entre vista de **Listado** y **Mensual** desde la cabecera.

### Changed
- Se mejora la navegación de la vista mensual conservando filtros al cambiar de vista y usando el mes actual por defecto.
- Se actualiza la versión del plugin de `1.2.0` a `1.3.0`.

## [1.2.0] - 2026-02-16
### Changed
- Mejora integral UI/UX del portal frontend standalone: nueva jerarquía visual, filtros en bloque, tarjetas de resumen, botones más claros y mejor adaptación móvil.
- Se agrega acción "Limpiar" en filtros y mejoras de accesibilidad (labels ARIA en controles clave).
- Se mejora la interacción de edición de observaciones con estado visual de cambios pendientes y feedback de guardado más claro.
- Se actualiza la versión del plugin de `1.1.3` a `1.2.0`.

## [1.1.3] - 2026-02-16
### Changed
- Se corrige la validación de ruta standalone para comparar contra la ruta absoluta real de `home_url(...)`, evitando que el portal cargue el theme cuando el sitio está en subdirectorio.
- El portal standalone se renderiza únicamente por coincidencia de ruta configurada, sin depender de shortcode ni de query params.
- En Ajustes se agrega una nota explícita indicando que la ruta del portal no debe coincidir con una página existente.
- Se actualiza la versión del plugin de `1.1.2` a `1.1.3`.

## [1.1.2] - 2026-02-16
### Changed
- Se corrige la URL de acceso del portal configurable para abrir directamente en la ruta guardada, sin requerir `?wpfp_portal=1`.
- Se mantiene compatibilidad legacy con `?wpfp_portal=1` cuando se usa en la ruta configurada.
- Se actualiza la versión del plugin de `1.1.1` a `1.1.2`.

## [1.1.1] - 2026-02-16
### Added
- Nuevo ajuste persistente `portal_path` para definir la ruta de acceso del portal standalone.

### Changed
- En Ajustes se muestra un link clickeable al portal construido con la ruta configurada.
- El render standalone ahora valida que la petición coincida con la ruta configurada.
- Se actualiza la versión del plugin de `1.1.0` a `1.1.1`.

## [1.1.0] - 2026-02-16
### Changed
- El portal frontend ahora funciona exclusivamente en modo standalone sin theme, renderizado con `?wpfp_portal=1`.
- Se elimina la opción basada en shortcode y sus referencias legacy en código/admin.
- Se actualiza la versión del plugin de `1.0.3` a `1.1.0`.

## [1.0.3] - 2026-02-16
### Added
- Se agrega este archivo `CHANGELOG.md` para registrar versiones y cambios.

### Changed
- Se incrementa la versión del plugin de `1.0.2` a `1.0.3` en el encabezado principal.
- Se incrementa la constante interna de versión de `1.0.2` a `1.0.3`.
