# Plataforma de Expositores FEXCO

Portal para que las empresas participantes consulten eventos y cupos, registren acreditados y gestionen sus credenciales digitales.

## Tecnologías

- Laravel 12 y PHP 8.2 o superior
- Vue 3, Vite y Vuetify
- MySQL/MariaDB para la base de datos de negocio
- Cola Laravel para el envío de credenciales por correo

## Requisitos de datos

La aplicación usa las tablas de negocio existentes de la plataforma FEXCO, entre ellas `empresas`, `eventos`, `contrato`, `empresa_evento_credenciales`, `expositores`, `credenciales`, `cred_adicionales`, `reglamentos_feria`, `stands` y `pabellones`. Estas tablas no se crean con las migraciones de este repositorio; antes de ejecutar la aplicación, configura una conexión al esquema compatible y verifica que estén disponibles.

La generación de credenciales también requiere los diseños de cada evento y sus fuentes/fotografías. Las fotos se comparten con `intranetfexco`: configura `CREDENTIAL_PHOTOS_PATH` con la misma ruta absoluta en los `.env` de ambos proyectos. Si se deja vacío, `plataforma` usa `../intranetfexco/storage/app/private/accredited-photos`, que coincide con el directorio privado predeterminado de `intranetfexco` cuando ambos proyectos están desplegados como directorios hermanos. Los diseños actualmente se leen desde el proyecto `intranetfexco` adyacente a esta aplicación.

El módulo **Reglamentos de Expositor** obtiene los reglamentos cargados por `intranetfexco`. La empresa solo ve eventos próximos que tengan un contrato vigente. Los PDFs se entregan exclusivamente desde el almacenamiento privado de `intranetfexco`; por defecto, el portal busca en `../intranetfexco/storage/app/private`. Configura `REGLAMENTOS_FERIA_PRIVATE_PATH` si los proyectos no comparten la ruta predeterminada.

Después de desplegar el cambio en `intranetfexco`, migra los reglamentos antiguos fuera de `public/` ejecutando primero `php artisan reglamentos:secure-files` para revisar el número de archivos y luego `php artisan reglamentos:secure-files --apply`. El portal no permite descargar copias que permanezcan en la carpeta pública. Apache bloquea el acceso directo mediante `public/.htaccess`; en Nginx configura una regla equivalente para denegar `/reglamentos-feria/`, ya que Nginx no aplica `.htaccess`. Mantén el almacenamiento privado fuera del document root y no incluyas `reglamentos-feria` en tareas de restauración a `public/`.

No compartas ni guardes archivos `.env` con credenciales en el repositorio.

## Instalación y ejecución

1. Instala dependencias de PHP y JavaScript:

   ```sh
   composer install
   npm install
   ```

2. Configura `.env` con la conexión a la base de datos de negocio, las sesiones y el correo. Genera la clave de Laravel:

   ```sh
   php artisan key:generate
   ```

3. Inicia el servidor, el worker de cola y Vite:

   ```sh
   composer run dev
   ```

Para producción, compila los recursos con `npm run build` y ejecuta un worker persistente para procesar los envíos de correo. Los archivos compilados se generan en `public/assets` y se sirven desde `/assets/` (sin el segmento `/build/`); el document root del servidor debe apuntar a `public`.

## Módulos principales

- **Autenticación empresarial:** sesión Laravel con guard `empresa`.
- **Eventos y cupos:** consulta eventos asociados, contratos, vinculaciones y movimientos de credenciales.
- **Acreditaciones:** búsqueda por CI, validación de duplicados y registro de datos/fotografía.
- **Credenciales digitales:** generación de PNG/QR, descarga individual o por lote y envío por correo.
- **Información de mi empresa:** consulta de solo lectura de los datos registrados y contratos de participación de la empresa autenticada.
- **Reglamentos de Expositor:** consulta y descarga de los PDFs publicados en `intranetfexco`, filtrados por evento próximo con contrato de la empresa.
- **Interfaz:** SPA Vue con navegación protegida para acreditaciones, reglamentos e información de la empresa.

## Pruebas

```sh
php artisan test
```

Las pruebas de autenticación configuran un esquema mínimo temporal en SQLite; no requieren conectarse a la base de datos de negocio.
