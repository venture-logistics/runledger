# Ledger

![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange)
![License](https://img.shields.io/badge/license-MIT-green)

A lightweight (2Mb), fast - Google PageSpeed 100, self-hosted knowledge base and community platform built with PHP and MySQL.

Visit [project website](https://runledger.org/) for further details.

## Contributors

[![venture-logistics](https://img.shields.io)]([https://github.com](https://github.com/venture-logistics/runledger))

[![Contributor](https://img.shields.io)](

## Features

- **Knowledge Base**: Organize documentation with categories and articles
- **Community Forum**: Discussion forums with categories, topics, and posts
- **User Management**: Role-based access control (admin/user)
- **Download Center**: Track and manage file downloads
- **Auto-Update System**: One-click updates via admin panel
- **Custom Menus**: Flexible navigation menu management
- **Site Customization**: Customize branding, colors, and notices

## The Engineering Philosophy

RunLedger is built with a commitment to **performance, privacy, and efficiency**.

In an era of bloated web frameworks and expensive cloud subscriptions, we take a different approach:
*   **Hand-Coded Craftsmanship:** The application is hand-coded in raw PHP/HTML/CSS. There are no heavy dependencies, no complex build processes, and zero unnecessary code.
*   **Minimal Footprint:** The entire application footprint (binaries excluded) is a tiny **1.6MB**. It is designed to be lightweight enough to run instantly from a USB stick.
*   **Instant Performance:** This efficiency translates to a perfect **Google PageSpeed 100** score, ensuring an instant, sub-second experience for every user.

We believe that robust, secure, and private software doesn't have to be slow or complicated.

## Requirements

- PHP 8.3 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache/Nginx web server
- Git (for updates)

## Installation

### Quick Install

1. Access the installer:
Navigate to `http://yoursite.com/install/index.php` and follow the setup wizard.

2. Delete or rename install folder

### Manual Installation

If you prefer manual setup, create the database and import `install/schema.sql`, then configure `includes/db.php` with your database credentials.

## Configuration

### Site Settings

Access admin panel at `/admin.php` to configure:
- Site name and description
- Logo and branding
- Footer content
- Menu items
- User roles

## Updates

### Automatic Updates (Recommended)

1. Log in to admin panel
2. Navigate to Updates section
3. If an update is available,
4. Click "Install Update"
5. System automatically downloads, backs up, and applies updates

## Database Migrations

The system uses automatic migrations to keep your database schema up to date. Migrations run automatically during updates.

Migration files are located in `install/migrations/` and are tracked in the `schema_migrations` table.

## Directory Structure

```
ledger/
├── admin.php              # Admin panel
├── includes/              # Core PHP files
│   ├── db.php            # Database connection
│   ├── auth.php          # Authentication
│   └── migrations.php    # Migration system
├── install/               # Installation files
│   ├── schema.sql        # Database schema
│   └── migrations/       # Migration files
├── backups/               # Automatic backups
```

## Admin Panel

Access the admin panel at `/admin.php` to manage:
- Site settings
- Member Settings
- Forum categories
- Knowledge base categories
- Manage Documents
- Menu Management
- Document Quality
- Database Management
- System updates

## Security

- All user passwords are hashed using PHP's password_hash()
- CSRF protection on all forms
- Role-based access control
- SQL injection protection via prepared statements

## Backups

Automatic backups are created before each update and stored in `/backups/` directory. Manual backups can be created by exporting the database:

## Troubleshooting

### Database Connection Failed
Check credentials in `includes/db.php` and verify database server is running.

### Table Does Not Exist Errors
Run migrations from Admin > Updates > Database Migrations.

### Update Failed
Check `/backups/` for automatic backup, then restore if needed:
```bash
mysql -u username -p database_name < backups/backup_YYYY-MM-DD.sql
```

### Permission Errors
Ensure web server has write access to `backups/` and `downloads/` directories.

## Development

### Adding New Features

1. Make code changes
2. Update database schema if needed
3. Generate new schema.sql:
```bash
mysqldump -u user -p database --no-data > install/schema.sql
```
4. Commit and push changes

### Creating Migrations

For custom migrations, add SQL files to `install/migrations/` following the naming pattern:
```
001_description.sql
002_another_feature.sql
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## License

This project is open source. Please check the LICENSE file for details.

## Support

For issues, questions, or feature requests, please use the GitHub issue tracker.

## Credits

Developed by Venture Governance Ltd

## Version

Check the admin panel for the latest installed version.

---

# Portable Ledger Server Stack (Beta)

A fully portable, "zero-install" web server environment designed for testing and demonstrating the Ledger software without requiring XAMPP, WAMP, or local server installations.

The full 200MB portable stack (MariaDB, PHP, Caddy) is hosted on our official site to ensure you get the latest pre-configured binaries.

Download from [project website](https://runledger.org/download.php)

## Overview
This stack provides a pre-configured environment including:
*   **Caddy Server**: Modern, fast web server with automatic configuration.
*   **PHP (FastCGI)**: Lightweight PHP processing.
*   **MariaDB**: Portable database instance with automated schema initialization.

The `install.ps1` script automates the entire setup, including hosts file mapping for `ledger.test`, database creation, and service startup.

## Quick Start
1.  Download the repository to your local machine.
2.  Right-click `install.ps1` and select **Run with PowerShell**.
3.  If prompted for Administrator rights, select **Yes** (required to map `ledger.test` to your local hosts file).
4.  Once the script finishes, it opens a new browser window at `http://ledger.test`.

## Compatibility & Troubleshooting

### Why it might not work on older computers
This stack uses modern binaries and PowerShell automation that require specific Windows features. If the installer window closes immediately on your machine, check the following:

#### 1. PowerShell Version (Requires 5.1+)
Older versions of Windows (Windows 7/8) often ship with PowerShell 2.0 or 3.0. This script uses commands like `Test-NetConnection` and modern redirection syntax that do not exist in older versions.
*   **Fix:** Install [Windows Management Framework 5.1]().

### 2. Unblock in Properties

Right click install.ps1 select properties and Security and tick/select "unblock"

## Server Scripts

More info on the server and the other features view our docs page - [Powershell Files](https://runledger.org/knowledge_document.php?doc=knowledge-powershell-files)
