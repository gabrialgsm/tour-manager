GMJS TOUR MANAGER — DEPARTURE PLACE FILTER + PRINT LIST UPDATE

WHAT THIS ADDS
1. Passenger List now has a Departure Place column.
2. Departure Place filter is available: All, Dhapari, TNT Math, Baksanagar, Other, and any saved custom value.
3. Search and Departure Place filter work together.
4. Print List button follows the selected Departure Place filter.
5. New passenger_print.php prints a clean boarding list with:
   - Passenger name
   - Phone
   - Seat
   - Departure Place
   - Bus / Bus Number
   - Room
6. When printing all departure places, the printout is grouped by departure place and shows counts.
7. When a departure filter is selected, only passengers from that place are printed.

INSTALL
- Replace passengers.php with the supplied file.
- Add passenger_print.php to the project root.
- No database migration is required. The existing passengers.departure field is already present and the booking form already saves it.
