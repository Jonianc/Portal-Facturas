# Changelog

Todos los cambios relevantes de este plugin se documentan en este archivo.

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
