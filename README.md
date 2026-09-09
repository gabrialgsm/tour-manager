# GMJS Tour Manager — FINAL RELEASE

Production-oriented PHP + MySQL tour management system. Includes booking, payments, rooms, expenses, reports, printing, public seat plan, multiple contacts, check-in, audit logging, and UTF8MB4 support.

GMJS Tour Manager V3.2 — UI/UX refresh

Brand palette: lively green + white. Enhanced dashboard, seat states, cards, forms, mobile spacing, hover/focus states and print-friendly visuals.

# GMJS Tour Manager — PHP + MySQL

## Included
- Secure admin login
- Multiple tours: create, edit, archive/delete empty tours, switch current tour
- Multiple buses per tour: create, edit, activate/deactivate, delete empty buses
- Configurable seat count; generated 5-seat rows (A1-A5, B1-B5...)
- Seat booking with passenger information
- Individual/custom fee and financial assistance discount
- Multiple payments and payment summary
- AC Couple / Non-AC Couple / AC 4 Bed / Non-AC 4 Bed
- Room creation and passenger assignment
- Expense tracking
- Passenger edit/delete (delete means CANCELLED and releases seat)
- Seat map shows booked passenger name
- Payment-due color
- Individual ticket print
- Multi-select ticket print
- Passenger list print
- Tour financial/booking summary print
- Mobile responsive UI

## Install
1. Create a MySQL database/user in Contabo/CloudPanel.
2. Import `db.sql`.
3. Copy `config.example.php` to `config.php` and set DB credentials.
4. Upload the whole folder to your PHP site's document root.
5. Open `/setup_admin.php` once and create the first admin.
6. Delete `setup_admin.php`.
7. Open `/login.php`.

PHP 8.1+ and MySQL 8/MariaDB are recommended.

## Important
- Do not expose `setup_admin.php` after first admin creation.
- Use HTTPS.
- Back up the MySQL database regularly.


## Public Passenger Seat Plan
Open `seat_plan.php` publicly. Passengers can select an active tour and bus and see available/booked seats. No login and no booking action are exposed. Booked passenger names/details are intentionally hidden for privacy. Configure the public contact phone/WhatsApp/message from Admin → Settings.

### V2 → Public Seat Plan migration
If V2 is already installed, import `db_upgrade_from_v2_public_seat_plan.sql` once before using the public seat plan. Then enter contact phone/WhatsApp in Settings.


## V3.1 Multiple Public Contacts
Admin Settings now supports unlimited contact rows per tour. Each row can have a label, phone, and WhatsApp number. The public seat plan displays every active contact with Call/WhatsApp buttons. Existing V3 contact fields are migrated automatically by `db_upgrade_from_v3_multiple_contacts.sql`.
