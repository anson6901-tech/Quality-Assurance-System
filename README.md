# Quality Assurance System

This is a Laravel, Inertia, and Vue application for managing university quality assurance work. It covers user and role management, academic structure setup, report upload and approval, print queue access, audit logging, and personal account settings.

## Lecture Evaluation Workflow

The Quality Assurance Lecture Evaluation System is organized around the institution's academic structure. The process begins with the creation of an Academic Year, with each academic year consisting of one or more Semesters.

At the beginning of every semester, the Head of Department (HOD) assigns courses to lecturers within the department. Once the course assignments are completed, the assigned courses and their respective lecturers become available to the Quality Assurance (QA) Officers, who are responsible for conducting lecture evaluations.

After completing the evaluation, the QA Officer uploads an evaluation report for each lecturer. Every uploaded report enters a structured approval workflow within the Quality Assurance Directorate.

The first stage of the workflow is the QA Supervisor, who reviews each report to ensure that it is complete, accurate, and complies with the institution's quality assurance standards. If corrections are required, the Supervisor returns the report to the QA Officer with comments explaining the necessary revisions. Once the report meets the required standards, the Supervisor forwards it to the Director of Quality Assurance.

The Director of Quality Assurance performs the final review and approval of the report. If additional corrections are required, the Director returns the report to the QA Officer with the appropriate comments for revision. Once the Director is satisfied with the report, it is published, making it officially available within the system.

After publication, access to the report is controlled according to user roles:

- The Dean of the School can view published evaluation reports for all departments within the school.
- Each Lecturer can access only the published evaluation reports associated with their own courses through their personal portal.
- Lecturers can also download their published evaluation reports for reference and professional development.

This workflow establishes a clear and accountable approval process in which course assignments are managed by the Head of Department, report preparation is performed by QA Officers, quality verification is carried out by the QA Supervisor, final approval and publication are the responsibility of the Director of Quality Assurance, and published reports are made available to the relevant stakeholders based on their access privileges.

## Technology Stack

- Backend: Laravel 13, PHP 8.3+
- Frontend: Vue 3, Inertia, Vite, Tailwind CSS
- Database: SQLite, MySQL, PostgreSQL, or any Laravel-supported database
- Authentication: Laravel Fortify
- Testing: Pest PHP and Vue TypeScript checks

## First-Time Setup

### Run with Docker

Install Docker Desktop, then from the project root build the development image
and run the setup script. It starts MySQL, installs dependencies, applies
migrations, and builds the frontend:

```bash
docker compose run --build --rm app composer run setup
```

Start the app:

```bash
docker compose up
```

Open http://127.0.0.1:8000. The source is mounted into the container, and the
development server, queue worker, Vite server, and MySQL run together.
Dependencies and MySQL data are kept in Docker-managed volumes.

To run Laravel directly on your Mac instead, start only MySQL with
`docker compose up -d db`, keep `DB_HOST=127.0.0.1` in `.env`, and run
`php artisan config:clear` before `php artisan serve`. MySQL is published only
on your local machine at port 3306; if that port is already in use, stop the
other MySQL server or change the published port and `DB_PORT` in `.env`.

The default MySQL credentials are for local development only. Change
`DB_PASSWORD` and `MYSQL_ROOT_PASSWORD` in `.env` before exposing the database
outside your development machine.

Link public storage for uploaded report files from another terminal:

```bash
docker compose exec app php artisan storage:link
```

### Run without Docker

Install PHP 8.3 or newer with Composer, plus Node.js and npm and a MySQL server.
Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in
`.env` for your MySQL server, then run `composer run setup` and
`composer run dev` from the project root.

## Useful Development Commands

```bash
php artisan test
npm run types:check
npm run build
```

Before production deployment, always run:

```bash
php artisan test
npm run build
```

## Production Deployment Checklist

1. Do not commit `.env`, `vendor`, `node_modules`, `public/build`, or log files.
2. Set production environment values in the server `.env`.

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
```

3. Install optimized PHP dependencies.

```bash
composer install --no-dev --optimize-autoloader
```

4. Build frontend assets.

```bash
npm ci
npm run build
```

5. Run migrations.

```bash
php artisan migrate --force
```

6. Cache Laravel configuration.

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

7. Make sure the web server can write to:

```text
storage/
bootstrap/cache/
```

8. Make sure the queue worker is running if queued jobs are used.

```bash
php artisan queue:work
```

## Backend Overview

The backend is organized around Laravel routes, controllers, models, policies, middleware, and migrations.

### Main Request Flow

1. A user signs in through Fortify authentication.
2. Laravel middleware loads the authenticated user and shared Inertia data.
3. Routes in `routes/web.php` send quality assurance pages to controllers under `app/Http/Controllers/Quality`.
4. Controllers validate the request, check the user's permissions, read or update Eloquent models, then render Vue pages with Inertia.
5. Vue pages in `resources/js/pages` receive props from the controller and submit forms back to Laravel routes.
6. `RecordAuditLog` records important quality module visits and write actions.

## Main Modules

### Authentication and Profile Settings

Key files:

- `app/Providers/FortifyServiceProvider.php`
- `app/Http/Controllers/Settings/ProfileController.php`
- `app/Http/Controllers/Settings/SecurityController.php`
- `routes/settings.php`
- `resources/js/pages/settings/Profile.vue`

Settings has been simplified to only:

- edit name and email
- reset password

The password update endpoint is still handled by `SecurityController@update`, but the form lives on the profile settings page.

### Users and Roles

Key files:

- `app/Http/Controllers/Quality/UserController.php`
- `app/Http/Controllers/Quality/RoleRightController.php`
- `app/Models/User.php`
- `app/Models/QualityRolePermission.php`
- `app/Enums/UniversityRole.php`

Users have a `university_role`. Each role maps to permissions in `QualityRolePermission`.

Important permissions include:

- `manage_users`
- `manage_structure`
- `upload_reports`
- `view_approval_queue`
- `approve_reports`
- `view_print_queue`
- `view_audit_logs`

If a user cannot see a module, check their role permissions first.

### Academic Structure

Key files:

- `SchoolController.php`
- `DepartmentController.php`
- `CourseController.php`
- `AcademicYearController.php`
- `School`, `Department`, `Course`, and `AcademicYear` models

The structure is connected like this:

```text
School -> Departments -> Courses
Academic Year -> Quality Reports
Users -> School and/or Department
```

Reports depend on this setup. If uploads fail because lecturers, schools, departments, courses, or academic years are missing, seed or create those records first.

### Quality Reports

Key files:

- `app/Http/Controllers/Quality/QualityReportController.php`
- `app/Models/QualityReport.php`
- `resources/js/pages/quality/reports`
- `database/migrations/*quality_reports*`

Report flow:

```text
Uploaded by QA user
Submitted
Pushed to approval
Under review
Approved
Print queue
Downloaded or printed
```

Important status values:

- `draft`
- `submitted`
- `under_review`
- `approved`

Reports are linked to:

- academic year
- school
- department
- lecturer
- one or more courses
- uploader
- optional PDF file in public storage

Visibility is controlled in `QualityReport::scopeVisibleTo()`. If someone cannot see a report, check:

- report status
- user role permission
- user's assigned school or department
- lecturer/course relationship

### Approval and Print Queue

Approval and print queue are handled by `QualityReportController`.

Users need:

- `view_approval_queue` to see reports waiting for approval
- `approve_reports` to approve or unapprove reports
- `view_print_queue` to see approved reports ready for printing

Only approved reports can be downloaded from the print queue.

### Audit Logs

Key files:

- `app/Http/Middleware/RecordAuditLog.php`
- `app/Models/AuditLog.php`
- `app/Http/Controllers/Quality/AuditLogController.php`
- `resources/js/pages/quality/audit-logs/Index.vue`

The audit system records:

- user login events
- module visits
- create/update/delete actions
- route name
- response status
- target record
- submitted field names and safe values
- actor role context
- IP address and user agent

Sensitive values such as passwords are not stored.

Audit logs can be exported from:

```text
/quality/audit-logs/export
```

Only users with `view_audit_logs` can access the audit screen and export.

## Database Seed Data

The seeder creates sample schools, departments, users, courses, one academic year, and one sample quality report.

Default local test users include:

```text
test@example.com
dean@example.com
hod@example.com
lecturer@example.com
```

In local development, seeded users use:

```text
password
```

Change all seeded or temporary passwords before production use.

## Troubleshooting

### Page shows 403 forbidden

Check the user's role and permissions in:

```text
quality_role_permissions
```

Also check:

- `User::canManageUsers()`
- `User::canManageQualityStructure()`
- `User::canUploadQualityReports()`
- `User::canViewAuditLogs()`
- `QualityReport::scopeVisibleTo()`

### Uploaded PDFs do not open

Run:

```bash
php artisan storage:link
```

Confirm the file exists under:

```text
storage/app/public/quality-reports
```

Confirm the web server can read `storage/`.

### Frontend changes do not appear

For development:

```bash
npm run dev
```

For production:

```bash
npm run build
```

Then clear caches if needed:

```bash
php artisan optimize:clear
```

### Routes or config seem stale

Clear Laravel caches:

```bash
php artisan optimize:clear
```

Then rebuild production caches:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Database errors after deployment

Run:

```bash
php artisan migrate --force
```

If the issue is missing seed data, inspect the relevant tables before running seeders in production.

### Audit export does not download

Check that the user has:

```text
view_audit_logs
```

Then confirm the route exists:

```bash
php artisan route:list --path=audit-logs
```

## Important Files

```text
routes/web.php                         Main quality module routes
routes/settings.php                    Profile and password settings routes
app/Models/User.php                    User permissions and quality profile
app/Models/QualityRolePermission.php   Role-permission mapping
app/Models/QualityReport.php           Report relationships and visibility
app/Http/Middleware/RecordAuditLog.php Audit capture
database/migrations                    Database structure
database/seeders/DatabaseSeeder.php    Local seed data
resources/js/pages                     Vue page components
```

## Notes for Future Developers

When fixing production issues, start with these questions:

1. Is the user authenticated?
2. Does the user role have the needed permission?
3. Does the route point to the expected controller method?
4. Does the controller validate the submitted fields correctly?
5. Does the model relationship or scope filter out the record?
6. Did a migration or storage link fail?
7. Does the audit log show what the user attempted?

The backend is permission-first. Most "missing page" or "missing data" issues are caused by role permissions or visibility scopes, not by the Vue page itself.
