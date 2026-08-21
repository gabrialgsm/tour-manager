# GMJS Tour Manager — Current Tour Configuration

## Bus
- Active bus capacity: 50 seats
- Layout: 10 rows × 5 seats
- Per row: 2 seats on one side + aisle + 3 seats on the other side
- Seat IDs: configurable; default generated as A1/A2 and B1/B2/B3 per row.

## Pricing
Passenger price is NOT fixed. Each booking stores its own final price.
- Base/package price can be selected from settings.
- Admin can override the final amount for individual passengers.
- Discounts/financial assistance such as ৳100 or ৳200 reductions are supported.
- Booking payment and later payments are tracked separately.

## Room categories
1. AC Couple
2. Non-AC Couple
3. AC 4 Bed
4. Non-AC 4 Bed

Room category is stored with each passenger/room allocation.

## Important
Do not hard-code ৳4000 as the final passenger price. The booking form must calculate due from the passenger's custom final price minus total payments received.
