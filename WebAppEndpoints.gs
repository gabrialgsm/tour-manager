/*
 * Public Web App endpoints.
 * The frontend calls these names. Keep the actual business logic in Code.gs.
 */
function webGetAppData(token) {
  auth_(token);
  return getAppDataInternal_();
}

function webBookPassenger(data, token) {
  return bookPassenger(data, token);
}

function webAddExpense(data, token) {
  return addExpense(data, token);
}
