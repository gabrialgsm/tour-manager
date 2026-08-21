const CONFIG = {
  sheets: {
    settings:'Settings', tours:'Tours', buses:'Buses', seats:'Seats', passengers:'Passengers',
    payments:'Payments', rooms:'Rooms', expenses:'Expenses'
  }
};

const HEADERS = {
  Settings:['Key','Value'],
  Tours:['TourID','TourName','StartDate','EndDate','BookingDeadline','PackageFee','CoupleFee','BookingFee','Status','CreatedAt'],
  Buses:['BusID','TourID','BusName','SeatCount','LayoutJSON','Status','CreatedAt'],
  Seats:['SeatID','TourID','BusID','SeatNo','RowNo','ColNo','SeatType','Status','PassengerID','UpdatedAt'],
  Passengers:['PassengerID','TourID','BusID','SeatNo','Name','Phone','Address','BloodGroup','EmergencyContact','Departure','RoomType','PackageType','TotalFee','Paid','Due','Notes','Status','CreatedAt','UpdatedAt'],
  Payments:['PaymentID','PassengerID','TourID','Amount','Method','Date','ReceivedBy','Note'],
  Rooms:['RoomID','TourID','RoomNo','RoomType','Capacity','PassengerIDs','Status','Note'],
  Expenses:['ExpenseID','TourID','Date','Category','Description','Amount','PaidBy','Note']
};

function doGet(){return HtmlService.createTemplateFromFile('Index').evaluate().setTitle('GMJS Tour Manager').setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);}
function include(name){return HtmlService.createHtmlOutputFromFile(name).getContent();}
function ss(){return SpreadsheetApp.getActiveSpreadsheet();}
function sh(name){return ss().getSheetByName(name);}
function id(prefix){return prefix+'-'+Utilities.getUuid().slice(0,8).toUpperCase();}
function now(){return new Date();}

function setupSystem(){
  Object.keys(HEADERS).forEach(name=>{
    let s=sh(name); if(!s)s=ss().insertSheet(name);
    if(s.getLastRow()===0)s.getRange(1,1,1,HEADERS[name].length).setValues([HEADERS[name]]);
    else s.getRange(1,1,1,HEADERS[name].length).setValues([HEADERS[name]]);
    s.setFrozenRows(1);
  });
  const settings=sh('Settings');
  if(settings.getLastRow()<2) settings.getRange(2,1,4,2).setValues([
    ['APP_NAME','GMJS Tour Manager'],['CURRENCY','BDT'],['DEFAULT_PACKAGE_FEE','4000'],['DEFAULT_BOOKING_FEE','2000']
  ]);
  if(sh('Tours').getLastRow()<2){
    const tour=id('TOUR');
    sh('Tours').appendRow([tour,'Kuakata & Sundarbans Tour 2026','2026-09-03','2026-09-05','2026-08-25',4000,4500,2000,'OPEN',now()]);
    createBus_(tour,'BUS-1','BUS 1',defaultLayout_());
    createBus_(tour,'BUS-2','BUS 2',defaultLayout_());
  }
  return getAppData();
}
function defaultLayout_(){
  const a=[];
  'ABCDEFGHIJ'.split('').forEach(r=>[1,2,4,5,6].forEach(c=>a.push({seat:r+c,row:r,col:c,type:'STANDARD'})));
  [1,2,3,4,5,6].forEach(c=>a.push({seat:'K'+c,row:'K',col:c,type:'REAR'}));
  return a;
}
function createBus_(tourId,busId,name,layout){
  sh('Buses').appendRow([busId,tourId,name,layout.length,JSON.stringify(layout),'ACTIVE',now()]);
  const rows=layout.map(x=>[id('SEAT'),tourId,busId,x.seat,x.row,x.col,x.type,'AVAILABLE','',now()]);
  if(rows.length)sh('Seats').getRange(sh('Seats').getLastRow()+1,1,rows.length,10).setValues(rows);
}
function rows_(name){const s=sh(name),v=s.getDataRange().getValues();if(v.length<2)return[];const h=v[0];return v.slice(1).filter(r=>r.some(x=>x!=='')).map(r=>Object.fromEntries(h.map((x,i)=>[x,r[i]])));}
function activeTour_(){return rows_('Tours').find(x=>x.Status==='OPEN')||rows_('Tours')[0]||null;}
function getAppData(){
  const t=activeTour_(); if(!t)return {tour:null,buses:[],passengers:[],expenses:[],rooms:[],summary:{}};
  const buses=rows_('Buses').filter(x=>x.TourID===t.TourID&&x.Status==='ACTIVE');
  const seats=rows_('Seats').filter(x=>x.TourID===t.TourID);
  const passengers=rows_('Passengers').filter(x=>x.TourID===t.TourID&&x.Status!=='CANCELLED');
  const expenses=rows_('Expenses').filter(x=>x.TourID===t.TourID);
  const rooms=rows_('Rooms').filter(x=>x.TourID===t.TourID);
  buses.forEach(b=>{b.Layout=safeJson_(b.LayoutJSON,[]);b.Seats=seats.filter(s=>s.BusID===b.BusID);});
  const collection=passengers.reduce((n,p)=>n+Number(p.Paid||0),0),due=passengers.reduce((n,p)=>n+Number(p.Due||0),0),expense=expenses.reduce((n,e)=>n+Number(e.Amount||0),0);
  return {tour:t,buses,passengers,expenses,rooms,summary:{totalSeats:seats.length,booked:seats.filter(s=>s.Status==='BOOKED').length,available:seats.filter(s=>s.Status==='AVAILABLE').length,collection,due,expense,balance:collection-expense,paidCount:passengers.filter(p=>Number(p.Due)<=0).length,dueCount:passengers.filter(p=>Number(p.Due)>0).length}};
}
function safeJson_(x,f){try{return JSON.parse(x)}catch(e){return f}}
function rowBy_(sheet,key,value){const s=sh(sheet),v=s.getDataRange().getValues(),h=v[0],i=h.indexOf(key);for(let n=1;n<v.length;n++)if(String(v[n][i])===String(value))return {row:n+1,values:v[n],headers:h};return null;}
function rowBy2_(sheet,k1,v1,k2,v2){const s=sh(sheet),v=s.getDataRange().getValues(),h=v[0],a=h.indexOf(k1),b=h.indexOf(k2);for(let n=1;n<v.length;n++)if(String(v[n][a])===String(v1)&&String(v[n][b])===String(v2))return {row:n+1,values:v[n],headers:h};return null;}

function bookPassenger(d){
  const lock=LockService.getScriptLock();lock.waitLock(10000);
  try{
    const t=activeTour_();if(!t)throw Error('No active tour');
    if(!d.Name||!d.BusID||!d.SeatNo)throw Error('Name, bus and seat are required.');
    const seat=rowBy2_('Seats','BusID',d.BusID,'SeatNo',d.SeatNo);if(!seat)throw Error('Seat not found.');
    if(seat.values[7]==='BOOKED' && seat.values[8]!==d.PassengerID)throw Error('Seat already booked.');
    const pid=d.PassengerID||id('P');const total=Number(d.TotalFee||t.PackageFee), paid=Number(d.Paid||0), due=Math.max(total-paid,0), stamp=now();
    const data=[pid,t.TourID,d.BusID,d.SeatNo,d.Name,d.Phone||'',d.Address||'',d.BloodGroup||'',d.EmergencyContact||'',d.Departure||'',d.RoomType||'SHARED',d.PackageType||'STANDARD',total,paid,due,d.Notes||'','ACTIVE',stamp,stamp];
    const existing=rowBy_('Passengers','PassengerID',pid);
    if(existing)sh('Passengers').getRange(existing.row,1,1,data.length).setValues([data]);else sh('Passengers').appendRow(data);
    sh('Seats').getRange(seat.row,8,1,3).setValues([['BOOKED',pid,stamp]]);
    if(paid>0 && !existing) sh('Payments').appendRow([id('PAY'),pid,t.TourID,paid,d.PaymentMethod||'Cash',stamp,d.ReceivedBy||'Admin','Booking payment']);
    return getAppData();
  }finally{lock.releaseLock();}
}
function addPayment(d){
  const p=rowBy_('Passengers','PassengerID',d.PassengerID);if(!p)throw Error('Passenger not found.');const amount=Number(d.Amount);if(amount<=0)throw Error('Invalid amount.');
  const total=Number(p.values[12]),paid=Number(p.values[13])+amount,due=Math.max(total-paid,0);sh('Payments').appendRow([id('PAY'),d.PassengerID,p.values[1],amount,d.Method||'Cash',now(),d.ReceivedBy||'Admin',d.Note||'']);sh('Passengers').getRange(p.row,14,1,2).setValues([[paid,due]]);return getAppData();
}
function addExpense(d){const t=activeTour_();if(!d.Category||Number(d.Amount)<=0)throw Error('Category and amount required.');sh('Expenses').appendRow([id('EXP'),t.TourID,d.Date||now(),d.Category,d.Description||'',Number(d.Amount),d.PaidBy||'',d.Note||'']);return getAppData();}
function updatePassenger(d){const p=rowBy_('Passengers','PassengerID',d.PassengerID);if(!p)throw Error('Passenger not found.');const oldSeat=p.values[3],oldBus=p.values[2];if(oldBus!==d.BusID||oldSeat!==d.SeatNo){const target=rowBy2_('Seats','BusID',d.BusID,'SeatNo',d.SeatNo);if(!target||target.values[7]==='BOOKED')throw Error('New seat unavailable.');const old=rowBy2_('Seats','BusID',oldBus,'SeatNo',oldSeat);if(old)sh('Seats').getRange(old.row,8,1,3).setValues([['AVAILABLE','',now()]]);sh('Seats').getRange(target.row,8,1,3).setValues([['BOOKED',d.PassengerID,now()]]);}
  const total=Number(d.TotalFee),paid=Number(p.values[13]),due=Math.max(total-paid,0);sh('Passengers').getRange(p.row,2,1,18).setValues([[p.values[1],d.BusID,d.SeatNo,d.Name,d.Phone||'',d.Address||'',d.BloodGroup||'',d.EmergencyContact||'',d.Departure||'',d.RoomType||'SHARED',d.PackageType||'STANDARD',total,paid,due,d.Notes||'','ACTIVE',p.values[17],now()]]);return getAppData();
}
function deletePassenger(pid){const p=rowBy_('Passengers','PassengerID',pid);if(!p)throw Error('Passenger not found.');const seat=rowBy2_('Seats','BusID',p.values[2],'SeatNo',p.values[3]);if(seat)sh('Seats').getRange(seat.row,8,1,3).setValues([['AVAILABLE','',now()]]);sh('Passengers').getRange(p.row,17).setValue('CANCELLED');return getAppData();}
function addRoom(d){const t=activeTour_();sh('Rooms').appendRow([id('ROOM'),t.TourID,d.RoomNo,d.RoomType||'SHARED',Number(d.Capacity||4),'','AVAILABLE',d.Note||'']);return getAppData();}
function getPaymentHistory(pid){return rows_('Payments').filter(x=>x.PassengerID===pid);}
