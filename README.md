# Laravel CRM + Aprobación OEA

Laravel CRM 2.x en español, con el módulo de aprobación de clientes OEA. Corre en Docker (PHP 8.3 + MySQL 8).

- App: http://localhost:8000/crm
- MySQL desde el host: `localhost:3307` (usuario `crm`, clave `secret`, base `laravel_crm`)
- Código del módulo OEA: `src/app/Oea`, `src/app/Livewire/Oea`, `src/resources/views/livewire/oea`

## Instalar en un PC nuevo

Requisitos: Docker Desktop y Git. En Windows, usar Git Bash para estos comandos.

```bash
git clone <url-del-repo> laravel_crm
cd laravel_crm

cp src/.env.example src/.env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan storage:link
```

Luego elige una de las dos opciones para la base de datos.

### Opción A: traer los datos del otro PC

En el PC original:

```bash
mkdir -p backup
docker compose exec -T db mysqldump -ucrm -psecret --no-tablespaces laravel_crm > backup/laravel_crm.sql
tar -czf backup/storage.tar.gz -C src/storage app
```

Copia la carpeta `backup/` al PC nuevo (USB, Drive…; no va en el repo) y ahí:

```bash
docker compose exec -T db mysql -ucrm -psecret laravel_crm < backup/laravel_crm.sql
tar -xzf backup/storage.tar.gz -C src/storage
docker compose exec app php artisan optimize:clear
```

Los usuarios y contraseñas son los mismos del PC original.

### Opción B: instalación limpia

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan laravelcrm:install
docker compose exec app php artisan oea:setup
docker compose exec app php artisan db:seed --class=OeaDemoSeeder
```

`laravelcrm:install` pide los datos del usuario dueño. Créalo con el correo `admin@example.com`, porque el seeder de demo lo usa como responsable de Gerencia.

El seeder crea los 16 documentos y los usuarios de prueba `revision@example.com` y `cliente@example.com` (clave `password123`).

## Comandos útiles

```bash
docker compose up -d                                   # iniciar
docker compose down                                    # detener (los datos se conservan)
docker compose exec app php artisan optimize:clear     # limpiar caché
docker compose exec app php tests/Oea/oea_e2e.php      # prueba del flujo OEA (no deja datos)
```

## Notas

- `vendor/` vive en un volumen de Docker para que el sitio cargue rápido en Windows. Por eso después de clonar hay que correr `composer install`.
- Los documentos que se suben a los clientes se guardan en `src/storage/app` y no van en el repo.
