/* Web-app spreadsheet connection fix.
 * This file intentionally defines the final global ss() used by the app.
 */
function bindGMJSSpreadsheet(){
  const active = SpreadsheetApp.getActiveSpreadsheet();
  if (!active) throw new Error('Open the GMJS Google Sheet and run bindGMJSSpreadsheet() from its Apps Script editor.');
  PropertiesService.getScriptProperties().setProperty('SPREADSHEET_ID', active.getId());
  Logger.log('GMJS spreadsheet bound: ' + active.getName() + ' / ' + active.getId());
  return 'CONNECTED: ' + active.getName();
}

function ss(){
  const id = PropertiesService.getScriptProperties().getProperty('SPREADSHEET_ID');
  if (id) return SpreadsheetApp.openById(id);
  const active = SpreadsheetApp.getActiveSpreadsheet();
  if (active) return active;
  throw new Error('GMJS spreadsheet is not connected. Run bindGMJSSpreadsheet() once from the bound Google Sheet Apps Script editor.');
}

function gmjsConnectionStatus(){
  const id = PropertiesService.getScriptProperties().getProperty('SPREADSHEET_ID');
  if (!id) return 'NOT CONNECTED';
  const book = SpreadsheetApp.openById(id);
  return 'CONNECTED: ' + book.getName() + ' | ' + id;
}
