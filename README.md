# YAKAP GAMOT SYSTEM

A complete PHP + MySQL + XAMPP web application for tracking clinic patient visits, medicine distribution, consultation summaries, and PCSF/SAP transmission logs.

## Features

### Pages
1. **Dashboard** (`dashboard.php`) — Overview landing page with statistics cards (today's patients, monthly counts, pending transmits), recent activity feed, and today's physician breakdown with CSS bar chart.
2. **Daily Log** (`index.php`) — Add, edit, and delete patient daily records with physician assignment, medication type, meds/labs/gamot meds checkboxes. Includes patient autocomplete, search/filter by name/physician/meds type, paginated date list, and CSV export.
3. **Consultation Summary** (`consultation.php`) — View per-physician patient counts and totals within a date range, meds type breakdown with percentages, FPE/no-consultation counts, inactive physician markers, CSS bar chart visualization, CSV export, and print-friendly layout.
4. **Transmit Tracker** (`transmit.php`) — Monthly PCSF/SAP transmission tracking with staff assignment, year selector (±2 years), summary stat cards (completed/pending), "Mark All" quick actions, year totals row, and CSV export.
5. **Patient History** (`patient_history.php`) — Search patient by name to view all their visits across all dates, with visit statistics, visit calendar, and CSV export.
6. **Physicians & Rates** (`physicians.php`) — Manage physicians (name, rate, active status), medication types, and staff members. CSV export for each table.

### Highlights
- Patient name autocomplete using HTML5 `<datalist>` synced from the patients table
- Session-based flash messages (no URL params)
- Interactive JavaScript with toast notifications, smooth scrolling back-to-top, and AJAX save capability
- CSS-only bar charts for patient distribution visualization
- CSV export on all data pages with UTF-8 BOM for Excel compatibility
- Print stylesheet for clean paper output
- Responsive design with mobile breakpoints
- Animated UI elements (flash messages, toasts, hover effects)
- Pagination on the date list (15 per page)

## Setup Instructions (XAMPP)

### Prerequisites
- [XAMPP](https://www.apachefriends.org/) installed with PHP 8+ and MySQL/MariaDB.

### Steps

1. **Copy the project folder**  
   Copy the entire `DailyPatient` folder into your XAMPP `htdocs` directory:
   ```
   C:\xampp\htdocs\DailyPatient\
   ```

2. **Start Apache & MySQL**  
   Open the XAMPP Control Panel and start **Apache** and **MySQL** services.

3. **Create the database**  
   - Open your browser and go to [http://localhost/phpmyadmin](http://localhost/phpmyadmin).
   - Click the **Import** tab.
   - Click **Choose File** and select `database/yakap_gamot.sql` from the project folder.
   - Click **Go** to execute the SQL file. This will:
     - Create the `yakap_gamot` database.
     - Create all 6 tables (`physicians`, `patients`, `meds_types`, `daily_records`, `transmit_log`, `staff`) with proper indexes.
     - Insert seed data (4 physicians, 4 meds types, 1 staff member, 1 sample daily record, 1 sample transmit log).

4. **Open the application**  
   In your browser, navigate to:
   ```
   http://localhost/DailyPatient/
   ```
   or (if you renamed the folder):
   ```
   http://localhost/yakap_gamot_system/
   ```

## Default Credentials

No login/authentication is required. The system is ready to use immediately after setup.

## Database Configuration

If your MySQL credentials differ from the defaults (`root` / no password), edit `config.php`:

```php
$host = 'localhost';
$user = 'root';     // change if needed
$pass = '';         // change if needed
$db   = 'yakap_gamot';
```

## File Structure

```
DailyPatient/
├── config.php              # Database connection, helpers, session flash, CSV export
├── dashboard.php           # Dashboard overview (NEW)
├── index.php               # Daily Log (Page 1)
├── consultation.php        # Consultation Summary (Page 2)
├── transmit.php            # Transmit Tracker (Page 3)
├── physicians.php          # Physicians & Rates (Page 4)
├── patient_history.php     # Patient visit history (NEW)
├── includes/
│   ├── header.php          # Shared header with Dashboard nav tab + flash messages
│   └── footer.php          # Shared footer with back-to-top button + JS
├── css/
│   └── style.css           # Enhanced stylesheet (dashboard, toasts, print, charts, animations)
├── js/
│   └── script.js           # JavaScript enhancements (toasts, back-to-top, AJAX, autocomplete)
├── database/
│   └── yakap_gamot.sql     # SQL file for phpMyAdmin import (with indexes)
├── README.md               # This file
└── TODO.md                 # Progress tracker
```

## Technology Stack

- **PHP** — Server-side scripting with prepared statements (mysqli)
- **MySQL** — Relational database with proper indexes
- **HTML5 + CSS3** — Responsive UI with modern styling and animations
- **JavaScript** — Enhanced interactivity (toasts, AJAX, autocomplete)
- **XAMPP** — Local development environment

## All POST Actions

| Page | Action | Description |
|------|--------|-------------|
| index.php | `add` | Insert new daily record |
| index.php | `update` | Update existing record |
| index.php | `delete` | Delete a record |
| consultation.php | (export) | CSV download |
| transmit.php | `save_transmit` | Upsert transmit log for a month |
| transmit.php | `mark_all_pcsf` | Mark all months PCSF complete |
| transmit.php | `mark_all_sap` | Mark all months SAP complete |
| physicians.php | `add_physician` | Insert new physician |
| physicians.php | `update_physician` | Update physician details |
| physicians.php | `add_meds_type` | Insert new meds type |
| physicians.php | `add_staff` | Insert new staff member |

